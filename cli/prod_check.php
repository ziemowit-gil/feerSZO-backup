#!/usr/bin/env php
<?php
/**
 * cli/prod_check.php — Lista kontrolna przed wdrożeniem na produkcję
 *
 * Sprawdza konfigurację, bezpieczeństwo i dostępność usług.
 * NIE modyfikuje żadnych danych ani ustawień.
 * NIE usuwa credentials ani kluczy API.
 *
 * Użycie:
 *   php cli/prod_check.php              — standardowy raport
 *   php cli/prod_check.php --strict     — traktuj ostrzeżenia jako błędy
 *   php cli/prod_check.php --no-color   — wyłącz kolory ANSI
 *   php cli/prod_check.php --json       — output w formacie JSON
 *
 * Kody wyjścia:
 *   0 — wszystko OK (lub tylko ostrzeżenia)
 *   1 — wystąpiły ostrzeżenia (tylko ze --strict)
 *   2 — wystąpiły błędy krytyczne
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit("Tylko CLI.\n");
}

define('APP_CLI', true);
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';

$opts   = getopt('', ['strict', 'no-color', 'json', 'help']);
$STRICT = isset($opts['strict']);
$JSON   = isset($opts['json']);

if (isset($opts['help'])) {
    echo <<<HELP

Lista kontrolna wdrożenia produkcyjnego — FEER System

Użycie:
  php cli/prod_check.php [--strict] [--no-color] [--json]

Opcje:
  --strict    Ostrzeżenia traktowane jak błędy (exit code 1)
  --no-color  Wyłącz kolory ANSI
  --json      Wynik w formacie JSON
  --help      Ta pomoc

Kody wyjścia:
  0 — OK (lub tylko ⚠ ostrzeżenia bez --strict)
  1 — ostrzeżenia ze --strict
  2 — błędy krytyczne ✗

HELP;
    exit(0);
}

// ── ANSI ──────────────────────────────────────────────────────────────────────
$NO_COLOR = isset($opts['no-color']) || !stream_isatty(STDOUT) || getenv('NO_COLOR') || $JSON;
function ansi(string $code, string $t): string {
    global $NO_COLOR; return $NO_COLOR ? $t : "\033[{$code}m{$t}\033[0m";
}
function pass(string $s): string { return ansi('32', "  ✓  ") . $s; }
function fail(string $s): string { return ansi('31', "  ✗  ") . $s; }
function warn(string $s): string { return ansi('33', "  ⚠  ") . $s; }
function skip(string $s): string { return ansi('90', "  –  ") . $s; }
function note(string $s): string { return ansi('90',  "       → $s"); }
function h1(string $s):   string { return "\n" . ansi('1;34', "── $s ──") . "\n"; }

// ── Kolekcja wyników ──────────────────────────────────────────────────────────
$results = []; // [{section, label, status: ok|warn|fail|skip, detail}]

function chk(string $section, string $label, string $status, string $detail = ''): void {
    global $results;
    $results[] = compact('section', 'label', 'status', 'detail');
}

// ══════════════════════════════════════════════════════════════════════════════
//  1. PHP — wersja i rozszerzenia
// ══════════════════════════════════════════════════════════════════════════════
$section = 'PHP';

$php_ver = PHP_VERSION;
if (version_compare($php_ver, '8.1.0', '>=')) {
    chk($section, "PHP >= 8.1", 'ok', "Wersja: {$php_ver}");
} elseif (version_compare($php_ver, '8.0.0', '>=')) {
    chk($section, "PHP >= 8.1", 'warn', "Wersja: {$php_ver} — zalecane 8.1+");
} else {
    chk($section, "PHP >= 8.1", 'fail', "Wersja: {$php_ver} — wymagane PHP 8.0+");
}

$extensions = [
    'pdo'        => 'PDO (baza danych)',
    'pdo_sqlite' => 'PDO SQLite',
    'pdo_mysql'  => 'PDO MySQL',
    'json'       => 'JSON',
    'mbstring'   => 'Multibyte Strings',
    'openssl'    => 'OpenSSL',
    'curl'       => 'cURL (Graph API, SMS)',
    'zip'        => 'ZIP (archiwizacja)',
    'gd'         => 'GD (obrazy/certyfikaty)',
    'intl'       => 'Intl (unicode)',
    'fileinfo'   => 'Fileinfo',
    'session'    => 'Sessions',
];
$optional_ext = ['gd', 'intl', 'zip', 'pdo_mysql'];

foreach ($extensions as $ext => $label) {
    if (extension_loaded($ext)) {
        chk($section, "ext/{$ext}", 'ok', $label);
    } elseif (in_array($ext, $optional_ext, true)) {
        chk($section, "ext/{$ext}", 'warn', "{$label} — opcjonalne, ale zalecane");
    } else {
        chk($section, "ext/{$ext}", 'fail', "{$label} — brak! Zainstaluj: apt install php-{$ext}");
    }
}

// Memory limit
$mem = ini_get('memory_limit');
$mem_bytes = return_bytes($mem);
if ($mem_bytes >= 128 * 1024 * 1024 || $mem_bytes === -1) {
    chk($section, 'memory_limit', 'ok', $mem);
} else {
    chk($section, 'memory_limit', 'warn', "{$mem} — zalecane >= 128M (php.ini: memory_limit = 256M)");
}

// display_errors
$de = ini_get('display_errors');
if (!$de || $de === 'Off' || $de === '0') {
    chk($section, 'display_errors', 'ok', 'Off');
} else {
    chk($section, 'display_errors', 'fail', "display_errors = {$de} — musi być Off na produkcji (php.ini)");
}

// ══════════════════════════════════════════════════════════════════════════════
//  2. Środowisko i konfiguracja aplikacji
// ══════════════════════════════════════════════════════════════════════════════
$section = 'Konfiguracja';

// APP_ENV
if (defined('APP_ENV') && APP_ENV === 'production') {
    chk($section, 'APP_ENV', 'ok', 'production');
} elseif (defined('APP_ENV')) {
    chk($section, 'APP_ENV', 'warn', 'APP_ENV = ' . APP_ENV . ' — ustaw na "production" w config.local.php lub zmiennej APP_ENV');
} else {
    chk($section, 'APP_ENV', 'fail', 'APP_ENV nie jest zdefiniowane');
}

// APP_KEY — nie może być wartością domyślną z repozytorium
$default_key = '0d74d40a14da3673d68c6bd7094d4142f60d20eea6435eb99b567f232b4608d7';
if (!defined('APP_KEY') || empty(APP_KEY)) {
    chk($section, 'APP_KEY', 'fail', 'APP_KEY nie jest ustawiony');
} elseif (APP_KEY === $default_key) {
    chk($section, 'APP_KEY', 'fail',
        'APP_KEY = klucz deweloperski z repozytorium — wygeneruj nowy: php -r "echo bin2hex(random_bytes(32));"');
} elseif (strlen(APP_KEY) < 32) {
    chk($section, 'APP_KEY', 'warn', 'APP_KEY jest krótki — zalecane 64 znaki hex');
} else {
    chk($section, 'APP_KEY', 'ok', 'Ustawiony (' . strlen(APP_KEY) . ' znaków)');
}

// APP_URL
if (!defined('APP_URL') || empty(APP_URL)) {
    chk($section, 'APP_URL', 'fail', 'APP_URL nie jest ustawiony');
} elseif (!str_starts_with(APP_URL, 'https://')) {
    chk($section, 'APP_URL', 'warn', APP_URL . ' — produkcja powinna używać HTTPS');
} else {
    chk($section, 'APP_URL', 'ok', APP_URL);
}

// ORG_NAME
$default_org = 'Fundacja Edukacji Empatii Rozwoju FEER';
if (!defined('ORG_NAME') || empty(ORG_NAME)) {
    chk($section, 'ORG_NAME', 'warn', 'ORG_NAME nie jest ustawiony');
} else {
    chk($section, 'ORG_NAME', 'ok', ORG_NAME);
}

// config.local.php
if (file_exists($root . '/config.local.php')) {
    chk($section, 'config.local.php', 'ok', 'Istnieje — lokalna konfiguracja aktywna');
} else {
    chk($section, 'config.local.php', 'warn',
        'Brak — użyj config.local.php.example jako szablonu dla ustawień środowiskowych');
}

// ══════════════════════════════════════════════════════════════════════════════
//  3. Baza danych
// ══════════════════════════════════════════════════════════════════════════════
$section = 'Baza danych';

$db_type = defined('DB_TYPE') ? DB_TYPE : 'sqlite';
chk($section, 'DB_TYPE', 'ok', $db_type);

if ($db_type === 'sqlite') {
    $db_path = defined('DB_PATH') ? DB_PATH : ($root . '/umowy.db');

    if (!file_exists($db_path)) {
        chk($section, 'Plik SQLite', 'fail', "Nie istnieje: {$db_path}");
    } else {
        $db_size = filesize($db_path);
        $db_size_h = $db_size > 1048576 ? round($db_size / 1048576, 1) . ' MB' : round($db_size / 1024) . ' KB';
        chk($section, 'Plik SQLite', 'ok', basename($db_path) . " ({$db_size_h})");

        // Permissions
        if (!is_readable($db_path)) {
            chk($section, 'SQLite — uprawnienia', 'fail', 'Brak uprawnień do odczytu!');
        } elseif (!is_writable($db_path)) {
            chk($section, 'SQLite — uprawnienia', 'fail', 'Brak uprawnień do zapisu!');
        } else {
            chk($section, 'SQLite — uprawnienia', 'ok', 'r/w OK');
        }

        // Katalog musi być zapisywalny (WAL potrzebuje -wal i -shm)
        $db_dir = dirname($db_path);
        if (!is_writable($db_dir)) {
            chk($section, 'SQLite — katalog', 'fail', "Katalog nie jest zapisywalny: {$db_dir}");
        } else {
            chk($section, 'SQLite — katalog', 'ok', $db_dir);
        }
    }

    // Połączenie i WAL mode
    try {
        $test_pdo = new PDO('sqlite:' . $db_path);
        $wal = $test_pdo->query("PRAGMA journal_mode")->fetchColumn();
        $table_count = (int)$test_pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table'"
        )->fetchColumn();
        chk($section, 'Połączenie SQLite', 'ok', "{$table_count} tabel");
        if ($wal === 'wal') {
            chk($section, 'journal_mode=WAL', 'ok', 'WAL aktywny');
        } else {
            chk($section, 'journal_mode=WAL', 'warn', "Tryb: {$wal} — zalecany WAL (PRAGMA journal_mode=WAL)");
        }
    } catch (\Throwable $e) {
        chk($section, 'Połączenie SQLite', 'fail', $e->getMessage());
    }

} else {
    // MySQL
    foreach (['DB_HOST' => DB_HOST, 'DB_NAME' => DB_NAME, 'DB_USER' => DB_USER] as $k => $v) {
        if (empty($v)) {
            chk($section, $k, 'fail', "Nie ustawiono — dodaj do config.local.php");
        } else {
            chk($section, $k, 'ok', ($k === 'DB_NAME' || $k === 'DB_HOST' || $k === 'DB_USER') ? $v : '***');
        }
    }
    if (empty(DB_PASS)) {
        chk($section, 'DB_PASS', 'warn', 'Brak hasła MySQL — upewnij się, że jest wymagane');
    } else {
        chk($section, 'DB_PASS', 'ok', 'Ustawione');
    }
    try {
        $test_pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS
        );
        $ver = $test_pdo->query('SELECT VERSION()')->fetchColumn();
        $tables = (int)$test_pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "'"
        )->fetchColumn();
        chk($section, 'Połączenie MySQL', 'ok', "{$ver} — {$tables} tabel");
    } catch (\Throwable $e) {
        chk($section, 'Połączenie MySQL', 'fail', $e->getMessage());
    }
}

// Użytkownicy admini
try {
    $admins = db_all("SELECT name, email, is_active FROM users WHERE role='admin'");
    if ($admins) {
        $active = array_filter($admins, fn($u) => $u['is_active']);
        $label = count($admins) . " adminów (" . count($active) . " aktywnych)";
        chk($section, 'Konta admin', count($active) >= 1 ? 'ok' : 'fail', $label);
    } else {
        chk($section, 'Konta admin', 'fail', 'Brak kont admin w bazie!');
    }
} catch (\Throwable $e) {
    chk($section, 'Konta admin', 'fail', 'Błąd zapytania: ' . $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════════
//  4. Pliki i uprawnienia
// ══════════════════════════════════════════════════════════════════════════════
$section = 'Pliki';

// UPLOAD_DIR
$upload_dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : ($root . '/uploads/');
if (!is_dir($upload_dir)) {
    chk($section, 'UPLOAD_DIR', 'fail', "Katalog nie istnieje: {$upload_dir} — mkdir -p {$upload_dir}");
} elseif (!is_writable($upload_dir)) {
    chk($section, 'UPLOAD_DIR', 'fail', "Brak uprawnień zapisu: {$upload_dir} — chmod 775 lub chown");
} else {
    chk($section, 'UPLOAD_DIR', 'ok', $upload_dir);
}

// .htaccess w uploads/ (blokuje wykonanie PHP)
$uploads_htaccess = rtrim($upload_dir, '/') . '/.htaccess';
if (file_exists($uploads_htaccess)) {
    $htc = file_get_contents($uploads_htaccess);
    if (str_contains($htc, 'php') || str_contains($htc, 'Options')) {
        chk($section, 'uploads/.htaccess', 'ok', 'Ochrona przed wykonaniem PHP');
    } else {
        chk($section, 'uploads/.htaccess', 'warn', 'Plik istnieje ale może nie blokować PHP');
    }
} else {
    chk($section, 'uploads/.htaccess', 'warn',
        'Brak! Utwórz ' . $uploads_htaccess . " z zawartością:\n" .
        "       Options -Indexes\n       php_flag engine off\n       RemoveHandler .php .php3 .phtml");
}

// config.local.php w gitignore
$gitignore = $root . '/.gitignore';
if (file_exists($gitignore)) {
    $gi = file_get_contents($gitignore);
    if (str_contains($gi, 'config.local.php')) {
        chk($section, '.gitignore — config.local.php', 'ok', 'Ignorowany przez git');
    } else {
        chk($section, '.gitignore — config.local.php', 'warn',
            'config.local.php nie jest w .gitignore — dodaj: echo "config.local.php" >> .gitignore');
    }
} else {
    chk($section, '.gitignore', 'warn', 'Brak pliku .gitignore');
}

// Pliki debugowe — nie powinny być na produkcji
$debug_files = [
    $root . '/debug_login_84.php',
    $root . '/setup/install.php',
    $root . '/phpinfo.php',
    $root . '/test.php',
    $root . '/info.php',
];
$found_debug = [];
foreach ($debug_files as $f) {
    if (file_exists($f)) $found_debug[] = basename($f);
}
if ($found_debug) {
    chk($section, 'Pliki debugowe', 'warn', 'Znaleziono: ' . implode(', ', $found_debug) . ' — rozważ usunięcie');
} else {
    chk($section, 'Pliki debugowe', 'ok', 'Brak');
}

// Katalog /tmp dostępny (ICS cache, tmp files)
if (is_writable(sys_get_temp_dir())) {
    chk($section, 'Katalog temp', 'ok', sys_get_temp_dir());
} else {
    chk($section, 'Katalog temp', 'warn', sys_get_temp_dir() . ' nie jest zapisywalny');
}

// ══════════════════════════════════════════════════════════════════════════════
//  5. Bezpieczeństwo
// ══════════════════════════════════════════════════════════════════════════════
$section = 'Bezpieczeństwo';

// HTTPS
if (defined('APP_URL') && str_starts_with(APP_URL, 'https://')) {
    chk($section, 'HTTPS', 'ok', APP_URL);
} else {
    chk($section, 'HTTPS', 'warn', 'APP_URL nie używa HTTPS — wymagane na produkcji');
}

// Session security
$sess_secure   = ini_get('session.cookie_secure');
$sess_httponly = ini_get('session.cookie_httponly');
$sess_samesite = ini_get('session.cookie_samesite');

if ($sess_secure && $sess_httponly) {
    chk($section, 'Session cookies', 'ok', "secure={$sess_secure}, httponly={$sess_httponly}, samesite={$sess_samesite}");
} else {
    $hints = [];
    if (!$sess_secure)   $hints[] = 'session.cookie_secure = On';
    if (!$sess_httponly) $hints[] = 'session.cookie_httponly = On';
    if (!$sess_samesite) $hints[] = 'session.cookie_samesite = Lax';
    chk($section, 'Session cookies', 'warn', 'Niezabezpieczone — dodaj do php.ini: ' . implode(', ', $hints));
}

// expose_php
$expose = ini_get('expose_php');
if (!$expose || $expose === 'Off' || $expose === '0') {
    chk($section, 'expose_php', 'ok', 'Off');
} else {
    chk($section, 'expose_php', 'warn', 'expose_php = On — ustaw Off w php.ini');
}

// Sprawdź czy serwis@local istnieje
try {
    $svc = db_one("SELECT id, password FROM users WHERE email = 'serwis@local'");
    if ($svc) {
        if (!empty($svc['password'])) {
            chk($section, 'Konto serwis@local', 'ok', 'Istnieje z hasłem');
        } else {
            chk($section, 'Konto serwis@local', 'warn', 'Konto bez hasła — ustaw hasło przez passwd.php');
        }
    } else {
        chk($section, 'Konto serwis@local', 'warn', 'Nie istnieje — utwórz przez CreateServiceUser.php');
    }
} catch (\Throwable $e) {}

// ══════════════════════════════════════════════════════════════════════════════
//  6. Moduły i usługi
// ══════════════════════════════════════════════════════════════════════════════
$section = 'Moduły';

// M365
$m365_tenant = '';
$m365_client = '';
try {
    require_once $root . '/includes/m365.php';
    $m365_tenant = m365_setting('m365_tenant_id');
    $m365_client = m365_setting('m365_graph_client_id');
    $m365_secret = m365_setting('m365_graph_client_secret');
    $m365_enabled = m365_setting('m365_enabled');

    if ($m365_enabled === '1' || $m365_enabled === '') {
        if ($m365_tenant && $m365_client && $m365_secret) {
            chk($section, 'Microsoft 365', 'ok', "Tenant: {$m365_tenant}");
        } elseif ($m365_tenant || $m365_client) {
            chk($section, 'Microsoft 365', 'warn', 'Częściowo skonfigurowany — brak ' .
                (!$m365_tenant ? 'Tenant ID' : '') . (!$m365_client ? ' Client ID' : '') . (!$m365_secret ? ' Secret' : ''));
        } else {
            chk($section, 'Microsoft 365', 'skip', 'Nieskonfigurowany (admin/m365_settings.php)');
        }
    } else {
        chk($section, 'Microsoft 365', 'skip', 'Moduł wyłączony');
    }
} catch (\Throwable $e) {
    chk($section, 'Microsoft 365', 'skip', 'Błąd ładowania m365.php: ' . $e->getMessage());
}

// SharePoint backup
try {
    $sp_site    = function_exists('m365_setting') ? m365_setting('sp_site_url') : '';
    $sp_library = function_exists('m365_setting') ? m365_setting('sp_library')  : '';
    if ($sp_site) {
        chk($section, 'SharePoint backup', 'ok', $sp_site);
    } else {
        chk($section, 'SharePoint backup', 'warn', 'Nieskonfigurowany — admin/m365_settings.php → sekcja SharePoint');
    }
} catch (\Throwable $e) {}

// SMTP / mail
try {
    $smtp_host = function_exists('org_setting') ? org_setting('smtp_host') : '';
    if ($smtp_host) {
        chk($section, 'SMTP', 'ok', $smtp_host);
    } else {
        $m365_sender = $m365_tenant ? m365_setting('m365_sender_user_id') : '';
        if ($m365_sender) {
            chk($section, 'SMTP', 'ok', "Graph API Mail (nadawca: {$m365_sender})");
        } else {
            chk($section, 'SMTP', 'warn', 'Brak konfiguracji poczty — admin/m365_settings.php lub ustawienia SMTP');
        }
    }
} catch (\Throwable $e) {}

// Cron
$cron_dispatcher = $root . '/cron/dispatcher.php';
if (file_exists($cron_dispatcher)) {
    chk($section, 'Cron dispatcher', 'ok', 'Plik istnieje: cron/dispatcher.php');
    chk($section, 'Cron konfiguracja', 'warn',
        'Zweryfikuj manualnie: crontab -l | grep dispatcher — zalecane: */5 * * * * php ' . $cron_dispatcher);
} else {
    chk($section, 'Cron dispatcher', 'fail', 'Brak pliku cron/dispatcher.php');
}

// ICS / org calendar
try {
    $ics_url = function_exists('org_setting') ? org_setting('org_calendar_ics_url') : '';
    if ($ics_url) {
        chk($section, 'Kalendarz ICS', 'ok', $ics_url);
    } else {
        chk($section, 'Kalendarz ICS', 'skip', 'Nieskonfigurowany (admin/org_calendar.php)');
    }
} catch (\Throwable $e) {}

// ══════════════════════════════════════════════════════════════════════════════
//  7. Podsumowanie
// ══════════════════════════════════════════════════════════════════════════════
$count = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];
foreach ($results as $r) $count[$r['status']] = ($count[$r['status']] ?? 0) + 1;

if ($JSON) {
    echo json_encode([
        'summary' => $count,
        'checks'  => $results,
        'exit_code' => $count['fail'] > 0 ? 2 : ($STRICT && $count['warn'] > 0 ? 1 : 0),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($count['fail'] > 0 ? 2 : ($STRICT && $count['warn'] > 0 ? 1 : 0));
}

// Wyświetl wyniki pogrupowane
$current_section = '';
foreach ($results as $r) {
    if ($r['section'] !== $current_section) {
        $current_section = $r['section'];
        echo h1($current_section);
    }
    $line = match($r['status']) {
        'ok'   => pass($r['label']),
        'warn' => warn($r['label']),
        'fail' => fail($r['label']),
        default => skip($r['label']),
    };
    echo $line;
    if ($r['detail']) {
        // Multi-line detail: indent each line
        foreach (explode("\n", $r['detail']) as $dl) {
            echo "\n" . note($dl);
        }
    }
    echo "\n";
}

// Finalne podsumowanie
echo "\n";
echo ansi('1', "══ Podsumowanie ══") . "\n";
$ok_str   = ansi('32', "  ✓ OK     : {$count['ok']}");
$warn_str = ansi('33', "  ⚠ Uwagi  : {$count['warn']}");
$fail_str = ansi('31', "  ✗ Błędy  : {$count['fail']}");
$skip_str = ansi('90', "  – Pominięto: {$count['skip']}");
echo "{$ok_str}  {$warn_str}  {$fail_str}  {$skip_str}\n\n";

if ($count['fail'] > 0) {
    echo ansi('1;31', "  ✗ SYSTEM NIE JEST GOTOWY DO WDROŻENIA — napraw błędy powyżej.") . "\n\n";
    exit(2);
} elseif ($count['warn'] > 0) {
    if ($STRICT) {
        echo ansi('1;33', "  ⚠ Tryb --strict: ostrzeżenia traktowane jako błędy.") . "\n\n";
        exit(1);
    }
    echo ansi('1;33', "  ⚠ Są ostrzeżenia — przejrzyj i zdecyduj czy je zignorować.") . "\n\n";
    exit(0);
} else {
    echo ansi('1;32', "  ✓ System gotowy do wdrożenia na produkcję.") . "\n\n";
    exit(0);
}

// ── Helper ────────────────────────────────────────────────────────────────────
function return_bytes(string $val): int
{
    $val  = trim($val);
    $last = strtolower($val[-1] ?? '');
    $num  = (int)$val;
    return match($last) {
        'g' => $num * 1073741824,
        'm' => $num * 1048576,
        'k' => $num * 1024,
        default => $num,
    };
}
