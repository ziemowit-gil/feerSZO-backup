<?php
// Composer autoloader (SDK-i zewnętrzne, m.in. KSeF PHP Client)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// ---- SaaS multi-tenant detection ----
$_saas_slug = $_SERVER['REDIRECT_TENANT_SLUG'] ?? $_SERVER['TENANT_SLUG'] ?? '';

if ($_saas_slug !== '') {
    $_saas_slug  = preg_replace('/[^a-z0-9]/', '', strtolower($_saas_slug));
    $_tenant_dir = __DIR__ . '/tenants/' . $_saas_slug;
    $_tenant_cfg = $_tenant_dir . '/tenant.php';

    if ($_saas_slug !== '' && is_file($_tenant_cfg)) {
        $_tc = require $_tenant_cfg;

        define('APP_INSTALLED', true);
        define('APP_KEY',         $_tc['app_key']           ?? bin2hex(random_bytes(16)));
        define('ORG_NAME',        $_tc['org_name']          ?? $_saas_slug);
        define('DB_TYPE',         'sqlite');
        define('DB_PATH',         $_tenant_dir . '/umowy.db');
        define('MS_ENABLED',      (bool)($_tc['ms_enabled'] ?? false));
        define('MS_TENANT_ID',    $_tc['ms_tenant_id']      ?? '');
        define('MS_CLIENT_ID',    $_tc['ms_client_id']      ?? '');
        define('MS_CLIENT_SECRET',$_tc['ms_client_secret']  ?? '');
        define('UPLOAD_DIR', (function() use ($_tc, $_tenant_dir) {
            $ud = $_tc['upload_dir'] ?? '';
            return ($ud !== '' && is_dir($ud)) ? rtrim($ud, '/') . '/' : $_tenant_dir . '/uploads/';
        })());
        define('TENANT_SLUG',      $_saas_slug);
        define('CRM_STANDALONE',   (bool)($_tc['crm_standalone'] ?? false));
        define('APP_URL', (function() use ($_saas_slug) {
            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
            $appDir  = rtrim(str_replace('\\', '/', realpath(__DIR__)), '/');
            $base    = ($docRoot && str_starts_with($appDir, $docRoot)) ? substr($appDir, strlen($docRoot)) : '';
            return rtrim($scheme . '://' . $host . $base, '/') . '/org/' . $_saas_slug;
        })());
        define('MS_REDIRECT_URI', APP_URL . '/auth/microsoft.php');

        unset($_saas_slug, $_tenant_dir, $_tenant_cfg, $_tc);
        return;
    }
}
unset($_saas_slug);
// ---- koniec SaaS ----

// ── Lokalna nadpisanie konfiguracji ───────────────────────────────────────────
// Skopiuj config.local.php.example → config.local.php i dostosuj do środowiska.
// config.local.php NIE jest wersjonowane w git (.gitignore).
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

if (!defined('APP_INSTALLED'))  define('APP_INSTALLED',  false);
if (!defined('CRM_STANDALONE')) define('CRM_STANDALONE', false);
if (!defined('APP_KEY'))    define('APP_KEY',    getenv('APP_KEY') ?: '0d74d40a14da3673d68c6bd7094d4142f60d20eea6435eb99b567f232b4608d7');
if (!defined('ORG_NAME'))   define('ORG_NAME',   getenv('ORG_NAME') ?: 'Fundacja Edukacji Empatii Rozwoju FEER');

// Wersja i środowisko aplikacji
if (!defined('APP_VERSION')) define('APP_VERSION', '1.11');
if (!defined('APP_ENV'))     define('APP_ENV',     getenv('APP_ENV') ?: (
    ($_SERVER['SERVER_NAME'] ?? 'localhost') === 'localhost' ? 'development' : 'production'
));

// ── Baza danych ───────────────────────────────────────────────────────────────
// Domyślnie SQLite. Przełącz na MySQL przez env DB_TYPE=mysql lub config.local.php.
if (!defined('DB_TYPE'))  define('DB_TYPE',  getenv('DB_TYPE') ?: 'sqlite');
if (!defined('DB_PATH'))  define('DB_PATH',  __DIR__ . '/umowy.db');
if (!defined('DB_HOST'))  define('DB_HOST',  getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_PORT'))  define('DB_PORT',  (int)(getenv('DB_PORT') ?: 3306));
if (!defined('DB_NAME'))  define('DB_NAME',  getenv('DB_NAME') ?: '');
if (!defined('DB_USER'))  define('DB_USER',  getenv('DB_USER') ?: '');
if (!defined('DB_PASS'))  define('DB_PASS',  getenv('DB_PASS') ?: '');

// ── Microsoft OAuth ───────────────────────────────────────────────────────────
if (!defined('MS_ENABLED'))      define('MS_ENABLED',      getenv('MS_ENABLED') === '1');
if (!defined('MS_TENANT_ID'))    define('MS_TENANT_ID',    getenv('MS_TENANT_ID') ?: '');
if (!defined('MS_CLIENT_ID'))    define('MS_CLIENT_ID',    getenv('MS_CLIENT_ID') ?: '');
if (!defined('MS_CLIENT_SECRET'))define('MS_CLIENT_SECRET',getenv('MS_CLIENT_SECRET') ?: '');
if (!defined('MS_REDIRECT_URI')) define('MS_REDIRECT_URI', getenv('MS_REDIRECT_URI') ?: 'https://localhost/auth/microsoft.php');

// ── Ścieżki ───────────────────────────────────────────────────────────────────
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', __DIR__ . '/uploads/');
if (!defined('APP_URL'))    define('APP_URL', (function() {
    // Zmienna środowiskowa ma priorytet (np. w Docker)
    if ($env = getenv('APP_URL')) return rtrim($env, '/');
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $appDir  = rtrim(str_replace('\\', '/', realpath(__DIR__)), '/');
    $path    = ($docRoot && str_starts_with($appDir, $docRoot)) ? substr($appDir, strlen($docRoot)) : '';
    return rtrim($scheme . '://' . $host . $path, '/');
})());
