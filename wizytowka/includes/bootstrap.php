<?php
/**
 * bootstrap.php — wspólny start aplikacji.
 * Ustawia stałe, bezpieczną sesję, zależności i wykrywa stan instalacji.
 */
declare(strict_types=1);

// ── Ścieżki ───────────────────────────────────────────────────────────
define('APP_ROOT',    dirname(__DIR__));
define('APP_NAME',    'Wizytówka');
define('APP_VERSION', '1.0.0');
define('DB_FILE',     APP_ROOT . '/database.sqlite');
define('SCHEMA_FILE', APP_ROOT . '/schema.sql');
define('UPLOAD_ROOT', APP_ROOT . '/uploads');

// Opcjonalne nadpisania (np. inna ścieżka bazy na hostingu)
if (is_file(APP_ROOT . '/config.local.php')) {
    require APP_ROOT . '/config.local.php';
}

// ── Błędy: cicho na produkcji, głośno lokalnie ────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) ? '1' : '0');
ini_set('log_errors', '1');

date_default_timezone_set('Europe/Warsaw');
mb_internal_encoding('UTF-8');

// ── Wykrycie HTTPS oraz katalogu bazowego aplikacji ───────────────────
function is_https(): bool
{
    if (($_SERVER['HTTPS'] ?? '') && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return false;
}

/** Prefiks katalogu, w którym leży aplikacja (obsługa instalacji w podkatalogu). */
function base_path(): string
{
    static $base = null;
    if ($base !== null) return $base;
    $dir  = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $base = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    return $base;
}

/** URL wewnętrzny: url('/admin/tiles') → /podkatalog/admin/tiles */
function url(string $path = '/'): string
{
    $path = '/' . ltrim($path, '/');
    return base_path() . $path;
}

/** Pełny URL absolutny (SEO / Open Graph / vCard). */
function abs_url(string $path = '/'): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https://' : 'http://') . $host . url($path);
}

// ── Bezpieczna sesja ──────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_name('wizytowka_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path() !== '' ? base_path() . '/' : '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

// ── Nagłówki bezpieczeństwa ───────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ── Zależności ────────────────────────────────────────────────────────
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/markdown.php';
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/content.php';
require_once APP_ROOT . '/includes/ui.php';

/**
 * Czy aplikacja jest już zainstalowana?
 * Warunek: plik bazy istnieje, ma tabelę settings i co najmniej jednego użytkownika.
 */
function app_installed(): bool
{
    static $installed = null;
    if ($installed !== null) return $installed;

    if (!is_file(DB_FILE) || filesize(DB_FILE) === 0) return $installed = false;
    try {
        $pdo = db();
        $has = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='settings'")->fetchColumn();
        if (!$has) return $installed = false;
        return $installed = ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $installed = false;
    }
}
