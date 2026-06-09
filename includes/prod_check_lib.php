<?php
/**
 * includes/prod_check_lib.php — Logika listy kontrolnej wdrożenia produkcyjnego
 *
 * Używana przez: cli/prod_check.php, admin/prod_check.php
 *
 * Jedyna funkcja publiczna: run_prod_checks(string $root): array
 *
 * Każdy wynik to tablica:
 *   section string  — nazwa sekcji (np. 'PHP', 'Baza danych')
 *   label   string  — nazwa sprawdzenia
 *   status  string  — 'ok' | 'warn' | 'fail' | 'skip'
 *   detail  string  — szczegółowy opis / wskazówka naprawcza (może być wielolinijkowy)
 *   link    string  — opcjonalny URL do strony naprawczej (dla GUI)
 */

function run_prod_checks(string $root): array
{
    $r = [];

    // Shorthand: add result
    $add = function(
        string $section, string $label, string $status,
        string $detail = '', string $link = ''
    ) use (&$r): void {
        $r[] = [
            'section' => $section,
            'label'   => $label,
            'status'  => $status,
            'detail'  => $detail,
            'link'    => $link,
        ];
    };

    // ══════════════════════════════════════════════════════════════════════════
    //  1. PHP — wersja i rozszerzenia
    // ══════════════════════════════════════════════════════════════════════════
    $s = 'PHP';

    $v = PHP_VERSION;
    if (version_compare($v, '8.1.0', '>='))      $add($s, 'PHP >= 8.1', 'ok', "Wersja: {$v}");
    elseif (version_compare($v, '8.0.0', '>='))  $add($s, 'PHP >= 8.1', 'warn', "Wersja: {$v} — zalecane 8.1+");
    else                                          $add($s, 'PHP >= 8.1', 'fail', "Wersja: {$v} — wymagane PHP 8.0+");

    $exts = [
        'pdo'        => ['PDO (baza danych)', true],
        'pdo_sqlite' => ['PDO SQLite', true],
        'pdo_mysql'  => ['PDO MySQL', false],
        'json'       => ['JSON', true],
        'mbstring'   => ['Multibyte Strings', true],
        'openssl'    => ['OpenSSL', true],
        'curl'       => ['cURL (Graph API, SMS)', true],
        'zip'        => ['ZIP (archiwizacja)', false],
        'gd'         => ['GD (obrazy/certyfikaty)', false],
        'intl'       => ['Intl (unicode)', false],
        'fileinfo'   => ['Fileinfo', true],
        'session'    => ['Sessions', true],
    ];
    foreach ($exts as $ext => [$label, $required]) {
        if (extension_loaded($ext)) {
            $add($s, "ext/{$ext}", 'ok', $label);
        } elseif ($required) {
            $add($s, "ext/{$ext}", 'fail',
                "{$label} — brak! Zainstaluj: apt install php8.x-{$ext}");
        } else {
            $add($s, "ext/{$ext}", 'warn', "{$label} — opcjonalne, ale zalecane");
        }
    }

    $mem = ini_get('memory_limit');
    $mem_bytes = prod_check_bytes($mem);
    if ($mem_bytes >= 128 * 1024 * 1024 || $mem_bytes === -1)
         $add($s, 'memory_limit', 'ok', $mem);
    else $add($s, 'memory_limit', 'warn', "{$mem} — zalecane ≥ 128M (php.ini: memory_limit = 256M)");

    $de = ini_get('display_errors');
    if (!$de || $de === 'Off' || $de === '0')
         $add($s, 'display_errors', 'ok', 'Off');
    else $add($s, 'display_errors', 'fail',
             "display_errors = {$de} — musi być Off na produkcji\nphp.ini: display_errors = Off");

    // ══════════════════════════════════════════════════════════════════════════
    //  2. Środowisko i konfiguracja
    // ══════════════════════════════════════════════════════════════════════════
    $s = 'Konfiguracja';

    if (defined('APP_ENV') && APP_ENV === 'production')
         $add($s, 'APP_ENV', 'ok', 'production');
    elseif (defined('APP_ENV'))
         $add($s, 'APP_ENV', 'warn',
             'APP_ENV = ' . APP_ENV . "\nUstaw APP_ENV=production w config.local.php lub zmiennej środowiskowej");
    else $add($s, 'APP_ENV', 'fail', 'APP_ENV nie jest zdefiniowane');

    $default_key = '0d74d40a14da3673d68c6bd7094d4142f60d20eea6435eb99b567f232b4608d7';
    if (!defined('APP_KEY') || !APP_KEY)
         $add($s, 'APP_KEY', 'fail', 'APP_KEY nie jest ustawiony');
    elseif (APP_KEY === $default_key)
         $add($s, 'APP_KEY', 'fail',
             "APP_KEY = domyślny klucz z repozytorium\nWygeneruj nowy: php -r \"echo bin2hex(random_bytes(32));\"");
    elseif (strlen(APP_KEY) < 32)
         $add($s, 'APP_KEY', 'warn', 'APP_KEY zbyt krótki — zalecane 64 znaki hex');
    else $add($s, 'APP_KEY', 'ok', 'Ustawiony (' . strlen(APP_KEY) . ' znaków)');

    if (!defined('APP_URL') || !APP_URL)
         $add($s, 'APP_URL', 'fail', 'APP_URL nie jest ustawiony');
    elseif (!str_starts_with(APP_URL, 'https://'))
         $add($s, 'APP_URL', 'warn', APP_URL . "\nProdukcja powinna używać HTTPS");
    else $add($s, 'APP_URL', 'ok', APP_URL);

    if (!defined('ORG_NAME') || !ORG_NAME)
         $add($s, 'ORG_NAME', 'warn', 'ORG_NAME nie jest ustawiony — ustaw w config.local.php');
    else $add($s, 'ORG_NAME', 'ok', ORG_NAME);

    if (file_exists($root . '/config.local.php'))
         $add($s, 'config.local.php', 'ok', 'Istnieje — lokalna konfiguracja aktywna');
    else $add($s, 'config.local.php', 'warn',
             'Brak pliku config.local.php' . "\n" .
             'Skopiuj: cp config.local.php.example config.local.php i dostosuj');

    // ══════════════════════════════════════════════════════════════════════════
    //  3. Baza danych
    // ══════════════════════════════════════════════════════════════════════════
    $s = 'Baza danych';

    $db_type = defined('DB_TYPE') ? DB_TYPE : 'sqlite';
    $add($s, 'DB_TYPE', 'ok', $db_type);

    if ($db_type === 'sqlite') {
        $db_path = defined('DB_PATH') ? DB_PATH : ($root . '/umowy.db');
        if (!file_exists($db_path)) {
            $add($s, 'Plik SQLite', 'fail', "Nie istnieje: {$db_path}");
        } else {
            $sz = filesize($db_path);
            $szh = $sz > 1048576 ? round($sz / 1048576, 1) . ' MB' : round($sz / 1024) . ' KB';
            $add($s, 'Plik SQLite', 'ok', basename($db_path) . " ({$szh})");
            if (!is_readable($db_path))
                $add($s, 'SQLite uprawnienia', 'fail', 'Brak uprawnień odczytu!');
            elseif (!is_writable($db_path))
                $add($s, 'SQLite uprawnienia', 'fail', 'Brak uprawnień zapisu!');
            else
                $add($s, 'SQLite uprawnienia', 'ok', 'r/w OK');
            if (!is_writable(dirname($db_path)))
                $add($s, 'SQLite katalog', 'fail',
                    'Katalog nie jest zapisywalny (potrzebne dla WAL): ' . dirname($db_path));
            else
                $add($s, 'SQLite katalog', 'ok', dirname($db_path));
        }
        try {
            $tpdo = new PDO('sqlite:' . ($db_path ?? ''));
            $wal = $tpdo->query("PRAGMA journal_mode")->fetchColumn();
            $tc  = (int)$tpdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table'"
            )->fetchColumn();
            $add($s, 'Połączenie SQLite', 'ok', "{$tc} tabel");
            if ($wal === 'wal') $add($s, 'journal_mode=WAL', 'ok', 'WAL aktywny');
            else $add($s, 'journal_mode=WAL', 'warn',
                "Tryb: {$wal}\nPRAGMA journal_mode=WAL; — wymagane dla współbieżnych żądań");
        } catch (\Throwable $e) {
            $add($s, 'Połączenie SQLite', 'fail', $e->getMessage());
        }
    } else {
        // MySQL
        foreach ([
            'DB_HOST' => [defined('DB_HOST') ? DB_HOST : '', true],
            'DB_NAME' => [defined('DB_NAME') ? DB_NAME : '', true],
            'DB_USER' => [defined('DB_USER') ? DB_USER : '', true],
        ] as $k => [$v, $req]) {
            if (!$v) $add($s, $k, 'fail', "Nie ustawiono — dodaj do config.local.php");
            else     $add($s, $k, 'ok', $v);
        }
        if (!defined('DB_PASS') || !DB_PASS)
             $add($s, 'DB_PASS', 'warn', 'Brak hasła MySQL — upewnij się że jest wymagane');
        else $add($s, 'DB_PASS', 'ok', 'Ustawione');
        try {
            $tpdo = new PDO(
                'mysql:host=' . (defined('DB_HOST') ? DB_HOST : '') .
                ';port=' . (defined('DB_PORT') ? DB_PORT : 3306) .
                ';dbname=' . (defined('DB_NAME') ? DB_NAME : '') . ';charset=utf8mb4',
                defined('DB_USER') ? DB_USER : '',
                defined('DB_PASS') ? DB_PASS : ''
            );
            $ver = $tpdo->query('SELECT VERSION()')->fetchColumn();
            $tc  = (int)$tpdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "'"
            )->fetchColumn();
            $add($s, 'Połączenie MySQL', 'ok', "{$ver} — {$tc} tabel");
        } catch (\Throwable $e) {
            $add($s, 'Połączenie MySQL', 'fail', $e->getMessage());
        }
    }

    try {
        $admins = function_exists('db_all')
            ? db_all("SELECT name, email, is_active FROM users WHERE role='admin'")
            : [];
        if ($admins) {
            $active = count(array_filter($admins, fn($u) => $u['is_active']));
            if ($active >= 1) $add($s, 'Konta admin', 'ok',
                count($admins) . ' adminów (' . $active . ' aktywnych)');
            else              $add($s, 'Konta admin', 'fail', 'Brak aktywnych adminów!');
        } else {
            $add($s, 'Konta admin', 'fail', 'Brak kont admin w bazie!');
        }
    } catch (\Throwable $e) {
        $add($s, 'Konta admin', 'fail', $e->getMessage());
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  4. Pliki i katalogi
    // ══════════════════════════════════════════════════════════════════════════
    $s = 'Pliki';

    $upload_dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : ($root . '/uploads/');
    if (!is_dir($upload_dir))
         $add($s, 'UPLOAD_DIR', 'fail',
             "Katalog nie istnieje: {$upload_dir}\nmkdir -p {$upload_dir} && chmod 775 {$upload_dir}");
    elseif (!is_writable($upload_dir))
         $add($s, 'UPLOAD_DIR', 'fail',
             "Brak uprawnień zapisu: {$upload_dir}\nchmod 775 {$upload_dir} lub chown www-data");
    else $add($s, 'UPLOAD_DIR', 'ok', $upload_dir);

    $htc_path = rtrim($upload_dir, '/') . '/.htaccess';
    if (file_exists($htc_path)) {
        $htc = file_get_contents($htc_path);
        if (str_contains($htc, 'php') || str_contains($htc, 'Options'))
             $add($s, 'uploads/.htaccess', 'ok', 'Ochrona przed wykonaniem PHP');
        else $add($s, 'uploads/.htaccess', 'warn', 'Plik istnieje ale może nie blokować PHP');
    } else {
        $add($s, 'uploads/.htaccess', 'warn',
            "Brak pliku {$htc_path}\n" .
            "Utwórz z zawartością:\nOptions -Indexes\nphp_flag engine off\nRemoveHandler .php .php3 .phtml");
    }

    $gi = $root . '/.gitignore';
    if (file_exists($gi)) {
        if (str_contains(file_get_contents($gi), 'config.local.php'))
             $add($s, '.gitignore', 'ok', 'config.local.php jest ignorowany przez git');
        else $add($s, '.gitignore', 'warn',
                 'config.local.php nie jest w .gitignore' .
                 "\nDodaj: echo \"config.local.php\" >> .gitignore");
    } else {
        $add($s, '.gitignore', 'warn', 'Brak pliku .gitignore');
    }

    $debug_files = array_filter([
        $root . '/debug_login_84.php',
        $root . '/phpinfo.php',
        $root . '/test.php',
        $root . '/info.php',
    ], 'file_exists');
    if ($debug_files)
         $add($s, 'Pliki debugowe', 'warn',
             'Znaleziono: ' . implode(', ', array_map('basename', $debug_files)) . "\nRozważ usunięcie przed wdrożeniem");
    else $add($s, 'Pliki debugowe', 'ok', 'Brak');

    if (is_writable(sys_get_temp_dir()))
         $add($s, 'Katalog temp', 'ok', sys_get_temp_dir());
    else $add($s, 'Katalog temp', 'warn', sys_get_temp_dir() . ' nie jest zapisywalny');

    // ══════════════════════════════════════════════════════════════════════════
    //  5. Bezpieczeństwo
    // ══════════════════════════════════════════════════════════════════════════
    $s = 'Bezpieczeństwo';

    if (defined('APP_URL') && str_starts_with(APP_URL, 'https://'))
         $add($s, 'HTTPS', 'ok', APP_URL);
    else $add($s, 'HTTPS', 'warn',
             (defined('APP_URL') ? APP_URL : '—') . "\nUstaw APP_URL=https://... w config.local.php i skonfiguruj certyfikat SSL");

    $sess_secure   = ini_get('session.cookie_secure');
    $sess_httponly = ini_get('session.cookie_httponly');
    $sess_samesite = ini_get('session.cookie_samesite');
    if ($sess_secure && $sess_httponly) {
        $add($s, 'Session cookies', 'ok',
            "secure={$sess_secure}, httponly={$sess_httponly}, samesite=" . ($sess_samesite ?: 'Lax'));
    } else {
        $hints = [];
        if (!$sess_secure)   $hints[] = 'session.cookie_secure = On';
        if (!$sess_httponly) $hints[] = 'session.cookie_httponly = On';
        if (!$sess_samesite) $hints[] = 'session.cookie_samesite = Lax';
        $add($s, 'Session cookies', 'warn', implode("\n", $hints) . "\n(php.ini lub .htaccess)");
    }

    $expose = ini_get('expose_php');
    if (!$expose || $expose === 'Off' || $expose === '0')
         $add($s, 'expose_php', 'ok', 'Off');
    else $add($s, 'expose_php', 'warn', "expose_php = {$expose}\nphp.ini: expose_php = Off");

    try {
        if (function_exists('db_one')) {
            $svc = db_one("SELECT id, password FROM users WHERE email = 'serwis@local'");
            if ($svc) {
                if ($svc['password'])  $add($s, 'Konto serwis@local', 'ok', 'Istnieje z hasłem');
                else                   $add($s, 'Konto serwis@local', 'warn',
                    "Konto bez hasła\nUstaw hasło: php cli/passwd.php");
            } else {
                $add($s, 'Konto serwis@local', 'warn',
                    "Nie istnieje — utwórz: php cli/CreateServiceUser.php");
            }
        }
    } catch (\Throwable $e) {}

    // ══════════════════════════════════════════════════════════════════════════
    //  6. Moduły i usługi
    // ══════════════════════════════════════════════════════════════════════════
    $s = 'Moduły';

    // M365
    try {
        if (!function_exists('m365_setting') && file_exists($root . '/includes/m365.php')) {
            require_once $root . '/includes/m365.php';
        }
        if (function_exists('m365_setting')) {
            $m365_tenant = m365_setting('m365_tenant_id');
            $m365_client = m365_setting('m365_graph_client_id');
            $m365_secret = m365_setting('m365_graph_client_secret');
            $m365_on     = m365_setting('m365_enabled');

            if ($m365_on === '1' || $m365_on === '') {
                if ($m365_tenant && $m365_client && $m365_secret) {
                    $add($s, 'Microsoft 365', 'ok', "Tenant: {$m365_tenant}", APP_URL . '/admin/m365_settings.php');
                } elseif ($m365_tenant || $m365_client) {
                    $missing = implode(', ', array_filter([
                        !$m365_tenant ? 'Tenant ID' : null,
                        !$m365_client ? 'Client ID' : null,
                        !$m365_secret ? 'Client Secret' : null,
                    ]));
                    $add($s, 'Microsoft 365', 'warn', "Brak: {$missing}", APP_URL . '/admin/m365_settings.php');
                } else {
                    $add($s, 'Microsoft 365', 'skip', 'Nieskonfigurowany', APP_URL . '/admin/m365_settings.php');
                }

                // Graph permissions
                if ($m365_tenant && $m365_client) {
                    $add($s, 'Uprawnienia Graph', 'warn',
                        'Zweryfikuj uprawnienia Graph API', APP_URL . '/admin/graph_permissions.php');
                }
            } else {
                $add($s, 'Microsoft 365', 'skip', 'Moduł wyłączony', APP_URL . '/admin/m365_settings.php');
            }

            // SharePoint
            $sp_site = m365_setting('sp_site_url');
            if ($sp_site) $add($s, 'SharePoint backup', 'ok', $sp_site, APP_URL . '/admin/m365_settings.php');
            else          $add($s, 'SharePoint backup', 'warn', 'Nieskonfigurowany',
                               APP_URL . '/admin/m365_settings.php');

            // SMTP via Graph
            $m365_sender = m365_setting('m365_sender_user_id');
            if ($m365_sender) {
                $add($s, 'SMTP (Graph Mail)', 'ok', "Nadawca: {$m365_sender}", APP_URL . '/admin/m365_settings.php');
            } else {
                $add($s, 'SMTP (Graph Mail)', 'warn', 'Brak skonfigurowanego nadawcy poczty',
                     APP_URL . '/admin/m365_settings.php');
            }
        }
    } catch (\Throwable $e) {
        $add($s, 'Microsoft 365', 'skip', 'Błąd ładowania: ' . $e->getMessage());
    }

    // Cron
    $cron = $root . '/cron/dispatcher.php';
    if (file_exists($cron))
         $add($s, 'Cron dispatcher', 'ok', 'cron/dispatcher.php istnieje');
    else $add($s, 'Cron dispatcher', 'fail', 'Brak pliku cron/dispatcher.php');

    $add($s, 'Cron w systemie', 'warn',
        "Zweryfikuj manualnie: crontab -l | grep dispatcher\n" .
        "Zalecane: */5 * * * * php {$root}/cron/dispatcher.php >> /var/log/feer_cron.log 2>&1");

    // Org calendar ICS
    try {
        if (function_exists('org_setting')) {
            $ics = org_setting('org_calendar_ics_url');
            if ($ics) $add($s, 'Kalendarz ICS', 'ok', $ics, APP_URL . '/admin/org_calendar.php');
            else      $add($s, 'Kalendarz ICS', 'skip', 'Nieskonfigurowany', APP_URL . '/admin/org_calendar.php');
        }
    } catch (\Throwable $e) {}

    return $r;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function prod_check_bytes(string $val): int
{
    $val  = trim($val);
    if ($val === '-1') return -1;
    $last = strtolower($val[-1] ?? '');
    $num  = (int)$val;
    return match($last) {
        'g' => $num * 1073741824,
        'm' => $num * 1048576,
        'k' => $num * 1024,
        default => $num,
    };
}

function prod_check_summary(array $results): array
{
    $cnt = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];
    foreach ($results as $r) $cnt[$r['status']] = ($cnt[$r['status']] ?? 0) + 1;
    return $cnt;
}
