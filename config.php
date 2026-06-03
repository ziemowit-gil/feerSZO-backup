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

if (!defined('APP_INSTALLED'))  define('APP_INSTALLED',  false);
if (!defined('CRM_STANDALONE')) define('CRM_STANDALONE', false);
define('APP_KEY', '0d74d40a14da3673d68c6bd7094d4142f60d20eea6435eb99b567f232b4608d7');
define('ORG_NAME', 'Fundacja Edukacji Empatii Rozwoju FEER');

// Wersja i środowisko aplikacji
define('APP_VERSION', '2.2.0');
define('APP_ENV',     getenv('APP_ENV') ?: (
    ($_SERVER['SERVER_NAME'] ?? 'localhost') === 'localhost' ? 'development' : 'production'
));

// Baza danych
define('DB_TYPE', 'sqlite');
define('DB_PATH', __DIR__ . '/umowy.db');

// Microsoft OAuth
define('MS_ENABLED', false);
define('MS_TENANT_ID', '');
define('MS_CLIENT_ID', '');
define('MS_CLIENT_SECRET', '');
define('MS_REDIRECT_URI', 'http://localhost:3000/auth/microsoft.php');

// Ścieżki
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('APP_URL', (function() {
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $appDir  = rtrim(str_replace('\\', '/', realpath(__DIR__)), '/');
    $path    = ($docRoot && str_starts_with($appDir, $docRoot)) ? substr($appDir, strlen($docRoot)) : '';
    return rtrim($scheme . '://' . $host . $path, '/');
})());
