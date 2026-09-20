<?php
/**
 * modules/sprawdz_konto/logic/sprawdz_konto.php
 *
 * Logika publicznej (bez logowania) sprawdzarki numeru konta do wpłat
 * — dla kursanta TI oraz dla kontrahenta z dowolnej umowy Rejestru Umów.
 *
 * Zasady bezpieczeństwa/RODO (patrz modules/sprawdz_konto/index.php):
 *  - Dopasowanie wymaga DWÓCH pól łącznie (identyfikator + imię i nazwisko /
 *    nazwa) — nie da się przeglądać danych po samym identyfikatorze.
 *  - Komunikat o braku dopasowania jest zawsze taki sam, niezależnie od tego,
 *    które pole się nie zgadza — nie ujawnia, czy identyfikator w ogóle istnieje.
 *  - Kontrahent nie ma dziś indywidualnego konta — po potwierdzeniu tożsamości
 *    dostaje to samo oficjalne konto organizacji co moduł TI
 *    (k30_ti_org_account(), includes/karty30.php), co chroni przed podmianą
 *    numeru konta na fakturze/w mailu (oszustwo BEC), a nie ujawnia niczyich
 *    danych bankowych.
 *  - Każda próba (udana i nieudana) jest logowana (sprawdz_konto_log) bez
 *    przechowywania podanego imienia i nazwiska — tylko identyfikator, wynik,
 *    IP i user-agent — do rozliczalności (RODO art. 5 ust. 2) i wykrywania
 *    nadużyć, z rotacją po 90 dniach (patrz sprawdz_konto_cleanup_old_logs()).
 *
 * Kolumny "nazwa strony umowy" per typ umowy — jak w includes/functions.php
 * (CONTRACT_REGISTRY_FIELDS) — z wyjątkiem 'powierzenie', gdzie ta stała mapuje
 * na nazwa_zadania (nazwa zadania, nie strona umowy) i dlatego typ ten jest
 * tu pominięty — nie nadaje się do weryfikacji tożsamości kontrahenta.
 */

const SPRAWDZ_KONTO_CONTRACT_NAME_COLS = [
    'zlecenie'    => 'imie_nazwisko',
    'uslugi'      => 'nazwa_wykonawcy',
    'wolontariat' => 'imie_nazwisko',
    'dzielo'      => 'imie_nazwisko',
    'praca'       => 'imie_nazwisko',
    'inne'        => 'strona_umowy',
];

function sprawdz_konto_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
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
}

/** Normalizacja do porównań: przycina, składa białe znaki, małe litery (mb, PL). */
function sprawdz_konto_normalize(string $s): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_strtolower($s, 'UTF-8');
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
 * Sprawdzenie kursanta: login panelu + imię i nazwisko (k30_clients.name).
 * @return array{numer_konta:string,typ_konta:string}|null
 */
function sprawdz_konto_lookup_kursant(string $login, string $imie_nazwisko): ?array {
    $login = trim($login);
    if ($login === '' || trim($imie_nazwisko) === '') return null;

    $row = db_one(
        "SELECT a.payment_bank_account, a.payment_bank_account_source, cl.name
         FROM k30_ti_student_accounts a
         JOIN k30_clients cl ON cl.id = a.client_id
         WHERE a.login = ? COLLATE NOCASE",
        [$login]
    );
    if (!$row) return null;
    if (sprawdz_konto_normalize($row['name']) !== sprawdz_konto_normalize($imie_nazwisko)) return null;

    $account = trim((string)($row['payment_bank_account'] ?? ''));
    if ($account !== '') {
        $typ = ($row['payment_bank_account_source'] ?? '') === 'org'
            ? 'Rachunek organizacji (zatwierdzony indywidualnie)'
            : 'Numer indywidualny (zatwierdzony dla tego kursanta)';
        return ['numer_konta' => $account, 'typ_konta' => $typ];
    }

    // Brak indywidualnie zatwierdzonego numeru — domyślne konto organizacji dla TI.
    require_once __DIR__ . '/../../../includes/karty30.php';
    $org = k30_ti_org_account();
    if ($org['nrb'] === '') return null;
    return ['numer_konta' => $org['iban'] ?: $org['nrb'], 'typ_konta' => 'Domyślny rachunek organizacji (dla TI)'];
}

/**
 * Sprawdzenie kontrahenta: numer umowy + imię i nazwisko / nazwa, w dowolnej
 * tabeli umów. Po potwierdzeniu tożsamości zwraca oficjalne konto organizacji
 * (patrz nagłówek pliku — kontrahent nie ma indywidualnego konta).
 * @return array{numer_konta:string,typ_konta:string}|null
 */
function sprawdz_konto_lookup_kontrahent(string $numer_umowy, string $imie_nazwisko): ?array {
    $numer_umowy = trim($numer_umowy);
    if ($numer_umowy === '' || trim($imie_nazwisko) === '') return null;

    require_once __DIR__ . '/../../../includes/functions.php';
    $matches = [];
    foreach (SPRAWDZ_KONTO_CONTRACT_NAME_COLS as $slug => $name_col) {
        $table = table_for_type($slug);
        try {
            $rows = db_all(
                "SELECT {$name_col} AS strona FROM {$table} WHERE numer_umowy = ? COLLATE NOCASE",
                [$numer_umowy]
            );
        } catch (\Throwable $e) { continue; }
        foreach ($rows as $r) {
            if (sprawdz_konto_normalize((string)$r['strona']) === sprawdz_konto_normalize($imie_nazwisko)) {
                $matches[] = $slug;
            }
        }
    }

    // Dokładnie jedno dopasowanie — więcej niż jedno traktujemy jak brak
    // (numer_umowy nie ma constraintu UNIQUE w bazie — nie zgadujemy, która
    // umowa jest właściwa; niejednoznaczność sama w sobie jest nietypowa,
    // ale ujawnienie w takim wypadku danych osobie z zewnątrz byłoby ryzykowne).
    if (count($matches) !== 1) return null;

    require_once __DIR__ . '/../../../includes/karty30.php';
    $org = k30_ti_org_account();
    if ($org['nrb'] === '') return null;
    return ['numer_konta' => $org['iban'] ?: $org['nrb'], 'typ_konta' => 'Oficjalny rachunek organizacji'];
}
