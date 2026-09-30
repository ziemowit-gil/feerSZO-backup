<?php
/**
 * modules/payment_portal/logic/paymentPortal.php — logika portalu płatności SZO (/platnosci).
 *
 * Przepływ:
 *   1. Admin (platnosci/admin.php) zakłada uczestnikowi dostęp: indywidualny NRB
 *      (generowany z prefiksu rachunków wirtualnych banku albo wpisany) i link z
 *      tokenem (w bazie tylko SHA-256). Pozycje do zapłaty: ręczne, faktury, karty,
 *      warsztaty oraz import nieopłaconych rozliczeń TI (kwota = bieżąca niedopłata).
 *   2. Uczestnik (platnosci/index.php?t=…) zaznacza pozycje; serwer liczy kwotę z bazy
 *      (pp_create_transaction — pozycje → processing, transakcja pending, w jednej
 *      transakcji PDO z audytem) i:
 *        • P24: p24_create_order('payment_portal', tx) → przekierowanie do bramki;
 *          webhook + transaction/verify (includes/p24.php) → pp_settle();
 *        • NRB: dane do przelewu (NRB, kwota, tytuł z identyfikatorem transakcji);
 *          „Zgłoś wykonanie przelewu” zostawia transakcję pending do potwierdzenia
 *          przez admina po zaksięgowaniu (pp_settle) albo odrzucenia (pp_fail).
 *   3. pp_settle(): transakcja success, pozycje paid i skutki w źródłach (rozliczenie
 *      TI → wpłata w księdze ti_payment_add, faktura → invoices.paid_at) — atomowo.
 * Audyt (audit_logs): payments.attempt / p24_request / p24_simulated / nrb_declared /
 * success / failed / item_status / item_created / token_issued / nrb_set.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';
require_once dirname(__DIR__, 3) . '/includes/p24.php';
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

const PP_REF_TYPES = ['invoice' => 'Faktura VAT', 'card_application' => 'Karta dostępu', 'workshop' => 'Warsztat / zajęcia',
                      'manual' => 'Inna opłata', 'ti_billing' => 'Zajęcia TI (rozliczenie)'];
const PP_ITEM_STATUS = ['pending' => 'do zapłaty', 'processing' => 'w trakcie opłacania', 'paid' => 'opłacone', 'cancelled' => 'anulowane'];
const PP_TX_STATUS   = ['pending' => 'oczekuje', 'success' => 'opłacona', 'failed' => 'nieudana / anulowana'];

function pp_migrate(): void {
    static $done = false; if ($done) return; $done = true;
    audit_logs_migrate();
    p24_migrate();
    db()->exec((string)file_get_contents(dirname(__DIR__) . '/schema.sql'));
    // Rachunek, na który miał iść przelew (indywidualny albo ogólny) — do dopasowania z wyciągów
    try { db()->exec("ALTER TABLE portal_transactions ADD COLUMN target_nrb TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    // Wpływy z wyciągów EODoK (edok_bank_tx) rozliczone w portalu — jeden wpływ = jedna płatność
    db()->exec("CREATE TABLE IF NOT EXISTS pp_bank_matches (
        id INTEGER PRIMARY KEY AUTOINCREMENT, bank_tx_id INTEGER NOT NULL UNIQUE, portal_transaction_id INTEGER NOT NULL,
        how TEXT NOT NULL DEFAULT '', by_name TEXT NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    // Pula rachunków wirtualnych wygenerowanych przez bank (import z listy TXT); grp = ti / inni / ...
    db()->exec("CREATE TABLE IF NOT EXISTS pp_vnrb_pool (
        nrb TEXT PRIMARY KEY, grp TEXT NOT NULL DEFAULT 'ti', participant_id INTEGER,
        imported_by TEXT NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, assigned_at DATETIME)");
    try { db()->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN is_virtual INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}   // kursant wirtualny: bez rachunku z puli
    db()->exec("CREATE TABLE IF NOT EXISTS pp_notice_exclusions (batch_id INTEGER NOT NULL, client_id INTEGER NOT NULL, by_name TEXT NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (batch_id, client_id))");
    db()->exec("CREATE TABLE IF NOT EXISTS pp_item_invoices (item_id INTEGER PRIMARY KEY, local_id INTEGER, betterfly_invoice_id INTEGER,
        number TEXT NOT NULL DEFAULT '', by_name TEXT NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    db()->exec("CREATE TABLE IF NOT EXISTS pp_vnrb_blocks (nrb TEXT PRIMARY KEY, reason TEXT NOT NULL DEFAULT '', by_name TEXT NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    db()->exec("CREATE TABLE IF NOT EXISTS pp_bank_autopost (bank_tx_id INTEGER PRIMARY KEY, participant_id INTEGER NOT NULL, payment_id INTEGER,
        amount REAL NOT NULL, nrb TEXT NOT NULL, by_name TEXT NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    db()->exec("CREATE TABLE IF NOT EXISTS pp_notice_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT 'draft', scope TEXT NOT NULL DEFAULT 'unnotified', batch TEXT NOT NULL DEFAULT '',
        subject TEXT NOT NULL, body TEXT NOT NULL, sms_text TEXT NOT NULL DEFAULT '', send_at DATETIME, approved_by TEXT, approved_at DATETIME,
        created_by TEXT NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, sent_at DATETIME, stats TEXT NOT NULL DEFAULT '')");
    try { db()->exec("ALTER TABLE pp_vnrb_pool ADD COLUMN crm_contact_id INTEGER"); } catch (\Throwable $e) {}   // numer przypisany kontrahentowi CRM
    try { db()->exec("ALTER TABLE pp_vnrb_pool ADD COLUMN notified_at DATETIME"); } catch (\Throwable $e) {}     // kiedy admin wysłał powiadomienie o numerze
    db()->exec("CREATE TABLE IF NOT EXISTS pp_vnrb_crm (contact_id INTEGER PRIMARY KEY, nrb TEXT NOT NULL UNIQUE, assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    try { db()->exec("ALTER TABLE pp_vnrb_pool ADD COLUMN batch TEXT"); } catch (\Throwable $e) {}   // paczka importu (ostatnio zaimportowane)
}
function pp_gr(float|int|string $v): int { return (int)round((float)$v * 100); }
function pp_zl(int $gr): float { return round($gr / 100, 2); }
function pp_fmt(float $v): string { return number_format($v, 2, ',', ' ') . ' zł'; }

// ── NRB ─────────────────────────────────────────────────────────────────────
/** Cyfry kontrolne IBAN dla polskiego BBAN (24 cyfry). */
function pp_nrb_check(string $bban): string {
    $num = $bban . '2521' . '00';                      // PL = 25 21, "00" na miejsce cyfr kontrolnych
    $mod = 0;
    foreach (str_split($num) as $d) $mod = ($mod * 10 + (int)$d) % 97;
    return str_pad((string)(98 - $mod), 2, '0', STR_PAD_LEFT);
}
function pp_nrb_normalize(string $raw): string { return preg_replace('/\D/', '', preg_replace('/^\s*PL/i', '', $raw)); }
function pp_nrb_valid(string $nrb): bool {
    $n = pp_nrb_normalize($nrb);
    return strlen($n) === 26 && pp_nrb_check(substr($n, 2)) === substr($n, 0, 2);
}
function pp_nrb_format(string $nrb): string {
    $n = pp_nrb_normalize($nrb);
    return strlen($n) === 26 ? substr($n, 0, 2) . ' ' . trim(chunk_split(substr($n, 2), 4, ' ')) : $nrb;
}
/**
 * Grupy kontrahentów rachunków wirtualnych. Struktura BBAN (24 cyfry) wg banku:
 *   8 cyfr nr rozliczeniowy banku (PKO BP, np. 1020 2906) + 4 cyfry RRRR = ID USŁUGI (UMOWY) w banku + 12 cyfr NNNN
 * Część NNNN (12 cyfr) zależy od grupy — dzięki temu z samego numeru wiadomo, kto płaci:
 *   ti    — nr kursanta TI (k30_ti_student_accounts.student_no, już 12 cyfr) bez zmian
 *   nip   — „00" + NIP (10 cyfr)            — firma / kontrahent
 *   crm   — „8" + ID kontaktu CRM (11 cyfr) — kontrahent bez NIP (z NIP-em: grupa nip)
 *   id    — „9" + ID uczestnika (11 cyfr)   — uczestnik spoza TI (bez numeru kursanta)
 *   reczny— dowolne do 12 cyfr, dopełnione zerami z lewej
 */
/** Domyślny prefiks banku (PKO BP): 1020 2906 = nr rozliczeniowy, RRRR = ID usługi (umowy) w banku (domyślnie 3286). Nadpisują go ustawienia. */
const PP_VNRB_BANK = '10202906';
const PP_VNRB_RRRR = '3286';
function pp_vnrb_bank(): string { $v = preg_replace('/\D/', '', (string)org_setting('pp_nrb_bank')); return $v !== '' ? $v : PP_VNRB_BANK; }
function pp_vnrb_rrrr(): string { $v = preg_replace('/\D/', '', (string)org_setting('pp_nrb_prefix')); return $v !== '' ? $v : PP_VNRB_RRRR; }

/**
 * Serie rachunków: końcówka NNNN (12 cyfr) = kod serii (8 cyfr) + numer kolejny nadawany przez bank (4 cyfry).
 * Klucz = grupa puli; kod nadpisywalny org_setting pp_series_{klucz}. Z numeru od banku da się więc rozpoznać serię.
 */
function pp_series(): array {
    $def = ['ti' => ['Kursanci TI', '00000001'], 'inni' => ['Kontrahenci inni', '00000002'], 'spoza_ti' => ['Uczestnicy spoza TI', '00000003'], 'reczny' => ['Ręczne', '00000004']];
    $out = [];
    foreach ($def as $k => [$label, $code]) {
        $c = preg_replace('/\D/', '', (string)org_setting('pp_series_' . $k));
        $out[$k] = ['label' => $label, 'code' => strlen($c) === 8 ? $c : $code];
    }
    return $out;
}
/** Jedyny numer startowy serii (kod 8 cyfr + 0001), który SZO podaje bankowi; bank numeruje dalej ostatnie 4 cyfry. */
function pp_series_start(string $grp): ?string {
    $se = pp_series()[$grp] ?? null;
    return $se ? pp_vnrb_build(pp_vnrb_bank(), pp_vnrb_rrrr(), $se['code'] . '0001') : null;
}
/**
 * Generator numerów jak w banku: numer kontrahenta (12 cyfr) + liczba następnych → lista pełnych NRB
 * (numer początkowy + $count kolejnych). Do podglądu, kontroli listy z banku i pobrania TXT.
 * @return list<string>|string lista 26-cyfrowych NRB albo komunikat błędu
 */
function pp_gen_list(string $start12, int $count): array|string {
    $start12 = preg_replace('/\D/', '', $start12);
    if (strlen($start12) !== 12) return 'Numer kontrahenta ma 12 cyfr.';
    if ($count < 0 || $count > 9999) return 'Liczba następnych numerów: 0–9999.';
    if ((int)substr($start12, -4) + $count > 9999) return 'Przekroczono zakres serii: ostatnie 4 cyfry dochodzą do 9999 (numer kontrahenta ' . $start12 . ' + ' . $count . ').';
    $out = [];
    for ($i = 0; $i <= $count; $i++) {
        $n = pp_vnrb_build(pp_vnrb_bank(), pp_vnrb_rrrr(), str_pad((string)((int)substr($start12, 0, 8) * 10000 + (int)substr($start12, -4) + $i), 12, '0', STR_PAD_LEFT));
        if ($n === null) return 'Uzupełnij bank (8 cyfr) i RRRR (4 cyfry) w ustawieniach.';
        $out[] = $n;
    }
    return $out;
}
/** Ostatnio użyte wartości generatora dla serii: [numer_kontrahenta, liczba]. */
function pp_gen_last(string $grp): array {
    $v = explode('|', (string)org_setting('pp_gen_last_' . $grp));
    $se = pp_series()[$grp] ?? ['code' => '00000000'];
    return [preg_match('/^\d{12}$/', $v[0] ?? '') ? $v[0] : $se['code'] . '0001', (int)($v[1] ?? 0) ?: 100];
}

/** Seria (klucz grupy) rozpoznana po kodzie w numerze od banku albo null. */
function pp_series_detect(string $nrb): ?string {
    $code = substr(pp_nrb_normalize($nrb), 14, 8);
    foreach (pp_series() as $k => $se) if ($se['code'] === $code) return $k;
    return null;
}

const PP_VGROUPS = [
    'ti'     => ['label' => 'Kursant TI (nr kursanta)',       'hint' => 'ID uczestnika — numer weźmiemy z konta kursanta'],
    'nip'    => ['label' => 'Firma / kontrahent (NIP)',       'hint' => 'NIP, 10 cyfr'],
    'crm'    => ['label' => 'Kontrahent CRM (ID kontaktu)',   'hint' => 'ID kontaktu w CRM'],
    'id'     => ['label' => 'Uczestnik spoza TI (ID w SZO)',  'hint' => 'ID uczestnika (k30_clients)'],
    'reczny' => ['label' => 'Numer ręczny',                   'hint' => 'do 12 cyfr'],
];

/** Numer kursanta TI (12 cyfr) uczestnika albo null, gdy nie ma konta / numer niepoprawny. */
function pp_ti_student_no(int $participant_id): ?string {
    try {
        $r = db_one("SELECT student_no FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id LIMIT 1", [$participant_id]);
    } catch (\Throwable $e) { return null; }
    $no = (string)($r['student_no'] ?? '');
    return preg_match('/^\d{12}$/', $no) ? $no : null;
}

/** Część NNNN (12 cyfr) dla grupy i wartości; string z błędem zaczyna się od "!". */
function pp_vnrb_part(string $group, string $value): string {
    $d = preg_replace('/\D/', '', $value);
    switch ($group) {
        case 'ti':
            if ($d === '') return '!Podaj ID uczestnika.';
            return pp_ti_student_no((int)$d) ?? '!Uczestnik nie ma konta kursanta TI z 12-cyfrowym numerem.';
        case 'nip':   return strlen($d) === 10 ? '00' . $d : '!NIP ma 10 cyfr.';
        case 'crm':   return $d !== '' && strlen($d) <= 11 ? '8' . str_pad($d, 11, '0', STR_PAD_LEFT) : '!Podaj ID kontaktu CRM (do 11 cyfr).';
        case 'id':    return $d !== '' && strlen($d) <= 11 ? '9' . str_pad($d, 11, '0', STR_PAD_LEFT) : '!Podaj ID uczestnika (do 11 cyfr).';
        case 'reczny':return $d !== '' && strlen($d) <= 12 ? str_pad($d, 12, '0', STR_PAD_LEFT) : '!Podaj od 1 do 12 cyfr.';
    }
    return '!Nieznana grupa.';
}

/** Składa NRB: cyfry kontrolne + bank(8) + RRRR(4) + NNNN(12). null przy złych danych. */
function pp_vnrb_build(string $bank, string $rrrr, string $n12): ?string {
    if (!preg_match('/^\d{8}$/', $bank) || !preg_match('/^\d{4}$/', $rrrr) || !preg_match('/^\d{12}$/', $n12)) return null;
    $bban = $bank . $rrrr . $n12;
    return pp_nrb_check($bban) . $bban;
}

/** Czy ten NRB jest wolny (nie ma go inny uczestnik ani inny kursant jako swojego numeru). */
function pp_vnrb_conflict(string $nrb, int $participant_id): ?string {
    $n = pp_nrb_normalize($nrb);
    if (db_one("SELECT 1 FROM payment_portal_users WHERE individual_nrb=? AND participant_id!=?", [$n, $participant_id])) return 'Ten numer ma już inny uczestnik.';
    $part = substr($n, 14);
    try {
        if (db_one("SELECT 1 FROM k30_ti_student_accounts WHERE student_no=? AND client_id!=?", [$part, $participant_id])) return 'Ta część numeru jest numerem kursanta TI innej osoby.';
    } catch (\Throwable $e) {}
    return null;
}

/** Rachunek wirtualny kursanta TI do pokazania (nie zapisuje); null = brak numeru kursanta. */
function pp_vnrb_for_ti(int $client_id): ?string {
    $no = pp_ti_student_no($client_id);
    $n = $no ? pp_vnrb_build(pp_vnrb_bank(), pp_vnrb_rrrr(), $no) : null;
    return $n ? pp_nrb_format($n) : null;
}
/** Rachunek wirtualny kontrahenta CRM: z NIP-em (10 cyfr) grupa nip, inaczej ID kontaktu. */
function pp_vnrb_for_crm(int $contact_id, ?string $nip = null): ?string {
    // Numery pochodzą z puli banku — pokazujemy tylko faktycznie przypisany (bez dawnego wyliczenia z NIP/ID)
    try { $a = db_one("SELECT nrb FROM pp_vnrb_crm WHERE contact_id=?", [$contact_id]); } catch (\Throwable $e) { $a = null; }
    return $a ? pp_nrb_format((string)$a['nrb']) : null;
}

/**
 * NRB wirtualny dla uczestnika z ustawień: bank (8) + RRRR (pp_nrb_prefix, 4) + NNNN.
 * Kursant z kontem TI → jego nr kursanta; pozostali → grupa „id". null = nie skonfigurowano
 * albo konflikt numerów.
 */
function pp_nrb_generate(int $participant_id): ?string {
    return null;   // numery nadaje bank (lista TXT → pula), SZO nie generuje ich samodzielnie
}
/** @deprecated dawny generator z numeru kursanta — nieużywany, zostawiony dla wstecznej zgodności. */
function pp_nrb_generate_legacy(int $participant_id): ?string {
    $bank = pp_vnrb_bank();
    $rrrr = pp_vnrb_rrrr();
    $part = pp_ti_student_no($participant_id) ?? pp_vnrb_part('id', (string)$participant_id);
    if ($part[0] === '!') return null;
    $nrb = pp_vnrb_build($bank, $rrrr, $part);
    return $nrb !== null && pp_vnrb_conflict($nrb, $participant_id) === null ? $nrb : null;
}

/**
 * Rachunek do przelewu tradycyjnego: indywidualny NRB uczestnika (rachunek wirtualny)
 * albo — gdy go nie ma — rachunek ogólny organizacji (pp_general_nrb). Przy rachunku
 * ogólnym płatność rozpoznajemy wyłącznie po tytule przelewu.
 * @return array{nrb:string, kind:string}|null
 */
function pp_payment_account(?array $user): ?array {
    $ind = pp_nrb_normalize((string)($user['individual_nrb'] ?? ''));
    if ($ind !== '' && pp_nrb_valid($ind)) return ['nrb' => $ind, 'kind' => 'individual'];
    $gen = pp_nrb_normalize((string)org_setting('pp_general_nrb'));
    return $gen !== '' && pp_nrb_valid($gen) ? ['nrb' => $gen, 'kind' => 'general'] : null;
}

// ── Użytkownicy i dostęp ────────────────────────────────────────────────────
function pp_user(int $participant_id): ?array {
    pp_migrate();
    return db_one("SELECT u.*, c.name AS participant_name FROM payment_portal_users u JOIN k30_clients c ON c.id=u.participant_id WHERE u.participant_id=?", [$participant_id]) ?: null;
}
/** Zakłada dostęp uczestnika (idempotentnie); NRB z generatora, gdy skonfigurowany. */
function pp_user_ensure(int $participant_id, string $by = '', ?int $uid = null): array|string {
    pp_migrate();
    $cl = db_one("SELECT id, name, email FROM k30_clients WHERE id=?", [$participant_id]);
    if (!$cl) return 'Nie ma takiego uczestnika.';
    if ($u = pp_user($participant_id)) return $u;
    $email = trim((string)$cl['email']);
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || db_one("SELECT 1 FROM payment_portal_users WHERE email=?", [$email]))) $email = '';
    db_insert('payment_portal_users', ['participant_id' => $participant_id, 'email' => $email !== '' ? $email : null,
                                       'individual_nrb' => pp_nrb_generate($participant_id), 'is_active' => 1]);
    audit_log('payments.portal_user_created', ['participant_id' => $participant_id, 'by' => $by], $uid);
    return pp_user($participant_id);
}
function pp_set_nrb(int $participant_id, string $nrb, string $by, ?int $uid): ?string {
    pp_migrate();
    $n = pp_nrb_normalize($nrb);
    if ($n !== '' && !pp_nrb_valid($n)) return 'Nieprawidłowy numer rachunku (26 cyfr, suma kontrolna).';
    if ($n !== '' && db_one("SELECT 1 FROM payment_portal_users WHERE individual_nrb=? AND participant_id!=?", [$n, $participant_id])) return 'Ten numer rachunku ma już inny uczestnik.';
    $pdo = db(); $pdo->beginTransaction();
    try {
        $old = db_one("SELECT individual_nrb FROM payment_portal_users WHERE participant_id=?", [$participant_id]);
        db()->prepare("UPDATE payment_portal_users SET individual_nrb=? WHERE participant_id=?")->execute([$n !== '' ? $n : null, $participant_id]);
        audit_log('payments.nrb_set', ['participant_id' => $participant_id, 'before' => $old['individual_nrb'] ?? null, 'after' => $n, 'by' => $by], $uid);
        $pdo->commit();
        return null;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd: ' . $e->getMessage(); }
}

/**
 * Powiadamia kursanta TI (SMS + e-mail) o nowym numerze rachunku do wpłat.
 * Każdy kanał niezależnie; zwraca ['sms' => bool, 'email' => bool].
 */
function pp_ti_notify_nrb(int $client_id, string $nrb, bool $correction = false): array {
    $res = ['sms' => false, 'email' => false];
    $cl = db_one("SELECT name, email, phone FROM k30_clients WHERE id=?", [$client_id]);
    if (!$cl) return $res;
    $org = defined('ORG_NAME') ? ORG_NAME : 'FEER';
    $fmt = pp_nrb_format($nrb);
    $phone = trim((string)($cl['phone'] ?? ''));
    if ($phone !== '') {
        if (!function_exists('sms_send')) require_once dirname(__DIR__, 3) . '/includes/sms.php';
        try {
            if (sms_channel_ready()) { sms_send($phone, ($correction ? "{$org}: poprzednio wyslany numer rachunku zostal wygenerowany blednie. Prosimy nie uzywac go. Prawidlowy numer do wplat: "
                                            : "{$org}: Twoj nowy numer rachunku do wplat za zajecia: ") . preg_replace('/\D/', '', $nrb) . ". W tytule przelewu wpisz imie i nazwisko."); $res['sms'] = true; }
        } catch (\Throwable $e) {}
    }
    $email = trim((string)($cl['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        if (!function_exists('mail_queue_add')) require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        try {
            $n = htmlspecialchars((string)$cl['name'], ENT_QUOTES);
            $body = '<p>Dzień dobry' . ($n !== '' ? ', ' . $n : '') . ',</p>'
                  . ($correction ? '<p>Poprzednio przesłany numer rachunku do wpłat został wygenerowany <strong>błędnie</strong> — przepraszamy za pomyłkę i przesyłamy prawidłowy numer. Prosimy nie używać poprzedniego.</p>'
                                 : '<p>nadaliśmy Ci nowy, indywidualny numer rachunku do wpłat za zajęcia:</p>')
                  . '<p style="font-size:1.2em"><strong>' . htmlspecialchars($fmt, ENT_QUOTES) . '</strong></p>'
                  . '<p>Od teraz wpłaty kieruj wyłącznie na ten rachunek. W tytule przelewu wpisz imię i nazwisko kursanta. '
                  . 'Wpłaty na poprzedni numer nie będą już przypisywane automatycznie — w razie wątpliwości skontaktuj się z biurem.</p>'
                  . '<p>' . htmlspecialchars($org, ENT_QUOTES) . '</p>';
            mail_queue_add($email, (string)$cl['name'], ($correction ? "[{$org}] Korekta: prawidłowy numer rachunku do wpłat" : "[{$org}] Nowy numer rachunku do wpłat"), $body, '', 'ti_vnrb', $client_id);
            $res['email'] = true;
        } catch (\Throwable $e) {}
    }
    return $res;
}

/**
 * Ustawia rachunek wirtualny kursanta TI z jego numeru kursanta (idempotentnie).
 * Zwraca nowy NRB, gdy się zmienił; null gdy bez zmian / brak numeru / konflikt.
 */
function pp_ti_sync_nrb(int $client_id, string $by, ?int $uid): ?string {
    // Numery nadaje bank: kursant bez rachunku dostaje kolejny wolny z puli TI; istniejącego nie ruszamy
    $cur = pp_user($client_id);
    if ($cur && pp_nrb_normalize((string)$cur['individual_nrb']) !== '') return null;
    if (pp_ti_is_virtual($client_id)) return null;
    $p = db_one("SELECT nrb FROM pp_vnrb_pool WHERE grp='ti' AND participant_id IS NULL ORDER BY substr(nrb,15,12) LIMIT 1");
    if (!$p) return null;
    if (!$cur && is_string(pp_user_ensure($client_id, $by, $uid))) return null;
    if (pp_set_nrb($client_id, $p['nrb'], $by, $uid) !== null) return null;
    db()->prepare("UPDATE pp_vnrb_pool SET participant_id=?, assigned_at=datetime('now') WHERE nrb=? AND participant_id IS NULL")->execute([$client_id, $p['nrb']]);
    return $p['nrb'];
}

/** Czy uczestnik ma wyłącznie konta TI oznaczone jako „kursant wirtualny” (nie nadajemy mu rachunku). */
function pp_ti_is_virtual(int $client_id): bool {
    $r = db_one("SELECT COUNT(*) n, SUM(COALESCE(is_virtual,0)) v FROM k30_ti_student_accounts WHERE client_id=?", [$client_id]);
    return (int)($r['n'] ?? 0) > 0 && (int)($r['n']) === (int)($r['v'] ?? 0);
}

/**
 * Masowo: nowe rachunki dla kursantów TI, którzy mają już dostęp do portalu.
 * Pomija tych z oczekującym przelewem na stary NRB. $notify → SMS + e-mail.
 * @return array{changed:int, same:int, skipped:list<string>, sms:int, email:int}
 */
function pp_ti_regenerate_all(bool $notify, string $by, ?int $uid, bool $correction = false): array {
    $r = ['changed' => 0, 'same' => 0, 'skipped' => [], 'sms' => 0, 'email' => 0];
    $rows = db_all("SELECT u.participant_id, c.name FROM payment_portal_users u JOIN k30_clients c ON c.id=u.participant_id
                     WHERE EXISTS (SELECT 1 FROM k30_ti_student_accounts a WHERE a.client_id=u.participant_id) ORDER BY c.name");
    foreach ($rows as $row) {
        $pid = (int)$row['participant_id'];
        if (db_one("SELECT 1 FROM portal_transactions WHERE participant_id=? AND status='pending' AND payment_method='individual_nrb'", [$pid])) {
            $r['skipped'][] = $row['name'] . ' (oczekujący przelew na stary numer)'; continue;
        }
        if (pp_ti_student_no($pid) === null) { $r['skipped'][] = $row['name'] . ' (brak 12-cyfrowego nr kursanta)'; continue; }
        $n = pp_ti_sync_nrb($pid, $by, $uid);
        if ($n === null) { $r['same']++; continue; }
        $r['changed']++;
        if ($notify) { $x = pp_ti_notify_nrb($pid, $n, $correction); $r['sms'] += (int)$x['sms']; $r['email'] += (int)$x['email']; }
    }
    audit_log('payments.vnrb_regenerate_ti', $r + ['notify' => $notify, 'by' => $by], $uid);
    return $r;
}
/** Nowy link dostępu (poprzedni przestaje działać). Zwraca URL z tokenem — pokazywany raz. */
function pp_issue_link(int $participant_id, string $by, ?int $uid): string {
    pp_migrate();
    $tok = bin2hex(random_bytes(24));
    db()->prepare("UPDATE payment_portal_users SET access_hash=?, token_created_at=datetime('now'), is_active=1 WHERE participant_id=?")
        ->execute([hash('sha256', $tok), $participant_id]);
    audit_log('payments.token_issued', ['participant_id' => $participant_id, 'by' => $by], $uid);
    return rtrim(APP_URL, '/') . '/platnosci/?t=' . $tok;
}
function pp_session_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('szo_platnosci');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/platnosci', 'httponly' => true, 'samesite' => 'Lax',
                                   'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
        session_start();
    }
}
/** Logowanie tokenem z linku → sesja portalu (osobna od SZO). */
function pp_login_token(string $tok): ?array {
    pp_migrate();
    if (!preg_match('/^[a-f0-9]{48}$/', $tok)) return null;
    $u = db_one("SELECT * FROM payment_portal_users WHERE access_hash=? AND is_active=1", [hash('sha256', $tok)]);
    if (!$u) return null;
    pp_session_start();
    session_regenerate_id(true);
    $_SESSION['pp_participant'] = ['participant_id' => (int)$u['participant_id'], 'ts' => time()];
    $_SESSION['pp_csrf'] = bin2hex(random_bytes(16));
    db()->prepare("UPDATE payment_portal_users SET last_login_at=datetime('now') WHERE id=?")->execute([(int)$u['id']]);
    audit_log('payments.portal_login', ['participant_id' => (int)$u['participant_id']], null);
    return $u;
}
function pp_current(): ?int {
    pp_session_start();
    $s = $_SESSION['pp_participant'] ?? null;
    if (!$s || time() - (int)$s['ts'] > 7200) { unset($_SESSION['pp_participant']); return null; }
    $_SESSION['pp_participant']['ts'] = time();
    $u = db_one("SELECT is_active FROM payment_portal_users WHERE participant_id=?", [(int)$s['participant_id']]);
    return $u && (int)$u['is_active'] ? (int)$s['participant_id'] : null;
}
function pp_csrf(): string { pp_session_start(); return $_SESSION['pp_csrf'] ??= bin2hex(random_bytes(16)); }
function pp_csrf_check(): void {
    if (!hash_equals((string)($_SESSION['pp_csrf'] ?? ''), (string)($_POST['_csrf'] ?? ''))) { http_response_code(403); exit('Sesja wygasła — odśwież stronę.'); }
}

// ── Pozycje do zapłaty ──────────────────────────────────────────────────────
function pp_add_item(int $participant_id, string $title, float $amount, string $type, ?int $ref_id, ?string $due, string $by, ?int $uid): int|string {
    pp_migrate();
    $title = mb_substr(trim($title), 0, 200);
    if ($title === '') return 'Podaj nazwę pozycji.';
    if (!array_key_exists($type, PP_REF_TYPES)) return 'Nieznany typ pozycji.';
    if ($amount <= 0 || $amount > 1000000) return 'Kwota musi być większa od zera.';
    if ($due !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) return 'Nieprawidłowy termin płatności.';
    if (!db_one("SELECT 1 FROM k30_clients WHERE id=?", [$participant_id])) return 'Nie ma takiego uczestnika.';
    $pdo = db(); $pdo->beginTransaction();
    try {
        $id = db_insert('payable_items', ['participant_id' => $participant_id, 'title' => $title, 'reference_type' => $type,
            'reference_id' => $ref_id ?: null, 'amount' => round($amount, 2), 'status' => 'pending', 'due_date' => $due, 'created_by' => $by]);
        audit_log('payments.item_created', ['item_id' => $id, 'participant_id' => $participant_id, 'type' => $type, 'reference_id' => $ref_id, 'amount' => round($amount, 2), 'by' => $by], $uid);
        $pdo->commit();
        return $id;
    } catch (\Throwable $e) { $pdo->rollBack(); return str_contains($e->getMessage(), 'UNIQUE') ? 'Ta pozycja jest już otwarta w portalu.' : 'Błąd: ' . $e->getMessage(); }
}
function pp_cancel_item(int $item_id, string $by, ?int $uid): ?string {
    pp_migrate();
    $pdo = db(); $pdo->beginTransaction();
    try {
        $it = db_one("SELECT * FROM payable_items WHERE id=?", [$item_id]);
        if (!$it || $it['status'] !== 'pending') { $pdo->rollBack(); return 'Anulować można tylko pozycję „do zapłaty” (nie w trakcie opłacania).'; }
        db()->prepare("UPDATE payable_items SET status='cancelled' WHERE id=?")->execute([$item_id]);
        audit_log('payments.item_status', ['item_id' => $item_id, 'from' => 'pending', 'to' => 'cancelled', 'by' => $by], $uid);
        $pdo->commit();
        return null;
    } catch (\Throwable $e) { $pdo->rollBack(); return 'Błąd: ' . $e->getMessage(); }
}

/** Bieżąca niedopłata rozliczenia TI (z alokacji wpłat), w groszach. */
function pp_ti_billing_debt_gr(int $billing_id): int {
    $b = db_one("SELECT * FROM k30_ti_billing WHERE id=?", [$billing_id]);
    if (!$b || !in_array($b['status'], ['issued', 'paid'], true)) return 0;
    $paid = 0.0;
    foreach (ti_client_allocation((int)$b['client_id'])['rows'] as $r) if ($r['id'] === (int)$b['id']) $paid = $r['paid'];
    return max(0, pp_gr((float)$b['amount'] + (float)($b['adjustment'] ?? 0)) - pp_gr($paid));
}
/** Import nieopłaconych rozliczeń TI jako pozycji portalu (jedna otwarta pozycja na rozliczenie). */
function pp_import_ti(?int $participant_id, string $by, ?int $uid): int {
    pp_migrate();
    require_once dirname(__DIR__, 3) . '/includes/ti_virtual.php';
    $pl = [1=>'styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'];
    $rows = db_all("SELECT b.*, c.name AS course_name FROM k30_ti_billing b LEFT JOIN k30_ti_courses c ON c.id=b.course_id AND b.course_id>0
                     WHERE b.status='issued'" . ($participant_id ? " AND b.client_id=?" : '') . " ORDER BY b.year, b.month",
                   $participant_id ? [$participant_id] : []);
    $n = 0;
    foreach ($rows as $b) {
        if (ti_client_no_billing((int)$b['client_id'])) continue;   // „bez rozliczeń”
        if (db_one("SELECT 1 FROM payable_items WHERE reference_type='ti_billing' AND reference_id=? AND status IN ('pending','processing')", [(int)$b['id']])) continue;
        $debt = pp_ti_billing_debt_gr((int)$b['id']);
        if ($debt <= 0) continue;
        $title = 'Zajęcia TI — ' . ((int)$b['course_id'] > 0 ? (string)$b['course_name'] : 'rozliczenie łączne') . ', ' . ($pl[(int)$b['month']] ?? $b['month']) . ' ' . $b['year'];
        if (is_int(pp_add_item((int)$b['client_id'], $title, pp_zl($debt), 'ti_billing', (int)$b['id'], $b['due_date'] ?: null, $by, $uid))) $n++;
    }
    return $n;
}
/**
 * Uzgadnia otwarte pozycje TI z księgą (ktoś zapłacił inaczej / zmieniła się kwota):
 * brak niedopłaty → pozycja opłacona poza portalem; inna kwota → nowa kwota.
 */
function pp_sync_ti_items(int $participant_id): void {
    foreach (db_all("SELECT * FROM payable_items WHERE participant_id=? AND reference_type='ti_billing' AND status='pending'", [$participant_id]) as $it) {
        $debt = pp_ti_billing_debt_gr((int)$it['reference_id']);
        if ($debt <= 0) {
            db()->prepare("UPDATE payable_items SET status='paid', paid_at=datetime('now') WHERE id=? AND status='pending'")->execute([(int)$it['id']]);
            audit_log('payments.item_status', ['item_id' => (int)$it['id'], 'from' => 'pending', 'to' => 'paid', 'reason' => 'rozliczenie TI opłacone poza portalem'], null);
        } elseif ($debt !== pp_gr($it['amount'])) {
            db()->prepare("UPDATE payable_items SET amount=? WHERE id=?")->execute([pp_zl($debt), (int)$it['id']]);
            audit_log('payments.item_amount_synced', ['item_id' => (int)$it['id'], 'from' => (float)$it['amount'], 'to' => pp_zl($debt)], null);
        }
    }
}

// ── Transakcje ──────────────────────────────────────────────────────────────
function pp_uuid(): string {
    $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
/**
 * Tytuł przelewu transakcji: „SZO {kod} ZOB/{id,id…} {nazwa zobowiązania…} — {uczestnik}” (do 140 znaków).
 * Kod SZO… zostaje na początku (księgowość dopasowuje po nim wpływ), a kody ZOB/{id} wskazują zobowiązania,
 * które ta wpłata pokrywa — każde zobowiązanie ma też własny tytuł (pp_item_transfer_title).
 */
function pp_transfer_title(string $uuid, array $cl, array $items = []): string {
    $head = 'SZO ' . strtoupper(substr(str_replace('-', '', $uuid), 0, 10));
    $name = mb_substr(preg_replace('/\s+/', ' ', (string)($cl['name'] ?? '')), 0, 40);
    if (!$items) return $head . ' ' . $name;
    $codes = 'ZOB/' . implode(',', array_map(fn($i) => (int)$i['id'], $items));
    $tail  = $name !== '' ? ' — ' . $name : '';
    $room  = 140 - mb_strlen($head . ' ' . $codes . ' ') - mb_strlen($tail);
    $titles = preg_replace('/\s+/', ' ', trim(implode('; ', array_map(fn($i) => (string)$i['title'], $items))));
    if ($room < 10) $titles = '';
    elseif (mb_strlen($titles) > $room) $titles = rtrim(mb_substr($titles, 0, $room - 1)) . '…';
    return trim($head . ' ' . $codes . ' ' . $titles) . $tail;
}

/**
 * Tworzy transakcję z koszyka: pozycje muszą należeć do uczestnika i być „do zapłaty”;
 * kwota wyłącznie z bazy. Zwraca transakcję albo komunikat błędu.
 */
function pp_create_transaction(int $participant_id, array $item_ids, string $method): array|string {
    pp_migrate();
    if (!in_array($method, ['individual_nrb', 'p24'], true)) return 'Wybierz metodę płatności.';
    $ids = array_values(array_unique(array_filter(array_map('intval', $item_ids))));
    if (!$ids) return 'Zaznacz co najmniej jedną pozycję.';
    $u = pp_user($participant_id);
    if (!$u) return 'Brak dostępu do portalu.';
    $acct = pp_payment_account($u);
    if ($method === 'individual_nrb' && !$acct) return 'Przelew tradycyjny jest chwilowo niedostępny (brak numeru rachunku) — skontaktuj się z biurem.';
    if ($method === 'p24' && !p24_enabled() && org_setting('pp_p24_simulation') !== '1') return 'Płatność Przelewy24 jest chwilowo niedostępna.';
    pp_sync_ti_items($participant_id);
    $pdo = db(); $pdo->beginTransaction();
    try {
        $ph    = implode(',', array_fill(0, count($ids), '?'));
        $items = db_all("SELECT * FROM payable_items WHERE id IN ($ph) AND participant_id=? AND status='pending'", [...$ids, $participant_id]);
        if (count($items) !== count($ids)) { $pdo->rollBack(); return 'Część pozycji jest już opłacana albo niedostępna — odśwież stronę.'; }
        $total = 0; foreach ($items as $it) $total += pp_gr($it['amount']);
        if ($total <= 0) { $pdo->rollBack(); return 'Kwota do zapłaty musi być większa od zera.'; }
        $uuid = pp_uuid();
        $tid  = db_insert('portal_transactions', ['participant_id' => $participant_id, 'transaction_uuid' => $uuid, 'total_amount' => pp_zl($total),
            'payment_method' => $method, 'status' => 'pending', 'transfer_title' => pp_transfer_title($uuid, ['name' => $u['participant_name']], $items),
            'target_nrb' => $method === 'individual_nrb' ? $acct['nrb'] : '']);
        $st = db()->prepare("UPDATE payable_items SET status='processing' WHERE id=? AND status='pending'");
        foreach ($items as $it) {
            $st->execute([(int)$it['id']]);
            if ($st->rowCount() !== 1) throw new \RuntimeException('Pozycja zmieniła stan w trakcie — spróbuj ponownie.');
            db_insert('portal_transaction_items', ['portal_transaction_id' => $tid, 'payable_item_id' => (int)$it['id'], 'amount' => (float)$it['amount']]);
            audit_log('payments.item_status', ['item_id' => (int)$it['id'], 'from' => 'pending', 'to' => 'processing', 'transaction_id' => $tid], null);
        }
        audit_log('payments.attempt', ['transaction_id' => $tid, 'uuid' => $uuid, 'participant_id' => $participant_id, 'method' => $method,
            'total' => pp_zl($total), 'items' => array_map(fn($i) => (int)$i['id'], $items)], null);
        if ($method === 'individual_nrb') audit_log('payments.nrb_declared', ['transaction_id' => $tid, 'total' => pp_zl($total)], null);
        $pdo->commit();
        return db_one("SELECT * FROM portal_transactions WHERE id=?", [$tid]);
    } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return $e->getMessage(); }
}

/** P24: rejestracja w bramce po utworzeniu transakcji. Zwraca URL przekierowania albo błąd (transakcja wtedy failed). */
function pp_start_p24(array $tx, string $email): string|array {
    $base = rtrim(APP_URL, '/');
    if (!p24_enabled() && org_setting('pp_p24_simulation') === '1') {
        audit_log('payments.p24_request', ['transaction_id' => (int)$tx['id'], 'mode' => 'simulation'], null);
        return $base . '/platnosci/?view=sim&tx=' . urlencode($tx['transaction_uuid']);
    }
    try {
        $o = p24_create_order('payment_portal', (int)$tx['id'], (float)$tx['total_amount'], $tx['transfer_title'],
                              $base . '/platnosci/?view=status&tx=' . urlencode($tx['transaction_uuid']), $base . '/api/p24_webhook.php', $email);
        db()->prepare("UPDATE portal_transactions SET p24_payment_id=?, gateway_response=? WHERE id=?")
            ->execute([(int)$o['id'], json_encode(['p24_payment_id' => $o['id'], 'token' => $o['token']]), (int)$tx['id']]);
        audit_log('payments.p24_request', ['transaction_id' => (int)$tx['id'], 'p24_payment_id' => (int)$o['id']], null);
        return $o['url'];
    } catch (\Throwable $e) {
        pp_fail((int)$tx['id'], 'Przelewy24: ' . $e->getMessage(), 'system', null);
        return ['error' => 'Nie udało się połączyć z Przelewy24. Spróbuj ponownie albo wybierz przelew na indywidualny rachunek.'];
    }
}

/** Rozliczenie transakcji: success + pozycje paid + skutki w źródłach — atomowo, idempotentnie. */
function pp_settle(int $tx_id, array $response, string $by, ?int $uid): ?string {
    pp_migrate();
    $pdo = db(); $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
    try {
        $tx = db_one("SELECT * FROM portal_transactions WHERE id=?", [$tx_id]);
        if (!$tx) throw new \RuntimeException('Nie znaleziono transakcji.');
        if ($tx['status'] === 'success') { if ($own) $pdo->commit(); return null; }
        if ($tx['status'] !== 'pending') throw new \RuntimeException('Transakcja jest już zamknięta (' . $tx['status'] . ').');
        $prev = json_decode((string)$tx['gateway_response'], true) ?: [];
        db()->prepare("UPDATE portal_transactions SET status='success', completed_at=datetime('now'), gateway_response=? WHERE id=? AND status='pending'")
            ->execute([json_encode($prev + ['settlement' => $response], JSON_UNESCAPED_UNICODE), $tx_id]);
        $method = $tx['payment_method'] === 'p24' ? 'p24' : 'transfer';
        foreach (db_all("SELECT pi.*, ti.amount AS tx_amount FROM portal_transaction_items ti JOIN payable_items pi ON pi.id=ti.payable_item_id
                          WHERE ti.portal_transaction_id=?", [$tx_id]) as $it) {
            db()->prepare("UPDATE payable_items SET status='paid', paid_at=datetime('now') WHERE id=?")->execute([(int)$it['id']]);
            audit_log('payments.item_status', ['item_id' => (int)$it['id'], 'from' => $it['status'], 'to' => 'paid', 'transaction_id' => $tx_id], $uid);
            if ($it['reference_type'] === 'ti_billing' && (int)$it['reference_id'] > 0) {
                $b = db_one("SELECT client_id, COALESCE(course_id,0) AS course_id FROM k30_ti_billing WHERE id=?", [(int)$it['reference_id']]);
                if ($b) ti_payment_add((int)$b['client_id'], (float)$it['tx_amount'], date('Y-m-d'), $method,
                                       'Portal płatności ' . substr((string)$tx['transaction_uuid'], 0, 8), 'payment_portal', $tx_id, (int)$b['course_id']);
            } elseif ($it['reference_type'] === 'invoice' && (int)$it['reference_id'] > 0) {
                try { db()->prepare("UPDATE invoices SET paid_at=datetime('now') WHERE id=? AND paid_at IS NULL")->execute([(int)$it['reference_id']]); } catch (\Throwable $e) {}
            }
        }
        audit_log('payments.success', ['transaction_id' => $tx_id, 'uuid' => $tx['transaction_uuid'], 'method' => $tx['payment_method'],
            'total' => (float)$tx['total_amount'], 'response' => $response, 'by' => $by], $uid);
        if ($own) $pdo->commit();
        return null;
    } catch (\Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); return $e->getMessage(); }
}

/** Nieudana / anulowana transakcja — pozycje wracają do „do zapłaty”. */
function pp_fail(int $tx_id, string $reason, string $by, ?int $uid): ?string {
    pp_migrate();
    $pdo = db(); $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
    try {
        $tx = db_one("SELECT * FROM portal_transactions WHERE id=? AND status='pending'", [$tx_id]);
        if (!$tx) throw new \RuntimeException('Transakcja nie oczekuje (już rozliczona albo anulowana).');
        $prev = json_decode((string)$tx['gateway_response'], true) ?: [];
        db()->prepare("UPDATE portal_transactions SET status='failed', completed_at=datetime('now'), gateway_response=? WHERE id=?")
            ->execute([json_encode($prev + ['failure' => $reason, 'by' => $by], JSON_UNESCAPED_UNICODE), $tx_id]);
        foreach (db_all("SELECT payable_item_id FROM portal_transaction_items WHERE portal_transaction_id=?", [$tx_id]) as $r) {
            db()->prepare("UPDATE payable_items SET status='pending' WHERE id=? AND status='processing'")->execute([(int)$r['payable_item_id']]);
            audit_log('payments.item_status', ['item_id' => (int)$r['payable_item_id'], 'from' => 'processing', 'to' => 'pending', 'transaction_id' => $tx_id], $uid);
        }
        audit_log('payments.failed', ['transaction_id' => $tx_id, 'reason' => $reason, 'by' => $by], $uid);
        if ($own) $pdo->commit();
        return null;
    } catch (\Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); return $e->getMessage(); }
}

/** Wywoływane z includes/p24.php (p24_mark_paid) po transaction/verify. */
function pp_settle_from_p24(int $tx_id, array $p24row): void {
    $tx = db_one("SELECT * FROM portal_transactions WHERE id=?", [$tx_id]);
    if (!$tx || $tx['status'] !== 'pending') return;
    if (pp_gr($p24row['amount_grosze'] / 100) !== pp_gr($tx['total_amount'])) {   // kwota z bramki ≠ koszyk — nie księgujemy
        audit_log('payments.amount_mismatch', ['transaction_id' => $tx_id, 'p24' => (int)$p24row['amount_grosze'], 'expected' => pp_gr($tx['total_amount'])], null);
        return;
    }
    pp_settle($tx_id, ['gateway' => 'p24', 'p24_payment_id' => (int)$p24row['id'], 'order_id' => (string)$p24row['order_id']], 'Przelewy24', null);
}

/** Stan transakcji uczestnika (dla ekranu statusu); dla P24 aktywne sprawdzenie w bramce. */
function pp_transaction_view(string $uuid, int $participant_id): ?array {
    pp_migrate();
    $tx = db_one("SELECT * FROM portal_transactions WHERE transaction_uuid=? AND participant_id=?", [$uuid, $participant_id]);
    if (!$tx) return null;
    if ($tx['status'] === 'pending' && $tx['payment_method'] === 'p24' && (int)$tx['p24_payment_id'] > 0) {
        p24_reconcile_payment((int)$tx['p24_payment_id']);   // p24_mark_paid → pp_settle_from_p24
        $tx = db_one("SELECT * FROM portal_transactions WHERE id=?", [(int)$tx['id']]);
    }
    $tx['items'] = db_all("SELECT pi.title, pi.reference_type, ti.amount, pi.status FROM portal_transaction_items ti JOIN payable_items pi ON pi.id=ti.payable_item_id
                            WHERE ti.portal_transaction_id=? ORDER BY pi.id", [(int)$tx['id']]);
    return $tx;
}

// ── Dopasowanie wpływów z wyciągów EODoK (edok_bank_tx, MT940) ───────────────
/** Kod płatności z tytułu: „SZO” + 10 znaków z identyfikatora transakcji. */
function pp_title_code(string $uuid): string { return 'SZO' . strtoupper(substr(str_replace('-', '', $uuid), 0, 10)); }
function _pp_norm(string $s): string { return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($s)); }

/**
 * Propozycje dopasowania: dla każdego nierozliczonego wpływu (znak C) oczekujące
 * płatności przelewem, z oceną. Sygnały: kod płatności w tytule (najmocniejszy,
 * działa też na rachunku ogólnym), wpływ na indywidualny NRB uczestnika (rachunek
 * wyciągu albo numer w tytule/referencji), zgodność kwoty.
 * @return list<array{bank:array, tx:array, score:int, amount_ok:bool, reasons:list<string>}>
 */
function pp_bank_candidates(): array {
    pp_migrate();
    try { $bank = db_all("SELECT b.* FROM edok_bank_tx b LEFT JOIN pp_bank_matches m ON m.bank_tx_id=b.id
                           WHERE b.znak='C' AND b.ignored=0 AND b.doc_id IS NULL AND m.id IS NULL ORDER BY b.data_waluty DESC LIMIT 1000"); }
    catch (\Throwable $e) { return []; }   // EODoK bez tabeli wyciągów
    $pend = db_all("SELECT t.*, c.name FROM portal_transactions t JOIN k30_clients c ON c.id=t.participant_id
                     WHERE t.status='pending' AND t.payment_method='individual_nrb'");
    if (!$bank || !$pend) return [];
    $ind = [];   // NRB indywidualne uczestników → participant_id
    foreach (db_all("SELECT participant_id, individual_nrb FROM payment_portal_users WHERE individual_nrb IS NOT NULL AND individual_nrb!=''") as $u)
        $ind[pp_nrb_normalize((string)$u['individual_nrb'])] = (int)$u['participant_id'];
    $out = [];
    foreach ($bank as $b) {
        $title = _pp_norm($b['tytul'] . ' ' . $b['referencja']);
        $digits = preg_replace('/\D/', '', $b['tytul'] . ' ' . $b['referencja']);
        $acc = pp_nrb_normalize((string)$b['account_nrb']);
        $to_pid = $ind[$acc] ?? null;
        if ($to_pid === null) foreach ($ind as $nrb => $p) if (strlen($digits) >= 26 && str_contains($digits, $nrb)) { $to_pid = $p; break; }
        foreach ($pend as $t) {
            $score = 0; $why = [];
            if (str_contains($title, pp_title_code((string)$t['transaction_uuid']))) { $score += 200; $why[] = 'kod płatności w tytule'; }
            if ($to_pid !== null && $to_pid === (int)$t['participant_id']) { $score += 120; $why[] = 'wpływ na indywidualny rachunek uczestnika'; }
            if (!$score) continue;
            $ok = pp_gr($b['kwota']) === pp_gr($t['total_amount']);
            $why[] = $ok ? 'kwota zgodna' : 'kwota ' . pp_fmt((float)$b['kwota']) . ' ≠ ' . pp_fmt((float)$t['total_amount']);
            $out[] = ['bank' => $b, 'tx' => $t, 'score' => $score + ($ok ? 50 : 0), 'amount_ok' => $ok, 'reasons' => $why];
        }
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return $out;
}

/** Rozlicza płatność wpływem z wyciągu (automatycznie albo ręcznie przez biuro). */
function pp_bank_assign(int $bank_tx_id, int $tx_id, string $how, string $by, ?int $uid): ?string {
    pp_migrate();
    $b = db_one("SELECT * FROM edok_bank_tx WHERE id=? AND znak='C'", [$bank_tx_id]);
    if (!$b) return 'Nie znaleziono wpływu z wyciągu.';
    if (db_one("SELECT 1 FROM pp_bank_matches WHERE bank_tx_id=?", [$bank_tx_id])) return 'Ten wpływ jest już rozliczony w portalu.';
    $pdo = db(); $pdo->beginTransaction();
    try {
        db_insert('pp_bank_matches', ['bank_tx_id' => $bank_tx_id, 'portal_transaction_id' => $tx_id, 'how' => $how, 'by_name' => $by]);
        $err = pp_settle($tx_id, ['gateway' => 'nrb', 'how' => $how, 'bank_tx_id' => $bank_tx_id, 'bank_date' => $b['data_waluty'],
            'bank_amount' => (float)$b['kwota'], 'payer' => $b['kontrahent_nazwa'], 'bank_title' => $b['tytul']], $by, $uid);
        if ($err) throw new \RuntimeException($err);
        // Ślad w EODoK: wpływ obsłużony w portalu płatności (bez dokumentu EODoK)
        db()->prepare("UPDATE edok_bank_tx SET matched_how=?, matched_by_name=?, matched_at=datetime('now') WHERE id=? AND doc_id IS NULL AND matched_how=''")
            ->execute(['platnosci:' . $tx_id, $by, $bank_tx_id]);
        audit_log('payments.bank_matched', ['bank_tx_id' => $bank_tx_id, 'transaction_id' => $tx_id, 'how' => $how, 'amount' => (float)$b['kwota'], 'by' => $by], $uid);
        $pdo->commit();
        return null;
    } catch (\Throwable $e) { $pdo->rollBack(); return $e->getMessage(); }
}

/**
 * Automatyczne dopasowanie: tylko jednoznaczne pary ze zgodną kwotą — kod płatności
 * w tytule, albo wpływ na indywidualny NRB, gdy uczestnik ma dokładnie jedną
 * oczekującą płatność z tą kwotą. Rozbieżności kwot zostają do decyzji biura.
 * @return array{matched:int, review:int}
 */
function pp_bank_auto_match(string $by = 'automat', ?int $uid = null): array {
    $c = pp_bank_candidates();
    $by_bank = []; $by_tx = [];
    foreach ($c as $x) { $by_bank[(int)$x['bank']['id']][] = $x; $by_tx[(int)$x['tx']['id']][] = $x; }
    $n = 0; $used_tx = [];
    foreach ($by_bank as $bid => $list) {
        $good = array_values(array_filter($list, fn($x) => $x['amount_ok']));
        if (count($good) !== 1) continue;                         // brak albo niejednoznaczne
        $x = $good[0]; $tid = (int)$x['tx']['id'];
        if (isset($used_tx[$tid])) continue;
        $rivals = array_filter($by_tx[$tid], fn($y) => $y['amount_ok'] && (int)$y['bank']['id'] !== $bid);
        if ($rivals && $x['score'] < 250) continue;               // bez kodu w tytule i kilka pasujących wpływów
        if (pp_bank_assign($bid, $tid, 'auto: ' . implode(', ', $x['reasons']), $by, $uid) === null) { $n++; $used_tx[$tid] = true; }
    }
    return ['matched' => $n, 'review' => count(pp_bank_candidates())];
}

/** Porzucone płatności P24 (> 24 h bez potwierdzenia) wracają do koszyka. */
function pp_expire_stale(int $participant_id): void {
    foreach (db_all("SELECT id, p24_payment_id FROM portal_transactions WHERE participant_id=? AND status='pending' AND payment_method='p24'
                      AND created_at < datetime('now','-24 hours')", [$participant_id]) as $t) {
        if ((int)$t['p24_payment_id'] > 0 && p24_reconcile_payment((int)$t['p24_payment_id']) === 'paid') continue;
        pp_fail((int)$t['id'], 'Brak potwierdzenia płatności Przelewy24 w ciągu 24 h', 'system', null);
    }
}


/**
 * Import listy rachunków wirtualnych z banku (TXT, jeden numer w linii; PL/spacje dozwolone).
 * Odrzuca niepoprawne (26 cyfr + suma kontrolna) i duplikaty. @return array{added:int, dup:int, bad:list<string>, batch:string}
 */
function pp_vnrb_pool_import(string $text, string $grp, string $by, ?int $uid): array {
    pp_migrate();
    $r = ['added' => 0, 'dup' => 0, 'bad' => [], 'batch' => date('YmdHis') . '-' . bin2hex(random_bytes(2))];
    $ins = db()->prepare("INSERT OR IGNORE INTO pp_vnrb_pool (nrb, grp, participant_id, imported_by, assigned_at, batch) VALUES (?,?,?,?,?,?)");
    db()->beginTransaction();
    try {
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line); if ($line === '') continue;
            $n = pp_nrb_normalize($line);
            if (!pp_nrb_valid($n)) { $r['bad'][] = mb_substr($line, 0, 40); continue; }
            $g = $grp === 'auto' ? pp_series_detect($n) : $grp;
            if ($g === null) { $r['bad'][] = mb_substr($line, 0, 40) . ' (nieznana seria)'; continue; }
            $owner = db_one("SELECT participant_id FROM payment_portal_users WHERE individual_nrb=?", [$n]);
            $ins->execute([$n, $g, $owner ? (int)$owner['participant_id'] : null, $by, $owner ? date('Y-m-d H:i:s') : null, $r['batch']]);
            if ($ins->rowCount()) $r['added']++; else $r['dup']++;
        }
        db()->commit();
    } catch (\Throwable $e) { db()->rollBack(); throw $e; }
    if ($r['added']) org_setting_set('pp_pool_last_batch', $r['batch']);
    audit_log('payments.vnrb_pool_import', ['group' => $grp, 'added' => $r['added'], 'dup' => $r['dup'], 'bad' => count($r['bad']), 'by' => $by], $uid);
    return $r;
}

/** Przypisuje wolne rachunki z puli (grp='ti') kursantom TI bez indywidualnego NRB, wg kolejności numerów. @return array{assigned:int, left:int, nopool:int} */
function pp_vnrb_pool_assign_ti(string $by, ?int $uid, ?string $batch = null): array {
    pp_migrate();
    $r = ['assigned' => 0, 'left' => 0, 'nopool' => 0];
    $rows = db_all("SELECT DISTINCT a.client_id FROM k30_ti_student_accounts a
                      LEFT JOIN payment_portal_users u ON u.participant_id=a.client_id
                     WHERE (u.individual_nrb IS NULL OR u.individual_nrb='') AND COALESCE(a.is_virtual,0)=0 ORDER BY a.client_id");
    foreach ($rows as $row) {
        $cid = (int)$row['client_id'];
        $p = db_one("SELECT nrb FROM pp_vnrb_pool WHERE grp='ti' AND participant_id IS NULL" . ($batch !== null ? " AND batch=?" : "") . " ORDER BY substr(nrb,15,12) LIMIT 1", $batch !== null ? [$batch] : []);
        if (!$p) { $r['nopool']++; continue; }
        if (is_string(pp_user_ensure($cid, $by, $uid))) continue;
        if (pp_set_nrb($cid, $p['nrb'], $by, $uid) !== null) continue;
        db()->prepare("UPDATE pp_vnrb_pool SET participant_id=?, assigned_at=datetime('now') WHERE nrb=?")->execute([$cid, $p['nrb']]);
        $r['assigned']++;
    }
    $r['left'] = (int)(db_one("SELECT COUNT(*) c FROM pp_vnrb_pool WHERE grp='ti' AND participant_id IS NULL")['c'] ?? 0);
    audit_log('payments.vnrb_pool_assign_ti', $r + ['batch' => $batch, 'by' => $by], $uid);
    return $r;
}

/**
 * Raport rachunków wirtualnych kursantów TI. $scope: 'all' = wszyscy kursanci z kontem TI (także bez rachunku),
 * 'last' = tylko numery z ostatniego importu od banku.
 * @return list<array{client_id:int,name:string,nrb:string,grp:string,assigned_at:string}>
 */
function pp_vnrb_report_rows(string $scope = 'all'): array {
    pp_migrate();
    if ($scope === 'last') {
        $b = (string)org_setting('pp_pool_last_batch');
        if ($b === '') return [];
        $rows = db_all("SELECT p.participant_id client_id, c.name, p.nrb, p.grp, p.assigned_at FROM pp_vnrb_pool p
                          LEFT JOIN k30_clients c ON c.id=p.participant_id WHERE p.batch=? ORDER BY substr(p.nrb,15,12)", [$b]);
    } else {
        $rows = db_all("SELECT c.id client_id, c.name, COALESCE(u.individual_nrb,'') nrb, COALESCE(p.grp,'') grp, COALESCE(p.assigned_at,'') assigned_at
                          FROM k30_clients c
                          LEFT JOIN payment_portal_users u ON u.participant_id=c.id
                          LEFT JOIN pp_vnrb_pool p ON p.nrb=u.individual_nrb
                         WHERE EXISTS (SELECT 1 FROM k30_ti_student_accounts a WHERE a.client_id=c.id
                                        AND (COALESCE(a.is_virtual,0)=0 OR COALESCE(u.individual_nrb,'')!=''))
                         ORDER BY c.name COLLATE NOCASE");
    }
    return array_map(fn($r) => ['client_id' => (int)$r['client_id'], 'name' => (string)($r['name'] ?? ''), 'nrb' => (string)$r['nrb'],
                                'grp' => (string)$r['grp'], 'assigned_at' => (string)$r['assigned_at']], $rows);
}

/** Samodzielny dokument HTML do wydruku raportu (oba ekrany: płatności i kierownik TI). Kończy skrypt. */
function pp_vnrb_report_print(string $scope, string $by): never {
    $rows = pp_vnrb_report_rows($scope);
    $series = array_column(pp_series(), 'label');
    $title = 'Raport rachunków wirtualnych kursantów TI' . ($scope === 'last' ? ' — ostatni import' : '');
    $with = count(array_filter($rows, fn($r) => $r['nrb'] !== ''));
    header('Content-Type: text/html; charset=utf-8');
    audit_log('payments.vnrb_report_print', ['scope' => $scope, 'rows' => count($rows), 'by' => $by], null);
    ?><!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<style>
  body{font:12px/1.4 Arial,sans-serif;color:#000;margin:16px}
  h1{font-size:16px;margin:0 0 2px} .meta{color:#444;margin-bottom:10px}
  table{border-collapse:collapse;width:100%} th,td{border:1px solid #999;padding:3px 6px;text-align:left} thead th{background:#eee}
  td.n{font-family:monospace;white-space:nowrap} tr{break-inside:avoid} .no{color:#666}
  .pbtn{margin-bottom:10px;padding:6px 12px;font-size:13px} @media print{.pbtn{display:none}}
</style></head><body>
<button class="pbtn" onclick="window.print()">Drukuj</button>
<h1><?= h($title) ?></h1>
<div class="meta"><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?> · wydruk: <?= date('d.m.Y H:i') ?> · wystawił: <?= h($by) ?> · pozycji: <?= count($rows) ?>, z rachunkiem: <?= $with ?></div>
<table><thead><tr><th>Lp.</th><th>Kursant</th><th>ID</th><th>Rachunek wirtualny</th><th>Seria</th><th>Przypisano</th></tr></thead><tbody>
<?php foreach ($rows as $i => $r): ?>
<tr><td><?= $i + 1 ?></td><td><?= h($r['name'] !== '' ? $r['name'] : '—') ?></td><td><?= $r['client_id'] ?: '' ?></td>
<td class="n"><?= $r['nrb'] !== '' ? h(pp_nrb_format($r['nrb'])) : '<span class="no">brak</span>' ?></td>
<td><?= h($r['grp']) ?></td><td><?= h($r['assigned_at']) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6">Brak danych.</td></tr><?php endif; ?>
</tbody></table></body></html><?php
    exit;
}


/** Powiadomienie (SMS + e-mail) o numerze rachunku — zlecane ręcznie przez admina. Wirtualni kursanci są pomijani centralnie. @return array{students:int, sms:int, email:int, left:int} */
function pp_vnrb_pool_notify(?string $batch, string $by, ?int $uid): array {
    pp_migrate();
    $r = ['students' => 0, 'sms' => 0, 'email' => 0, 'left' => 0];
    $rows = db_all("SELECT nrb, participant_id FROM pp_vnrb_pool WHERE grp='ti' AND participant_id IS NOT NULL AND notified_at IS NULL"
                   . ($batch !== null ? " AND batch=?" : "") . " ORDER BY substr(nrb,15,12)", $batch !== null ? [$batch] : []);
    foreach ($rows as $row) {
        $x = pp_ti_notify_nrb((int)$row['participant_id'], (string)$row['nrb'], false);
        db()->prepare("UPDATE pp_vnrb_pool SET notified_at=datetime('now') WHERE nrb=?")->execute([$row['nrb']]);
        $r['students']++; $r['sms'] += (int)$x['sms']; $r['email'] += (int)$x['email'];
    }
    $r['left'] = (int)(db_one("SELECT COUNT(*) c FROM pp_vnrb_pool WHERE grp='ti' AND participant_id IS NOT NULL AND notified_at IS NULL")['c'] ?? 0);
    audit_log('payments.vnrb_pool_notify', $r + ['batch' => $batch, 'by' => $by], $uid);
    return $r;
}

/** Przypisuje wolne numery serii „inni” kontrahentom CRM bez numeru z puli. @return array{assigned:int, left:int, nopool:int} */
function pp_vnrb_pool_assign_crm(string $by, ?int $uid, ?string $batch = null): array {
    pp_migrate();
    $r = ['assigned' => 0, 'left' => 0, 'nopool' => 0];
    $have = array_column(db_all("SELECT contact_id FROM pp_vnrb_crm"), 'contact_id', 'contact_id');
    foreach (crm_all("SELECT id FROM crm_contacts WHERE crm_active=1 AND status='klient' ORDER BY id") as $c) {
        $cid = (int)$c['id']; if (isset($have[$cid])) continue;
        $p = db_one("SELECT nrb FROM pp_vnrb_pool WHERE grp='inni' AND participant_id IS NULL AND crm_contact_id IS NULL" . ($batch !== null ? " AND batch=?" : "") . " ORDER BY substr(nrb,15,12) LIMIT 1", $batch !== null ? [$batch] : []);
        if (!$p) { $r['nopool']++; continue; }
        db()->beginTransaction();
        try {
            db()->prepare("INSERT INTO pp_vnrb_crm (contact_id, nrb) VALUES (?,?)")->execute([$cid, $p['nrb']]);
            db()->prepare("UPDATE pp_vnrb_pool SET crm_contact_id=?, assigned_at=datetime('now') WHERE nrb=?")->execute([$cid, $p['nrb']]);
            db()->commit(); $r['assigned']++;
        } catch (\Throwable $e) { db()->rollBack(); }
    }
    $r['left'] = (int)(db_one("SELECT COUNT(*) c FROM pp_vnrb_pool WHERE grp='inni' AND participant_id IS NULL AND crm_contact_id IS NULL")['c'] ?? 0);
    audit_log('payments.vnrb_pool_assign_crm', $r + ['batch' => $batch, 'by' => $by], $uid);
    return $r;
}

/** Przypisuje wolne numery serii „spoza_ti” uczestnikom z dostępem do portalu, którzy nie mają konta TI ani numeru. */
function pp_vnrb_pool_assign_other(string $by, ?int $uid, ?string $batch = null): array {
    pp_migrate();
    $r = ['assigned' => 0, 'left' => 0, 'nopool' => 0];
    $rows = db_all("SELECT u.participant_id FROM payment_portal_users u
                     WHERE (u.individual_nrb IS NULL OR u.individual_nrb='')
                       AND NOT EXISTS (SELECT 1 FROM k30_ti_student_accounts a WHERE a.client_id=u.participant_id) ORDER BY u.participant_id");
    foreach ($rows as $row) {
        $pid = (int)$row['participant_id'];
        $p = db_one("SELECT nrb FROM pp_vnrb_pool WHERE grp='spoza_ti' AND participant_id IS NULL AND crm_contact_id IS NULL" . ($batch !== null ? " AND batch=?" : "") . " ORDER BY substr(nrb,15,12) LIMIT 1", $batch !== null ? [$batch] : []);
        if (!$p) { $r['nopool']++; continue; }
        if (pp_set_nrb($pid, $p['nrb'], $by, $uid) !== null) continue;
        db()->prepare("UPDATE pp_vnrb_pool SET participant_id=?, assigned_at=datetime('now') WHERE nrb=?")->execute([$pid, $p['nrb']]);
        $r['assigned']++;
    }
    $r['left'] = (int)(db_one("SELECT COUNT(*) c FROM pp_vnrb_pool WHERE grp='spoza_ti' AND participant_id IS NULL AND crm_contact_id IS NULL")['c'] ?? 0);
    audit_log('payments.vnrb_pool_assign_other', $r + ['batch' => $batch, 'by' => $by], $uid);
    return $r;
}

/** Kategorie raportu stanu rachunków wirtualnych kursantów TI. */
const PP_VSTATUS = [
    'brak'       => 'Bez numeru',
    'nie_powiad' => 'Nadany, bez powiadomienia',
    'powiad'     => 'Nadany i powiadomiony',
    'reczny'     => 'Nadany ręcznie (brak danych o powiadomieniu)',
    'wirtualny'  => 'Kursant wirtualny',
    'bez_rozl'   => 'Bez rozliczeń i powiadomień',
];

/**
 * Jedna lista kursantów TI ze stanem rachunku i powiadomienia.
 * @return list<array{client_id:int,name:string,nrb:string,cat:string,assigned_at:string,notified_at:string,flags:string}>
 */
function pp_vnrb_status_rows(): array {
    pp_migrate();
    $rows = db_all("SELECT c.id client_id, c.name,
                           MAX(COALESCE(a.is_virtual,0)) virt_any, MIN(COALESCE(a.is_virtual,0)) virt_all,
                           MAX(COALESCE(a.no_billing,0)) nb_any, MIN(COALESCE(a.no_billing,0)) nb_all,
                           COALESCE(u.individual_nrb,'') nrb, p.assigned_at, p.notified_at, (p.nrb IS NOT NULL) in_pool
                      FROM k30_clients c
                      JOIN k30_ti_student_accounts a ON a.client_id=c.id
                      LEFT JOIN payment_portal_users u ON u.participant_id=c.id
                      LEFT JOIN pp_vnrb_pool p ON p.nrb=u.individual_nrb
                     GROUP BY c.id ORDER BY c.name COLLATE NOCASE");
    $out = [];
    foreach ($rows as $r) {
        $nrb = preg_replace('/\D/', '', (string)$r['nrb']);
        $flags = [];
        if ((int)$r['virt_all']) $flags[] = 'wirtualny';
        if ((int)$r['nb_all'])   $flags[] = 'bez rozliczeń';
        if ($nrb === '') {
            $cat = (int)$r['virt_all'] ? 'wirtualny' : ((int)$r['nb_all'] ? 'bez_rozl' : 'brak');
        } elseif (!(int)$r['in_pool']) {
            $cat = 'reczny';
        } else {
            $cat = empty($r['notified_at']) ? 'nie_powiad' : 'powiad';
        }
        $out[] = ['client_id' => (int)$r['client_id'], 'name' => (string)$r['name'], 'nrb' => $nrb, 'cat' => $cat,
                  'assigned_at' => (string)($r['assigned_at'] ?? ''), 'notified_at' => (string)($r['notified_at'] ?? ''), 'flags' => implode(', ', $flags)];
    }
    return $out;
}

/** Wydruk raportu stanu (samodzielny dokument HTML). $cat: '' = wszystkie kategorie albo klucz z PP_VSTATUS. Kończy skrypt. */
function pp_vnrb_status_print(string $cat, string $by): never {
    $all = pp_vnrb_status_rows();
    $counts = array_fill_keys(array_keys(PP_VSTATUS), 0);
    foreach ($all as $r) $counts[$r['cat']]++;
    $rows = isset(PP_VSTATUS[$cat]) ? array_values(array_filter($all, fn($r) => $r['cat'] === $cat)) : $all;
    $title = 'Raport rachunków wirtualnych — stan kursantów TI' . (isset(PP_VSTATUS[$cat]) ? ': ' . PP_VSTATUS[$cat] : '');
    audit_log('payments.vnrb_status_print', ['cat' => $cat, 'rows' => count($rows), 'by' => $by], null);
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($title) ?></title>
<style>
  body{font:12px/1.4 Arial,sans-serif;color:#000;margin:16px} h1{font-size:16px;margin:0 0 2px} .meta{color:#444;margin-bottom:8px}
  .chips span{display:inline-block;border:1px solid #999;border-radius:10px;padding:1px 8px;margin:0 4px 4px 0}
  table{border-collapse:collapse;width:100%} th,td{border:1px solid #999;padding:3px 6px;text-align:left} thead th{background:#eee}
  td.n{font-family:monospace;white-space:nowrap} tr{break-inside:avoid} .pbtn{margin-bottom:10px;padding:6px 12px;font-size:13px} @media print{.pbtn{display:none}}
</style></head><body>
<button class="pbtn" onclick="window.print()">Drukuj</button>
<h1><?= h($title) ?></h1>
<div class="meta"><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?> · wydruk: <?= date('d.m.Y H:i') ?> · wystawił: <?= h($by) ?> · pozycji: <?= count($rows) ?> z <?= count($all) ?></div>
<div class="chips"><?php foreach (PP_VSTATUS as $k => $l): ?><span><?= h($l) ?>: <strong><?= $counts[$k] ?></strong></span><?php endforeach; ?></div>
<table><thead><tr><th>Lp.</th><th>Kursant</th><th>ID</th><th>Stan</th><th>Rachunek</th><th>Nadano</th><th>Powiadomiono</th><th>Uwagi</th></tr></thead><tbody>
<?php foreach ($rows as $i => $r): ?>
<tr><td><?= $i + 1 ?></td><td><?= h($r['name']) ?></td><td><?= $r['client_id'] ?></td><td><?= h(PP_VSTATUS[$r['cat']]) ?></td>
<td class="n"><?= $r['nrb'] !== '' ? h(pp_nrb_format($r['nrb'])) : '—' ?></td><td><?= h($r['assigned_at'] ?: '—') ?></td><td><?= h($r['notified_at'] ?: '—') ?></td><td><?= h($r['flags']) ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="8">Brak pozycji.</td></tr><?php endif; ?>
</tbody></table></body></html><?php
    exit;
}

/** Próg ostrzeżenia o kończącej się puli (org_setting pp_pool_min, domyślnie 10 wolnych numerów). */
function pp_pool_min(): int { $v = (int)org_setting('pp_pool_min'); return $v > 0 ? $v : 10; }

/**
 * Alarm kończącej się puli numerów. Dla serii TI porównuje wolne numery z liczbą kursantów bez rachunku,
 * dla pozostałych serii (używanych, tzn. z numerami w puli) — z progiem.
 * @return list<array{grp:string,label:string,level:string,free:int,need:int,missing:int,order_start:string,order_count:int,msg:string}>
 */
function pp_pool_alarms(): array {
    pp_migrate();
    $min = pp_pool_min(); $out = [];
    $need_ti = (int)(db_one("SELECT COUNT(DISTINCT a.client_id) c FROM k30_ti_student_accounts a
                              LEFT JOIN payment_portal_users u ON u.participant_id=a.client_id
                             WHERE (u.individual_nrb IS NULL OR u.individual_nrb='') AND COALESCE(a.is_virtual,0)=0 AND COALESCE(a.no_billing,0)=0")['c'] ?? 0);
    foreach (pp_series() as $k => $se) {
        $r = db_one("SELECT COUNT(*) n, COALESCE(SUM(participant_id IS NULL AND crm_contact_id IS NULL),0) f, MAX(substr(nrb,15,12)) mx FROM pp_vnrb_pool WHERE grp=?", [$k]);
        $n = (int)$r['n']; $free = (int)$r['f'];
        $need = $k === 'ti' ? $need_ti : 0;
        if ($k !== 'ti' && $n === 0) continue;                 // seria jeszcze nieużywana
        $missing = max(0, $need - $free);
        $level = $missing > 0 ? 'crit' : ($free < $min ? 'warn' : '');
        if ($level === '') continue;
        // Propozycja zamówienia w banku: kolejny numer po ostatnim w puli (albo numer startowy serii) + brakujące + zapas 20
        $last = $r['mx'] ? (string)$r['mx'] : ($se['code'] . '0000');   // mx = największa końcówka 12 cyfr (nie cały NRB — cyfry kontrolne są z przodu)
        $start = str_pad((string)((int)substr($last, 0, 8) * 10000 + (int)substr($last, -4) + 1), 12, '0', STR_PAD_LEFT);
        $count = max($missing, 0) + 20;
        $msg = $level === 'crit'
            ? "Brakuje {$missing} numerów: wolnych {$free}, kursantów bez rachunku {$need}."
            : "Kończy się pula: wolnych tylko {$free} (próg {$min}).";
        $out[] = ['grp' => $k, 'label' => $se['label'], 'level' => $level, 'free' => $free, 'need' => $need, 'missing' => $missing,
                  'order_start' => $start, 'order_count' => $count, 'msg' => $msg];
    }
    return $out;
}


/**
 * Automatyczne księgowanie wpłat po numerze wirtualnym: wpływy z wyciągów EODoK (znak C), których rachunek docelowy
 * (albo 26 cyfr w tytule/referencji) to indywidualny NRB uczestnika, księgujemy w księdze TI (ti_payment_add) — bez
 * oczekującej płatności w portalu. Każdy wpływ tylko raz (pp_bank_autopost); pomijamy wpływy rozliczone w portalu,
 * zignorowane i przypięte do dokumentów EODoK.
 * @return array{posted:int, amount:float, rows:list<array>, skipped:int}
 */
function pp_bank_autopost(string $by = 'automat', ?int $uid = null, bool $dry = false): array {
    pp_migrate();
    $r = ['posted' => 0, 'amount' => 0.0, 'rows' => [], 'skipped' => 0];
    try {
        $bank = db_all("SELECT b.* FROM edok_bank_tx b
                         LEFT JOIN pp_bank_matches m ON m.bank_tx_id=b.id LEFT JOIN pp_bank_autopost a ON a.bank_tx_id=b.id
                        WHERE b.znak='C' AND b.ignored=0 AND b.doc_id IS NULL AND m.id IS NULL AND a.bank_tx_id IS NULL
                        ORDER BY b.data_waluty, b.id LIMIT 1000");
    } catch (\Throwable $e) { return $r; }   // EODoK bez tabeli wyciągów
    $ind = [];
    foreach (db_all("SELECT participant_id, individual_nrb FROM payment_portal_users WHERE individual_nrb IS NOT NULL AND individual_nrb!=''") as $u)
        $ind[pp_nrb_normalize((string)$u['individual_nrb'])] = (int)$u['participant_id'];
    if (!$ind) return $r;
    foreach ($bank as $b) {
        $acc = pp_nrb_normalize((string)$b['account_nrb']);
        $pid = $ind[$acc] ?? null; $nrb = $acc;
        if ($pid === null) {
            $digits = preg_replace('/\D/', '', $b['tytul'] . ' ' . $b['referencja']);
            foreach ($ind as $n => $p) if (strlen($digits) >= 26 && str_contains($digits, $n)) { $pid = $p; $nrb = $n; break; }
        }
        if ($pid === null || (float)$b['kwota'] <= 0) { $r['skipped']++; continue; }
        if (db_one("SELECT 1 FROM pp_vnrb_blocks WHERE nrb=?", [$nrb])) { $r['blocked'] = ($r['blocked'] ?? 0) + 1; continue; }   // zablokowany numer — wpłata czeka na decyzję
        $name = (string)(db_one("SELECT name FROM k30_clients WHERE id=?", [$pid])['name'] ?? ('#' . $pid));
        $row = ['bank_tx_id' => (int)$b['id'], 'participant_id' => $pid, 'name' => $name, 'amount' => (float)$b['kwota'], 'date' => (string)$b['data_waluty'],
                'payer' => (string)$b['kontrahent_nazwa'], 'title' => (string)$b['tytul'], 'nrb' => $nrb];
        if (!$dry) {
            $pdo = db(); $pdo->beginTransaction();
            try {
                $pay = ti_payment_add($pid, (float)$b['kwota'], substr((string)$b['data_waluty'], 0, 10), 'transfer',
                    'Wpływ z wyciągu na numer wirtualny — ' . trim($b['kontrahent_nazwa'] . ' ' . $b['tytul']), 'bank_tx', (int)$b['id']);
                db_insert('pp_bank_autopost', ['bank_tx_id' => (int)$b['id'], 'participant_id' => $pid, 'payment_id' => (int)($pay['payment_id'] ?? 0),
                                               'amount' => (float)$b['kwota'], 'nrb' => $nrb, 'by_name' => $by]);
                db()->prepare("UPDATE edok_bank_tx SET matched_how=?, matched_by_name=?, matched_at=datetime('now') WHERE id=? AND doc_id IS NULL AND matched_how=''")
                    ->execute(['platnosci:nrb', $by, (int)$b['id']]);
                audit_log('payments.bank_autopost', ['bank_tx_id' => (int)$b['id'], 'participant_id' => $pid, 'amount' => (float)$b['kwota'], 'nrb_last4' => substr($nrb, -4), 'by' => $by], $uid);
                $pdo->commit();
            } catch (\Throwable $e) { $pdo->rollBack(); $r['skipped']++; continue; }
        }
        $r['posted']++; $r['amount'] = round($r['amount'] + (float)$b['kwota'], 2); $r['rows'][] = $row;
    }
    return $r;
}

/** Jedna strona informacji dla kursanta: nadano numer rachunku, wpłacasz tam wszystkie należności z tytułu szkoleń (HTML do mPDF). */
function pp_vnrb_notice_html(int $client_id): ?string {
    $cl = db_one("SELECT id, name FROM k30_clients WHERE id=?", [$client_id]);
    $u  = pp_user($client_id);
    $nrb = preg_replace('/\D/', '', (string)($u['individual_nrb'] ?? ''));
    if (!$cl || strlen($nrb) !== 26) return null;
    $org = defined('ORG_NAME') ? ORG_NAME : '';
    $fmt = pp_nrb_format($nrb);
    $title = function_exists('k30_ti_payment_title') ? (string)k30_ti_payment_title($client_id) : ('TI/' . $client_id . ' ' . $cl['name']);
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    return '<div style="font-family:dejavusans;font-size:11pt;color:#111">'
        . '<table width="100%"><tr><td style="font-size:10pt;color:#444">' . $e($org) . '</td><td align="right" style="font-size:10pt;color:#444">' . date('d.m.Y') . '</td></tr></table>'
        . '<h1 style="font-size:17pt;margin:26px 0 4px">Informacja o numerze rachunku do wpłat</h1>'
        . '<p style="margin:0 0 18px;color:#444">Szkolenia i zajęcia — rozliczenia</p>'
        . '<p>Uczestnik: <strong>' . $e($cl['name']) . '</strong></p>'
        . '<p>Informujemy, że <strong>nadano Ci indywidualny numer rachunku bankowego</strong> do wpłat:</p>'
        . '<div style="border:2px solid #10335c;border-radius:6px;padding:14px 10px;margin:14px 0;text-align:center;font-size:18pt;letter-spacing:1px;font-family:dejavusansmono"><strong>' . $e($fmt) . '</strong></div>'
        . '<p style="font-size:12.5pt"><strong>Na ten rachunek wpłacasz wszystkie należności z tytułu szkoleń</strong> — opłaty za zajęcia, szkolenia i związane z nimi rozliczenia. Nie musisz sprawdzać osobnych numerów dla poszczególnych grup.</p>'
        . '<ul style="margin:8px 0 14px;line-height:1.6"><li>Numer jest przypisany wyłącznie do Ciebie — wpłata zostanie przypisana automatycznie.</li>'
        . '<li>Odbiorca przelewu: <strong>' . $e($org) . '</strong>. Rachunek obsługuje <strong>PKO Bank Polski S.A.</strong></li>'
        . '<li>Wpłaty są księgowane na koniec dnia, o godzinie 20:00.</li>'
        . '<li>Tytuł przelewu (zalecany): <strong>' . $e($title) . '</strong>.</li>'
        . '<li>Zachowaj ten dokument — numer będzie obowiązywał w kolejnych okresach rozliczeniowych.</li></ul>'
        . '<p style="margin-top:26px;font-size:9.5pt;color:#555">W razie pytań skontaktuj się z prowadzącym lub biurem. Dokument ma charakter informacyjny; wygenerowano ' . date('d.m.Y H:i') . '.</p>'
        . '</div>';
}

/** PDF z informacjami (jedna strona na kursanta z nadanym numerem). Zwraca bajty PDF albo null, gdy nikt nie ma numeru. */
function pp_vnrb_notice_pdf(array $client_ids): ?string {
    $pages = [];
    foreach (array_unique(array_map('intval', $client_ids)) as $cid) { $h = pp_vnrb_notice_html($cid); if ($h !== null) $pages[] = $h; }
    if (!$pages) return null;
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    $tmp = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp'; if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 22, 'margin_right' => 22, 'margin_top' => 18, 'margin_bottom' => 18, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
    $mpdf->SetTitle('Informacja o numerze rachunku do wpłat');
    foreach ($pages as $i => $h) { if ($i) $mpdf->AddPage(); $mpdf->WriteHTML($h); }
    return (string)$mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

/**
 * Zestaw kursantów do PDF: 'client' = jeden, 'last' = z ostatniego importu, 'unnotified' = nadany bez powiadomienia,
 * 'all' = wszyscy z numerem (kursanci TI).
 * @return list<int>
 */
function pp_vnrb_notice_clients(string $scope, int $client_id = 0): array {
    pp_migrate();
    if ($scope === 'client') return $client_id ? [$client_id] : [];
    $sql = "SELECT DISTINCT p.participant_id id FROM pp_vnrb_pool p JOIN k30_clients c ON c.id=p.participant_id WHERE p.grp='ti' AND p.participant_id IS NOT NULL";
    $par = [];
    if ($scope === 'last') { $sql .= " AND p.batch=?"; $par[] = (string)org_setting('pp_pool_last_batch'); }
    elseif ($scope === 'unnotified') $sql .= " AND p.notified_at IS NULL";
    elseif ($scope === 'all') $sql = "SELECT DISTINCT u.participant_id id FROM payment_portal_users u WHERE u.individual_nrb IS NOT NULL AND u.individual_nrb!='' AND EXISTS (SELECT 1 FROM k30_ti_student_accounts a WHERE a.client_id=u.participant_id)";
    return array_map(fn($r) => (int)$r['id'], db_all($sql . ' ORDER BY 1', $par));
}

/** Wysyła PDF z informacjami do przeglądarki (inline) i kończy skrypt. */
function pp_vnrb_notice_send(string $scope, int $client_id, string $by): never {
    $ids = pp_vnrb_notice_clients($scope, $client_id);
    $pdf = pp_vnrb_notice_pdf($ids);
    if ($pdf === null) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Brak kursantów z nadanym numerem rachunku.'; exit; }
    audit_log('payments.vnrb_notice_pdf', ['scope' => $scope, 'client_id' => $client_id, 'clients' => count($ids), 'by' => $by], null);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="informacja_rachunek_' . ($scope === 'client' ? $client_id : $scope) . '_' . date('Ymd') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf; exit;
}


// ── Powiadomienia o numerze rachunku: treść zatwierdza admin, wysyłka o 8:00 następnego dnia (cron) ──────────

const PP_NOTICE_STATUSES = ['draft' => 'szkic (czeka na zatwierdzenie)', 'approved' => 'zatwierdzona — zaplanowana', 'sent' => 'wysłana', 'cancelled' => 'anulowana'];

/** Gotowe wersje treści do wyboru (znaczniki {imie_nazwisko} {numer} {numer_cyfry} {tytul} {organizacja}). */
function pp_notice_templates(): array {
    return [
        'v1' => [
            'label'   => 'Wersja 1 — krótka',
            'subject' => 'Twój indywidualny numer rachunku do wpłat za szkolenia',
            'body'    => "Dzień dobry,\n\nnadaliśmy Ci indywidualny numer rachunku bankowego do wpłat:\n\n{numer}\n\nNa ten rachunek wpłacasz wszystkie należności z tytułu szkoleń i zajęć. "
                       . "Numer jest przypisany wyłącznie do Ciebie, a wpłata zostanie przypisana automatycznie.\n\nTytuł przelewu (zalecany): {tytul}\n\nPozdrawiamy,\n{organizacja}",
            'sms'     => '{organizacja}: Twoj indywidualny numer rachunku do wplat za szkolenia: {numer_cyfry}. Wplacasz tu wszystkie naleznosci z tytulu szkolen.',
        ],
        'v2' => [
            'label'   => 'Wersja 2 — zakończenie wdrożenia + księgowanie o 20:00',
            'subject' => 'Zakończyliśmy wdrażanie indywidualnych numerów rachunków — Twój numer do wpłat',
            'body'    => "Dzień dobry,\n\nzakończyliśmy proces wdrażania indywidualnych numerów rachunków bankowych. Twój numer do wpłat (rachunek obsługuje PKO Bank Polski):\n\n{numer}\n\n"
                       . "Na ten rachunek wpłacasz wszystkie należności z tytułu szkoleń i zajęć. Numer jest przypisany wyłącznie do Ciebie, a wpłata zostanie przypisana automatycznie.\n\n"
                       . "Ważne: wpłaty są księgowane zawsze na koniec dnia, o godzinie 20:00 — saldo po wpłacie zobaczysz po tej godzinie.\n\n"
                       . "Tytuł przelewu (zalecany): {tytul}\n\nPozdrawiamy,\n{organizacja}",
            'sms'     => '{organizacja}: zakonczylismy wdrazanie indywidualnych numerow. Twoj numer do wplat za szkolenia: {numer_cyfry}. Wplaty ksiegujemy codziennie o 20:00.',
        ],
    ];
}

/** Domyślna treść = wersja 1. */
function pp_notice_default(): array { $t = pp_notice_templates()['v1']; return ['subject' => $t['subject'], 'body' => $t['body'], 'sms' => $t['sms']]; }

function pp_notice_render(string $tpl, array $v, bool $html = false): string {
    $map = ['{imie_nazwisko}' => $v['name'], '{numer}' => $v['nrb_fmt'], '{numer_cyfry}' => $v['nrb'], '{tytul}' => $v['title'], '{organizacja}' => $v['org']];
    $out = strtr($tpl, $html ? array_map(fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'), $map) : $map);
    return $html ? nl2br($out, false) : $out;
}

/** Dane do podstawienia dla kursanta (null = brak numeru). */
function pp_notice_vars(int $client_id): ?array {
    $cl = db_one("SELECT id, name, email, phone FROM k30_clients WHERE id=?", [$client_id]);
    $u = pp_user($client_id);
    $nrb = preg_replace('/\D/', '', (string)($u['individual_nrb'] ?? ''));
    if (!$cl || strlen($nrb) !== 26) return null;
    return ['name' => (string)$cl['name'], 'email' => (string)$cl['email'], 'phone' => (string)$cl['phone'], 'nrb' => $nrb, 'nrb_fmt' => pp_nrb_format($nrb),
            'title' => function_exists('k30_ti_payment_title') ? (string)k30_ti_payment_title($client_id) : ('TI/' . $client_id . ' ' . $cl['name']),
            'org' => defined('ORG_NAME') ? ORG_NAME : ''];
}

/** @return list<int> kursanci, do których wyśle się wiadomość z danej paczki (numer z puli TI, bez powiadomienia). */
function pp_notice_recipients(string $scope, string $batch = '', int $batch_id = 0): array {
    $sql = "SELECT DISTINCT participant_id id FROM pp_vnrb_pool WHERE grp='ti' AND participant_id IS NOT NULL AND notified_at IS NULL";
    $par = [];
    if ($batch_id > 0) { $sql .= " AND participant_id NOT IN (SELECT client_id FROM pp_notice_exclusions WHERE batch_id=?)"; $par[] = $batch_id; }
    if ($scope === 'last' && $batch !== '') { $sql .= " AND batch=?"; $par[] = $batch; }
    return array_map(fn($r) => (int)$r['id'], db_all($sql . ' ORDER BY 1', $par));
}

function pp_notice_save(int $id, string $scope, string $subject, string $body, string $sms, string $by): int|string {
    pp_migrate();
    if (mb_strlen(trim($subject)) < 3 || mb_strlen(trim($body)) < 20) return 'Podaj temat i treść wiadomości.';
    if (!str_contains($body, '{numer}')) return 'Treść musi zawierać znacznik {numer} (numer rachunku).';
    $scope = $scope === 'last' ? 'last' : 'unnotified';
    $batch = $scope === 'last' ? (string)org_setting('pp_pool_last_batch') : '';
    if ($id > 0) {
        $b = db_one("SELECT status FROM pp_notice_batches WHERE id=?", [$id]);
        if (!$b || $b['status'] !== 'draft') return 'Edytować można tylko szkic (zatwierdzoną wysyłkę anuluj i utwórz nową).';
        db()->prepare("UPDATE pp_notice_batches SET scope=?, batch=?, subject=?, body=?, sms_text=? WHERE id=?")->execute([$scope, $batch, trim($subject), trim($body), trim($sms), $id]);
        return $id;
    }
    return db_insert('pp_notice_batches', ['status' => 'draft', 'scope' => $scope, 'batch' => $batch, 'subject' => trim($subject), 'body' => trim($body), 'sms_text' => trim($sms), 'created_by' => $by]);
}

/** Zatwierdzenie treści → wysyłka zaplanowana na 08:00 następnego dnia. */
function pp_notice_approve(int $id, string $by, ?int $uid): ?string {
    pp_migrate();
    $b = db_one("SELECT * FROM pp_notice_batches WHERE id=?", [$id]);
    if (!$b || $b['status'] !== 'draft') return 'Zatwierdzić można tylko szkic.';
    if (!pp_notice_recipients($b['scope'], $b['batch'], $id)) return 'Brak odbiorców (nikt z nadanym numerem nie czeka na powiadomienie).';
    $at = date('Y-m-d 08:00:00', strtotime('+1 day'));
    db()->prepare("UPDATE pp_notice_batches SET status='approved', approved_by=?, approved_at=datetime('now'), send_at=? WHERE id=?")->execute([$by, $at, $id]);
    audit_log('payments.notice_approved', ['batch_id' => $id, 'send_at' => $at, 'recipients' => count(pp_notice_recipients($b['scope'], $b['batch'], $id)), 'by' => $by], $uid);
    return null;
}

function pp_notice_cancel(int $id, string $by, ?int $uid): ?string {
    pp_migrate();
    $b = db_one("SELECT status FROM pp_notice_batches WHERE id=?", [$id]);
    if (!$b || !in_array($b['status'], ['draft', 'approved'], true)) return 'Tej wysyłki nie można anulować.';
    db()->prepare("UPDATE pp_notice_batches SET status='cancelled' WHERE id=?")->execute([$id]);
    audit_log('payments.notice_cancelled', ['batch_id' => $id, 'by' => $by], $uid);
    return null;
}

/** Cron: wysyła zatwierdzone wysyłki, których termin minął. @return array{batches:int, students:int, sms:int, email:int} */
function pp_notice_process(): array {
    pp_migrate();
    $r = ['batches' => 0, 'students' => 0, 'sms' => 0, 'email' => 0];
    foreach (db_all("SELECT * FROM pp_notice_batches WHERE status='approved' AND send_at<=datetime('now','localtime') ORDER BY id") as $b) {
        $st = ['students' => 0, 'sms' => 0, 'email' => 0, 'clients' => []];
        foreach (pp_notice_recipients($b['scope'], $b['batch'], (int)$b['id']) as $cid) {
            $v = pp_notice_vars($cid); if (!$v) continue;
            $nrb = $v['nrb'];
            if ($v['email'] !== '') {
                try {
                    if (!function_exists('mail_queue_add')) require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
                    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5">' . pp_notice_render($b['body'], $v, true) . '</div>';
                    if (mail_queue_add($v['email'], $v['name'], pp_notice_render($b['subject'], $v), $html, pp_notice_render($b['body'], $v), 'pp_notice', $cid, '', false) > 0) $st['email']++;
                } catch (\Throwable $e) {}
            }
            if (trim($b['sms_text']) !== '' && trim($v['phone']) !== '') {
                try {
                    if (!function_exists('sms_send')) require_once dirname(__DIR__, 3) . '/includes/sms.php';
                    if (sms_channel_ready()) { sms_send($v['phone'], pp_notice_render($b['sms_text'], $v)); $st['sms']++; }
                } catch (\Throwable $e) {}
            }
            db()->prepare("UPDATE pp_vnrb_pool SET notified_at=datetime('now') WHERE nrb=?")->execute([$nrb]);
            $st['students']++; $st['clients'][] = $cid;
        }
        db()->prepare("UPDATE pp_notice_batches SET status='sent', sent_at=datetime('now'), stats=? WHERE id=?")->execute([json_encode($st), (int)$b['id']]);
        audit_log('payments.notice_sent', array_diff_key($st, ['clients' => 1]) + ['batch_id' => (int)$b['id']], null);
        $r['batches']++; foreach (['students', 'sms', 'email'] as $k) $r[$k] += $st[$k];
    }
    return $r;
}


// ── Dodatkowe opcje dla rachunków: odepnij / zablokuj / historia / eksport (kursanci i kontrahenci CRM) ─────────

function pp_vnrb_is_blocked(string $nrb): bool {
    pp_migrate();
    return (bool)db_one("SELECT 1 FROM pp_vnrb_blocks WHERE nrb=?", [pp_nrb_normalize($nrb)]);
}

/** Blokada numeru: wpłaty na niego nie są księgowane automatycznie; zablokowanego numeru nie można odpiąć. */
function pp_vnrb_block(string $nrb, bool $block, string $reason, string $by, ?int $uid): ?string {
    pp_migrate();
    $n = pp_nrb_normalize($nrb);
    if (!pp_nrb_valid($n)) return 'Nieprawidłowy numer rachunku.';
    if ($block) {
        if (mb_strlen(trim($reason)) < 5) return 'Podaj powód blokady (min. 5 znaków).';
        db()->prepare("INSERT OR REPLACE INTO pp_vnrb_blocks (nrb, reason, by_name) VALUES (?,?,?)")->execute([$n, trim($reason), $by]);
    } else {
        db()->prepare("DELETE FROM pp_vnrb_blocks WHERE nrb=?")->execute([$n]);
    }
    audit_log($block ? 'payments.vnrb_blocked' : 'payments.vnrb_unblocked', ['nrb_last4' => substr($n, -4), 'nrb' => $n, 'reason' => trim($reason), 'by' => $by], $uid);
    return null;
}

/**
 * Odpięcie numeru od kursanta ($kind='ti', $id=client_id) albo kontrahenta CRM ($kind='crm', $id=contact_id) — numer wraca
 * do puli (jako wolny, bez daty powiadomienia). Odmowa: numer zablokowany albo oczekujący przelew na ten numer.
 */
function pp_vnrb_release(string $kind, int $id, string $reason, string $by, ?int $uid): ?string {
    pp_migrate();
    if (mb_strlen(trim($reason)) < 5) return 'Podaj powód odpięcia (min. 5 znaków).';
    if ($kind === 'crm') {
        $r = db_one("SELECT nrb FROM pp_vnrb_crm WHERE contact_id=?", [$id]);
        if (!$r) return 'Kontrahent nie ma nadanego numeru.';
        if (pp_vnrb_is_blocked($r['nrb'])) return 'Numer jest zablokowany — najpierw go odblokuj.';
        db()->beginTransaction();
        try {
            db()->prepare("DELETE FROM pp_vnrb_crm WHERE contact_id=?")->execute([$id]);
            db()->prepare("UPDATE pp_vnrb_pool SET crm_contact_id=NULL, assigned_at=NULL WHERE nrb=?")->execute([$r['nrb']]);
            audit_log('payments.vnrb_released', ['kind' => 'crm', 'contact_id' => $id, 'nrb' => $r['nrb'], 'reason' => trim($reason), 'by' => $by], $uid);
            db()->commit();
        } catch (\Throwable $e) { db()->rollBack(); return 'Błąd: ' . $e->getMessage(); }
        return null;
    }
    $u = pp_user($id);
    $nrb = preg_replace('/\D/', '', (string)($u['individual_nrb'] ?? ''));
    if (strlen($nrb) !== 26) return 'Kursant nie ma nadanego numeru.';
    if (pp_vnrb_is_blocked($nrb)) return 'Numer jest zablokowany — najpierw go odblokuj.';
    if (db_one("SELECT 1 FROM portal_transactions WHERE participant_id=? AND status='pending' AND payment_method='individual_nrb'", [$id]))
        return 'Kursant ma oczekujący przelew na ten numer — potwierdź lub anuluj go przed odpięciem.';
    $err = pp_set_nrb($id, '', $by, $uid);   // własna transakcja + audyt zmiany numeru
    if ($err) return $err;
    db()->prepare("UPDATE pp_vnrb_pool SET participant_id=NULL, assigned_at=NULL, notified_at=NULL WHERE nrb=?")->execute([$nrb]);
    audit_log('payments.vnrb_released', ['kind' => 'ti', 'participant_id' => $id, 'nrb' => $nrb, 'reason' => trim($reason), 'by' => $by], $uid);
    return null;
}

/** Historia numeru kursanta: wpisy audytu płatności dotyczące uczestnika (najnowsze pierwsze). */
function pp_vnrb_history(int $client_id, int $limit = 40): array {
    pp_migrate();
    return db_all("SELECT created_at, action, details FROM audit_logs WHERE action LIKE 'payments.%'
                     AND (details LIKE ? OR details LIKE ?) ORDER BY id DESC LIMIT " . (int)$limit,
                  ['%"participant_id":' . $client_id . ',%', '%"participant_id":' . $client_id . '}%']);
}

/** Eksport CSV wszystkich numerów (kursanci TI z numerem + kontrahenci CRM). Kończy skrypt. */
function pp_vnrb_export_csv(string $by): never {
    pp_migrate();
    $rows = [];
    foreach (db_all("SELECT c.id, c.name, u.individual_nrb nrb, p.grp, p.assigned_at, p.notified_at FROM payment_portal_users u
                       JOIN k30_clients c ON c.id=u.participant_id LEFT JOIN pp_vnrb_pool p ON p.nrb=u.individual_nrb
                      WHERE u.individual_nrb IS NOT NULL AND u.individual_nrb!='' ORDER BY c.name COLLATE NOCASE") as $r)
        $rows[] = ['kursant/uczestnik', $r['id'], $r['name'], $r['nrb'], $r['grp'] ?? '', $r['assigned_at'] ?? '', $r['notified_at'] ?? ''];
    $crm = db_all("SELECT contact_id id, nrb, assigned_at FROM pp_vnrb_crm ORDER BY contact_id");
    $names = [];
    if ($crm) { try { foreach (crm_all("SELECT id, imie_nazwisko FROM crm_contacts WHERE id IN (" . implode(',', array_map('intval', array_column($crm, 'id'))) . ")") as $n) $names[(int)$n['id']] = $n['imie_nazwisko']; } catch (\Throwable $e) {} }
    foreach ($crm as $r) $rows[] = ['kontrahent CRM', $r['id'], $names[(int)$r['id']] ?? '', $r['nrb'], 'inni', $r['assigned_at'], ''];
    $blocked = array_column(db_all("SELECT nrb FROM pp_vnrb_blocks"), 'nrb', 'nrb');
    audit_log('payments.vnrb_export_csv', ['rows' => count($rows), 'by' => $by], null);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rachunki_wirtualne_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['typ', 'id', 'nazwa', 'numer_rachunku', 'seria', 'nadano', 'powiadomiono', 'zablokowany'], ';');
    foreach ($rows as $r) { $n = preg_replace('/\D/', '', (string)$r[3]); fputcsv($out, [$r[0], $r[1], $r[2], pp_nrb_format($n), $r[4], $r[5], $r[6], isset($blocked[$n]) ? 'tak' : 'nie'], ';'); }
    fclose($out); exit;
}


/** Zmiana terminu zatwierdzonej wysyłki (tylko przyszły termin, w godzinach 06:00–22:00). Treść pozostaje zatwierdzona. */
function pp_notice_reschedule(int $id, string $when, string $by, ?int $uid): ?string {
    pp_migrate();
    $b = db_one("SELECT status FROM pp_notice_batches WHERE id=?", [$id]);
    if (!$b || $b['status'] !== 'approved') return 'Termin można zmienić tylko dla zatwierdzonej, niewysłanej wysyłki.';
    $t = strtotime(str_replace('T', ' ', $when));
    if (!$t) return 'Podaj poprawną datę i godzinę.';
    if ($t <= time() + 300) return 'Termin musi być w przyszłości.';
    $h = (int)date('G', $t);
    if ($h < 6 || $h >= 22) return 'Wysyłka tylko w godzinach 06:00–22:00.';
    $at = date('Y-m-d H:i:00', $t);
    db()->prepare("UPDATE pp_notice_batches SET send_at=? WHERE id=?")->execute([$at, $id]);
    audit_log('payments.notice_rescheduled', ['batch_id' => $id, 'send_at' => $at, 'by' => $by], $uid);
    return null;
}


/** Przypisuje kontrahentowi CRM kolejny wolny numer z puli serii „inni” (na jego kartotece w CRM). */
function pp_vnrb_assign_crm_one(int $contact_id, string $by, ?int $uid): string|array {
    pp_migrate();
    if (db_one("SELECT 1 FROM pp_vnrb_crm WHERE contact_id=?", [$contact_id])) return 'Ten kontrahent ma już nadany numer rachunku.';
    if (!pp_crm_is_client($contact_id)) return 'Rachunek do wpłat można wygenerować tylko dla kontrahenta ze statusem „Klient”.';
    $p = db_one("SELECT nrb FROM pp_vnrb_pool WHERE grp='inni' AND participant_id IS NULL AND crm_contact_id IS NULL ORDER BY substr(nrb,15,12) LIMIT 1");
    if (!$p) return 'Brak wolnych numerów w puli serii „inni” — zamów numery w banku (panel płatności → Rachunki wirtualne).';
    db()->beginTransaction();
    try {
        db()->prepare("INSERT INTO pp_vnrb_crm (contact_id, nrb) VALUES (?,?)")->execute([$contact_id, $p['nrb']]);
        db()->prepare("UPDATE pp_vnrb_pool SET crm_contact_id=?, assigned_at=datetime('now') WHERE nrb=?")->execute([$contact_id, $p['nrb']]);
        audit_log('payments.vnrb_assigned_crm', ['contact_id' => $contact_id, 'nrb' => $p['nrb'], 'by' => $by], $uid);
        db()->commit();
    } catch (\Throwable $e) { db()->rollBack(); return 'Błąd: ' . $e->getMessage(); }
    return ['nrb' => $p['nrb'], 'fmt' => pp_nrb_format($p['nrb'])];
}


/** Wyklucza (lub przywraca) jedną osobę z niewysłanej wysyłki — wiadomość do niej nie wyjdzie, a numer zostaje „bez powiadomienia”. */
function pp_notice_exclude(int $batch_id, int $client_id, bool $exclude, string $by, ?int $uid): ?string {
    pp_migrate();
    $b = db_one("SELECT status FROM pp_notice_batches WHERE id=?", [$batch_id]);
    if (!$b || !in_array($b['status'], ['draft', 'approved'], true)) return 'Odbiorców można zmieniać tylko w szkicu lub zatwierdzonej, niewysłanej wysyłce.';
    if ($exclude) db()->prepare("INSERT OR IGNORE INTO pp_notice_exclusions (batch_id, client_id, by_name) VALUES (?,?,?)")->execute([$batch_id, $client_id, $by]);
    else db()->prepare("DELETE FROM pp_notice_exclusions WHERE batch_id=? AND client_id=?")->execute([$batch_id, $client_id]);
    audit_log($exclude ? 'payments.notice_excluded' : 'payments.notice_included', ['batch_id' => $batch_id, 'participant_id' => $client_id, 'by' => $by], $uid);
    return null;
}


/** Czy kontakt CRM ma status „Klient” (klucz statusu 'klient') — tylko takim generujemy rachunek do wpłat. */
function pp_crm_is_client(int $contact_id): bool {
    try { $r = crm_all("SELECT status FROM crm_contacts WHERE id=? AND COALESCE(crm_active,1)=1", [$contact_id]); } catch (\Throwable $e) { return false; }
    return $r && mb_strtolower(trim((string)$r[0]['status'])) === 'klient';
}


/**
 * Logowanie uczestnika do portalu /platnosci z panelu kursanta/rodzica (bez osobnego linku): zamyka bieżącą sesję PHP
 * panelu i zakłada sesję portalu. @return bool false gdy konto portalu jest zablokowane albo nie da się go założyć.
 */
function pp_portal_login_participant(int $participant_id, string $via): bool {
    pp_migrate();
    if (!db_one("SELECT 1 FROM k30_clients WHERE id=?", [$participant_id])) return false;
    $u = pp_user_ensure($participant_id, $via, null);
    if (is_string($u)) return false;
    $row = pp_user($participant_id);
    if (!$row || !(int)$row['is_active']) return false;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_name('szo_platnosci');
    session_id(bin2hex(random_bytes(16)));
    session_set_cookie_params(['lifetime' => 0, 'path' => '/platnosci', 'httponly' => true, 'samesite' => 'Lax',
                               'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
    $_SESSION['pp_participant'] = ['participant_id' => $participant_id, 'ts' => time()];
    $_SESSION['pp_csrf'] = bin2hex(random_bytes(16));
    db()->prepare("UPDATE payment_portal_users SET last_login_at=datetime('now') WHERE participant_id=?")->execute([$participant_id]);
    audit_log('payments.portal_login', ['participant_id' => $participant_id, 'via' => $via], null);
    return true;
}


/**
 * Faktura w Comarch Betterfly do pozycji „do zapłaty” (ręcznej / dowolnej): nabywca = uczestnik, jedna pozycja
 * (domyślny produkt TI, cena netto z kwoty pozycji wg ustawienia brutto/netto), termin płatności = termin pozycji,
 * rachunek = numer do wpłat uczestnika. Faktura zostaje w buforze Betterfly (bez zatwierdzenia) — zatwierdza się ją
 * w Betterfly albo w obiegu akceptacji. @return string komunikat błędu albo array{number:string, local_id:int}
 */
function pp_item_betterfly_invoice(int $item_id, string $by, ?int $uid, int $product_id = 0): string|array {
    pp_migrate();
    require_once dirname(__DIR__, 3) . '/includes/betterfly.php';
    require_once dirname(__DIR__, 3) . '/includes/betterfly_invoices.php';
    $it = db_one("SELECT * FROM payable_items WHERE id=?", [$item_id]);
    if (!$it) return 'Nie znaleziono pozycji.';
    if ($it['status'] === 'cancelled') return 'Pozycja jest anulowana.';
    if (db_one("SELECT 1 FROM pp_item_invoices WHERE item_id=?", [$item_id])) return 'Do tej pozycji wystawiono już fakturę.';
    if (!BetterFlyClient::isEnabled()) return 'Integracja Comarch Betterfly nie jest włączona.';
    try {
        $pid = (int)$it['participant_id'];
        $buyer = betterfly_buyer_from_ti_client($pid);
        $items = [[
            'ProductId'            => $product_id > 0 ? $product_id : betterfly_ti_product_id(0),
            'Quantity'             => 1.0,
            'ProductCurrencyPrice' => betterfly_ti_unit_net((float)$it['amount']),
            'ProductDescription'   => mb_substr((string)$it['title'], 0, 200),
            'VatRateId'            => (int)org_setting('betterfly_default_vat_rate_id'),
        ]];
        $r = betterfly_issue_crm_invoice($buyer, $items, [
            'payment_deadline'    => $it['due_date'] ?: date('Y-m-d', strtotime('+7 days')),
            'bank_account_number' => betterfly_ti_bank_account($pid),
            'description'         => 'Do zapłaty: ' . $it['title'] . ' · tytuł przelewu: ' . pp_item_transfer_title($it),
            'uid'                 => (int)$uid,
        ]);
    } catch (\Throwable $e) { return 'Betterfly: ' . $e->getMessage(); }
    db()->prepare("INSERT OR REPLACE INTO pp_item_invoices (item_id, local_id, betterfly_invoice_id, number, by_name) VALUES (?,?,?,?,?)")
        ->execute([$item_id, (int)$r['local_id'], (int)$r['betterfly_invoice_id'], (string)$r['number'], $by]);
    audit_log('payments.item_invoice', ['item_id' => $item_id, 'participant_id' => (int)$it['participant_id'], 'number' => (string)$r['number'], 'betterfly_invoice_id' => (int)$r['betterfly_invoice_id'], 'by' => $by], $uid);
    return ['number' => (string)$r['number'], 'local_id' => (int)$r['local_id']];
}


/**
 * Tytuł przelewu przypisany do KAŻDEGO zobowiązania (pozycji „do zapłaty”): „ZOB/{id} {nazwa pozycji} — {uczestnik}”
 * (do 140 znaków). Kod ZOB/{id} jednoznacznie wskazuje pozycję przy ręcznym dopasowaniu wpływu z wyciągu.
 */
function pp_item_transfer_title(array $item, string $participant_name = ''): string {
    if ($participant_name === '' && !empty($item['participant_id'])) {
        $participant_name = (string)(db_one("SELECT name FROM k30_clients WHERE id=?", [(int)$item['participant_id']])['name'] ?? '');
    }
    $head = 'ZOB/' . (int)$item['id'] . ' ';
    $tail = $participant_name !== '' ? ' — ' . $participant_name : '';
    $room = 140 - mb_strlen($head) - mb_strlen($tail);
    $t = preg_replace('/\s+/', ' ', trim((string)$item['title']));
    if (mb_strlen($t) > $room) $t = rtrim(mb_substr($t, 0, max(10, $room - 1))) . '…';
    return $head . $t . $tail;
}


/** Lista produktów z Betterfly (do wyboru przy fakturze), z krótką pamięcią podręczną w sesji. @return array{list:list<array>, error:string} */
function pp_bf_products(bool $refresh = false): array {
    if (session_status() === PHP_SESSION_ACTIVE && !$refresh && !empty($_SESSION['pp_bf_products']) && time() - (int)$_SESSION['pp_bf_products']['ts'] < 600)
        return ['list' => $_SESSION['pp_bf_products']['list'], 'error' => ''];
    try {
        require_once dirname(__DIR__, 3) . '/includes/betterfly.php';
        if (!BetterFlyClient::isEnabled()) return ['list' => [], 'error' => 'Integracja Betterfly jest wyłączona.'];
        $raw = BetterFlyClient::fromSettings()->listProducts();
        $list = [];
        foreach ($raw as $p) if (isset($p['Id'])) $list[] = ['id' => (int)$p['Id'], 'name' => (string)($p['Name'] ?? ('#' . $p['Id'])), 'code' => (string)($p['ProductCode'] ?? ''), 'net' => isset($p['SaleNetPrice']) ? (float)$p['SaleNetPrice'] : null];
        usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['pp_bf_products'] = ['ts' => time(), 'list' => $list];
        return ['list' => $list, 'error' => ''];
    } catch (\Throwable $e) { return ['list' => [], 'error' => $e->getMessage()]; }
}


// ── Wpływy do wyjaśnienia i cofanie księgowań (wpłaty po numerze wirtualnym) ─────────────────────────────

/** 26-cyfrowe numery rachunków z tekstu (tytuł/referencja): ciągi cyfr ze spacjami co 4, opcjonalnie z „PL”. @return list<string> */
function pp_extract_nrbs(string $text): array {
    $out = [];
    if (preg_match_all('/(?:PL)?\s?(\d{2}(?:\s?\d{4}){6})/i', $text, $m)) foreach ($m[1] as $x) { $d = preg_replace('/\D/', '', $x); if (strlen($d) === 26 && pp_nrb_valid($d)) $out[] = $d; }
    return array_values(array_unique($out));
}

/** Czy numer należy do naszej puli rachunków (ten sam bank + RRRR). */
function pp_nrb_is_ours(string $nrb): bool { return strlen($nrb) === 26 && substr($nrb, 2, 12) === pp_vnrb_bank() . pp_vnrb_rrrr(); }

/**
 * Wpływy z wyciągów na nasze numery wirtualne, których autopost nie zaksięguje i które wymagają decyzji:
 * 'blocked' (numer zablokowany) albo 'unassigned' (numer z naszej puli, którego nikt nie ma).
 * @return list<array{bank:array, nrb:string, reason:string}>
 */
function pp_bank_unresolved(): array {
    pp_migrate();
    try {
        $bank = db_all("SELECT b.* FROM edok_bank_tx b LEFT JOIN pp_bank_matches m ON m.bank_tx_id=b.id LEFT JOIN pp_bank_autopost a ON a.bank_tx_id=b.id
                         WHERE b.znak='C' AND b.ignored=0 AND b.doc_id IS NULL AND m.id IS NULL AND a.bank_tx_id IS NULL ORDER BY b.data_waluty DESC, b.id DESC LIMIT 1000");
    } catch (\Throwable $e) { return []; }
    $owners = [];
    foreach (db_all("SELECT individual_nrb n FROM payment_portal_users WHERE individual_nrb IS NOT NULL AND individual_nrb!=''") as $u) $owners[pp_nrb_normalize((string)$u['n'])] = true;
    $out = [];
    foreach ($bank as $b) {
        $cands = array_filter(array_merge([pp_nrb_normalize((string)$b['account_nrb'])], pp_extract_nrbs((string)$b['tytul'] . ' ' . (string)$b['referencja'])), 'pp_nrb_is_ours');
        foreach ($cands as $n) {
            if (isset($owners[$n])) { if (pp_vnrb_is_blocked($n)) { $out[] = ['bank' => $b, 'nrb' => $n, 'reason' => 'blocked']; } break; }   // z właścicielem i nie zablokowany — księguje autopost
            $out[] = ['bank' => $b, 'nrb' => $n, 'reason' => 'unassigned']; break;
        }
    }
    return $out;
}

/** Ręczne zaksięgowanie wpływu na wskazanego uczestnika (wpływ „do wyjaśnienia”). */
function pp_bank_post_manual(int $bank_tx_id, int $participant_id, string $reason, string $by, ?int $uid): ?string {
    pp_migrate();
    if (mb_strlen(trim($reason)) < 5) return 'Podaj powód księgowania (min. 5 znaków).';
    $b = db_one("SELECT * FROM edok_bank_tx WHERE id=? AND znak='C' AND ignored=0 AND doc_id IS NULL", [$bank_tx_id]);
    if (!$b) return 'Nie znaleziono wpływu do zaksięgowania.';
    if (db_one("SELECT 1 FROM pp_bank_autopost WHERE bank_tx_id=?", [$bank_tx_id]) || db_one("SELECT 1 FROM pp_bank_matches WHERE bank_tx_id=?", [$bank_tx_id])) return 'Ten wpływ jest już zaksięgowany.';
    $cl = db_one("SELECT name FROM k30_clients WHERE id=?", [$participant_id]);
    if (!$cl) return 'Wybierz uczestnika.';
    $nrbs = array_merge([pp_nrb_normalize((string)$b['account_nrb'])], pp_extract_nrbs((string)$b['tytul'] . ' ' . (string)$b['referencja']));
    $nrb = ''; foreach ($nrbs as $n) if (pp_nrb_is_ours($n)) { $nrb = $n; break; }
    db()->beginTransaction();
    try {
        $pay = ti_payment_add($participant_id, (float)$b['kwota'], substr((string)$b['data_waluty'], 0, 10), 'transfer',
            'Wpływ z wyciągu (wyjaśniony ręcznie: ' . trim($reason) . ') — ' . trim($b['kontrahent_nazwa'] . ' ' . $b['tytul']), 'bank_tx', (int)$b['id']);
        db_insert('pp_bank_autopost', ['bank_tx_id' => (int)$b['id'], 'participant_id' => $participant_id, 'payment_id' => (int)($pay['payment_id'] ?? 0),
                                       'amount' => (float)$b['kwota'], 'nrb' => $nrb, 'by_name' => $by . ' (ręcznie)']);
        db()->prepare("UPDATE edok_bank_tx SET matched_how=?, matched_by_name=?, matched_at=datetime('now') WHERE id=? AND doc_id IS NULL AND matched_how=''")->execute(['platnosci:reczne', $by, (int)$b['id']]);
        audit_log('payments.bank_post_manual', ['bank_tx_id' => (int)$b['id'], 'participant_id' => $participant_id, 'amount' => (float)$b['kwota'], 'reason' => trim($reason), 'by' => $by], $uid);
        db()->commit();
    } catch (\Throwable $e) { db()->rollBack(); return 'Błąd: ' . $e->getMessage(); }
    return null;
}

/** „Pomiń” wpływ (nie dotyczy uczestników — np. wpłata obca): znika z listy do wyjaśnienia. */
function pp_bank_dismiss(int $bank_tx_id, string $reason, string $by, ?int $uid): ?string {
    if (mb_strlen(trim($reason)) < 5) return 'Podaj powód (min. 5 znaków).';
    $b = db_one("SELECT kwota FROM edok_bank_tx WHERE id=? AND znak='C' AND ignored=0 AND doc_id IS NULL", [$bank_tx_id]);
    if (!$b) return 'Nie znaleziono wpływu.';
    db()->prepare("UPDATE edok_bank_tx SET ignored=1 WHERE id=?")->execute([$bank_tx_id]);
    audit_log('payments.bank_dismissed', ['bank_tx_id' => $bank_tx_id, 'amount' => (float)$b['kwota'], 'reason' => trim($reason), 'by' => $by], $uid);
    return null;
}

/** Cofnięcie zaksięgowania wpłaty po numerze wirtualnym (automatycznego albo ręcznego): usuwa wpłatę z księgi i wpływ wraca do wyjaśnienia. */
function pp_bank_autopost_undo(int $bank_tx_id, string $reason, string $by, ?int $uid): ?string {
    pp_migrate();
    if (mb_strlen(trim($reason)) < 5) return 'Podaj powód cofnięcia (min. 5 znaków).';
    $a = db_one("SELECT * FROM pp_bank_autopost WHERE bank_tx_id=?", [$bank_tx_id]);
    if (!$a) return 'Ten wpływ nie został zaksięgowany automatycznie.';
    db()->beginTransaction();
    try {
        $pid = (int)$a['payment_id'];
        if ($pid <= 0) { $r = db_one("SELECT id FROM k30_ti_payments WHERE source_type='bank_tx' AND source_id=? AND client_id=? ORDER BY id DESC LIMIT 1", [$bank_tx_id, (int)$a['participant_id']]); $pid = (int)($r['id'] ?? 0); }
        if ($pid > 0) ti_payment_delete($pid);
        db()->prepare("DELETE FROM pp_bank_autopost WHERE bank_tx_id=?")->execute([$bank_tx_id]);
        db()->prepare("UPDATE edok_bank_tx SET matched_how='', matched_by_name=NULL, matched_at=NULL WHERE id=? AND matched_how LIKE 'platnosci:%'")->execute([$bank_tx_id]);
        audit_log('payments.bank_autopost_undo', ['bank_tx_id' => $bank_tx_id, 'participant_id' => (int)$a['participant_id'], 'amount' => (float)$a['amount'], 'payment_id' => $pid, 'reason' => trim($reason), 'by' => $by], $uid);
        db()->commit();
    } catch (\Throwable $e) { db()->rollBack(); return 'Błąd: ' . $e->getMessage(); }
    return null;
}
