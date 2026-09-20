<?php
/**
 * modules/sprawdz_konto/logic/sprawdz_konto.php
 *
 * Logika publicznej (bez logowania) sprawdzarki numeru konta do wpłat
 * — dla kursanta TI oraz dla kontrahenta z dowolnej umowy Rejestru Umów.
 *
 * Mechanizm dostępu: WYŁĄCZNIE spersonalizowany link z tokenem w adresie
 * (jak helpdesk/track.php) — bez publicznego formularza z wpisywaniem
 * identyfikatora/nazwiska. Token (256-bitowy, losowy) sam w sobie jest
 * dowodem tożsamości: nikt poza odbiorcą maila go nie zna, więc strona nie
 * musi (i nie powinna) dodatkowo pytać o dane osobowe. Generowanie:
 *  - kursant: karty30/ti/dydaktyk/konta.php, akcja payment_account_confirm
 *    (token odświeżany przy każdym zatwierdzeniu numeru konta),
 *  - kontrahent: modules/sprawdz_konto/admin_send.php (akcja ręczna
 *    pracownika — kontrahent nie ma własnego konta w systemie, więc nie ma
 *    zdarzenia, które mogłoby wygenerować link automatycznie).
 *
 * Zasady bezpieczeństwa/RODO (patrz modules/sprawdz_konto/index.php):
 *  - Kontrahent nie ma dziś indywidualnego konta — token tylko potwierdza,
 *    że link trafił do właściwej osoby/firmy, a strona i tak pokazuje to
 *    samo oficjalne konto organizacji co moduł TI (k30_ti_org_account(),
 *    includes/karty30.php) — chroni to przed podmianą numeru konta na
 *    fakturze/w mailu (oszustwo BEC), bez ujawniania niczyich danych
 *    bankowych.
 *  - Każda próba odczytu tokenu (udana i nieudana) jest logowana
 *    (sprawdz_konto_log) — typ, token (obcięty), wynik, IP, user-agent —
 *    do rozliczalności (RODO art. 5 ust. 2) i wykrywania prób zgadywania
 *    tokenów, z rotacją po 90 dniach (sprawdz_konto_cleanup_old_logs()).
 *  - Token wygasa po 180 dniach (SPRAWDZ_KONTO_TOKEN_DAYS) i jest
 *    jednoznacznie unieważniany przy wygenerowaniu nowego dla tego samego
 *    odbiorcy (sprawdz_konto_revoke_tokens_for()) — tylko jeden aktywny
 *    link naraz.
 *
 * Kolumny "nazwa strony umowy"/"e-mail" per typ umowy — jak w
 * includes/functions.php (CONTRACT_REGISTRY_FIELDS), z wyjątkiem
 * 'powierzenie' (name_col tam to nazwa_zadania, nie strona umowy) —
 * pominięte, bo admin_send.php pokazuje pracownikowi znalezioną nazwę
 * przed wysyłką, a to pole nie nadaje się do tego celu.
 */

require_once __DIR__ . '/../../../includes/karty30.php';
require_once __DIR__ . '/../../../includes/ti_payment_account_schema.php';

const SPRAWDZ_KONTO_CONTRACT_NAME_COLS = [
    'zlecenie'    => 'imie_nazwisko',
    'uslugi'      => 'nazwa_wykonawcy',
    'wolontariat' => 'imie_nazwisko',
    'dzielo'      => 'imie_nazwisko',
    'praca'       => 'imie_nazwisko',
    'inne'        => 'strona_umowy',
];

// Kolumna adresu e-mail per typ umowy — umowy_praca ma email_login, nie email.
const SPRAWDZ_KONTO_CONTRACT_EMAIL_COLS = [
    'zlecenie'    => 'email',
    'uslugi'      => 'email',
    'wolontariat' => 'email',
    'dzielo'      => 'email',
    'praca'       => 'email_login',
    'inne'        => 'email',
];

// Ważność linku z tokenem — wysyłany ponownie przy każdym zatwierdzeniu/
// wygenerowaniu, więc nie musi być wieczny (RODO — ograniczenie przechowywania).
const SPRAWDZ_KONTO_TOKEN_DAYS = 180;

function sprawdz_konto_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    // Kolumny payment_bank_account (karty30_migrate) i payment_bank_account_source
    // (require w nagłówku pliku, IIFE) — ta strona jest pierwszym i jedynym
    // wejściem do systemu, które NIE przechodzi przez karty30/ti/dydaktyk/konta.php,
    // więc samonaprawa schematu może tu nigdy się nie uruchomić, jeśli nie
    // wymusimy jej jawnie.
    karty30_migrate();
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS sprawdz_konto_log (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            typ           VARCHAR(20) NOT NULL,
            identyfikator VARCHAR(100) NOT NULL DEFAULT '',
            matched       INTEGER NOT NULL DEFAULT 0,
            ip            TEXT NOT NULL DEFAULT '',
            user_agent    TEXT NOT NULL DEFAULT '',
            created_at    DATETIME NOT NULL
        )");
    } catch (\Throwable $e) {}
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS sprawdz_konto_tokens (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            token           VARCHAR(64) NOT NULL,
            typ             VARCHAR(20) NOT NULL,
            ref_type        VARCHAR(20) NOT NULL DEFAULT '',
            ref_id          INTEGER NOT NULL,
            created_by      INTEGER,
            revoked         INTEGER NOT NULL DEFAULT 0,
            created_at      DATETIME NOT NULL,
            expires_at      DATETIME NOT NULL,
            first_viewed_at DATETIME,
            last_viewed_at  DATETIME,
            view_count      INTEGER NOT NULL DEFAULT 0
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sprawdz_konto_tokens_token ON sprawdz_konto_tokens(token)");
    } catch (\Throwable $e) {}
    // Instalacje sprzed dodania śledzenia odsłon — kolumny mogły nie powstać
    // razem z CREATE TABLE powyżej (tabela już istniała).
    foreach (['first_viewed_at' => 'DATETIME', 'last_viewed_at' => 'DATETIME', 'view_count' => 'INTEGER NOT NULL DEFAULT 0'] as $col => $def) {
        try { db()->exec("ALTER TABLE sprawdz_konto_tokens ADD COLUMN {$col} {$def}"); } catch (\Throwable $e) {}
    }
}

// ── Ograniczenie liczby prób (po IP) ─────────────────────────────────────────
// Reużywa tabeli login_attempts z includes/auth_security.php (ten sam wzorzec
// throttlingu co logowanie) z osobnym prefiksem identyfikatora — bez pojęcia
// "konta" do zablokowania (to formularz anonimowy), więc tylko limit per IP.

const SPRAWDZ_KONTO_MAX_ATTEMPTS = 8;
const SPRAWDZ_KONTO_WINDOW_SEC   = 900; // 15 min

function sprawdz_konto_rate_limited(): bool {
    require_once __DIR__ . '/../../../includes/auth_security.php';
    _auth_security_init();
    $ip    = _auth_ip();
    $since = date('Y-m-d H:i:s', time() - SPRAWDZ_KONTO_WINDOW_SEC);
    $cnt = db_one(
        "SELECT COUNT(*) AS c FROM login_attempts WHERE identifier=? AND created_at > ?",
        ['sprawdz_konto:' . $ip, $since]
    );
    return (int)($cnt['c'] ?? 0) >= SPRAWDZ_KONTO_MAX_ATTEMPTS;
}

function sprawdz_konto_record_attempt(): void {
    require_once __DIR__ . '/../../../includes/auth_security.php';
    _auth_security_init();
    $ip = _auth_ip();
    db()->prepare("INSERT INTO login_attempts (identifier, ip) VALUES (?, ?)")
        ->execute(['sprawdz_konto:' . $ip, $ip]);
}

/** Log próby — bez imienia/nazwiska, patrz nagłówek pliku. */
function sprawdz_konto_log(string $typ, string $identyfikator, bool $matched): void {
    sprawdz_konto_migrate();
    require_once __DIR__ . '/../../../includes/auth_security.php';
    try {
        db()->prepare(
            "INSERT INTO sprawdz_konto_log (typ, identyfikator, matched, ip, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$typ, mb_substr($identyfikator, 0, 100), $matched ? 1 : 0, _auth_ip(), _auth_ua(), date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {}
}

/** Retencja logów — wywoływana z crona (patrz cron/sprawdz_konto_log_cleanup.php). */
function sprawdz_konto_cleanup_old_logs(int $days = 90): int {
    sprawdz_konto_migrate();
    $before = date('Y-m-d H:i:s', time() - $days * 86400);
    $stmt = db()->prepare("DELETE FROM sprawdz_konto_log WHERE created_at < ?");
    $stmt->execute([$before]);
    return $stmt->rowCount();
}

/**
 * Konto kursanta po ID (bez dopasowania nazwiska — tożsamość jest już
 * potwierdzona posiadaniem tokenu, patrz sprawdz_konto_resolve_token()).
 * @return array{numer_konta:string,typ_konta:string}|null
 */
function sprawdz_konto_kursant_account_by_id(int $account_id): ?array {
    $row = db_one(
        "SELECT payment_bank_account, payment_bank_account_source
         FROM k30_ti_student_accounts WHERE id = ?",
        [$account_id]
    );
    if (!$row) return null;

    $account = trim((string)($row['payment_bank_account'] ?? ''));
    if ($account !== '') {
        $typ = ($row['payment_bank_account_source'] ?? '') === 'org'
            ? 'Rachunek organizacji (zatwierdzony indywidualnie)'
            : 'Numer indywidualny (zatwierdzony dla tego kursanta)';
        return ['numer_konta' => $account, 'typ_konta' => $typ];
    }

    // Brak indywidualnie zatwierdzonego numeru — domyślne konto organizacji dla TI.
    $org = k30_ti_org_account();
    if ($org['nrb'] === '') return null;
    return ['numer_konta' => $org['iban'] ?: $org['nrb'], 'typ_konta' => 'Domyślny rachunek organizacji (dla TI)'];
}

/**
 * Oficjalne konto organizacji dla kontrahenta (patrz nagłówek pliku —
 * kontrahenci nie mają dziś indywidualnych kont, więc token tylko
 * potwierdza, że link trafił do właściwej osoby/firmy).
 * @return array{numer_konta:string,typ_konta:string}|null
 */
function sprawdz_konto_kontrahent_official_account(): ?array {
    $org = k30_ti_org_account();
    if ($org['nrb'] === '') return null;
    return ['numer_konta' => $org['iban'] ?: $org['nrb'], 'typ_konta' => 'Oficjalny rachunek organizacji'];
}

/**
 * Wyszukanie konkretnej umowy po typie i numerze — narzędzie DLA PRACOWNIKA
 * (modules/sprawdz_konto/admin_send.php, zalogowany, uprawniony), do
 * wskazania komu wysłać link. Nie służy do publicznej weryfikacji tożsamości
 * (stąd brak limitu prób/anty-enumeracji — użytkownik jest już zalogowany
 * i ma uprawnienia edycji umów).
 * @return array{id:int,name:string,email:string}|null
 */
function sprawdz_konto_find_kontrahent_contract(string $type, string $numer_umowy): ?array {
    if (!isset(SPRAWDZ_KONTO_CONTRACT_NAME_COLS[$type])) return null;
    require_once __DIR__ . '/../../../includes/functions.php';
    $table     = table_for_type($type);
    $name_col  = SPRAWDZ_KONTO_CONTRACT_NAME_COLS[$type];
    $email_col = SPRAWDZ_KONTO_CONTRACT_EMAIL_COLS[$type];
    $row = db_one(
        "SELECT id, {$name_col} AS strona, {$email_col} AS adres_email
         FROM {$table} WHERE numer_umowy = ? COLLATE NOCASE",
        [trim($numer_umowy)]
    );
    if (!$row) return null;
    return ['id' => (int)$row['id'], 'name' => (string)$row['strona'], 'email' => trim((string)($row['adres_email'] ?? ''))];
}

// ── Tokeny (linki spersonalizowane) ──────────────────────────────────────────

/** Unieważnia poprzednie aktywne tokeny dla tego samego odbiorcy — jeden aktualny link naraz. */
function sprawdz_konto_revoke_tokens_for(string $typ, string $ref_type, int $ref_id): void {
    sprawdz_konto_migrate();
    db()->prepare(
        "UPDATE sprawdz_konto_tokens SET revoked=1 WHERE typ=? AND ref_type=? AND ref_id=? AND revoked=0"
    )->execute([$typ, $ref_type, $ref_id]);
}

function sprawdz_konto_create_token(string $typ, string $ref_type, int $ref_id, ?int $created_by = null): string {
    sprawdz_konto_migrate();
    sprawdz_konto_revoke_tokens_for($typ, $ref_type, $ref_id);
    $token = bin2hex(random_bytes(32));
    db_insert('sprawdz_konto_tokens', [
        'token'      => $token,
        'typ'        => $typ,
        'ref_type'   => $ref_type,
        'ref_id'     => $ref_id,
        'created_by' => $created_by,
        'revoked'    => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + SPRAWDZ_KONTO_TOKEN_DAYS * 86400),
    ]);
    return $token;
}

function sprawdz_konto_token_url(string $token): string {
    return rtrim(APP_URL, '/') . '/modules/sprawdz_konto/?t=' . $token;
}

/**
 * Rozwiązuje token z linku na dane do wyświetlenia — jedyna droga wejścia
 * na publiczną stronę (modules/sprawdz_konto/index.php). Sam format tokenu
 * (64 znaki hex) jest sprawdzany przed zapytaniem do bazy, jak w
 * helpdesk/track.php.
 * @return array{numer_konta:string,typ_konta:string}|null
 */
function sprawdz_konto_resolve_token(string $token): ?array {
    sprawdz_konto_migrate();
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    $row = db_one("SELECT * FROM sprawdz_konto_tokens WHERE token = ? AND revoked = 0", [$token]);
    if (!$row) return null;
    if (strtotime($row['expires_at']) < time()) return null;

    $result = $row['typ'] === 'kursant'
        ? sprawdz_konto_kursant_account_by_id((int)$row['ref_id'])
        : sprawdz_konto_kontrahent_official_account();

    if ($result) {
        $now = date('Y-m-d H:i:s');
        db()->prepare(
            "UPDATE sprawdz_konto_tokens
             SET first_viewed_at = COALESCE(first_viewed_at, ?), last_viewed_at = ?, view_count = view_count + 1
             WHERE id = ?"
        )->execute([$now, $now, $row['id']]);
    }

    return $result;
}

/**
 * Lista wysłanych linków dla panelu admina (modules/sprawdz_konto/admin_list.php)
 * — status, odbiorca/kontekst, statystyki odsłon. Rozwiązuje odbiorcę per typ,
 * bez ujawniania numeru konta (to osobna akcja "Podgląd" w panelu, na żądanie).
 */
function sprawdz_konto_list_tokens(int $limit = 300): array {
    sprawdz_konto_migrate();
    require_once __DIR__ . '/../../../includes/functions.php';
    $rows = db_all("SELECT * FROM sprawdz_konto_tokens ORDER BY created_at DESC LIMIT ?", [$limit]);
    $now = time();

    foreach ($rows as &$r) {
        $r['status'] = $r['revoked']
            ? 'unieważniony'
            : (strtotime($r['expires_at']) < $now ? 'wygasł' : 'aktywny');

        if ($r['typ'] === 'kursant') {
            $acc = db_one(
                "SELECT cl.name FROM k30_ti_student_accounts a
                 JOIN k30_clients cl ON cl.id = a.client_id WHERE a.id = ?",
                [(int)$r['ref_id']]
            );
            $r['odbiorca'] = $acc['name'] ?? '(konto kursanta usunięte)';
            $r['kontekst'] = 'Kursant TI';
        } else {
            $type = $r['ref_type'];
            $r['odbiorca'] = '(umowa usunięta)';
            $r['kontekst'] = CONTRACT_TYPES[$type] ?? $type;
            if (isset(SPRAWDZ_KONTO_CONTRACT_NAME_COLS[$type])) {
                $table    = table_for_type($type);
                $name_col = SPRAWDZ_KONTO_CONTRACT_NAME_COLS[$type];
                try {
                    $c = db_one("SELECT {$name_col} AS strona, numer_umowy FROM {$table} WHERE id = ?", [(int)$r['ref_id']]);
                    if ($c) {
                        $r['odbiorca'] = (string)$c['strona'];
                        $r['kontekst'] .= ' · ' . $c['numer_umowy'];
                    }
                } catch (\Throwable $e) {}
            }
        }

        $r['created_by_name'] = '';
        if (!empty($r['created_by'])) {
            $u = db_one("SELECT name FROM users WHERE id = ?", [(int)$r['created_by']]);
            $r['created_by_name'] = $u['name'] ?? '';
        }
    }
    unset($r);

    return $rows;
}

// ── Wysyłka linku e-mailem ────────────────────────────────────────────────────
// Dla kursanta token generowany jest bezpośrednio w karty30/ti/dydaktyk/konta.php
// (sprawdz_konto_create_token('kursant', 'ti', $aid) + sprawdz_konto_token_url())
// — tamten handler ma już gotową (i wielo-odbiorczą: kursant + opiekun)
// logikę budowania i wysyłki maila ti_payment_account, więc nie ma potrzeby
// duplikować jej tutaj osobnym wrapperem.

/** Wysyła link kontrahentowi — wołane z modules/sprawdz_konto/admin_send.php. */
function sprawdz_konto_send_kontrahent_link(string $type, int $contract_id, string $email, string $name, ?int $sent_by = null): bool {
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    require_once __DIR__ . '/../../../includes/mail_queue.php';
    require_once __DIR__ . '/../../../includes/email_templates.php';

    $token = sprawdz_konto_create_token('kontrahent', $type, $contract_id, $sent_by);
    $org   = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
    $rendered = email_tpl_render('sprawdz_konto_kontrahent', [
        'accent' => '#0d6efd',
        'osoba'  => $name,
        'link'   => sprawdz_konto_token_url($token),
        'org'    => $org,
    ]);
    if (!$rendered['enabled']) return false;
    mail_queue_add($email, $name, $rendered['subject'], $rendered['html'], '', 'sprawdz_konto_kontrahent', $contract_id);
    return true;
}
