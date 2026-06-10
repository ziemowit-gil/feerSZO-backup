<?php
/**
 * Moduł IT — helpery dla "Dostępy i Infrastruktura"
 */

/**
 * Jednorazowa migracja tabel IT + seed domyślnych serwisów.
 * Wywołuj na początku każdego pliku korzystającego z modułu IT.
 */
function it_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();

    // it_services
    $pdo->exec("CREATE TABLE IF NOT EXISTS it_services (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT    NOT NULL,
        slug       TEXT    NOT NULL UNIQUE,
        icon       TEXT    DEFAULT 'bi-hdd-network',
        color      TEXT    DEFAULT '#2563eb',
        is_active  INTEGER DEFAULT 1,
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT (datetime('now','localtime'))
    )");
    // kolumna is_active mogła nie istnieć we wcześniejszych schematach
    try { $pdo->exec("ALTER TABLE it_services ADD COLUMN is_active INTEGER DEFAULT 1"); } catch (\Throwable $e) {}

    // it_accounts
    $pdo->exec("CREATE TABLE IF NOT EXISTS it_accounts (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        service_id       INTEGER NOT NULL,
        contract_type    TEXT,
        contract_id      INTEGER,
        person_id        INTEGER,
        login            TEXT,
        external_id      TEXT,
        display_name     TEXT,
        is_active        INTEGER DEFAULT 1,
        license_assigned INTEGER DEFAULT 0,
        scope            TEXT,
        notes            TEXT,
        deactivated_at   DATETIME,
        deactivated_by   INTEGER,
        created_by       INTEGER,
        created_at       DATETIME DEFAULT (datetime('now','localtime')),
        updated_at       DATETIME DEFAULT (datetime('now','localtime'))
    )");

    // it_service_passwords
    $pdo->exec("CREATE TABLE IF NOT EXISTS it_service_passwords (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        account_id    INTEGER,
        service_id    INTEGER,
        contract_type TEXT,
        contract_id   INTEGER,
        person_id     INTEGER,
        login         TEXT,
        password_enc  TEXT,
        sent_to_email TEXT,
        sent_at       DATETIME,
        notes         TEXT,
        issued_by     INTEGER,
        issued_at     DATETIME DEFAULT (datetime('now','localtime')),
        expires_at    DATETIME,
        is_superseded INTEGER DEFAULT 0
    )");

    // Seed domyślnych serwisów IT
    $defaults = [
        ['Microsoft 365',       'm365',   'bi-microsoft',      '#0078d4', 1],
        ['Panel wolontariusza', 'panel',  'bi-person-circle',  '#7c3aed', 2],
        ['Canva Pro',           'canva',  'bi-palette',        '#8b5cf6', 3],
    ];
    foreach ($defaults as [$name, $slug, $icon, $color, $order]) {
        if (!db_one("SELECT id FROM it_services WHERE slug=?", [$slug])) {
            db_insert('it_services', [
                'name'       => $name,
                'slug'       => $slug,
                'icon'       => $icon,
                'color'      => $color,
                'sort_order' => $order,
                'is_active'  => 1,
            ]);
        }
    }
}

function it_encrypt_password(string $plain): string {
    $key = substr(hash('sha256', APP_KEY, true), 0, 32);
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function it_decrypt_password(string $enc): ?string {
    try {
        $key  = substr(hash('sha256', APP_KEY, true), 0, 32);
        $raw  = base64_decode($enc);
        $iv   = substr($raw, 0, 16);
        $data = substr($raw, 16);
        $dec  = openssl_decrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $dec !== false ? $dec : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Zapisz wydane hasło (wywołaj zaraz po wygenerowaniu, przed wysłaniem).
 */
function it_log_password(array $data): int {
    $plain = $data['plain'] ?? '';
    $enc   = $plain ? it_encrypt_password($plain) : null;

    // Poprzednie hasła dla tego konta oznacz jako wygasłe
    if (!empty($data['account_id'])) {
        db()->prepare("UPDATE it_service_passwords SET is_superseded=1 WHERE account_id=? AND is_superseded=0")
            ->execute([(int)$data['account_id']]);
    }

    return db_insert('it_service_passwords', [
        'account_id'    => $data['account_id']    ?? null,
        'service_id'    => $data['service_id'],
        'contract_type' => $data['contract_type'] ?? null,
        'contract_id'   => $data['contract_id']   ?? null,
        'person_id'     => $data['person_id']      ?? null,
        'login'         => $data['login']          ?? null,
        'password_enc'  => $enc,
        'sent_to_email' => $data['sent_to_email']  ?? null,
        'sent_at'       => !empty($data['sent_to_email']) ? date('Y-m-d H:i:s') : null,
        'notes'         => $data['notes']          ?? null,
        'issued_by'     => $data['issued_by']      ?? (current_user()['id'] ?? null),
        'issued_at'     => date('Y-m-d H:i:s'),
        'expires_at'    => $data['expires_at']     ?? null,
    ]);
}

/**
 * Zsynchronizuj lub utwórz rekord it_accounts na podstawie danych z umowy.
 * Zwraca ID rekordu it_accounts.
 */
function it_sync_account(string $contract_type, int $contract_id, string $service_slug, array $data): int {
    $svc = db_one("SELECT id FROM it_services WHERE slug=?", [$service_slug]);
    if (!$svc) throw new \RuntimeException("Nieznany serwis: {$service_slug}");
    $service_id = (int)$svc['id'];

    $existing = db_one(
        "SELECT id FROM it_accounts WHERE service_id=? AND contract_type=? AND contract_id=?",
        [$service_id, $contract_type, $contract_id]
    );

    $fields = array_filter([
        'service_id'       => $service_id,
        'contract_type'    => $contract_type,
        'contract_id'      => $contract_id,
        'login'            => $data['login']            ?? null,
        'external_id'      => $data['external_id']      ?? null,
        'display_name'     => $data['display_name']     ?? null,
        'is_active'        => $data['is_active']        ?? 1,
        'license_assigned' => $data['license_assigned'] ?? 0,
        'scope'            => isset($data['scope']) ? json_encode($data['scope']) : null,
        'notes'            => $data['notes']            ?? null,
        'updated_at'       => date('Y-m-d H:i:s'),
    ], fn($v) => $v !== null);

    if ($existing) {
        db_update('it_accounts', $fields, (int)$existing['id']);
        return (int)$existing['id'];
    } else {
        $fields['created_by'] = current_user()['id'] ?? null;
        $fields['created_at'] = date('Y-m-d H:i:s');
        return db_insert('it_accounts', $fields);
    }
}

/**
 * Zsynchronizuj kolumny m365_* z umowy do it_accounts (oba kierunki spójności).
 */
function it_sync_from_contract(string $type, array $row): void {
    if (empty($row['m365_konto']) || empty($row['m365_login'])) return;
    it_sync_account($type, (int)$row['id'], 'm365', [
        'login'            => $row['m365_login'],
        'external_id'      => $row['m365_user_id'] ?? null,
        'display_name'     => $row['imie_nazwisko'] ?? null,
        'is_active'        => (int)($row['m365_konto_aktywne'] ?? 0),
        'license_assigned' => (int)($row['m365_licencja_przypisana'] ?? 0),
    ]);
}

/**
 * Zwróć wszystkie konta IT dla danej umowy.
 */
function it_accounts_for_contract(string $type, int $id): array {
    return db_all(
        "SELECT a.*, s.name AS service_name, s.icon AS service_icon, s.color AS service_color, s.slug AS service_slug
         FROM it_accounts a
         JOIN it_services s ON s.id = a.service_id
         WHERE a.contract_type=? AND a.contract_id=?
         ORDER BY s.sort_order, a.id",
        [$type, $id]
    );
}

/**
 * Zwróć historię haseł dla konta IT.
 */
function it_passwords_for_account(int $account_id, int $limit = 10): array {
    return db_all(
        "SELECT p.*, u.name AS issued_by_name
         FROM it_service_passwords p
         LEFT JOIN users u ON u.id = p.issued_by
         WHERE p.account_id=?
         ORDER BY p.issued_at DESC LIMIT ?",
        [$account_id, $limit]
    );
}

/**
 * Zwróć etykietę dla typu umowy.
 */
function it_contract_label(string $type): string {
    return match ($type) {
        'wolontariat' => 'Wolontariusz',
        'zlecenie'    => 'Zleceniobiorca',
        'dzielo'      => 'Wykonawca',
        'praca'       => 'Pracownik',
        default       => ucfirst($type),
    };
}

/**
 * Link do widoku umowy.
 */
function it_contract_url(string $type, int $id): string {
    return APP_URL . "/contracts/{$type}/view.php?id={$id}";
}

/**
 * Numer umowy dla kontrakt-id.
 */
function it_contract_number(string $type, int $id): string {
    static $cache = [];
    $key = "{$type}:{$id}";
    if (!isset($cache[$key])) {
        $table = match ($type) {
            'wolontariat' => 'umowy_wolontariat',
            'zlecenie'    => 'umowy_zlecenie',
            'dzielo'      => 'umowy_dzielo',
            'praca'       => 'umowy_praca',
            default       => null,
        };
        if ($table) {
            $r = db_one("SELECT numer_umowy, imie_nazwisko FROM {$table} WHERE id=?", [$id]);
            $cache[$key] = $r ? ($r['numer_umowy'] . ' — ' . $r['imie_nazwisko']) : "#{$id}";
        } else {
            $cache[$key] = "#{$id}";
        }
    }
    return $cache[$key];
}
