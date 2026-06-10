#!/usr/bin/env php
<?php
/**
 * cli/admin_ip.php — zarządzanie ograniczeniem IP dla panelu admina
 *
 * Użycie:
 *   php cli/admin_ip.php                    # pokaż stan i listę dozwolonych IP
 *   php cli/admin_ip.php disable            # wyłącz ograniczenie (awaryjny dostęp)
 *   php cli/admin_ip.php enable             # włącz ograniczenie
 *   php cli/admin_ip.php add 192.168.1.0/24 # dodaj adres/klasę do listy
 *   php cli/admin_ip.php remove 192.168.1.0/24  # usuń z listy
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit('Tylko CLI.');
}

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// ── Kolory ANSI ───────────────────────────────────────────────────────────────
$tty = stream_isatty(STDOUT);
function c(string $text, string $color): string {
    global $tty;
    if (!$tty) return $text;
    $codes = ['red'=>31,'green'=>32,'yellow'=>33,'cyan'=>36,'gray'=>90,'bold'=>1];
    return "\033[{$codes[$color]}m{$text}\033[0m";
}
function ok(string $msg)   { echo c('✔ ', 'green') . $msg . PHP_EOL; }
function err(string $msg)  { echo c('✘ ', 'red')   . $msg . PHP_EOL; exit(1); }
function info(string $msg) { echo c('  ', 'gray')   . $msg . PHP_EOL; }

// ── Helpers DB ────────────────────────────────────────────────────────────────
function get_setting(string $key): string {
    $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
    return $r['value'] ?? '';
}
function set_setting(string $key, string $value): void {
    db()->prepare(
        "INSERT INTO settings (key_, value) VALUES (?, ?)
         ON CONFLICT(key_) DO UPDATE SET value = excluded.value"
    )->execute([$key, $value]);
}

// ── Wyświetl status ───────────────────────────────────────────────────────────
function show_status(): void {
    $enabled = get_setting('admin_ip_restrict') === '1';
    $list    = get_setting('admin_ip_whitelist');
    $entries = array_filter(array_map('trim', explode("\n", $list)));

    echo PHP_EOL;
    echo c('=== Ograniczenie IP dla panelu admina ===', 'bold') . PHP_EOL;
    echo '  Stan: ';
    echo $enabled ? c('WŁĄCZONE', 'green') : c('wyłączone', 'gray');
    echo PHP_EOL;
    echo '  Konto serwis@local: ' . c('zawsze wykluczone', 'cyan') . PHP_EOL;

    if ($entries) {
        echo PHP_EOL . '  Dozwolone adresy:' . PHP_EOL;
        foreach ($entries as $e) {
            $prefix = str_starts_with($e, '#') ? c('  # ', 'gray') : c('  + ', 'green');
            echo $prefix . $e . PHP_EOL;
        }
    } else {
        echo '  Dozwolone adresy: ' . c('(brak)', 'gray') . PHP_EOL;
    }

    echo PHP_EOL;
    echo 'Użycie:' . PHP_EOL;
    echo '  php cli/admin_ip.php disable            — wyłącz ograniczenie' . PHP_EOL;
    echo '  php cli/admin_ip.php enable             — włącz ograniczenie' . PHP_EOL;
    echo '  php cli/admin_ip.php add <ip/cidr>      — dodaj adres do listy' . PHP_EOL;
    echo '  php cli/admin_ip.php remove <ip/cidr>   — usuń adres z listy' . PHP_EOL;
    echo PHP_EOL;
}

// ── Argumenty ─────────────────────────────────────────────────────────────────
$args = array_slice($argv, 1);
$cmd  = $args[0] ?? '';

if ($cmd === '') {
    show_status();
    exit(0);
}

if ($cmd === 'disable') {
    set_setting('admin_ip_restrict', '0');
    ok('Ograniczenie IP wyłączone — panel admina dostępny z każdego adresu.');
    exit(0);
}

if ($cmd === 'enable') {
    $list = get_setting('admin_ip_whitelist');
    $entries = array_filter(array_map('trim', explode("\n", $list)));
    if (empty($entries)) {
        err('Lista dozwolonych adresów jest pusta. Dodaj najpierw adres: php cli/admin_ip.php add <ip>');
    }
    set_setting('admin_ip_restrict', '1');
    ok('Ograniczenie IP włączone.');
    info('Dozwolone adresy: ' . implode(', ', $entries));
    exit(0);
}

if ($cmd === 'add') {
    $entry = trim($args[1] ?? '');
    if ($entry === '') err('Podaj adres IP lub klasę CIDR: php cli/admin_ip.php add 192.168.1.0/24');

    $list    = get_setting('admin_ip_whitelist');
    $entries = array_filter(array_map('trim', explode("\n", $list)));
    if (in_array($entry, $entries, true)) {
        info("Adres {$entry} już jest na liście.");
        exit(0);
    }
    $entries[] = $entry;
    set_setting('admin_ip_whitelist', implode("\n", $entries));
    ok("Dodano: {$entry}");
    exit(0);
}

if ($cmd === 'remove') {
    $entry = trim($args[1] ?? '');
    if ($entry === '') err('Podaj adres do usunięcia: php cli/admin_ip.php remove 192.168.1.0/24');

    $list    = get_setting('admin_ip_whitelist');
    $entries = array_filter(array_map('trim', explode("\n", $list)));
    $new     = array_values(array_filter($entries, fn($e) => $e !== $entry));
    if (count($new) === count($entries)) {
        info("Nie znaleziono {$entry} na liście.");
        exit(0);
    }
    set_setting('admin_ip_whitelist', implode("\n", $new));
    ok("Usunięto: {$entry}");
    exit(0);
}

echo "Nieznana komenda: {$cmd}" . PHP_EOL;
echo 'Uruchom bez argumentów, żeby zobaczyć pomoc.' . PHP_EOL;
exit(1);
