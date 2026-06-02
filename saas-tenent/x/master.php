<?php
/**
 * Master DB i helpers dla panelu SaaS.
 */
require_once __DIR__ . '/auth.php';

define('SAAS_ROOT',       dirname(dirname(__DIR__)));
define('SAAS_DB_PATH',    __DIR__ . '/master.db');
define('TENANTS_DIR',     SAAS_ROOT . '/tenants');
define('SAAS_SYSTEM_EMAIL', 'serwis@local');

function saas_db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . SAAS_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;");
    saas_init($pdo);
    return $pdo;
}

function saas_init(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_settings (
        key_  VARCHAR(100) PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        krs          VARCHAR(20)  NOT NULL UNIQUE,
        slug         VARCHAR(20)  NOT NULL DEFAULT '',
        org_name     VARCHAR(255) NOT NULL,
        admin_email  VARCHAR(255) NOT NULL DEFAULT '',
        admin_name   VARCHAR(255) NOT NULL DEFAULT 'Administrator',
        plan         VARCHAR(50)  NOT NULL DEFAULT 'standard',
        is_active    INTEGER      NOT NULL DEFAULT 1,
        ms_enabled   INTEGER      NOT NULL DEFAULT 0,
        ms_tenant_id VARCHAR(255) NOT NULL DEFAULT '',
        ms_client_id VARCHAR(255) NOT NULL DEFAULT '',
        ms_client_secret VARCHAR(255) NOT NULL DEFAULT '',
        notes            TEXT         NOT NULL DEFAULT '',
        upload_dir       TEXT         NOT NULL DEFAULT '',
        backup_path      TEXT         NOT NULL DEFAULT '',
        billing_name     TEXT         NOT NULL DEFAULT '',
        billing_nip      VARCHAR(20)  NOT NULL DEFAULT '',
        billing_address  TEXT         NOT NULL DEFAULT '',
        billing_city     VARCHAR(100) NOT NULL DEFAULT '',
        billing_zip      VARCHAR(10)  NOT NULL DEFAULT '',
        billing_email    VARCHAR(255) NOT NULL DEFAULT '',
        billing_price    DECIMAL(8,2) NOT NULL DEFAULT 0,
        db_ready     INTEGER      NOT NULL DEFAULT 0,
        created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME     DEFAULT CURRENT_TIMESTAMP
    )");
    // Migrations for existing DBs
    try { $pdo->exec("ALTER TABLE tenants ADD COLUMN slug            VARCHAR(20)  NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tenants ADD COLUMN upload_dir      TEXT         NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tenants ADD COLUMN backup_path     TEXT         NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tenants ADD COLUMN billing_name    TEXT         NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tenants ADD COLUMN billing_nip     VARCHAR(20)  NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tenants ADD COLUMN billing_address TEXT         NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tenants ADD COLUMN billing_city    VARCHAR(100) NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tenants ADD COLUMN billing_zip     VARCHAR(10)  NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tenants ADD COLUMN billing_email   VARCHAR(255) NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tenants ADD COLUMN billing_price   DECIMAL(8,2) NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tenants ADD COLUMN crm_enabled     INTEGER      NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tenants ADD COLUMN crm_standalone  INTEGER      NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
}

function saas_setting(string $key, string $default = ''): string {
    try {
        $r = saas_db()->prepare("SELECT value FROM saas_settings WHERE key_=?");
        $r->execute([$key]);
        $row = $r->fetch();
        return $row ? $row['value'] : $default;
    } catch (\Throwable $e) { return $default; }
}

function saas_setting_set(string $key, string $value): void {
    saas_db()->prepare("INSERT OR REPLACE INTO saas_settings (key_,value) VALUES (?,?)")
        ->execute([$key, $value]);
}

function saas_all(): array {
    return saas_db()->query("SELECT * FROM tenants ORDER BY created_at DESC")->fetchAll();
}

function saas_get(int $id): ?array {
    $s = saas_db()->prepare("SELECT * FROM tenants WHERE id=?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function saas_get_by_krs(string $krs): ?array {
    $s = saas_db()->prepare("SELECT * FROM tenants WHERE krs=?");
    $s->execute([$krs]);
    return $s->fetch() ?: null;
}

function saas_make_slug(string $org_name, int $exclude_id = 0): string {
    $letters = preg_replace('/[^a-zA-Z]/', '', $org_name);
    $base    = strtolower(substr($letters, -5)) ?: 'org';
    $slug    = $base;
    $n       = 1;
    $db      = saas_db();
    while (true) {
        $s = $db->prepare("SELECT id FROM tenants WHERE slug=? AND id!=?");
        $s->execute([$slug, $exclude_id]);
        if (!$s->fetch()) break;
        $slug = $base . $n++;
    }
    return $slug;
}

function saas_create(array $data): int {
    $db = saas_db();
    $db->prepare(
        "INSERT INTO tenants (krs,slug,org_name,admin_email,admin_name,plan,ms_enabled,ms_tenant_id,ms_client_id,ms_client_secret,notes,crm_enabled,crm_standalone)
         VALUES (:krs,:slug,:org_name,:admin_email,:admin_name,:plan,:ms_enabled,:ms_tenant_id,:ms_client_id,:ms_client_secret,:notes,:crm_enabled,:crm_standalone)"
    )->execute([
        ':krs'              => $data['krs'],
        ':slug'             => $data['slug'],
        ':org_name'         => $data['org_name'],
        ':admin_email'      => $data['admin_email']      ?? '',
        ':admin_name'       => $data['admin_name']       ?? 'Administrator',
        ':plan'             => $data['plan']             ?? 'standard',
        ':ms_enabled'       => $data['ms_enabled']       ?? 0,
        ':ms_tenant_id'     => $data['ms_tenant_id']     ?? '',
        ':ms_client_id'     => $data['ms_client_id']     ?? '',
        ':ms_client_secret' => $data['ms_client_secret'] ?? '',
        ':notes'            => $data['notes']            ?? '',
        ':crm_enabled'      => $data['crm_enabled']      ?? 0,
        ':crm_standalone'   => $data['crm_standalone']   ?? 0,
    ]);
    return (int)$db->lastInsertId();
}

function saas_update(int $id, array $data): void {
    $fields = ['org_name','admin_email','admin_name','plan','is_active',
               'ms_enabled','ms_tenant_id','ms_client_id','ms_client_secret',
               'notes','upload_dir','backup_path','db_ready',
               'billing_name','billing_nip','billing_address','billing_city',
               'billing_zip','billing_email','billing_price','crm_enabled','crm_standalone'];
    $set = []; $params = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $data)) {
            $set[] = "$f=?";
            $params[] = $data[$f];
        }
    }
    if (!$set) return;
    $params[] = $id;
    saas_db()->prepare("UPDATE tenants SET " . implode(',', $set) . ", updated_at=datetime('now') WHERE id=?")->execute($params);
}

function saas_delete(int $id): void {
    $t = saas_get($id);
    if ($t) {
        saas_rmdir(TENANTS_DIR . '/' . ($t['slug'] ?: $t['krs']));
    }
    saas_db()->prepare("DELETE FROM tenants WHERE id=?")->execute([$id]);
}

function saas_provision(array $tenant): array {
    require_once SAAS_ROOT . '/setup/setup_sql.php';

    $krs  = $tenant['krs'];
    $slug = $tenant['slug'] ?: $krs;
    $dir  = TENANTS_DIR . '/' . $slug;

    // Katalog uploads — domyślny lub niestandardowy
    $upload_dir = rtrim($tenant['upload_dir'] ?? '', '/');
    if ($upload_dir === '' || !is_dir(dirname($upload_dir))) {
        $upload_dir = $dir . '/uploads';
    }

    // Zapisz upload_dir w tenant.php (będzie odczytany przez config.php)
    // (poniżej w generowaniu tenant.php)

    // Utwórz katalogi uploads z podkatalogami
    $upload_subdirs = ['wolontariat','zlecenie','dzielo','praca','uslugi',
                       'inne','certificates','letters','tasks'];
    foreach (array_merge([$dir, $upload_dir], array_map(fn($s) => $upload_dir.'/'.$s, $upload_subdirs)) as $d) {
        if (!is_dir($d)) mkdir($d, 0755, true);
    }

    // .htaccess blokujący bezpośrednie PHP w uploads
    file_put_contents($upload_dir . '/.htaccess',
        "Options -Indexes\n<FilesMatch \"\\.php$\">\nDeny from all\n</FilesMatch>\n");

    // tenant.php — konfiguracja
    $key           = bin2hex(random_bytes(16));
    $crm_standalone = !empty($tenant['crm_standalone']);
    $tpl = "<?php\nreturn [\n"
         . "    'org_name'        => " . var_export($tenant['org_name'], true)        . ",\n"
         . "    'app_key'         => '$key',\n"
         . "    'upload_dir'      => " . var_export($upload_dir . '/', true)           . ",\n"
         . "    'ms_enabled'      => " . ($tenant['ms_enabled'] ? 'true' : 'false')   . ",\n"
         . "    'ms_tenant_id'    => " . var_export($tenant['ms_tenant_id'], true)    . ",\n"
         . "    'ms_client_id'    => " . var_export($tenant['ms_client_id'], true)    . ",\n"
         . "    'ms_client_secret'=> " . var_export($tenant['ms_client_secret'], true). ",\n"
         . "    'crm_standalone'  => " . ($crm_standalone ? 'true' : 'false')         . ",\n"
         . "];\n";
    file_put_contents($dir . '/tenant.php', $tpl);

    // Utwórz i zainicjalizuj bazę danych tenanta
    $db_path = $dir . '/umowy.db';
    // Backup istniejącej bazy przed re-prowizjonowaniem (zamiast nadpisywania)
    if (is_file($db_path)) {
        $backup_name = $db_path . '.bak.' . date('YmdHis');
        copy($db_path, $backup_name);
        unlink($db_path);
    }
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;");
    setup_tenant_db($pdo);

    // Utwórz konto admina organizacji
    $admin_email = $tenant['admin_email'] ?: 'admin@' . $krs . '.local';
    $admin_name  = $tenant['admin_name']  ?: 'Administrator';
    $admin_pass  = bin2hex(random_bytes(6));
    $pdo->prepare("INSERT OR REPLACE INTO users (name,email,password,role,is_active) VALUES (?,?,?,'admin',1)")
        ->execute([$admin_name, $admin_email, password_hash($admin_pass, PASSWORD_BCRYPT)]);

    // Konto systemowe SaaS — niewidoczne i niemożliwe do wyłączenia przez admina tenanta
    $pdo->prepare("INSERT OR REPLACE INTO users (name,email,password,role,is_active) VALUES (?,?,?,'admin',1)")
        ->execute(['SaaS System', SAAS_SYSTEM_EMAIL, password_hash(SAAS_PASSWORD, PASSWORD_BCRYPT)]);

    saas_update($tenant['id'], ['db_ready' => 1]);

    // Aktywuj CRM w ustawieniach tenanta jeśli zaznaczono (standalone wymusza crm_enabled)
    if (!empty($tenant['crm_enabled']) || $crm_standalone) {
        $pdo->prepare("INSERT OR REPLACE INTO settings (key_,value) VALUES ('crm_enabled','1')")->execute();
    }

    return ['admin_email' => $admin_email, 'admin_pass' => $admin_pass];
}

/**
 * Synchronizuje ustawienie crm_enabled z master.db do bazy konkretnego tenanta.
 */
function saas_sync_crm(int $tenant_id): bool {
    $t = saas_get($tenant_id);
    if (!$t || !$t['db_ready']) return false;
    $slug    = $t['slug'] ?: $t['krs'];
    $db_path = TENANTS_DIR . '/' . $slug . '/umowy.db';
    if (!is_file($db_path)) return false;
    try {
        $pdo = new PDO('sqlite:' . $db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->prepare("INSERT OR REPLACE INTO settings (key_,value) VALUES ('crm_enabled',?)")
            ->execute([$t['crm_enabled'] ? '1' : '0']);
        return true;
    } catch (\Throwable $e) { return false; }
}

/**
 * Generuje jednorazowy token SSO i zwraca URL do panelu CRM tenanta.
 * Cel: admin SaaS klika "Wejdź do CRM" i trafia bezpośrednio do /crm/dashboard.php.
 */
function saas_crm_login_url(array $tenant): ?string {
    $slug    = $tenant['slug'] ?: $tenant['krs'];
    $db_path = TENANTS_DIR . '/' . $slug . '/umowy.db';
    if (!is_file($db_path)) return null;
    try {
        $pdo = new PDO('sqlite:' . $db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $admin = $pdo->query("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch();
        if (!$admin) return null;
        $token   = bin2hex(random_bytes(20));
        $expires = date('Y-m-d H:i:s', time() + 90);
        $pdo->prepare("INSERT OR REPLACE INTO settings (key_,value) VALUES ('_saas_crm_token',?)")
            ->execute([$token . '|' . $admin['id'] . '|' . $expires]);
        $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        $appDir  = rtrim(str_replace('\\', '/', realpath(SAAS_ROOT)), '/');
        $base    = ($docRoot && str_starts_with($appDir, $docRoot)) ? substr($appDir, strlen($docRoot)) : '';
        $app_url = rtrim($scheme . '://' . $host . $base, '/');
        return $app_url . '/org/' . urlencode($slug) . '/auth/saas_crm_login.php?token=' . urlencode($token);
    } catch (\Throwable $e) { return null; }
}

function saas_sync_master_pass(): int {
    $updated = 0;
    $hash    = password_hash(SAAS_PASSWORD, PASSWORD_BCRYPT);
    foreach (saas_all() as $t) {
        if (!$t['db_ready']) continue;
        $slug    = $t['slug'] ?: $t['krs'];
        $db_path = TENANTS_DIR . '/' . $slug . '/umowy.db';
        if (!is_file($db_path)) continue;
        try {
            $pdo = new PDO('sqlite:' . $db_path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->prepare("UPDATE users SET password=? WHERE email=?")
                ->execute([$hash, SAAS_SYSTEM_EMAIL]);
            $updated++;
        } catch (\Throwable $e) {}
    }
    return $updated;
}

function saas_rmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? saas_rmdir($p) : unlink($p);
    }
    rmdir($dir);
}

function saas_csrf(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['saas_csrf'])) {
        $_SESSION['saas_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['saas_csrf'];
}

function saas_csrf_check(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $t = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['saas_csrf'] ?? '', $t)) {
        http_response_code(403); die('CSRF error');
    }
}
