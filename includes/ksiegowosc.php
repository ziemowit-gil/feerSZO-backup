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
    'ksef_reczny'      => ['label' => 'Faktura pobrana ręcznie z KSeF',      'icon' => 'bi-receipt-cutoff'],
    'faktura_papierowa'=> ['label' => 'Faktura papierowa',                  'icon' => 'bi-file-earmark-text'],
    'rachunek'         => ['label' => 'Rachunek do umowy',                   'icon' => 'bi-person-vcard'],
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
    'formal'     => 'Sprawdzono formalnie i rachunkowo',
    'meryt'      => 'Sprawdzono merytorycznie',
    'zatwierdza' => 'Zatwierdzono do wypłaty',
];

const KDOK_ROLES = [
    'upload'     => 'Może dodawać dokumenty',
    'formal'     => 'Akceptacja formalna i rachunkowa',
    'meryt'      => 'Akceptacja merytoryczna',
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
        'uwagi'           => "TEXT NOT NULL DEFAULT ''",
        'grant_name'      => "TEXT NOT NULL DEFAULT ''",
        'mpk'             => "TEXT NOT NULL DEFAULT ''",
        'kwota'           => "TEXT NOT NULL DEFAULT ''",
        'creator_name'    => "TEXT NOT NULL DEFAULT ''",
        'miesiac'         => "INTEGER",
        'rok'             => "INTEGER",
        'contract_type'   => "TEXT",
        'contract_id'     => "INTEGER",
        'ezd_dokument_id' => "INTEGER",
        // Pola finansowe — Preliminarz Płatności
        'nr_faktury'      => "TEXT NOT NULL DEFAULT ''",
        'nip_dostawcy'    => "TEXT NOT NULL DEFAULT ''",
        'rachunek_bankowy'=> "TEXT NOT NULL DEFAULT ''",
        'termin_platnosci'=> "TEXT",
        'kwota_netto'     => "TEXT NOT NULL DEFAULT ''",
        'kwota_vat'       => "TEXT NOT NULL DEFAULT ''",
        'kwota_brutto'    => "TEXT NOT NULL DEFAULT ''",
        'waluta'          => "TEXT NOT NULL DEFAULT 'PLN'",
        'wymaga_mpp'      => "INTEGER NOT NULL DEFAULT 0",
        'centrum_kosztow' => "TEXT NOT NULL DEFAULT ''",
        'projekt'         => "TEXT NOT NULL DEFAULT ''",
        'tytul_przelewu'  => "TEXT NOT NULL DEFAULT ''",
        'status_platnosci'       => "TEXT NOT NULL DEFAULT 'nowy'",
        'ezd_sprawa_id'          => "INTEGER",
        'oswiadczenie_ksef'      => "INTEGER NOT NULL DEFAULT 0",
        'wyklucz_z_preliminarza' => "INTEGER NOT NULL DEFAULT 0",
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

    // Tablica zapamiętanych kontrahentów (NIP → dane płatnicze)
    $kdb->exec("CREATE TABLE IF NOT EXISTS kdok_dostawcy (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        nazwa            TEXT    NOT NULL DEFAULT '',
        nip              TEXT    NOT NULL DEFAULT '',
        rachunek_bankowy TEXT    NOT NULL DEFAULT '',
        uwagi            TEXT    NOT NULL DEFAULT '',
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    try { $kdb->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_kdok_dostawcy_nip ON kdok_dostawcy(nip) WHERE nip!=''"); } catch (\Exception $e) {}

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

// ── Kontrahenci (dostawcy) ─────────────────────────────────────────────────────

/**
 * Zapamiętuje/aktualizuje kontrahenta po NIP.
 * Wywołać po zapisaniu dokumentu z NIP + rachunkiem bankowym.
 */
function kdok_dostawcy_upsert(string $nip, string $nazwa, string $rachunek): void {
    $nip = preg_replace('/\D/', '', $nip);
    if (!$nip) return;
    $existing = kdok_one("SELECT id, nazwa FROM kdok_dostawcy WHERE nip=?", [$nip]);
    if ($existing) {
        $upd = ['updated_at' => date('Y-m-d H:i:s')];
        if ($rachunek) $upd['rachunek_bankowy'] = $rachunek;
        if ($nazwa && !$existing['nazwa']) $upd['nazwa'] = $nazwa;
        $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($upd)));
        $vals = array_values($upd);
        $vals[] = $existing['id'];
        kdok_exec("UPDATE kdok_dostawcy SET $set WHERE id=?", $vals);
    } else {
        kdok_insert('kdok_dostawcy', ['nip' => $nip, 'nazwa' => $nazwa, 'rachunek_bankowy' => $rachunek]);
    }
}

/**
 * Szuka kontrahentów po frazie (nazwa lub NIP).
 * Łączy lokalne kdok_dostawcy + CRM contacts (org-like z NIP).
 * Zwraca max 12 rekordów: [{nazwa, nip, rachunek_bankowy, source}].
 */
function kdok_dostawcy_search(string $q): array {
    $q = trim($q);
    if (strlen($q) < 2) return [];
    $like = '%' . $q . '%';

    $local = kdok_query(
        "SELECT nazwa, nip, rachunek_bankowy, 'local' AS source
           FROM kdok_dostawcy
          WHERE (nazwa LIKE ? OR nip LIKE ?)
          ORDER BY updated_at DESC LIMIT 8",
        [$like, $like]
    );

    // Uzupełnij z CRM contacts (organizacja/kontrahent/partner, mają NIP)
    $crm = [];
    try {
        require_once __DIR__ . '/crm.php';
        $org_types = array_keys(array_filter(CRM_CONTACT_TYPES, fn($t) => $t['org_like']));
        $ph = implode(',', array_fill(0, count($org_types), '?'));
        $crm = db_all(
            "SELECT imie_nazwisko AS nazwa, nip, '' AS rachunek_bankowy, 'crm' AS source
               FROM crm_contacts
              WHERE crm_active=1 AND nip IS NOT NULL AND nip!=''
                AND type IN ($ph)
                AND (imie_nazwisko LIKE ? OR organizacja LIKE ? OR nip LIKE ?)
              ORDER BY imie_nazwisko LIMIT 8",
            array_merge($org_types, [$like, $like, $like])
        );
    } catch (\Throwable $_) {}

    // Scala, deduplikuje po NIP — local ma priorytet
    $seen = [];
    $out  = [];
    foreach (array_merge($local, $crm) as $r) {
        $key = $r['nip'] ?: ('_' . $r['nazwa']);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $r;
        if (count($out) >= 12) break;
    }
    return $out;
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

// ── MS365 step-up — alternatywa gdy użytkownik nie ma klucza WebAuthn ─────────
const KDOK_MS365_SESSION_TTL  = 21600; // 6h, jak IKAKS
const KDOK_BYPASS_SESSION_TTL = 86400; // 24h dla bypassu WebAuthn (MS365 + IKA)

// Oznacza udaną weryfikację tożsamości przez Microsoft 365
function kdok_ms365_mark(int $user_id): void {
    $_SESSION['kdok_ms365_uid'] = $user_id;
    $_SESSION['kdok_ms365_at']  = time();
}

function kdok_ms365_session_ok(int $user_id): bool {
    return !empty($_SESSION['kdok_ms365_uid'])
        && (int)$_SESSION['kdok_ms365_uid'] === $user_id
        && (time() - (int)($_SESSION['kdok_ms365_at'] ?? 0)) <= KDOK_MS365_SESSION_TTL;
}

function kdok_ms365_session_expires_at(int $user_id): ?int {
    if (!kdok_ms365_session_ok($user_id)) return null;
    return (int)$_SESSION['kdok_ms365_at'] + KDOK_MS365_SESSION_TTL;
}

// Oznacza pełny bypass WebAuthn (MS365 + kod IKA) — dla użytkowników z kluczem, gdy klucz
// fizycznie niedostępny. Trwa 24h.
function kdok_bypass_mark(int $user_id): void {
    $_SESSION['kdok_bypass_uid'] = $user_id;
    $_SESSION['kdok_bypass_at']  = time();
}

function kdok_bypass_session_ok(int $user_id): bool {
    return !empty($_SESSION['kdok_bypass_uid'])
        && (int)$_SESSION['kdok_bypass_uid'] === $user_id
        && (time() - (int)($_SESSION['kdok_bypass_at'] ?? 0)) <= KDOK_BYPASS_SESSION_TTL;
}

function kdok_bypass_session_expires_at(int $user_id): ?int {
    if (!kdok_bypass_session_ok($user_id)) return null;
    return (int)$_SESSION['kdok_bypass_at'] + KDOK_BYPASS_SESSION_TTL;
}

/**
 * Weryfikuje klucz WebAuthn (albo alternatywne metody) + certyfikat przed akceptacją kroku.
 *
 * Hierarchia dla użytkownika Z kluczem WebAuthn:
 *   1. Aktywna sesja bypass (MS365 + IKA, 24h) — nie wymaga klucza
 *   2. Krok bypassu w toku: sesja MS365 aktywna + podany kod IKA → ustanawia bypass
 *   3. Świeża weryfikacja kluczem WebAuthn (domyślna ścieżka)
 *   Opcja „Nie mam klucza przy sobie" inicjuje flow: MS365 redirect → powrót → podanie IKA.
 *
 * Hierarchia dla użytkownika BEZ klucza:
 *   1. Aktywna sesja MS365 (6h)
 *   2. Aktywna sesja IKAKS (6h)
 *   3. Nowa weryfikacja kodem IKAKS + powód
 *
 * Zwraca ['ok'=>bool, 'error'=>string|null, 'cert'=>array|null, 'display_name'=>string,
 *         'ikaks_reason_logged'=>string|null].
 */
function kdok_auth_verify(int $user_id, string $ika_plain = '', string $ika_reason = '', string $bypass_ika = ''): array {
    require_once __DIR__ . '/webauthn.php';
    webauthn_migrate();

    $ikaks_reason_logged = null;

    if (webauthn_user_has_keys($user_id)) {
        if (kdok_bypass_session_ok($user_id)) {
            // Aktywna 24h sesja bypass (MS365 + IKA) — klucz nie jest wymagany
        } elseif (kdok_ms365_session_ok($user_id)) {
            // MS365 potwierdzone — teraz wymaga kodu IKA, żeby ustanowić bypass
            require_once __DIR__ . '/cpc.php';
            $code = preg_replace('/\D/', '', $bypass_ika);
            if (empty($code)) {
                return ['ok' => false, 'error' => 'Podaj kod IKA (6 cyfr), aby dokończyć autoryzację bez klucza WebAuthn.', 'cert' => null, 'display_name' => '', 'ikaks_reason_logged' => null];
            }
            $res = cpc_verify($user_id, $code);
            if ($res['blocked']) {
                return ['ok' => false, 'error' => 'Kod IKA zablokowany po zbyt wielu błędnych próbach. Spróbuj ponownie za 15 minut.', 'cert' => null, 'display_name' => '', 'ikaks_reason_logged' => null];
            }
            if (!$res['ok']) {
                $left = max(0, 3 - (int)($res['fails'] ?? 0));
                return ['ok' => false, 'error' => 'Nieprawidłowy kod IKA.' . ($left > 0 ? ' Pozostało prób: ' . $left . '.' : ''), 'cert' => null, 'display_name' => '', 'ikaks_reason_logged' => null];
            }
            kdok_bypass_mark($user_id);
        } elseif (!kdok_webauthn_check($user_id)) {
            return ['ok' => false, 'error' => 'Wymagana świeża weryfikacja kluczem WebAuthn. Dotknij klucza sprzętowego lub skorzystaj z opcji „Nie mam klucza przy sobie".', 'cert' => null, 'display_name' => '', 'ikaks_reason_logged' => null];
        }
    } elseif (kdok_ms365_session_ok($user_id)) {
        // Brak klucza WebAuthn, ale aktywna sesja MS365 — wystarczająca autoryzacja
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
    // else: aktywna sesja awaryjna IKAKS lub MS365 (ustanowiona w ciągu ostatnich 6h)

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
/**
 * Rejestruje zaakceptowany (zatwierdzony do wypłaty) dokument księgowy jako
 * dokument wewnętrzny w EZD — JRWA „Dokumenty księgowe - obieg od zapłaty",
 * sprawa ciągła roczna. Idempotentne (link ezd_dokument_id w kdok_documents).
 * Bezpieczne: NIGDY nie rzuca wyjątkiem — błąd (np. EZD wyłączone) tylko loguje,
 * bo nie może zablokować obiegu księgowego. Moduł KDOK bywa w osobnej bazie, więc
 * dane dokumentu przekazujemy tablicą, a link zapisujemy po stronie bazy KDOK.
 * @return int|null id dokumentu EZD lub null
 */
function kdok_register_in_ezd(array $doc, int $user_id): ?int {
    if (!module_enabled('ezd_enabled')) return null;
    if (!empty($doc['ezd_dokument_id'])) return (int)$doc['ezd_dokument_id'];

    require_once __DIR__ . '/ezd.php';
    try {
        $created = $doc['created_at'] ?? date('Y-m-d');
        $rok     = (int)substr((string)$created, 0, 4) ?: (int)date('Y');
        $sprawa_id = ezd_kdok_sprawa_id($rok, $user_id ?: 0);

        $typ_label = KDOK_TYPES[$doc['type']]['label'] ?? ($doc['type'] ?? 'Dokument księgowy');
        $num  = trim((string)($doc['number'] ?? ''));

        $lines = [];
        if ($num)                        $lines[] = 'Numer: ' . $num;
        $lines[] = 'Typ: ' . $typ_label;
        if (($doc['kwota'] ?? '') !== '')  $lines[] = 'Kwota: ' . $doc['kwota'];
        if (($doc['grant_name'] ?? '') !== '') $lines[] = 'Dotacja/projekt: ' . $doc['grant_name'];
        if (($doc['mpk'] ?? '') !== '')    $lines[] = 'MPK: ' . $doc['mpk'];
        // Obieg akceptacji — kto podpisał kolejne kroki (dokumentacja „obiegu od zapłaty")
        if (!empty($doc['steps']) && is_array($doc['steps'])) {
            foreach (KDOK_STEPS as $sk => $slbl) {
                $st = $doc['steps'][$sk] ?? null;
                if ($st && ($st['status'] ?? '') === 'ok') {
                    $who  = ($st['user_name'] ?? '') ?: ('#' . ($st['user_id'] ?? '?'));
                    $when = !empty($st['decided_at']) ? ' (' . substr((string)$st['decided_at'], 0, 10) . ')' : '';
                    $lines[] = $slbl . ': ' . $who . $when;
                }
            }
        }

        $did = ezd_dokument_create([
            'sprawa_id' => $sprawa_id,
            'rodzaj'    => 'inne',
            'title'     => trim($typ_label . ($num ? ' ' . $num : '') . (($doc['title'] ?? '') !== '' ? ' — ' . $doc['title'] : '')),
            'tresc'     => implode("\n", $lines),
            'status'    => 'zatwierdzony',
            'owner_id'  => $user_id ?: null,
        ], $user_id ?: 0);

        kdok_exec("UPDATE kdok_documents SET ezd_dokument_id=? WHERE id=?", [$did, (int)$doc['id']]);
        // Log jest pomocniczy — nie może „cofnąć" udanej rejestracji, więc osobny try.
        try { kdok_log((int)$doc['id'], 'Zarejestrowano w EZD (dokument wewnętrzny #' . $did . ')'); } catch (\Throwable $e) {}
        return $did;
    } catch (\Throwable $e) {
        try { kdok_log((int)($doc['id'] ?? 0), 'EZD: nie udało się zarejestrować dokumentu', $e->getMessage()); } catch (\Throwable $e2) {}
        return null;
    }
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
        // Rejestracja w EZD (JRWA „Dokumenty księgowe - obieg od zapłaty") — best-effort.
        kdok_register_in_ezd($fresh_doc, $user_id);
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

// ── Preliminarz Płatności — priorytety i statusy ──────────────────────────────

const KDOK_STATUS_PLATNOSCI = [
    'nowy'         => ['label' => 'Nowy',             'class' => 'secondary'],
    'do_realizacji'=> ['label' => 'Do realizacji',    'class' => 'warning'],
    'zlecony'      => ['label' => 'Zlecony do banku', 'class' => 'info'],
    'oplacony'     => ['label' => 'Opłacony',         'class' => 'success'],
    'wstrzymany'   => ['label' => 'Wstrzymany',       'class' => 'danger'],
    'anulowany'    => ['label' => 'Anulowany',        'class' => 'dark'],
];

/** Zwraca priorytet P1–P5 na podstawie terminu płatności i flagi MPP. */
function kdok_platnosc_priorytet(array $doc): int {
    $termin = $doc['termin_platnosci'] ?? '';
    if (!$termin) return 5;
    $today = (int) date('Ymd');
    $tdate = (int) str_replace('-', '', substr($termin, 0, 10));
    $diff  = (int) round((strtotime(substr($termin, 0, 10)) - strtotime(date('Y-m-d'))) / 86400);
    $mpp   = !empty($doc['wymaga_mpp']);
    if ($diff < 0)                        return 1; // przeterminowane
    if ($mpp && $diff <= 7)               return 1; // MPP + bliski termin
    if ($diff <= 3)                       return 2;
    if ($diff <= 7)                       return 3;
    if ($diff <= 14)                      return 4;
    return 5;
}

function kdok_platnosc_priorytet_label(int $p): string {
    return ['', 'Krytyczny', 'Pilny', 'Wkrótce', 'Normalny', 'Oczekujący'][$p] ?? '?';
}

function kdok_status_platnosci_badge(string $status): string {
    $s = KDOK_STATUS_PLATNOSCI[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . h($s['label']) . '</span>';
}

/**
 * Dokumenty kwalifikowane do Preliminarza Płatności.
 * Kwalifikuje status 'zaakceptowany' (kroki zakończone).
 */
function kdok_preliminarz_query(array $f = []): array {
    $where  = ["d.status = 'zaakceptowany'", "COALESCE(d.wyklucz_z_preliminarza, 0) = 0"];
    $params = [];

    if (!empty($f['status_platnosci'])) {
        $where[]  = "d.status_platnosci = ?";
        $params[] = $f['status_platnosci'];
    } else {
        $where[]  = "d.status_platnosci NOT IN ('anulowany')";
    }
    if (!empty($f['termin_od'])) {
        $where[]  = "d.termin_platnosci >= ?";
        $params[] = $f['termin_od'];
    }
    if (!empty($f['termin_do'])) {
        $where[]  = "d.termin_platnosci <= ?";
        $params[] = $f['termin_do'];
    }
    if (!empty($f['waluta'])) {
        $where[]  = "d.waluta = ?";
        $params[] = $f['waluta'];
    }
    if (!empty($f['mpp'])) {
        $where[]  = "d.wymaga_mpp = 1";
    }
    if (!empty($f['centrum_kosztow'])) {
        $where[]  = "d.centrum_kosztow = ?";
        $params[] = $f['centrum_kosztow'];
    }
    if (!empty($f['q'])) {
        $where[]  = "(d.title LIKE ? OR d.nip_dostawcy LIKE ? OR d.nr_faktury LIKE ?)";
        $q = '%' . $f['q'] . '%';
        $params[] = $q; $params[] = $q; $params[] = $q;
    }

    $sql = "SELECT d.* FROM kdok_documents d WHERE " . implode(' AND ', $where)
         . " ORDER BY COALESCE(d.termin_platnosci,'9999-99-99') ASC, d.id ASC";
    $rows = kdok_all($sql, $params);

    foreach ($rows as &$row) {
        $row['priorytet'] = kdok_platnosc_priorytet($row);
    }
    usort($rows, fn($a, $b) => $a['priorytet'] <=> $b['priorytet'] ?: strcmp($a['termin_platnosci'] ?? '', $b['termin_platnosci'] ?? ''));
    return $rows;
}

/** Tworzy dedykowaną koszulkę EZD dla dokumentu KDOK (1:1). */
function kdok_create_koszulka_ezd(array $doc, int $user_id): ?int {
    if (!module_enabled('ezd_enabled')) return null;
    if (!empty($doc['ezd_sprawa_id'])) return (int)$doc['ezd_sprawa_id'];
    require_once __DIR__ . '/ezd.php';
    try {
        $sprawa_id = ezd_kdok_koszulka_create($doc, $user_id);
        kdok_exec("UPDATE kdok_documents SET ezd_sprawa_id=? WHERE id=?", [$sprawa_id, (int)$doc['id']]);
        kdok_log((int)$doc['id'], 'Koszulka EZD utworzona (sprawa #' . $sprawa_id . ')');
        return $sprawa_id;
    } catch (\Throwable $e) {
        try { kdok_log((int)($doc['id'] ?? 0), 'EZD: nie udało się utworzyć koszulki', $e->getMessage()); } catch (\Throwable $_) {}
        return null;
    }
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
        ORDER BY CASE step_type WHEN 'formal' THEN 1 WHEN 'meryt' THEN 2 WHEN 'zatwierdza' THEN 3 END",
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

/**
 * Liczy ile linii zajmie tekst po zawinięciu w danej szerokości — TAK SAMO jak
 * zrobi to MultiCell (zachłanne pakowanie słów wg realnej szerokości znaków tej
 * czcionki), żeby tła/ramki dało się narysować PRZED tekstem z właściwą wysokością.
 * Bez tego szacowanie „po liczbie znaków" rozjeżdżało się z prawdziwym zawinięciem
 * i tekst wychodził poza narysowane tło (efekt „zlewających się" elementów).
 * Celowo lekko nadszacowuje (bufor zamiast dokładnego cMargin) — bezpieczniej mieć
 * tło odrobinę za wysokie niż za niskie.
 */
function _pdf_count_lines($pdf, string $text, float $width): int {
    $avail = max(5, $width - 2);
    $lines = 0;
    foreach (explode("\n", $text) as $para) {
        $words = preg_split('/\s+/u', trim($para));
        if (!$words || $words === ['']) { $lines++; continue; }
        $cur = '';
        $n = 1;
        foreach ($words as $word) {
            $test = $cur === '' ? $word : $cur . ' ' . $word;
            if ($pdf->GetStringWidth(_pdf($test)) > $avail && $cur !== '') {
                $n++;
                $cur = $word;
            } else {
                $cur = $test;
            }
        }
        $lines += $n;
    }
    return max(1, $lines);
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

    // Paleta — łupkowy grafit + ciepły akcent (odróżnia kartę od oryginału dokumentu)
    $INK    = [33, 43, 54];    // niemal-czarny grafit — tytuły, tekst
    $ACCENT = [43, 87, 96];    // łupkowy teal — akcenty, linie, nagłówki sekcji
    $TINT   = [232, 238, 238]; // bardzo jasny teal — tła nagłówków tabel
    $LINE   = [210, 215, 217]; // jasnoszare linie/obramowania
    $GOLD   = [163, 116, 41];  // ciepły akcent — kwota, wyróżnienia

    // pomocnik: etykieta sekcji (kreska akcentu + tekst + cienka linia pod spodem)
    $sectionLabel = function (string $txt) use ($pdf, $W, $ACCENT, $INK, $LINE) {
        $y = $pdf->GetY();
        $pdf->SetFillColor(...$ACCENT);
        $pdf->Rect(15, $y + 0.8, 2.2, 4, 'F');
        $pdf->SetTextColor(...$INK);
        $pdf->SetFont('DejaVu', 'B', 8.5);
        $pdf->SetXY(19, $y);
        $pdf->Cell($W - 4, 5.6, _pdf(mb_strtoupper($txt)), 0, 1, 'L');
        $pdf->SetDrawColor(...$LINE);
        $pdf->SetLineWidth(0.25);
        $pdf->Line(15, $pdf->GetY() + 0.5, 15 + $W, $pdf->GetY() + 0.5);
        $pdf->SetY($pdf->GetY() + 2.3);
        $pdf->SetTextColor(0, 0, 0);
    };

    // ── Kod kreskowy (Code128, nr obiegu) — do skanowania przy dekretacji ─────
    $barcodeW = 0; $barcodeH = 0;
    $barcodeTmp = null;
    try {
        $gen = new \Picqer\Barcode\BarcodeGeneratorPNG();
        $png = $gen->getBarcode($doc['number'], $gen::TYPE_CODE_128, 2, 40, [0, 0, 0]);
        $barcodeTmp = tempnam(sys_get_temp_dir(), 'kdokbc') . '.png';
        file_put_contents($barcodeTmp, $png);
        $barcodeW = 52; $barcodeH = 11;
    } catch (\Throwable $e) { $barcodeTmp = null; }

    // ── Nagłówek — cienka linijka eyebrow + reguła akcentu ────────────────────
    $pdf->SetFont('DejaVu', '', 7);
    $pdf->SetTextColor(120, 128, 132);
    $pdf->SetXY(15, 15);
    $pdf->Cell($W * 0.6, 4, _pdf(mb_strtoupper('System EOD Dokumentów Księgowych' . ($org ? ' · ' . $org : ''))), 0, 0, 'L');
    $pdf->Cell($W * 0.4, 4, _pdf('Wygenerowano: ' . date('d.m.Y H:i')), 0, 1, 'R');
    $pdf->SetDrawColor(...$ACCENT);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(15, 19.5, 15 + $W, 19.5);
    $pdf->SetLineWidth(0.2);
    $pdf->SetTextColor(0, 0, 0);

    // ── Tytuł (lewo) + kod kreskowy i kwota (prawo) ───────────────────────────
    $leftW  = $W * 0.62;
    $rightX = 15 + $leftW + 5;
    $rightW = $W - $leftW - 5;
    $topY   = 23;

    $typLabel = KDOK_TYPES[$doc['type']]['label'] ?? $doc['type'];
    $pdf->SetXY(15, $topY);
    $pdf->SetFont('DejaVu', 'B', 8);
    $pdf->SetTextColor(...$ACCENT);
    $pdf->Cell($leftW, 4.5, _pdf(mb_strtoupper($typLabel) . '   ·   NR OBIEGU: ' . $doc['number']), 0, 1, 'L');
    $pdf->SetTextColor(...$INK);
    $pdf->SetX(15);
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->MultiCell($leftW, 6.5, _pdf($doc['title']), 0, 'L');

    if ($barcodeTmp) {
        $pdf->Image($barcodeTmp, $rightX + ($rightW - $barcodeW) / 2, $topY, $barcodeW, $barcodeH, 'PNG');
        @unlink($barcodeTmp);
        $pdf->SetFont('DejaVu', '', 6.5);
        $pdf->SetTextColor(...$INK);
        $pdf->SetXY($rightX, $topY + $barcodeH + 0.5);
        $pdf->Cell($rightW, 3.5, _pdf($doc['number']), 0, 1, 'C');
    }

    if ($doc['kwota']) {
        $boxY = $topY + $barcodeH + 5.5;
        $pdf->SetDrawColor(...$LINE);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($rightX, $boxY, $rightW, 11, 'DF');
        $pdf->SetFont('DejaVu', '', 6.5);
        $pdf->SetTextColor(120, 128, 132);
        $pdf->SetXY($rightX + 3, $boxY + 1.3);
        $pdf->Cell($rightW - 6, 3.5, _pdf('KWOTA DO WYPŁATY'), 0, 1, 'L');
        $pdf->SetFont('DejaVu', 'B', 11);
        $pdf->SetTextColor(...$GOLD);
        $pdf->SetXY($rightX + 3, $boxY + 4.8);
        $pdf->Cell($rightW - 6, 5.5, _pdf($doc['kwota'] . ' PLN'), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }

    $y = max($pdf->GetY(), $topY + $barcodeH + ($doc['kwota'] ? 17 : 6)) + 3;

    // ── Opis merytoryczny — wyróżniony blok ──────────────────────────────────
    // Wysokość tła liczona z realnego zawinięcia tekstu (_pdf_count_lines), a nie
    // z szacunku „liczba znaków / 100" — ten drugi rozjeżdżał się z MultiCell
    // i tekst wychodził poza narysowane tło.
    if ($doc['description']) {
        $descBodyW = $W - 8;
        $pdf->SetFont('DejaVu', '', 9);
        $descLines = _pdf_count_lines($pdf, $doc['description'], $descBodyW);
        $descH     = max(14, $descLines * 5 + 8);
        $pdf->SetDrawColor(...$LINE);
        $pdf->SetFillColor(250, 250, 249);
        $pdf->Rect(15, $y, $W, $descH, 'DF');
        $pdf->SetFillColor(...$ACCENT);
        $pdf->Rect(15, $y, 1.4, $descH, 'F'); // lewy pasek akcentu
        $pdf->SetFont('DejaVu', 'B', 7);
        $pdf->SetTextColor(...$ACCENT);
        $pdf->SetXY(20, $y + 2);
        $pdf->Cell(0, 4, _pdf('OPIS MERYTORYCZNY'), 0, 1);
        $pdf->SetFont('DejaVu', '', 9);
        $pdf->SetTextColor(...$INK);
        $pdf->SetXY(20, $y + 6);
        $pdf->MultiCell($descBodyW, 5, _pdf($doc['description']), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $y = max($pdf->GetY(), $y + $descH) + 3;
    }

    // MPK / Grant / Uwagi — pasek, teraz zawijany (MultiCell) zamiast jednej linii Cell,
    // która przy dłuższej treści wychodziła poza tło i poza margines strony.
    $extras = [];
    if ($doc['mpk'])        $extras[] = 'MPK: ' . $doc['mpk'];
    if ($doc['grant_name']) $extras[] = 'Grant: ' . $doc['grant_name'];
    if ($doc['uwagi'])      $extras[] = 'Uwagi: ' . $doc['uwagi'];
    if ($extras) {
        $extraBodyW = $W - 6;
        $extraText  = implode('     ·     ', $extras);
        $pdf->SetFont('DejaVu', '', 7.5);
        $extraLines = _pdf_count_lines($pdf, $extraText, $extraBodyW);
        $extraH     = max(6, $extraLines * 4.2 + 2.4);
        $pdf->SetFillColor(...$TINT);
        $pdf->Rect(15, $y, $W, $extraH, 'F');
        $pdf->SetTextColor(...$ACCENT);
        $pdf->SetXY(18, $y + 1.2);
        $pdf->MultiCell($extraBodyW, 4.2, _pdf($extraText), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $y = max($pdf->GetY(), $y + $extraH) + 1;
    }

    $y += 3;
    $pdf->SetY($y);

    // ── Tabela akceptacji ─────────────────────────────────────────────────────
    $pdf->SetX(15);
    $sectionLabel('Etapy akceptacji — podpis elektroniczny X.509 + WebAuthn/IKAKS');

    $cW = [75, 55, 32, 22, 83]; // etap | CN | data | decyzja | autoryzacja
    $pdf->SetDrawColor(...$LINE);
    $pdf->SetFillColor(...$TINT); $pdf->SetTextColor(...$INK); $pdf->SetFont('DejaVu', 'B', 7.5);
    $pdf->Cell($cW[0], 6, _pdf('Etap'),                  'B', 0, 'L', true);
    $pdf->Cell($cW[1], 6, _pdf('Imie i nazwisko (CN)'),  'B', 0, 'L', true);
    $pdf->Cell($cW[2], 6, _pdf('Data i godzina'),        'B', 0, 'C', true);
    $pdf->Cell($cW[3], 6, _pdf('Decyzja'),               'B', 0, 'C', true);
    $pdf->Cell($cW[4], 6, _pdf('Autoryzacja X.509'),     'B', 1, 'C', true);

    $stepDot = ['ok' => [46, 125, 90], 'uwagi' => [163, 116, 41], 'odrzucono' => [178, 58, 58], '' => [170, 175, 178]];
    $pdf->SetFont('DejaVu', '', 7.5);
    $rowAlt = false;
    foreach (['formal' => 'Sprawdzono formalnie i rachunkowo', 'meryt' => 'Sprawdzono merytorycznie', 'zatwierdza' => 'Zatwierdzono do wyplaty'] as $key => $label) {
        $step   = $doc['steps'][$key] ?? null;
        $status = $step['status'] ?? '';
        $dec    = match($status) { 'ok' => 'TAK', 'uwagi' => 'Z uwagami', 'odrzucono' => 'ODRZUCONO', default => 'Oczekuje' };
        $dt     = ($step && $step['decided_at']) ? date('d.m.Y H:i', strtotime($step['decided_at'])) : '—';
        $cn     = ($step['cert_cn'] ?? '') ?: ($step['user_name'] ?? '—');
        $fp     = $step['cert_fingerprint'] ?? '';
        $dot    = $stepDot[$status] ?? $stepDot[''];
        $rowAlt = !$rowAlt;
        // Ten sam kolor tła dla nagłówka WIERSZA i jego bloków SHA/Uwagi poniżej —
        // wcześniej SHA/Uwagi zawsze rysowały się na białym (fill=false), więc
        // przy zacienionym wierszu wyglądało to jak dwa osobne, "zlewające się"
        // elementy zamiast jednej spójnej sekcji tego samego etapu.
        $rf = $rowAlt ? [250, 250, 249] : [255, 255, 255];

        $pdf->SetFillColor(...$rf);
        $pdf->Cell($cW[0], 6.5, _pdf($label),  'B', 0, 'L', true);
        $pdf->Cell($cW[1], 6.5, _pdf($cn),     'B', 0, 'L', true);
        $pdf->Cell($cW[2], 6.5, _pdf($dt),     'B', 0, 'C', true);
        $pdf->SetFont('DejaVu', 'B', 7.5);
        $pdf->SetTextColor(...$dot);
        $pdf->Cell($cW[3], 6.5, _pdf($dec), 'B', 0, 'C', true);
        $pdf->SetFont('DejaVu', '', 7.5);
        $pdf->SetTextColor(...$INK);
        $pdf->Cell($cW[4], 6.5, _pdf($fp ? 'X.509 + WebAuthn/IKAKS' : '—'), 'B', 1, 'C', true);

        if ($fp) {
            $pdf->SetFont('DejaVu', '', 5.5); $pdf->SetTextColor(120, 128, 132); $pdf->SetX(15);
            $pdf->SetFillColor(...$rf);
            $fc = str_replace(':', '', $fp);
            $pdf->MultiCell($W, 3.6, _pdf('SHA-256  ' . substr($fc, 0, 32) . "\n              " . substr($fc, 32)), 'B', 'L', true);
            $pdf->SetFont('DejaVu', '', 7.5); $pdf->SetTextColor(...$INK);
        }
        if ($step && $step['notes']) {
            $pdf->SetFont('DejaVu', '', 6.5); $pdf->SetTextColor(...$GOLD); $pdf->SetX(15);
            $pdf->SetFillColor(...$rf);
            $pdf->MultiCell($W, 4, _pdf('Uwagi: ' . $step['notes']), 'B', 'L', true);
            $pdf->SetFont('DejaVu', '', 7.5); $pdf->SetTextColor(...$INK);
        }
        // Odstęp między etapami, żeby granica jednego etapu i początek kolejnego
        // były jednoznaczne nawet gdy oba mają to samo tło (rowAlt).
        $pdf->SetY($pdf->GetY() + 1.2);
    }
    $pdf->SetDrawColor(...$LINE);
    $pdf->Line(15, $pdf->GetY(), 15 + $W, $pdf->GetY());
    $pdf->SetTextColor(0, 0, 0);

    $y = $pdf->GetY() + 5;

    // ── Historia obiegu ───────────────────────────────────────────────────────
    if ($y > $LIM - 30) { $pdf->AddPage('L', 'A4'); $y = 15; }
    $pdf->SetXY(15, $y);
    $sectionLabel('Historia obiegu');

    $hW = [34, 58, $W - 92];
    $drawHistoryHeader = function () use ($pdf, $hW, $TINT, $INK) {
        $pdf->SetFillColor(...$TINT); $pdf->SetTextColor(...$INK); $pdf->SetFont('DejaVu', 'B', 7.5);
        $pdf->Cell($hW[0], 5.5, _pdf('Data i czas'), 'B', 0, 'C', true);
        $pdf->Cell($hW[1], 5.5, _pdf('Uzytkownik'),  'B', 0, 'C', true);
        $pdf->Cell($hW[2], 5.5, _pdf('Zdarzenie'),   'B', 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('DejaVu', '', 7.5);
    };
    $drawHistoryHeader();

    // "Zdarzenie" renderowane teraz przez MultiCell (zawija długi tekst) zamiast
    // Cell (który przy dłuższej notatce wychodził poza kolumnę i poza margines
    // strony — widoczne obcięte słowa na prawym brzegu). Wysokość wiersza liczona
    // z realnej liczby linii, więc wysoki wiersz nie zostaje przecięty na granicy
    // strony — a jeśli i tak trafi na nową stronę, nagłówek tabeli jest powtórzony.
    $alt = false;
    foreach ($history as $row) {
        $txt   = $row['action'] . ($row['note'] ? ': ' . $row['note'] : '');
        $lineH = 4.2;
        $lines = _pdf_count_lines($pdf, $txt, $hW[2] - 4);
        $rowH  = max(5.5, $lines * $lineH + 1.2);

        if ($pdf->GetY() + $rowH > $LIM) {
            $pdf->AddPage('L', 'A4');
            $drawHistoryHeader();
        }
        $alt = !$alt;
        $pdf->SetFillColor($alt ? 250 : 255, $alt ? 250 : 255, $alt ? 249 : 255);
        $pdf->Cell($hW[0], $rowH, _pdf(date('d.m.Y H:i', strtotime($row['created_at']))), 'B', 0, 'C', true);
        $pdf->Cell($hW[1], $rowH, _pdf($row['user_name']), 'B', 0, 'L', true);
        $pdf->MultiCell($hW[2], $lineH, _pdf($txt), 'B', 'L', true);
    }
    $pdf->SetDrawColor(...$LINE);
    $pdf->Line(15, $pdf->GetY(), 15 + $W, $pdf->GetY());

    $y = $pdf->GetY() + 5;

    // ── Klauzula ─────────────────────────────────────────────────────────────
    if ($y > $LIM - 22) { $pdf->AddPage('L', 'A4'); $y = 15; }
    $pdf->SetXY(15, $y);
    $sectionLabel('Klauzula zatwierdzenia elektronicznego');
    $pdf->SetDrawColor(...$LINE);
    $pdf->SetFillColor(250, 250, 249);
    $pdf->SetFont('DejaVu', '', 7);
    $pdf->SetTextColor(...$INK);
    $klauzula = 'Niniejszy dokument zostal zatwierdzony elektronicznie w systemie EOD Dokumentow Ksiegowych ' . $org
        . '. Elektroniczne zatwierdzenie jest rownowazne z podpisem wlasnorecznym (art. 7 ustawy o rachunkowosci,'
        . ' Dz.U. 2023 poz. 120). Kazdy etap akceptacji wymagal certyfikatu X.509 oraz klucza sprzetowego WebAuthn'
        . ' (lub, w przypadku braku klucza, kodu IKAKS).';
    $pdf->MultiCell($W, 4, _pdf($klauzula), 1, 'J', true);
    $pdf->SetFont('DejaVu', '', 6);
    $pdf->SetTextColor(120, 128, 132);
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

    kdok_log($doc_id, 'Wygenerowano dokument końcowy',
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
