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
 *   8 cyfr nr rozliczeniowy banku + 4 cyfry RRRR (identyfikator Klienta) + 12 cyfr NNNN
 * Część NNNN (12 cyfr) zależy od grupy — dzięki temu z samego numeru wiadomo, kto płaci:
 *   ti    — nr kursanta TI (k30_ti_student_accounts.student_no, już 12 cyfr) bez zmian
 *   nip   — „00" + NIP (10 cyfr)            — firma / kontrahent
 *   crm   — „8" + ID kontaktu CRM (11 cyfr) — kontrahent bez NIP (z NIP-em: grupa nip)
 *   id    — „9" + ID uczestnika (11 cyfr)   — uczestnik spoza TI (bez numeru kursanta)
 *   reczny— dowolne do 12 cyfr, dopełnione zerami z lewej
 */
/** Domyślny prefiks banku (PKO BP): 1020 2906 = nr rozliczeniowy, 3286 = RRRR Klienta. Nadpisują go ustawienia. */
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
function pp_vnrb_for_crm(int $contact_id, ?string $nip): ?string {
    $nip = preg_replace('/\D/', '', (string)$nip);
    $part = strlen($nip) === 10 ? pp_vnrb_part('nip', $nip) : pp_vnrb_part('crm', (string)$contact_id);
    $n = $part[0] === '!' ? null : pp_vnrb_build(pp_vnrb_bank(), pp_vnrb_rrrr(), $part);
    return $n ? pp_nrb_format($n) : null;
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
    $p = db_one("SELECT nrb FROM pp_vnrb_pool WHERE grp='ti' AND participant_id IS NULL ORDER BY nrb LIMIT 1");
    if (!$p) return null;
    if (!$cur && is_string(pp_user_ensure($client_id, $by, $uid))) return null;
    if (pp_set_nrb($client_id, $p['nrb'], $by, $uid) !== null) return null;
    db()->prepare("UPDATE pp_vnrb_pool SET participant_id=?, assigned_at=datetime('now') WHERE nrb=? AND participant_id IS NULL")->execute([$client_id, $p['nrb']]);
    return $p['nrb'];
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
    $pl = [1=>'styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'];
    $rows = db_all("SELECT b.*, c.name AS course_name FROM k30_ti_billing b LEFT JOIN k30_ti_courses c ON c.id=b.course_id AND b.course_id>0
                     WHERE b.status='issued'" . ($participant_id ? " AND b.client_id=?" : '') . " ORDER BY b.year, b.month",
                   $participant_id ? [$participant_id] : []);
    $n = 0;
    foreach ($rows as $b) {
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
/** Tytuł przelewu: stały format, który księgowość dopasowuje do transakcji. */
function pp_transfer_title(string $uuid, array $cl): string {
    return 'SZO ' . strtoupper(substr(str_replace('-', '', $uuid), 0, 10)) . ' ' . mb_substr(preg_replace('/\s+/', ' ', (string)$cl['name']), 0, 40);
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
            'payment_method' => $method, 'status' => 'pending', 'transfer_title' => pp_transfer_title($uuid, ['name' => $u['participant_name']]),
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
                     WHERE u.individual_nrb IS NULL OR u.individual_nrb='' ORDER BY a.client_id");
    foreach ($rows as $row) {
        $cid = (int)$row['client_id'];
        $p = db_one("SELECT nrb FROM pp_vnrb_pool WHERE grp='ti' AND participant_id IS NULL" . ($batch !== null ? " AND batch=?" : "") . " ORDER BY nrb LIMIT 1", $batch !== null ? [$batch] : []);
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
                          LEFT JOIN k30_clients c ON c.id=p.participant_id WHERE p.batch=? ORDER BY p.nrb", [$b]);
    } else {
        $rows = db_all("SELECT c.id client_id, c.name, COALESCE(u.individual_nrb,'') nrb, COALESCE(p.grp,'') grp, COALESCE(p.assigned_at,'') assigned_at
                          FROM k30_clients c
                          LEFT JOIN payment_portal_users u ON u.participant_id=c.id
                          LEFT JOIN pp_vnrb_pool p ON p.nrb=u.individual_nrb
                         WHERE EXISTS (SELECT 1 FROM k30_ti_student_accounts a WHERE a.client_id=c.id)
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
