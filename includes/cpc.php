<?php
/**
 * CPC — Critical Process Code
 *
 * Zapewnia:
 *  - migrację bazy (cpc_migrate)
 *  - weryfikację i zarządzanie kodem CPC użytkownika
 *  - awaryjne kody SMS
 *  - obsługę zgody na dokumentową formę umów
 *  - pomocnicze funkcje dla opiekunów i statusu użytkownika
 *
 * Wymaga: db(), db_one() z includes/db.php
 *         sms_send(), sms_is_enabled() z includes/sms.php
 *         stałej ORG_NAME oraz DB_TYPE
 */

if (defined('CPC_PHP_LOADED')) return;
define('CPC_PHP_LOADED', true);

// =============================================================================
// 1. MIGRACJA BAZY DANYCH
// =============================================================================

function cpc_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();
    $is_sqlite = (DB_TYPE === 'sqlite');

    // -------------------------------------------------------------------------
    // Kolumny tabeli users
    // -------------------------------------------------------------------------
    $users_columns = [
        'first_name'                => "ALTER TABLE users ADD COLUMN first_name TEXT NOT NULL DEFAULT ''",
        'last_name'                 => "ALTER TABLE users ADD COLUMN last_name TEXT NOT NULL DEFAULT ''",
        'user_status'               => "ALTER TABLE users ADD COLUMN user_status TEXT NOT NULL DEFAULT 'active'",
        'cpc_code'                  => "ALTER TABLE users ADD COLUMN cpc_code TEXT NULL",
        'phone_number'              => "ALTER TABLE users ADD COLUMN phone_number TEXT NOT NULL DEFAULT ''",
        'alt_phone_number'          => "ALTER TABLE users ADD COLUMN alt_phone_number TEXT NULL",
        'alt_email'                 => "ALTER TABLE users ADD COLUMN alt_email TEXT NULL",
        'use_alt_notifications'     => "ALTER TABLE users ADD COLUMN use_alt_notifications INTEGER NOT NULL DEFAULT 0",
        'document_form_consent'     => "ALTER TABLE users ADD COLUMN document_form_consent INTEGER NOT NULL DEFAULT 0",
        'consent_accepted_at'       => "ALTER TABLE users ADD COLUMN consent_accepted_at DATETIME NULL",
        'consent_text_version_hash' => "ALTER TABLE users ADD COLUMN consent_text_version_hash TEXT NULL",
        'sms_fallback_code'         => "ALTER TABLE users ADD COLUMN sms_fallback_code TEXT NULL",
        'sms_fallback_expires_at'   => "ALTER TABLE users ADD COLUMN sms_fallback_expires_at DATETIME NULL",
        'cpc_fails'                 => "ALTER TABLE users ADD COLUMN cpc_fails INTEGER NOT NULL DEFAULT 0",
        'cpc_blocked_until'         => "ALTER TABLE users ADD COLUMN cpc_blocked_until DATETIME NULL",
        'is_minor'                  => "ALTER TABLE users ADD COLUMN is_minor INTEGER NOT NULL DEFAULT 0",
        'guardian_name'             => "ALTER TABLE users ADD COLUMN guardian_name TEXT NULL",
        'guardian_email'            => "ALTER TABLE users ADD COLUMN guardian_email TEXT NULL",
        'guardian_phone'            => "ALTER TABLE users ADD COLUMN guardian_phone TEXT NULL",
        'activation_token'          => "ALTER TABLE users ADD COLUMN activation_token TEXT NULL",
        'ika_revoked_at'            => "ALTER TABLE users ADD COLUMN ika_revoked_at DATETIME NULL",
        'portal_scope'              => "ALTER TABLE users ADD COLUMN portal_scope TEXT NULL",
        'crm_ika_required'          => "ALTER TABLE users ADD COLUMN crm_ika_required INTEGER NULL",
        'is_standalone_volunteer'   => "ALTER TABLE users ADD COLUMN is_standalone_volunteer INTEGER NOT NULL DEFAULT 0",
        'ika_email_otp'             => "ALTER TABLE users ADD COLUMN ika_email_otp TEXT NULL",
        'ika_email_otp_expires'     => "ALTER TABLE users ADD COLUMN ika_email_otp_expires DATETIME NULL",
        'm365_login'                => "ALTER TABLE users ADD COLUMN m365_login TEXT NULL",
        'm365_security_group_id'    => "ALTER TABLE users ADD COLUMN m365_security_group_id TEXT NULL",
        'm365_security_group_name'  => "ALTER TABLE users ADD COLUMN m365_security_group_name TEXT NULL",
        'org_unit_id'               => "ALTER TABLE users ADD COLUMN org_unit_id INTEGER NULL",
    ];

    foreach ($users_columns as $col => $sql) {
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            // Kolumna już istnieje — ignorujemy
        }
    }

    // -------------------------------------------------------------------------
    // Kolumny guardian dla tabel umów
    // -------------------------------------------------------------------------
    $contract_tables = ['wolontariat', 'zlecenie', 'dzielo', 'praca'];
    $guardian_columns = [
        'guardian_editor_id' => "ALTER TABLE umowy_{tbl} ADD COLUMN guardian_editor_id INTEGER NULL",
        'guardian_initials'  => "ALTER TABLE umowy_{tbl} ADD COLUMN guardian_initials TEXT NULL",
    ];

    foreach ($contract_tables as $tbl) {
        foreach ($guardian_columns as $col => $sql_tpl) {
            $sql = str_replace('{tbl}', $tbl, $sql_tpl);
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                // Kolumna już istnieje — ignorujemy
            }
        }
    }

    // -------------------------------------------------------------------------
    // Kolumny specyficzne dla umów wolontariat
    // -------------------------------------------------------------------------
    $wolontariat_extra = [
        // Cudzoziemcy
        'id_document_type'    => "ALTER TABLE umowy_wolontariat ADD COLUMN id_document_type TEXT NULL",
        'id_document_number'  => "ALTER TABLE umowy_wolontariat ADD COLUMN id_document_number TEXT NULL",
        'no_pesel_reason'     => "ALTER TABLE umowy_wolontariat ADD COLUMN no_pesel_reason TEXT NULL",
        // Małoletni
        'rodzic_imie_nazwisko' => "ALTER TABLE umowy_wolontariat ADD COLUMN rodzic_imie_nazwisko TEXT NULL",
        'rodzic_email'         => "ALTER TABLE umowy_wolontariat ADD COLUMN rodzic_email TEXT NULL",
        'rodzic_telefon'       => "ALTER TABLE umowy_wolontariat ADD COLUMN rodzic_telefon TEXT NULL",
        // Finanse
        'limit_zwrotu_kosztow' => "ALTER TABLE umowy_wolontariat ADD COLUMN limit_zwrotu_kosztow DECIMAL(10,2) NULL",
        // Powiązania
        'action_id'            => "ALTER TABLE umowy_wolontariat ADD COLUMN action_id INTEGER NULL",
        'grant_id'             => "ALTER TABLE umowy_wolontariat ADD COLUMN grant_id INTEGER NULL",
        'org_unit_id'          => "ALTER TABLE umowy_wolontariat ADD COLUMN org_unit_id INTEGER NULL",
        'person_id'            => "ALTER TABLE umowy_wolontariat ADD COLUMN person_id INTEGER NULL",
        // Adres korespondencyjny
        'adres_odbiorca'       => "ALTER TABLE umowy_wolontariat ADD COLUMN adres_odbiorca TEXT NULL",
        'adres_linia1'         => "ALTER TABLE umowy_wolontariat ADD COLUMN adres_linia1 TEXT NULL",
        'adres_linia2'         => "ALTER TABLE umowy_wolontariat ADD COLUMN adres_linia2 TEXT NULL",
        'adres_kod_pocztowy'   => "ALTER TABLE umowy_wolontariat ADD COLUMN adres_kod_pocztowy TEXT NULL",
        'adres_miasto'         => "ALTER TABLE umowy_wolontariat ADD COLUMN adres_miasto TEXT NULL",
        'adres_kraj'           => "ALTER TABLE umowy_wolontariat ADD COLUMN adres_kraj TEXT NULL",
        'addr_street'          => "ALTER TABLE umowy_wolontariat ADD COLUMN addr_street TEXT NULL",
        'addr_house'           => "ALTER TABLE umowy_wolontariat ADD COLUMN addr_house TEXT NULL",
        'addr_flat'            => "ALTER TABLE umowy_wolontariat ADD COLUMN addr_flat TEXT NULL",
        'addr_postal'          => "ALTER TABLE umowy_wolontariat ADD COLUMN addr_postal TEXT NULL",
        'addr_city'            => "ALTER TABLE umowy_wolontariat ADD COLUMN addr_city TEXT NULL",
        'addr_country'         => "ALTER TABLE umowy_wolontariat ADD COLUMN addr_country TEXT NULL",
        // WebNGO
        'z_webngo'             => "ALTER TABLE umowy_wolontariat ADD COLUMN z_webngo INTEGER NOT NULL DEFAULT 0",
        'webngo_id'            => "ALTER TABLE umowy_wolontariat ADD COLUMN webngo_id TEXT NULL",
        'webngo_numer_umowy'   => "ALTER TABLE umowy_wolontariat ADD COLUMN webngo_numer_umowy TEXT NULL",
        // Kwalifikowany podpis elektroniczny
        'epodpis_dostawca'          => "ALTER TABLE umowy_wolontariat ADD COLUMN epodpis_dostawca TEXT NULL",
        'epodpis_nr_certyfikatu'    => "ALTER TABLE umowy_wolontariat ADD COLUMN epodpis_nr_certyfikatu TEXT NULL",
        'epodpis_data_waznosci'     => "ALTER TABLE umowy_wolontariat ADD COLUMN epodpis_data_waznosci DATE NULL",
        // Zakres dostępu portalu
        'portal_scope'              => "ALTER TABLE umowy_wolontariat ADD COLUMN portal_scope TEXT NULL",
        // Canva
        'canva_access'              => "ALTER TABLE umowy_wolontariat ADD COLUMN canva_access INTEGER NOT NULL DEFAULT 0",
        'canva_invited_at'          => "ALTER TABLE umowy_wolontariat ADD COLUMN canva_invited_at DATETIME NULL",
        'canva_email_sent_at'       => "ALTER TABLE umowy_wolontariat ADD COLUMN canva_email_sent_at DATETIME NULL",
        // Prośba o Canva (składana przez wolontariusza z panelu)
        'canva_access_requested_at' => "ALTER TABLE umowy_wolontariat ADD COLUMN canva_access_requested_at DATETIME NULL",
        // Token jednorazowy do uzupełnienia danych przez wolontariusza (przed 01.06.2026)
        'data_token'                => "ALTER TABLE umowy_wolontariat ADD COLUMN data_token TEXT NULL",
        'data_token_used_at'        => "ALTER TABLE umowy_wolontariat ADD COLUMN data_token_used_at DATETIME NULL",
    ];

    foreach ($wolontariat_extra as $col => $sql) {
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            // Kolumna już istnieje — ignorujemy
        }
    }

    // -------------------------------------------------------------------------
    // Tabela sesji QR check-in
    // -------------------------------------------------------------------------
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS checkin_sessions (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_id  INTEGER NOT NULL,
            user_id      INTEGER NOT NULL,
            date         TEXT    NOT NULL,
            time_start   TEXT    NOT NULL,
            time_end     TEXT    NULL,
            date_end     TEXT    NULL,
            hours_total  REAL    NULL,
            status       TEXT    NOT NULL DEFAULT 'open',
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\PDOException $e) {}

    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_checkin_contract_user
            ON checkin_sessions(contract_id, user_id, status)");
    } catch (\PDOException $e) {}

    // Dodatkowe kolumny timesheets (QR check-in)
    $timesheets_extra = [
        'hours_start' => "ALTER TABLE timesheets ADD COLUMN hours_start TEXT NULL",
        'hours_end'   => "ALTER TABLE timesheets ADD COLUMN hours_end TEXT NULL",
        'ts_status'   => "ALTER TABLE timesheets ADD COLUMN status TEXT NOT NULL DEFAULT 'zatwierdzone'",
    ];
    foreach ($timesheets_extra as $col => $sql) {
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            // Kolumna już istnieje — ignorujemy
        }
    }

    // -------------------------------------------------------------------------
    // Seedowanie tabeli settings
    // -------------------------------------------------------------------------
    $seeds = [
        'onboarding_info_text'        => 'Ten panel służy wyłącznie do zarządzania Twoimi umowami, zaświadczeniami i zadaniami. Nie jest skrzynką e-mail ani narzędziem komunikacji organizacyjnej.',
        'consent_document_form_text'  => 'Wyrażam zgodę na dokumentową formę zawierania i modyfikowania umów oraz korespondencji w rozumieniu art. 77³ Kodeksu Cywilnego. Dokumenty elektroniczne przesyłane za pośrednictwem tego portalu mają równoważną moc prawną z dokumentami papierowymi.',
    ];

    if ($is_sqlite) {
        $seed_sql = "INSERT OR IGNORE INTO settings (key_, value) VALUES (:key_, :value)";
    } else {
        $seed_sql = "INSERT IGNORE INTO settings (key_, value) VALUES (:key_, :value)";
    }

    $seed_stmt = $pdo->prepare($seed_sql);
    foreach ($seeds as $key => $value) {
        try {
            $seed_stmt->execute([':key_' => $key, ':value' => $value]);
        } catch (\PDOException $e) {
            // Ignorujemy — tabela może nie mieć jeszcze tej kolumny
        }
    }
}

// =============================================================================
// 2. FUNKCJE POMOCNICZE CPC
// =============================================================================

/**
 * Sprawdza czy użytkownik może używać kodu CPC
 * (rola admin lub editor ORAZ ustawiony cpc_code).
 */
function cpc_user_can_use(array $user): bool {
    $role = $user['role'] ?? '';
    if ($role !== 'admin' && $role !== 'editor') return false;
    return !empty($user['cpc_code']);
}

/**
 * Sprawdza czy konto CPC jest zablokowane z powodu zbyt wielu błędnych prób.
 */
function cpc_is_blocked(array $user): bool {
    $blocked_until = $user['cpc_blocked_until'] ?? null;
    if (empty($blocked_until)) return false;
    return $blocked_until > date('Y-m-d H:i:s');
}

/**
 * Weryfikuje kod CPC podany przez użytkownika.
 *
 * @return array{ok: bool, blocked: bool, blocked_until: string|null, fails: int}
 */
function cpc_verify(int $user_id, string $input_code): array {
    $user = db_one("SELECT * FROM users WHERE id = ?", [$user_id]);

    if (!$user) {
        return ['ok' => false, 'blocked' => false, 'blocked_until' => null, 'fails' => 0];
    }

    if (cpc_is_blocked($user)) {
        return [
            'ok'            => false,
            'blocked'       => true,
            'blocked_until' => $user['cpc_blocked_until'],
            'fails'         => (int) ($user['cpc_fails'] ?? 0),
        ];
    }

    $input_trimmed = trim($input_code);
    $stored_code   = (string) ($user['cpc_code'] ?? '');

    // Porównanie stałoczasowe — wyrównujemy długość do 64 znaków
    $pad = 64;
    $a   = str_pad($input_trimmed, $pad, "\0");
    $b   = str_pad($stored_code,   $pad, "\0");
    $match = hash_equals($b, $a);

    if ($match) {
        db()->prepare("UPDATE users SET cpc_fails = 0, cpc_blocked_until = NULL WHERE id = ?")
             ->execute([$user_id]);
        return ['ok' => true, 'blocked' => false, 'blocked_until' => null, 'fails' => 0];
    }

    // Błędny kod — inkrementujemy licznik
    $fails = (int) ($user['cpc_fails'] ?? 0) + 1;
    $blocked_until = null;
    $is_blocked    = false;

    if ($fails >= 3) {
        $blocked_until = date('Y-m-d H:i:s', time() + 15 * 60);
        $is_blocked    = true;
        db()->prepare("UPDATE users SET cpc_fails = ?, cpc_blocked_until = ? WHERE id = ?")
             ->execute([$fails, $blocked_until, $user_id]);
    } else {
        db()->prepare("UPDATE users SET cpc_fails = ? WHERE id = ?")
             ->execute([$fails, $user_id]);
    }

    return [
        'ok'            => false,
        'blocked'       => $is_blocked,
        'blocked_until' => $blocked_until,
        'fails'         => $fails,
    ];
}

/**
 * Generuje kryptograficznie losowy 6-cyfrowy kod CPC.
 */
function cpc_generate(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// =============================================================================
// 3. AWARYJNY KOD SMS
// =============================================================================

/**
 * Generuje i wysyła awaryjny kod weryfikacyjny przez SMS.
 *
 * @return array{ok: bool, error: string|null}
 */
function cpc_send_sms_fallback(int $user_id): array {
    $user = db_one("SELECT * FROM users WHERE id = ?", [$user_id]);

    if (!$user) {
        return ['ok' => false, 'error' => 'Użytkownik nie istnieje.'];
    }

    if (!sms_is_enabled()) {
        return ['ok' => false, 'error' => 'Bramka SMS jest wyłączona.'];
    }

    $code    = cpc_generate();
    $expires = date('Y-m-d H:i:s', time() + 10 * 60);

    db()->prepare("UPDATE users SET sms_fallback_code = ?, sms_fallback_expires_at = ? WHERE id = ?")
         ->execute([$code, $expires, $user_id]);

    $org     = defined('ORG_NAME') ? ORG_NAME : '';
    $message = "Kod awaryjny weryfikacji: {$code}. Ważny 10 minut. [{$org}]";

    // Ustalenie numerów docelowych
    $use_alt     = !empty($user['use_alt_notifications']);
    $primary     = !empty($user['phone_number']) ? $user['phone_number'] : ($user['twofa_phone'] ?? '');
    $alt         = !empty($user['alt_phone_number']) ? $user['alt_phone_number'] : '';

    $phones = [];
    if (!empty($primary)) {
        $phones[] = $primary;
    }
    if ($use_alt && !empty($alt)) {
        $phones[] = $alt;
    }

    if (empty($phones)) {
        return ['ok' => false, 'error' => 'Brak numeru telefonu dla użytkownika.'];
    }

    try {
        foreach ($phones as $phone) {
            sms_send($phone, $message);
        }
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Weryfikuje awaryjny kod SMS.
 * Po poprawnej weryfikacji czyści kod z bazy.
 */
function cpc_verify_sms_fallback(int $user_id, string $input_code): bool {
    $user = db_one("SELECT * FROM users WHERE id = ?", [$user_id]);

    if (!$user) return false;

    $stored  = (string) ($user['sms_fallback_code'] ?? '');
    $expires = $user['sms_fallback_expires_at'] ?? null;

    if (empty($stored) || empty($expires)) return false;

    $code_matches    = hash_equals($stored, trim($input_code));
    $not_expired     = $expires > date('Y-m-d H:i:s');

    if ($code_matches && $not_expired) {
        db()->prepare("UPDATE users SET sms_fallback_code = NULL, sms_fallback_expires_at = NULL WHERE id = ?")
             ->execute([$user_id]);
        return true;
    }

    return false;
}

// =============================================================================
// 4. FUNKCJE ZGODY NA DOKUMENTOWĄ FORMĘ UMÓW
// =============================================================================

/**
 * Pobiera treść zgody z tabeli settings.
 */
function consent_get_text(): string {
    $row = db_one("SELECT value FROM settings WHERE key_ = ?", ['consent_document_form_text']);
    return $row['value'] ?? '';
}

/**
 * Zwraca MD5 aktualnej treści zgody (identyfikator wersji).
 */
function consent_current_hash(): string {
    return md5(consent_get_text());
}

/**
 * Sprawdza czy użytkownik zaakceptował aktualną wersję zgody.
 */
function consent_check(array $user): bool {
    if (empty($user['document_form_consent'])) return false;
    return ($user['consent_text_version_hash'] ?? '') === consent_current_hash();
}

/**
 * Zapisuje akceptację zgody przez użytkownika.
 * Jeśli status był 'pending_activation', zmienia go na 'active'.
 */
function consent_save(int $user_id, string $ip): void {
    $now  = date('Y-m-d H:i:s');
    $hash = consent_current_hash();

    $user = db_one("SELECT user_status FROM users WHERE id = ?", [$user_id]);
    $new_status = ($user && $user['user_status'] === 'pending_activation') ? 'active' : null;

    if ($new_status !== null) {
        db()->prepare(
            "UPDATE users
             SET document_form_consent = 1,
                 consent_accepted_at = ?,
                 consent_text_version_hash = ?,
                 user_status = ?
             WHERE id = ?"
        )->execute([$now, $hash, $new_status, $user_id]);
    } else {
        db()->prepare(
            "UPDATE users
             SET document_form_consent = 1,
                 consent_accepted_at = ?,
                 consent_text_version_hash = ?
             WHERE id = ?"
        )->execute([$now, $hash, $user_id]);
    }
}

// =============================================================================
// 5. POMOCNIK INICJAŁÓW OPIEKUNA
// =============================================================================

/**
 * Zwraca inicjały opiekuna (2 wielkie litery) na podstawie imienia i nazwiska
 * lub pełnego imienia i nazwiska jako fallback.
 */
function guardian_initials(string $first_name, string $last_name, string $full_name = ''): string {
    if ($first_name !== '' && $last_name !== '') {
        $f = mb_strtoupper(mb_substr($first_name, 0, 1, 'UTF-8'), 'UTF-8');
        $l = mb_strtoupper(mb_substr($last_name,  0, 1, 'UTF-8'), 'UTF-8');
        return $f . $l;
    }

    $tokens = preg_split('/\s+/', trim($full_name), -1, PREG_SPLIT_NO_EMPTY);
    if (count($tokens) < 2) return '';

    $f = mb_strtoupper(mb_substr($tokens[0],                  0, 1, 'UTF-8'), 'UTF-8');
    $l = mb_strtoupper(mb_substr($tokens[count($tokens) - 1], 0, 1, 'UTF-8'), 'UTF-8');
    return $f . $l;
}

// =============================================================================
// 6. SYNCHRONIZACJA STATUSU UŻYTKOWNIKA (LEGACY)
// =============================================================================

/**
 * Ustawia user_status na podstawie is_active dla wierszy bez statusu.
 * Przeznaczone do wywołania przy każdym odczycie użytkownika ze starego schematu.
 */
function user_sync_status(int $user_id): void {
    $user = db_one("SELECT is_active, user_status FROM users WHERE id = ?", [$user_id]);

    if (!$user) return;

    $status = $user['user_status'] ?? '';
    if ($status !== '' && $status !== null) return;

    $new_status = ((int) ($user['is_active'] ?? 0) === 1) ? 'active' : 'blocked';

    db()->prepare("UPDATE users SET user_status = ? WHERE id = ?")
         ->execute([$new_status, $user_id]);
}
