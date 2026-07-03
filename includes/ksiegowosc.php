<?php
/**
 * EOD Dokumentów Księgowych (KDOK).
 *
 * Obsługuje oddzielną bazę danych (SQLite lub MySQL),
 * konfigurowaną przez admin → EOD Dokumentów Księgowych → Ustawienia.
 * Gdy kdok_db_type = 'main' (domyślnie), używa głównej bazy aplikacji.
 *
 * Role (tabela kdok_user_roles, zawsze w głównej bazie):
 *   upload     – może dodawać dokumenty
 *   meryt      – akceptacja merytoryczna
 *   formal     – akceptacja formalna i rachunkowa
 *   zatwierdza – zatwierdzenie do wypłaty
 * Admin ma wszystkie uprawnienia automatycznie.
 */

// ── Stałe ─────────────────────────────────────────────────────────────────────

const KDOK_TYPES = [
    'ksef'        => ['label' => 'Dokument z KSeF',                     'icon' => 'bi-receipt'],
    'ksef_reczny' => ['label' => 'Faktura pobrana ręcznie z KSeF',      'icon' => 'bi-receipt-cutoff'],
    'rachunek'    => ['label' => 'Rachunek do umowy',                   'icon' => 'bi-person-vcard'],
    'lista_plac'  => ['label' => 'Lista płac',                          'icon' => 'bi-people-fill'],
    'wyciag'      => ['label' => 'Wyciąg bankowy',                      'icon' => 'bi-bank'],
];

const KDOK_STATUSES = [
    'nowy'          => ['label' => 'Nowy',          'class' => 'secondary'],
    'w_obiegu'      => ['label' => 'W obiegu',      'class' => 'warning'],
    'zaakceptowany' => ['label' => 'Zaakceptowany', 'class' => 'success'],
    'odrzucony'     => ['label' => 'Odrzucony',     'class' => 'danger'],
];

const KDOK_STEPS = [
    'meryt'      => 'Sprawdzono merytorycznie',
    'formal'     => 'Sprawdzono formalnie i rachunkowo',
    'zatwierdza' => 'Zatwierdzono do wypłaty',
];

const KDOK_ROLES = [
    'upload'     => 'Może dodawać dokumenty',
    'meryt'      => 'Akceptacja merytoryczna',
    'formal'     => 'Akceptacja formalna i rachunkowa',
    'zatwierdza' => 'Zatwierdzenie do wypłaty',
];

// Typy umów, do których można podpiąć dokument typu "Rachunek do umowy" (zawsze w głównej bazie aplikacji)
const KDOK_CONTRACT_TYPES = [
    'zlecenie' => ['table' => 'umowy_zlecenie', 'label' => 'Umowa zlecenie', 'przedmiot' => 'przedmiot_zlecenia', 'url' => '/contracts/zlecenie/view.php?id='],
    'dzielo'   => ['table' => 'umowy_dzielo',   'label' => 'Umowa o dzieło', 'przedmiot' => 'opis_dziela',        'url' => '/contracts/dzielo/view.php?id='],
    'uslugi'   => ['table' => 'umowy_uslugi',   'label' => 'Umowa usługi',   'przedmiot' => 'przedmiot_uslugi',   'url' => '/contracts/uslugi/view.php?id='],
];

// ── Połączenie z bazą KDOK ────────────────────────────────────────────────────

function kdok_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $type = org_setting('kdok_db_type'); // 'main' | 'sqlite' | 'mysql'

    if ($type === 'sqlite') {
        $path = org_setting('kdok_db_sqlite_path');
        if (!$path) throw new RuntimeException('Ścieżka bazy SQLite dla KDOK nie jest skonfigurowana.');
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=OFF;');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    if ($type === 'mysql') {
        $host = org_setting('kdok_db_mysql_host') ?: 'localhost';
        $port = org_setting('kdok_db_mysql_port') ?: '3306';
        $name = org_setting('kdok_db_mysql_name');
        $user = org_setting('kdok_db_mysql_user');
        $pass = org_setting('kdok_db_mysql_pass');
        if (!$name || !$user) throw new RuntimeException('Baza MySQL dla KDOK nie jest w pełni skonfigurowana.');
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    // Domyślnie: główna baza aplikacji
    $pdo = db();
    return $pdo;
}

// Czy moduł używa oddzielnej bazy (nie może robić JOINów z users)?
function kdok_is_separate_db(): bool {
    $t = org_setting('kdok_db_type');
    return $t === 'sqlite' || $t === 'mysql';
}

// ── Helpery zapytań na bazie KDOK ─────────────────────────────────────────────

function kdok_one(string $sql, array $p = []): ?array {
    $s = kdok_db()->prepare($sql); $s->execute($p);
    return $s->fetch() ?: null;
}

function kdok_all(string $sql, array $p = []): array {
    $s = kdok_db()->prepare($sql); $s->execute($p);
    return $s->fetchAll();
}

function kdok_insert(string $table, array $data): int {
    foreach ($data as $k => &$v) {
        if ($v === '' && str_ends_with($k, '_id')) $v = null;
    }
    unset($v);
    $cols = implode(', ', array_keys($data));
    $phs  = ':' . implode(', :', array_keys($data));
    kdok_db()->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$phs})")->execute($data);
    return (int) kdok_db()->lastInsertId();
}

function kdok_exec(string $sql, array $p = []): void {
    kdok_db()->prepare($sql)->execute($p);
}

// ── Migracja ──────────────────────────────────────────────────────────────────

function kdok_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $kdb  = kdok_db();
    $mdb  = db(); // główna baza — tylko dla settings i user_roles
    $type = org_setting('kdok_db_type');
    $sep  = kdok_is_separate_db();

    // Tabele w bazie KDOK
    $kdb->exec("CREATE TABLE IF NOT EXISTS kdok_documents (
        id           INTEGER PRIMARY KEY " . ($type === 'mysql' ? 'AUTO_INCREMENT' : 'AUTOINCREMENT') . ",
        number       TEXT    NOT NULL,
        type         TEXT    NOT NULL,
        title        TEXT    NOT NULL,
        description  TEXT    NOT NULL DEFAULT '',
        uwagi        TEXT    NOT NULL DEFAULT '',
        grant_name   TEXT    NOT NULL DEFAULT '',
        mpk          TEXT    NOT NULL DEFAULT '',
        kwota        TEXT    NOT NULL DEFAULT '',
        creator_name TEXT    NOT NULL DEFAULT '',
        created_by   INTEGER,
        file_path    TEXT,
        file_sha256  TEXT,
        file_size    INTEGER,
        status       TEXT    NOT NULL DEFAULT 'nowy',
        created_at   TEXT    NOT NULL DEFAULT " . ($type === 'mysql' ? 'NOW()' : "(datetime('now'))") . ",
        updated_at   TEXT    NOT NULL DEFAULT " . ($type === 'mysql' ? 'NOW()' : "(datetime('now'))") . "
    )");

    // UNIQUE na number — SQLite i MySQL różnie
    if ($type === 'mysql') {
        try { $kdb->exec("ALTER TABLE kdok_documents ADD UNIQUE INDEX uniq_number (number(191))"); } catch (\Exception $e) {}
    } else {
        try { $kdb->exec("CREATE UNIQUE INDEX IF NOT EXISTS uniq_kdok_number ON kdok_documents(number)"); } catch (\Exception $e) {}
    }

    $kdb->exec("CREATE TABLE IF NOT EXISTS kdok_steps (
        id          INTEGER PRIMARY KEY " . ($type === 'mysql' ? 'AUTO_INCREMENT' : 'AUTOINCREMENT') . ",
        doc_id      INTEGER NOT NULL,
        step_type   TEXT    NOT NULL,
        status      TEXT    NOT NULL DEFAULT 'oczekuje',
        user_id     INTEGER,
        user_name   TEXT    NOT NULL DEFAULT '',
        decided_at  TEXT,
        notes       TEXT    NOT NULL DEFAULT ''
    )");

    $kdb->exec("CREATE TABLE IF NOT EXISTS kdok_history (
        id         INTEGER PRIMARY KEY " . ($type === 'mysql' ? 'AUTO_INCREMENT' : 'AUTOINCREMENT') . ",
        doc_id     INTEGER NOT NULL,
        user_id    INTEGER,
        user_name  TEXT    NOT NULL DEFAULT '',
        action     TEXT    NOT NULL,
        note       TEXT    NOT NULL DEFAULT '',
        ip         TEXT    NOT NULL DEFAULT '',
        created_at TEXT    NOT NULL DEFAULT " . ($type === 'mysql' ? 'NOW()' : "(datetime('now'))") . "
    )");

    $kdb->exec("CREATE TABLE IF NOT EXISTS kdok_generated_pdf (
        id           INTEGER PRIMARY KEY " . ($type === 'mysql' ? 'AUTO_INCREMENT' : 'AUTOINCREMENT') . ",
        doc_id       INTEGER NOT NULL,
        file_path    TEXT    NOT NULL,
        file_sha256  TEXT    NOT NULL,
        file_size    INTEGER NOT NULL DEFAULT 0,
        generated_by INTEGER,
        gen_name     TEXT    NOT NULL DEFAULT '',
        generated_at TEXT    NOT NULL DEFAULT " . ($type === 'mysql' ? 'NOW()' : "(datetime('now'))") . "
    )");

    // Tabela ról — ZAWSZE w głównej bazie (potrzebuje users)
    $mdb->exec("CREATE TABLE IF NOT EXISTS kdok_user_roles (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        role    TEXT    NOT NULL,
        UNIQUE(user_id, role)
    )");

    // Migracja schemy — dodaj nowe kolumny do istniejących tabel
    _kdok_add_columns($kdb, 'kdok_documents', [
        'uwagi'         => "TEXT NOT NULL DEFAULT ''",
        'grant_name'    => "TEXT NOT NULL DEFAULT ''",
        'mpk'           => "TEXT NOT NULL DEFAULT ''",
        'kwota'         => "TEXT NOT NULL DEFAULT ''",
        'creator_name'  => "TEXT NOT NULL DEFAULT ''",
        'miesiac'       => "INTEGER",
        'rok'           => "INTEGER",
        'contract_type' => "TEXT",
        'contract_id'   => "INTEGER",
    ]);
    _kdok_add_columns($kdb, 'kdok_steps', [
        'user_name'        => "TEXT NOT NULL DEFAULT ''",
        'cert_fingerprint' => "TEXT NOT NULL DEFAULT ''",
        'cert_subject'     => "TEXT NOT NULL DEFAULT ''",
        'cert_cn'          => "TEXT NOT NULL DEFAULT ''",
    ]);
    _kdok_add_columns($kdb, 'kdok_generated_pdf', [
        'gen_name' => "TEXT NOT NULL DEFAULT ''",
    ]);

    // Certyfikaty i IKAKS — zawsze w głównej bazie (przypisane do users)
    $mdb->exec("CREATE TABLE IF NOT EXISTS kdok_certificates (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id             INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        cert_pem            TEXT    NOT NULL,
        subject_cn          TEXT    NOT NULL DEFAULT '',
        subject_dn          TEXT    NOT NULL DEFAULT '',
        fingerprint_sha256  TEXT    NOT NULL DEFAULT '',
        fingerprint_sha1    TEXT    NOT NULL DEFAULT '',
        valid_from          TEXT    NOT NULL DEFAULT '',
        valid_to            TEXT    NOT NULL DEFAULT '',
        is_active           INTEGER NOT NULL DEFAULT 1,
        uploaded_at         TEXT    NOT NULL DEFAULT (datetime('now')),
        uploaded_by         INTEGER REFERENCES users(id)
    )");
    try { $mdb->exec("ALTER TABLE users ADD COLUMN kdok_ikaks_hash TEXT DEFAULT NULL"); } catch (\Exception $e) {}
    try { $mdb->exec("ALTER TABLE users ADD COLUMN kdok_ikaks_set_at TEXT DEFAULT NULL"); } catch (\Exception $e) {}

    // Domyślne ustawienia w głównej bazie
    foreach ([
        // Baza danych
        'kdok_db_type'          => 'main',
        'kdok_db_sqlite_path'   => '',
        'kdok_db_mysql_host'    => 'localhost',
        'kdok_db_mysql_port'    => '3306',
        'kdok_db_mysql_name'    => '',
        'kdok_db_mysql_user'    => '',
        'kdok_db_mysql_pass'    => '',
        'kdok_mpk_enabled'      => '0',
        'kdok_mpk_list'         => '',
        // eArchiwum (FTP + R2)
        'kdok_archive_enabled'  => '0',
        'kdok_ftp_enabled'      => '0',
        'kdok_ftp_host'         => '',
        'kdok_ftp_port'         => '21',
        'kdok_ftp_user'         => '',
        'kdok_ftp_pass'         => '',
        'kdok_ftp_path'         => '/kdok',
        'kdok_ftp_passive'      => '1',
        'kdok_r2_enabled'       => '0',
        'kdok_r2_account_id'    => '',
        'kdok_r2_access_key'    => '',
        'kdok_r2_secret_key'    => '',
        'kdok_r2_bucket'        => '',
        'kdok_r2_prefix'        => 'kdok',
        // Archiwum — PIN dostępu do przegladaj.php
        'kdok_przeglad_pin_hash'=> '',
        // KSeF
        'kdok_ksef_enabled'               => '0',
        'kdok_ksef_env'                   => 'production',
        'kdok_ksef_nip'                   => '',
        'kdok_ksef_token'                 => '',
        'kdok_ksef_last_sync'             => '',
        'kdok_ksef_public_key_cache_prod'  => '',
        'kdok_ksef_public_key_ts_prod'     => '',
        'kdok_ksef_public_key_cache_test'  => '',
        'kdok_ksef_public_key_ts_test'     => '',
        'kdok_ksef_pubkey_manual_production' => '',
        'kdok_ksef_pubkey_manual_demo'       => '',
        'kdok_ksef_pubkey_manual_test'       => '',
        'kdok_ksef_cert_pem_production'      => '',
        'kdok_ksef_key_pem_production'       => '',
        'kdok_ksef_key_pass_production'      => '',
        'kdok_ksef_cert_pem_demo'            => '',
        'kdok_ksef_key_pem_demo'             => '',
        'kdok_ksef_key_pass_demo'            => '',
        'kdok_ksef_cert_pem_test'            => '',
        'kdok_ksef_key_pem_test'             => '',
        'kdok_ksef_key_pass_test'            => '',
    ] as $key => $default) {
        if (!db_one("SELECT 1 FROM settings WHERE key_=?", [$key])) {
            $mdb->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$key, $default]);
        }
    }
}

function _kdok_add_columns(PDO $db, string $table, array $cols): void {
    foreach ($cols as $col => $def) {
        try { $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}"); }
        catch (\Exception $e) {} // kolumna już istnieje
    }
}

// ── IKAKS — Indywidualny Kod Autoryzacyjny ─────────────────────────────────────

function kdok_ikaks_set(int $user_id, string $plain): void {
    $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    $exists = db_one("SELECT 1 FROM users WHERE id=?", [$user_id]);
    if (!$exists) throw new RuntimeException('Użytkownik nie istnieje.');
    db()->prepare("UPDATE users SET kdok_ikaks_hash=?, kdok_ikaks_set_at=datetime('now') WHERE id=?")
         ->execute([$hash, $user_id]);
}

function kdok_ikaks_verify(int $user_id, string $plain): bool {
    $row = db_one("SELECT kdok_ikaks_hash FROM users WHERE id=?", [$user_id]);
    if (!$row || !$row['kdok_ikaks_hash']) return false;
    return password_verify($plain, $row['kdok_ikaks_hash']);
}

function kdok_ikaks_has(int $user_id): bool {
    $row = db_one("SELECT kdok_ikaks_hash FROM users WHERE id=?", [$user_id]);
    return !empty($row['kdok_ikaks_hash']);
}

// ── Certyfikaty X.509 ─────────────────────────────────────────────────────────

/**
 * Parsuje PEM certyfikatu i zwraca tablicę metadanych.
 * Rzuca RuntimeException przy błędzie.
 */
function kdok_cert_parse(string $pem): array {
    $cert = @openssl_x509_read($pem);
    if (!$cert) throw new RuntimeException('Nieprawidłowy certyfikat X.509 (PEM).');

    $info = openssl_x509_parse($cert);
    if (!$info) throw new RuntimeException('Nie można odczytać danych certyfikatu.');

    // Fingerprints
    $der = null;
    openssl_x509_export($cert, $pem_clean);
    $der_b64 = preg_replace('/-----[^-]+-----|[\r\n\s]/', '', $pem_clean);
    $der      = base64_decode($der_b64);
    $fp_sha256 = strtoupper(implode(':', str_split(hash('sha256', $der), 2)));
    $fp_sha1   = strtoupper(implode(':', str_split(sha1($der), 2)));

    // CN z subject
    $cn  = $info['subject']['CN'] ?? ($info['subject']['O'] ?? '');
    $dn  = '';
    if (!empty($info['subject'])) {
        $parts = [];
        foreach ($info['subject'] as $k => $v) {
            if (is_array($v)) $v = implode(', ', $v);
            $parts[] = $k . '=' . $v;
        }
        $dn = implode(', ', $parts);
    }

    return [
        'subject_cn'         => $cn,
        'subject_dn'         => $dn,
        'fingerprint_sha256' => $fp_sha256,
        'fingerprint_sha1'   => $fp_sha1,
        'valid_from'         => date('Y-m-d H:i:s', $info['validFrom_time_t'] ?? 0),
        'valid_to'           => date('Y-m-d H:i:s', $info['validTo_time_t']   ?? 0),
        'serial'             => $info['serialNumberHex'] ?? '',
        'issuer_cn'          => $info['issuer']['CN']    ?? '',
    ];
}

function kdok_cert_save(int $user_id, string $pem, int $uploaded_by): array {
    $meta = kdok_cert_parse($pem); // rzuci jeśli błąd

    // Dezaktywuj poprzednie certyfikaty
    db()->prepare("UPDATE kdok_certificates SET is_active=0 WHERE user_id=?")->execute([$user_id]);

    db_insert('kdok_certificates', [
        'user_id'           => $user_id,
        'cert_pem'          => trim($pem),
        'subject_cn'        => $meta['subject_cn'],
        'subject_dn'        => $meta['subject_dn'],
        'fingerprint_sha256'=> $meta['fingerprint_sha256'],
        'fingerprint_sha1'  => $meta['fingerprint_sha1'],
        'valid_from'        => $meta['valid_from'],
        'valid_to'          => $meta['valid_to'],
        'is_active'         => 1,
        'uploaded_by'       => $uploaded_by,
    ]);

    return $meta;
}

function kdok_cert_get(int $user_id): ?array {
    return db_one(
        "SELECT * FROM kdok_certificates WHERE user_id=? AND is_active=1 ORDER BY id DESC LIMIT 1",
        [$user_id]
    );
}

function kdok_cert_is_valid(?array $cert): bool {
    if (!$cert) return false;
    $now = time();
    return strtotime($cert['valid_from']) <= $now && $now <= strtotime($cert['valid_to']);
}

// ── WebAuthn — klucz sprzętowy wymagany do opisywania dokumentów ──────────────

const KDOK_WEBAUTHN_TTL      = 300;   // sekundy ważności świeżej weryfikacji kluczem (5 min, dotyk za każdym razem)
const KDOK_IKAKS_SESSION_TTL = 21600; // sekundy ważności sesji awaryjnej kodem IKAKS (6h, bez ponownego pytania)

// Zapamiętuje udaną weryfikację kluczem WebAuthn (wywoływane z ksiegowosc/webauthn_verify.php)
function kdok_webauthn_mark(int $user_id): void {
    $_SESSION['kdok_webauthn_uid'] = $user_id;
    $_SESSION['kdok_webauthn_at']  = time();
}

// Sprawdza i jednorazowo zużywa świeżą weryfikację kluczem — wymusza nowy dotyk klucza przy każdej akcji
function kdok_webauthn_check(int $user_id): bool {
    $ok = !empty($_SESSION['kdok_webauthn_uid'])
        && (int)$_SESSION['kdok_webauthn_uid'] === $user_id
        && (time() - (int)($_SESSION['kdok_webauthn_at'] ?? 0)) <= KDOK_WEBAUTHN_TTL;
    unset($_SESSION['kdok_webauthn_uid'], $_SESSION['kdok_webauthn_at']);
    return $ok;
}

// Zapamiętuje udane awaryjne użycie kodu IKAKS — w odróżnieniu od WebAuthn, sesja NIE jest
// zużywana po jednym sprawdzeniu: trwa KDOK_IKAKS_SESSION_TTL, żeby nie pytać o kod i powód
// przy każdej pojedynczej decyzji w ramach tej samej "sesji pracy" bez klucza.
function kdok_ikaks_mark(int $user_id): void {
    $_SESSION['kdok_ikaks_uid'] = $user_id;
    $_SESSION['kdok_ikaks_at']  = time();
}

function kdok_ikaks_session_ok(int $user_id): bool {
    return !empty($_SESSION['kdok_ikaks_uid'])
        && (int)$_SESSION['kdok_ikaks_uid'] === $user_id
        && (time() - (int)($_SESSION['kdok_ikaks_at'] ?? 0)) <= KDOK_IKAKS_SESSION_TTL;
}

// Znacznik czasu (unix) wygaśnięcia bieżącej sesji IKAKS, albo null gdy nieaktywna
function kdok_ikaks_session_expires_at(int $user_id): ?int {
    if (!kdok_ikaks_session_ok($user_id)) return null;
    return (int)$_SESSION['kdok_ikaks_at'] + KDOK_IKAKS_SESSION_TTL;
}

/**
 * Weryfikuje klucz WebAuthn (albo, gdy użytkownik nie ma zarejestrowanego klucza,
 * kod IKAKS jako awaryjną alternatywę) + certyfikat przed akceptacją kroku.
 * Pierwsze awaryjne użycie IKAKS w danym oknie 6h wymaga podania powodu (audytowalne);
 * kolejne decyzje w tym oknie nie proszą już ani o kod, ani o powód.
 * Zwraca ['ok'=>bool, 'error'=>string|null, 'cert'=>array|null, 'display_name'=>string,
 *         'ikaks_reason_logged'=>string|null].
 */
function kdok_auth_verify(int $user_id, string $ika_plain = '', string $ika_reason = ''): array {
    require_once __DIR__ . '/webauthn.php';
    webauthn_migrate();

    $ikaks_reason_logged = null;

    if (webauthn_user_has_keys($user_id)) {
        // Ma zarejestrowany klucz — wymagana świeża weryfikacja WebAuthn
        if (!kdok_webauthn_check($user_id)) {
            return ['ok' => false, 'error' => 'Wymagana świeża weryfikacja kluczem WebAuthn. Dotknij klucza sprzętowego i spróbuj ponownie.', 'cert' => null, 'display_name' => '', 'ikaks_reason_logged' => null];
        }
    } elseif (!kdok_ikaks_session_ok($user_id)) {
        // Brak zarejestrowanego klucza i brak aktywnej sesji awaryjnej — wymagany kod IKAKS + powód
        if (!kdok_ikaks_has($user_id)) {
            return ['ok' => false, 'error' => 'Nie masz zarejestrowanego klucza WebAuthn ani ustawionego kodu IKAKS. Zarejestruj klucz w Mój profil → Klucze bezpieczeństwa albo poproś administratora o nadanie IKAKS.', 'cert' => null, 'display_name' => '', 'ikaks_reason_logged' => null];
        }
        if (trim($ika_reason) === '') {
            return ['ok' => false, 'error' => 'Podaj powód użycia kodu IKAKS zamiast klucza WebAuthn.', 'cert' => null, 'display_name' => '', 'ikaks_reason_logged' => null];
        }
        if (!kdok_ikaks_verify($user_id, $ika_plain)) {
            return ['ok' => false, 'error' => 'Nieprawidłowy IKAKS. Autoryzacja odrzucona.', 'cert' => null, 'display_name' => '', 'ikaks_reason_logged' => null];
        }
        kdok_ikaks_mark($user_id);
        $ikaks_reason_logged = trim($ika_reason);
    }
    // else: aktywna sesja awaryjna IKAKS (ustanowiona w ciągu ostatnich 6h) — nic więcej nie pytamy

    // Certyfikat X.509
    $cert = kdok_cert_get($user_id);
    if (!$cert) {
        return ['ok' => false, 'error' => 'Brak certyfikatu X.509 w systemie. Poproś administratora o dodanie certyfikatu.', 'cert' => null, 'display_name' => '', 'ikaks_reason_logged' => null];
    }
    if (!kdok_cert_is_valid($cert)) {
        return ['ok' => false, 'error' => 'Certyfikat X.509 wygasł (' . date('d.m.Y', strtotime($cert['valid_to'])) . '). Skontaktuj się z administratorem.', 'cert' => null, 'display_name' => '', 'ikaks_reason_logged' => null];
    }

    // Pełne imię: preferuj CN z certyfikatu, fallback na users.name
    $user = db_one("SELECT name FROM users WHERE id=?", [$user_id]);
    $display_name = $cert['subject_cn'] ?: ($user['name'] ?? '');

    return ['ok' => true, 'error' => null, 'cert' => $cert, 'display_name' => $display_name, 'ikaks_reason_logged' => $ikaks_reason_logged];
}

/**
 * Zapisuje decyzję jednego kroku obiegu (meryt/formal/zatwierdza) dla dokumentu.
 * Wymaga wcześniejszego udanego kdok_auth_verify() — $auth to jego wynik.
 * Zwraca ['status'=>string kdok_documents.status po zapisie, 'rejected'=>bool].
 */
function kdok_decide_step(array $doc, string $step_key, string $status, int $user_id, string $notes, array &$auth): array {
    $id       = (int)$doc['id'];
    $cert     = $auth['cert'];
    $step_row = $doc['steps'][$step_key] ?? null;
    $dec_label = match($status) { 'ok' => 'TAK', 'uwagi' => 'Z uwagami', 'odrzucono' => 'ODRZUCONO', default => $status };

    // Powód awaryjnego użycia IKAKS logujemy tylko raz — przy pierwszej decyzji objętej tą
    // samą autoryzacją (np. "zatwierdź wszystkie kroki naraz" albo masowa akceptacja).
    if (!empty($auth['ikaks_reason_logged'])) {
        kdok_log($id, 'Autoryzacja kodem IKAKS (awaryjnie, brak klucza WebAuthn)', $auth['ikaks_reason_logged']);
        $auth['ikaks_reason_logged'] = null;
    }

    if ($step_row) {
        kdok_exec(
            "UPDATE kdok_steps SET status=?, user_id=?, user_name=?, cert_cn=?, cert_fingerprint=?, cert_subject=?, decided_at=datetime('now'), notes=? WHERE id=?",
            [$status, $user_id, $auth['display_name'],
             $cert['subject_cn'] ?? '', $cert['fingerprint_sha256'] ?? '', $cert['subject_dn'] ?? '',
             $notes, $step_row['id']]
        );
    } else {
        kdok_insert('kdok_steps', [
            'doc_id'           => $id,
            'step_type'        => $step_key,
            'status'           => $status,
            'user_id'          => $user_id,
            'user_name'        => $auth['display_name'],
            'cert_cn'          => $cert['subject_cn']         ?? '',
            'cert_fingerprint' => $cert['fingerprint_sha256'] ?? '',
            'cert_subject'     => $cert['subject_dn']         ?? '',
            'decided_at'       => date('Y-m-d H:i:s'),
            'notes'            => $notes,
        ]);
    }

    kdok_log($id, KDOK_STEPS[$step_key] . ' → ' . $dec_label, $notes);

    if ($status === 'odrzucono') {
        kdok_exec("UPDATE kdok_documents SET status='odrzucony', updated_at=datetime('now') WHERE id=?", [$id]);
        kdok_log($id, 'Dokument odrzucony na etapie: ' . KDOK_STEPS[$step_key], $notes);
        return ['status' => 'odrzucony', 'rejected' => true];
    }

    $fresh_doc  = kdok_get($id);
    $new_status = kdok_is_complete($fresh_doc) ? 'zaakceptowany' : 'w_obiegu';
    kdok_exec("UPDATE kdok_documents SET status=?, updated_at=datetime('now') WHERE id=?", [$new_status, $id]);
    if ($new_status === 'zaakceptowany') {
        kdok_log($id, 'Obieg zakończony — dokument zaakceptowany');
        if (org_setting('kdok_archive_enabled') === '1') {
            try {
                kdok_archive_push($id);
            } catch (\Throwable $arch_e) {
                kdok_log($id, 'eArchiwum: błąd wysyłki', $arch_e->getMessage());
            }
        }
    }
    return ['status' => $new_status, 'rejected' => false];
}

// ── Test połączenia ───────────────────────────────────────────────────────────

function kdok_db_test(): array {
    try {
        $type = org_setting('kdok_db_type');
        if ($type === 'main') return ['ok' => true, 'msg' => 'Używa głównej bazy aplikacji.'];

        $pdo = kdok_db();
        if ($type === 'sqlite') {
            $pdo->query("SELECT 1");
            $path = org_setting('kdok_db_sqlite_path');
            return ['ok' => true, 'msg' => 'SQLite OK: ' . $path . ' (' . number_format(filesize($path)/1024, 1) . ' KB)'];
        }
        if ($type === 'mysql') {
            $row = $pdo->query("SELECT VERSION() AS v")->fetch();
            return ['ok' => true, 'msg' => 'MySQL OK: wersja ' . ($row['v'] ?? '?')];
        }
        return ['ok' => false, 'msg' => 'Nieznany typ bazy.'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

// ── Ustawienia MPK ────────────────────────────────────────────────────────────

function kdok_mpk_enabled(): bool {
    return org_setting('kdok_mpk_enabled') === '1';
}

function kdok_mpk_list(): array {
    $raw = org_setting('kdok_mpk_list');
    if ($raw === '') return [];
    return array_values(array_filter(array_map('trim', explode("\n", $raw))));
}

// ── Numery dokumentów ─────────────────────────────────────────────────────────

function kdok_next_number(): string {
    $year = date('Y');
    $row  = kdok_one(
        "SELECT number FROM kdok_documents WHERE number LIKE ? ORDER BY id DESC LIMIT 1",
        ["KDOK/%/$year"]
    );
    $next = 1;
    if ($row) {
        preg_match('/KDOK\/(\d+)\//', $row['number'], $m);
        $next = (int)($m[1] ?? 0) + 1;
    }
    return sprintf('KDOK/%04d/%s', $next, $year);
}

// ── Role (zawsze z głównej bazy) ──────────────────────────────────────────────

function kdok_has_role(string $role, ?int $user_id = null): bool {
    if (is_admin()) return true;
    $uid = $user_id ?? (current_user()['id'] ?? 0);
    if (!$uid) return false;
    return (bool) db_one(
        "SELECT 1 FROM kdok_user_roles WHERE user_id = ? AND role = ?",
        [$uid, $role]
    );
}

function kdok_require_role(string $role): void {
    require_login();
    if (!kdok_has_role($role)) {
        http_response_code(403);
        require_once __DIR__ . '/header.php';
        echo '<div class="alert alert-danger m-4">Brak uprawnień do tej operacji.</div>';
        require_once __DIR__ . '/footer.php';
        exit;
    }
}

// Czy user ma JAKĄKOLWIEK rolę w KDOK (upload/meryt/formal/zatwierdza) — bazowe członkostwo w module
function kdok_has_any_role(?int $user_id = null): bool {
    if (is_admin()) return true;
    $uid = $user_id ?? (current_user()['id'] ?? 0);
    if (!$uid) return false;
    return (bool) db_one("SELECT 1 FROM kdok_user_roles WHERE user_id = ?", [$uid]);
}

// Bramka dostępu do modułu (przeglądanie rejestru/dokumentów) — bez tego każdy zalogowany
// widziałby całe archiwum dokumentów księgowych, niezależnie od przypisanych ról
function kdok_require_access(): void {
    require_login();
    if (!kdok_has_any_role()) {
        http_response_code(403);
        require_once __DIR__ . '/header.php';
        echo '<div class="alert alert-danger m-4">Brak dostępu do modułu EOD Dokumentów Księgowych.</div>';
        require_once __DIR__ . '/footer.php';
        exit;
    }
}

function kdok_user_roles(int $user_id): array {
    $rows = db_all("SELECT role FROM kdok_user_roles WHERE user_id = ?", [$user_id]);
    return array_column($rows, 'role');
}

// ── Historia obiegu ───────────────────────────────────────────────────────────

function kdok_log(int $doc_id, string $action, string $note = ''): void {
    $user = current_user();
    kdok_insert('kdok_history', [
        'doc_id'    => $doc_id,
        'user_id'   => $user['id'] ?? null,
        'user_name' => $user['name'] ?? 'System',
        'action'    => $action,
        'note'      => $note,
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    kdok_exec("UPDATE kdok_documents SET updated_at = datetime('now') WHERE id = ?", [$doc_id]);
}

function kdok_get_history(int $doc_id): array {
    return kdok_all(
        "SELECT * FROM kdok_history WHERE doc_id = ? ORDER BY id ASC",
        [$doc_id]
    );
}

// ── Pobieranie dokumentu ──────────────────────────────────────────────────────

function kdok_get(int $id): ?array {
    $doc = kdok_one("SELECT * FROM kdok_documents WHERE id = ?", [$id]);
    if (!$doc) return null;

    // Pobierz kroki — zaindeksuj po step_type
    $doc['steps'] = [];
    foreach (kdok_all("SELECT * FROM kdok_steps WHERE doc_id = ?
        ORDER BY CASE step_type WHEN 'meryt' THEN 1 WHEN 'formal' THEN 2 WHEN 'zatwierdza' THEN 3 END",
        [$id]) as $s) {
        $doc['steps'][$s['step_type']] = $s;
    }

    $doc['generated'] = kdok_one(
        "SELECT * FROM kdok_generated_pdf WHERE doc_id = ? ORDER BY id DESC LIMIT 1",
        [$id]
    );

    return $doc;
}

// ── Powiązanie z umową (dokument typu "Rachunek do umowy") ────────────────────
// Umowy żyją zawsze w głównej bazie aplikacji (nie w oddzielnej bazie KDOK), więc
// wyszukiwanie/etykietowanie robimy osobnymi zapytaniami na db(), bez JOIN-a.

function kdok_contract_search(string $q): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $like = '%' . $q . '%';
    $results = [];
    foreach (KDOK_CONTRACT_TYPES as $type => $cfg) {
        $rows = db_all(
            "SELECT id, numer_umowy, imie_nazwisko FROM {$cfg['table']}
             WHERE numer_umowy LIKE ? OR imie_nazwisko LIKE ?
             ORDER BY id DESC LIMIT 15",
            [$like, $like]
        );
        foreach ($rows as $r) {
            $results[] = [
                'type'  => $type,
                'id'    => (int)$r['id'],
                'label' => $cfg['label'] . ' ' . $r['numer_umowy'] . ' — ' . $r['imie_nazwisko'],
            ];
        }
    }
    return $results;
}

// Dokumenty KDOK powiązane z daną umową — do wyświetlenia na widoku umowy (sekcja "Rachunki w EOD")
function kdok_documents_for_contract(string $contract_type, int $contract_id): array {
    kdok_migrate();
    return kdok_all(
        "SELECT id, number, title, kwota, status FROM kdok_documents
         WHERE contract_type = ? AND contract_id = ? ORDER BY id DESC",
        [$contract_type, $contract_id]
    );
}

// Zwraca ['label'=>string, 'url'=>string] albo null, gdy umowa nie istnieje/typ nieznany
function kdok_contract_label(?string $type, ?int $id): ?array {
    if (!$type || !$id || !isset(KDOK_CONTRACT_TYPES[$type])) return null;
    $cfg = KDOK_CONTRACT_TYPES[$type];
    $row = db_one("SELECT id, numer_umowy, imie_nazwisko FROM {$cfg['table']} WHERE id = ?", [$id]);
    if (!$row) return null;
    return [
        'label' => $cfg['label'] . ' ' . $row['numer_umowy'] . ' — ' . $row['imie_nazwisko'],
        'url'   => APP_URL . $cfg['url'] . $row['id'],
    ];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function kdok_status_badge(string $status): string {
    $s = KDOK_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . h($s['label']) . '</span>';
}

function kdok_is_complete(array $doc): bool {
    foreach (array_keys(KDOK_STEPS) as $step) {
        $s = $doc['steps'][$step] ?? null;
        if (!$s || $s['status'] !== 'ok') return false;
    }
    return true;
}

// ── Konwersja UTF-8 → ISO-8859-2 dla FPDF ────────────────────────────────────

function _pdf(string $s): string {
    return iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;
}

// ── Budowanie obiektu PDF (wspólne dla raportu i finalnego PDF) ───────────────

/**
 * Tworzy FPDI z oryginalnymi stronami faktury + A4 pozioma karta obiegu.
 * Używane przez kdok_generate_final_pdf() (zapis) i raport.php (stream).
 */
function kdok_build_report_pdf(array $doc, array $history): \setasign\Fpdi\Fpdi {
    $org = defined('ORG_NAME') ? ORG_NAME : '';

    require_once __DIR__ . '/fpdf/fpdf.php';
    require_once __DIR__ . '/fpdi/autoload_fpdi.php';

    $pdf = new \setasign\Fpdi\Fpdi();
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(15, 15, 15);

    $font_dir = __DIR__ . '/fpdf/font/';
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $font_dir);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $font_dir);

    // Oryginalne strony PDF
    $orig_path = UPLOAD_DIR . ltrim($doc['file_path'] ?? '', '/');
    if ($doc['file_path'] && is_file($orig_path)) {
        try {
            $count = $pdf->setSourceFile($orig_path);
            for ($i = 1; $i <= $count; $i++) {
                $tpl  = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }
        } catch (\Exception $e) {}
    }

    // ── Oryginalne strony PDF ─────────────────────────────────────────────────
    $orig_path = UPLOAD_DIR . ltrim($doc['file_path'] ?? '', '/');
    if ($doc['file_path'] && is_file($orig_path)) {
        try {
            $count = $pdf->setSourceFile($orig_path);
            for ($i = 1; $i <= $count; $i++) {
                $tpl  = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }
        } catch (\Exception $e) {}
    }

    // ── Karta obiegu — A4 pozioma (297×210 mm, szerokość robocza 267 mm) ─────
    $pdf->AddPage('L', 'A4');
    $W   = 267;
    $LIM = 195; // max Y na stronie A4L

    // pomocnik: pasek-nagłówek sekcji
    $sectionBar = function (string $txt) use ($pdf, $W) {
        $pdf->SetFillColor(22, 53, 102); $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('DejaVu', 'B', 8.5);
        $pdf->Cell($W, 6.5, _pdf($txt), 0, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetX(15);
    };

    // ── Pasek nagłówkowy ─────────────────────────────────────────────────────
    $pdf->SetFillColor(22, 53, 102);
    $pdf->Rect(15, 15, $W, 13, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('DejaVu', 'B', 10);
    $pdf->SetXY(18, 15);
    $pdf->Cell($W * 0.5, 6.5, _pdf('KARTA OBIEGU DOKUMENTU KSIĘGOWEGO'), 0, 0, 'L');
    $pdf->SetFont('DejaVu', '', 7.5);
    $pdf->Cell(0, 6.5, _pdf($org . '   ·   ' . date('d.m.Y H:i')), 0, 1, 'R');
    $pdf->SetFont('DejaVu', '', 8);
    $pdf->SetXY(18, 21.5);
    $typLabel = KDOK_TYPES[$doc['type']]['label'] ?? $doc['type'];
    $pdf->Cell(0, 6, _pdf($typLabel . '   ·   nr obiegu (system): ' . $doc['number']), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);

    // ── Tytuł dokumentu ──────────────────────────────────────────────────────
    $pdf->SetY(31);
    $pdf->SetFont('DejaVu', 'B', 12);
    $pdf->SetTextColor(22, 53, 102);
    $pdf->MultiCell($W * 0.72, 7, _pdf($doc['title']), 0, 'L');
    $pdf->SetTextColor(0, 0, 0);

    // Kwota — w prawym górnym rogu (nad metadanymi), jeśli podana
    if ($doc['kwota']) {
        $pdf->SetXY(15 + $W * 0.74, 31);
        $pdf->SetFillColor(22, 53, 102);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('DejaVu', 'B', 14);
        $pdf->Cell($W * 0.26, 9, _pdf($doc['kwota'] . ' PLN'), 0, 0, 'R', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln();
    }

    // ── Opis merytoryczny — wyróżniony blok ──────────────────────────────────
    $y = max($pdf->GetY(), 31 + 9) + 3;
    if ($doc['description']) {
        $descLines = max(2, (int)ceil(mb_strlen($doc['description']) / 100) + 1);
        $descH     = $descLines * 5 + 8;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(15, $y, $W, $descH, 'FD');
        $pdf->SetFillColor(0, 0, 0);
        $pdf->Rect(15, $y, 3, $descH, 'F'); // lewy pasek akcentu
        $pdf->SetFont('DejaVu', 'B', 7.5);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY(21, $y + 2);
        $pdf->Cell(0, 4.5, _pdf('OPIS MERYTORYCZNY'), 0, 1);
        $pdf->SetFont('DejaVu', '', 9);
        $pdf->SetX(21);
        $pdf->MultiCell($W - 6, 5, _pdf($doc['description']), 0, 'L');
        $y = $pdf->GetY() + 3;
    }

    // MPK / Grant / Uwagi — kompaktowy pasek
    $extras = [];
    if ($doc['mpk'])        $extras[] = 'MPK: ' . $doc['mpk'];
    if ($doc['grant_name']) $extras[] = 'Grant: ' . $doc['grant_name'];
    if ($doc['uwagi'])      $extras[] = 'Uwagi: ' . $doc['uwagi'];
    if ($extras) {
        $pdf->SetFillColor(255, 249, 215);
        $pdf->Rect(15, $y, $W, 6, 'F');
        $pdf->SetFont('DejaVu', '', 7.5);
        $pdf->SetTextColor(100, 70, 0);
        $pdf->SetXY(18, $y + 0.8);
        $pdf->Cell(0, 4.5, _pdf(implode('   |   ', $extras)), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $y += 7;
    }

    $y += 3;
    $pdf->SetY($y);

    // ── Tabela akceptacji ─────────────────────────────────────────────────────
    $pdf->SetX(15);
    $sectionBar('ETAPY AKCEPTACJI — PODPIS ELEKTRONICZNY X.509 + IKAKS');

    $cW = [75, 55, 32, 22, 83]; // etap | CN | data | decyzja | autoryzacja
    $pdf->SetFillColor(230, 237, 250); $pdf->SetFont('DejaVu', 'B', 7.5);
    $pdf->Cell($cW[0], 5.5, _pdf('Etap'),                  1, 0, 'L', true);
    $pdf->Cell($cW[1], 5.5, _pdf('Imie i nazwisko (CN)'),  1, 0, 'L', true);
    $pdf->Cell($cW[2], 5.5, _pdf('Data i godzina'),        1, 0, 'C', true);
    $pdf->Cell($cW[3], 5.5, _pdf('Decyzja'),               1, 0, 'C', true);
    $pdf->Cell($cW[4], 5.5, _pdf('Autoryzacja X.509'),     1, 1, 'C', true);

    $stepColors = ['ok' => [235, 250, 238], 'uwagi' => [255, 250, 220], 'odrzucono' => [255, 235, 235], '' => [255, 255, 255]];
    $pdf->SetFont('DejaVu', '', 7.5);
    foreach (['meryt' => 'Sprawdzono merytorycznie', 'formal' => 'Sprawdzono formalnie i rachunkowo', 'zatwierdza' => 'Zatwierdzono do wyplaty'] as $key => $label) {
        $step   = $doc['steps'][$key] ?? null;
        $status = $step['status'] ?? '';
        $dec    = match($status) { 'ok' => 'TAK', 'uwagi' => 'Z uwagami', 'odrzucono' => 'ODRZUCONO', default => 'Oczekuje' };
        $dt     = ($step && $step['decided_at']) ? date('d.m.Y H:i', strtotime($step['decided_at'])) : '—';
        $cn     = ($step['cert_cn'] ?? '') ?: ($step['user_name'] ?? '—');
        $fp     = $step['cert_fingerprint'] ?? '';
        [$r,$g,$b] = $stepColors[$status] ?? $stepColors[''];

        $pdf->SetFillColor($r, $g, $b);
        $pdf->Cell($cW[0], 6, _pdf($label),  1, 0, 'L', true);
        $pdf->Cell($cW[1], 6, _pdf($cn),     1, 0, 'L', true);
        $pdf->Cell($cW[2], 6, _pdf($dt),     1, 0, 'C', true);
        $pdf->Cell($cW[3], 6, _pdf($dec),    1, 0, 'C', true);
        $pdf->Cell($cW[4], 6, _pdf($fp ? 'X.509 + IKAKS' : '—'), 1, 1, 'C', true);

        if ($fp) {
            $pdf->SetFont('DejaVu', '', 5.5); $pdf->SetFillColor(245, 248, 255); $pdf->SetX(15);
            $fc = str_replace(':', '', $fp);
            $pdf->MultiCell($W, 3.8, _pdf('SHA-256: ' . substr($fc, 0, 32) . "\n         " . substr($fc, 32)), 1, 'L', true);
            $pdf->SetFont('DejaVu', '', 7.5);
        }
        if ($step && $step['notes']) {
            $pdf->SetFont('DejaVu', '', 6.5); $pdf->SetFillColor(255, 252, 225); $pdf->SetX(15);
            $pdf->MultiCell($W, 4, _pdf('Uwagi: ' . $step['notes']), 1, 'L', true);
            $pdf->SetFont('DejaVu', '', 7.5);
        }
    }

    $y = $pdf->GetY() + 4;

    // ── Historia obiegu ───────────────────────────────────────────────────────
    if ($y > $LIM - 30) { $pdf->AddPage('L', 'A4'); $y = 15; }
    $pdf->SetXY(15, $y);
    $sectionBar('HISTORIA OBIEGU');

    $hW = [34, 58, $W - 92];
    $pdf->SetFillColor(230, 237, 250); $pdf->SetFont('DejaVu', 'B', 7.5);
    $pdf->Cell($hW[0], 5, _pdf('Data i czas'), 1, 0, 'C', true);
    $pdf->Cell($hW[1], 5, _pdf('Uzytkownik'),  1, 0, 'C', true);
    $pdf->Cell($hW[2], 5, _pdf('Zdarzenie'),   1, 1, 'C', true);

    $pdf->SetFont('DejaVu', '', 7.5); $alt = false;
    foreach ($history as $row) {
        if ($pdf->GetY() > $LIM - 10) { $pdf->AddPage('L', 'A4'); }
        $alt = !$alt;
        $pdf->SetFillColor($alt ? 248 : 255, $alt ? 249 : 255, $alt ? 252 : 255);
        $txt = $row['action'] . ($row['note'] ? ': ' . $row['note'] : '');
        $pdf->Cell($hW[0], 5, _pdf(date('d.m.Y H:i', strtotime($row['created_at']))), 1, 0, 'C', true);
        $pdf->Cell($hW[1], 5, _pdf($row['user_name']), 1, 0, 'L', true);
        $pdf->Cell($hW[2], 5, _pdf($txt),              1, 1, 'L', true);
    }

    $y = $pdf->GetY() + 4;

    // ── Klauzula ─────────────────────────────────────────────────────────────
    if ($y > $LIM - 22) { $pdf->AddPage('L', 'A4'); $y = 15; }
    $pdf->SetXY(15, $y);
    $sectionBar('KLAUZULA ZATWIERDZENIA ELEKTRONICZNEGO');
    $pdf->SetFillColor(248, 249, 252);
    $pdf->SetFont('DejaVu', '', 7);
    $klauzula = 'Niniejszy dokument zostal zatwierdzony elektronicznie w systemie EOD Dokumentow Ksiegowych ' . $org
        . '. Elektroniczne zatwierdzenie jest rownowazne z podpisem wlasnorecznym (art. 7 ustawy o rachunkowosci,'
        . ' Dz.U. 2023 poz. 120). Kazdy etap akceptacji wymagal certyfikatu X.509 oraz klucza sprzetowego WebAuthn'
        . ' (lub, w przypadku braku klucza, kodu IKAKS).';
    $pdf->MultiCell($W, 4, _pdf($klauzula), 1, 'J', true);
    $pdf->SetFont('DejaVu', '', 6);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->SetXY(15, $pdf->GetY() + 1);
    $pdf->Cell($W, 4, _pdf('SHA-256: ' . ($doc['file_sha256'] ?: '—') . '   |   ' . $doc['number'] . '   |   ' . date('d.m.Y H:i:s')), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);

    return $pdf;
}

// ── Generowanie i zapis finalnego PDF ─────────────────────────────────────────

function kdok_generate_final_pdf(int $doc_id): string {
    $doc     = kdok_get($doc_id);
    if (!$doc) throw new RuntimeException('Dokument nie istnieje');
    $history = kdok_get_history($doc_id);

    $pdf = kdok_build_report_pdf($doc, $history);

    $dir = UPLOAD_DIR . 'kdok_generated/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename  = preg_replace('/[^a-zA-Z0-9_.\-]/', '_',
        'final_' . $doc['number'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf');
    $full_path = $dir . $filename;
    $pdf->Output('F', $full_path);

    $rel    = 'kdok_generated/' . $filename;
    $sha256 = hash_file('sha256', $full_path);
    $size   = filesize($full_path);
    $user   = current_user();

    kdok_insert('kdok_generated_pdf', [
        'doc_id'       => $doc_id,
        'file_path'    => $rel,
        'file_sha256'  => $sha256,
        'file_size'    => $size,
        'generated_by' => $user['id'] ?? null,
        'gen_name'     => $user['name'] ?? '',
    ]);

    kdok_log($doc_id, 'Wygenerowano finalny PDF z kartą obiegu',
        'SHA-256: ' . $sha256 . ' | ' . number_format($size / 1024, 1) . ' KB');

    return $rel;
}

// ── Czyszczenie ───────────────────────────────────────────────────────────────

/**
 * Tworzy plik ZIP ze wszystkimi finalnymi PDF-ami dla danego miesiąca i roku.
 * Zwraca ścieżkę do pliku ZIP lub rzuca RuntimeException.
 */
function kdok_zip_month(int $miesiac, int $rok): string {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Brak rozszerzenia ZipArchive w PHP.');
    }

    // Pobierz wszystkie zaakceptowane dokumenty z danego miesiąca/roku
    $docs = kdok_all(
        "SELECT id, number, miesiac, rok, created_at FROM kdok_documents
         WHERE status = 'zaakceptowany'
           AND (
               (miesiac IS NOT NULL AND rok IS NOT NULL AND miesiac = ? AND rok = ?)
               OR
               (miesiac IS NULL AND CAST(SUBSTR(created_at,6,2) AS INTEGER) = ? AND CAST(SUBSTR(created_at,1,4) AS INTEGER) = ?)
           )
         ORDER BY id",
        [$miesiac, $rok, $miesiac, $rok]
    );

    if (!$docs) {
        throw new RuntimeException('Brak zaakceptowanych dokumentów dla wybranego miesiąca.');
    }

    $dir = UPLOAD_DIR . 'kdok_generated/';
    $zip_name = 'EOD_DK_' . sprintf('%04d_%02d', $rok, $miesiac) . '_' . date('Ymd_His') . '.zip';
    $zip_path = sys_get_temp_dir() . '/' . $zip_name;

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Nie można utworzyć pliku ZIP.');
    }

    $added = 0;
    $seen_docs = [];
    foreach ($docs as $doc) {
        $doc_id = $doc['id'];
        if (isset($seen_docs[$doc_id])) continue;
        // Pobierz najnowszy wygenerowany PDF
        $gen = kdok_one(
            "SELECT file_path FROM kdok_generated_pdf WHERE doc_id = ? ORDER BY id DESC LIMIT 1",
            [$doc_id]
        );
        if (!$gen) continue;
        $full = UPLOAD_DIR . $gen['file_path'];
        if (!is_file($full)) continue;
        $safe = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $doc['number']);
        $zip->addFile($full, $safe . '.pdf');
        $seen_docs[$doc_id] = true;
        $added++;
    }

    $zip->close();

    if ($added === 0) {
        @unlink($zip_path);
        throw new RuntimeException('Brak wygenerowanych PDF-ów dla zaakceptowanych dokumentów z tego miesiąca.');
    }

    return $zip_path;
}

function kdok_cleanup_old_generated(int $keep_per_doc = 3, int $older_than_days = 90): array {
    $stats = ['deleted_files' => 0, 'freed_bytes' => 0, 'errors' => []];
    foreach (kdok_all("SELECT DISTINCT doc_id FROM kdok_generated_pdf") as $d) {
        $all = kdok_all("SELECT * FROM kdok_generated_pdf WHERE doc_id = ? ORDER BY id DESC", [$d['doc_id']]);
        foreach (array_slice($all, $keep_per_doc) as $row) {
            $path = UPLOAD_DIR . $row['file_path'];
            if (is_file($path)) {
                $stats['freed_bytes'] += filesize($path);
                if (!unlink($path)) { $stats['errors'][] = 'Nie można usunąć: ' . $row['file_path']; continue; }
            }
            kdok_exec("DELETE FROM kdok_generated_pdf WHERE id = ?", [$row['id']]);
            $stats['deleted_files']++;
        }
    }
    $cutoff = strtotime("-{$older_than_days} days");
    $dir    = UPLOAD_DIR . 'kdok_generated/';
    if (is_dir($dir)) {
        foreach (glob($dir . '*.pdf') as $file) {
            if (filemtime($file) < $cutoff) {
                $rel    = 'kdok_generated/' . basename($file);
                if (!kdok_one("SELECT 1 FROM kdok_generated_pdf WHERE file_path = ?", [$rel])) {
                    $stats['freed_bytes'] += filesize($file);
                    unlink($file);
                    $stats['deleted_files']++;
                }
            }
        }
    }
    return $stats;
}
