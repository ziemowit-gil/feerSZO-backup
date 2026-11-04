<?php
declare(strict_types=1);

/**
 * modules/license_pool/lib/lpSchema.php — Pula licencji Fundacji: schemat.
 *
 * Moduł WSPÓŁDZIELONY przez TI (panel dydaktyka) i IT SZO: jedna pula licencji/kluczy, które ma Fundacja
 * na produkty, z importem z TXT i przydziałem do osób.
 *   lp_products     — produkty (Adobe, JetBrains…); ti_license_id = powiązanie z katalogiem TI (k30_ti_licenses)
 *   lp_licenses     — pojedyncze licencje/klucze (kod szyfrowany fieldCrypto; indeks ślepy do wykrywania duplikatów), miejsca, ważność
 *   lp_assignments  — przydziały: pracownik SZO (user) | uczestnik TI (client) | inny odbiorca (other); revoked_at = historia
 *   lp_imports      — dziennik importów z TXT (bez kodów)
 * Zapisy dla uczestników TI idą dodatkowo przez TiLicencjeManager (jedyna droga do k30_ti_client_licenses).
 */
require_once dirname(__DIR__, 3) . '/includes/db.php';

function lp_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('szo_schema_current') && szo_schema_current('lp_migrate', __FILE__)) return;

    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS lp_products (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT NOT NULL,
        vendor        TEXT NOT NULL DEFAULT '',
        category      TEXT NOT NULL DEFAULT '',
        vendor_url    TEXT NOT NULL DEFAULT '',
        ti_license_id INTEGER,
        notes         TEXT NOT NULL DEFAULT '',
        is_active     INTEGER NOT NULL DEFAULT 1,
        created_by    INTEGER,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_lp_products_name ON lp_products(name COLLATE NOCASE)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lp_licenses (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id  INTEGER NOT NULL REFERENCES lp_products(id) ON DELETE CASCADE,
        code_enc    TEXT NOT NULL,
        code_hash   TEXT NOT NULL,
        code_hint   TEXT NOT NULL DEFAULT '',
        seats       INTEGER NOT NULL DEFAULT 1,
        valid_until DATE,
        note        TEXT NOT NULL DEFAULT '',
        status      TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','retired')),
        import_id   INTEGER,
        created_by  INTEGER,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_lp_lic_code ON lp_licenses(product_id, code_hash)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lp_lic_product ON lp_licenses(product_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lp_assignments (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        license_id       INTEGER NOT NULL REFERENCES lp_licenses(id) ON DELETE CASCADE,
        holder_type      TEXT NOT NULL CHECK (holder_type IN ('user','client','other')),
        holder_id        INTEGER,
        holder_label     TEXT NOT NULL DEFAULT '',
        ti_assignment_id INTEGER,
        context          TEXT NOT NULL DEFAULT '',
        note             TEXT NOT NULL DEFAULT '',
        assigned_by      INTEGER,
        assigned_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        revoked_at       DATETIME,
        revoked_by       INTEGER,
        revoke_reason    TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lp_asg_license ON lp_assignments(license_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lp_asg_holder  ON lp_assignments(holder_type, holder_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lp_imports (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        filename    TEXT NOT NULL DEFAULT '',
        total       INTEGER NOT NULL DEFAULT 0,
        added       INTEGER NOT NULL DEFAULT 0,
        duplicates  INTEGER NOT NULL DEFAULT 0,
        invalid     INTEGER NOT NULL DEFAULT 0,
        created_by  INTEGER,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // dodatki: instrukcja aktywacji produktu, ślad wysłanej wiadomości z kluczem
    $has = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    if (!in_array('instructions', $has('lp_products'), true)) $pdo->exec("ALTER TABLE lp_products ADD COLUMN instructions TEXT NOT NULL DEFAULT ''");
    foreach (['activation_url' => "TEXT NOT NULL DEFAULT ''", 'attachment_path' => "TEXT NOT NULL DEFAULT ''", 'attachment_name' => "TEXT NOT NULL DEFAULT ''",
              'attachment_mime' => "TEXT NOT NULL DEFAULT ''", 'attachment_size' => 'INTEGER NOT NULL DEFAULT 0'] as $c => $ddl) {
        if (!in_array($c, $has('lp_products'), true)) $pdo->exec("ALTER TABLE lp_products ADD COLUMN $c $ddl");
    }
    // ważność liczona od dnia aktywacji (miesiące; 0 = wg daty ważności licencji). Jednorazowo: wszystkie produkty Nord = 12 mies. + adres aktywacji NordVPN.
    if (!in_array('validity_months', $has('lp_products'), true)) {
        $pdo->exec("ALTER TABLE lp_products ADD COLUMN validity_months INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("UPDATE lp_products SET validity_months = 12 WHERE name LIKE '%nord%' COLLATE NOCASE");
        $pdo->exec("UPDATE lp_products SET activation_url = 'https://my.nordaccount.com/activate/' WHERE name LIKE '%nordvpn%' COLLATE NOCASE AND activation_url = ''");
    }
    foreach (['mailed_at' => 'DATETIME', 'mailed_to' => "TEXT NOT NULL DEFAULT ''", 'mail_status' => "TEXT NOT NULL DEFAULT ''"] as $c => $ddl) {
        if (!in_array($c, $has('lp_assignments'), true)) $pdo->exec("ALTER TABLE lp_assignments ADD COLUMN $c $ddl");
    }
}
