#!/usr/bin/env php
<?php
/**
 * cli/ldap_install.php — instalator/walidator katalogu LDAP dla SZO.
 *
 * Używa tej samej konfiguracji (stałe LDAP_*) i klienta (includes/ldap.php) co
 * runtime aplikacji, więc sprawdza dokładnie to połączenie, którego SZO użyje
 * przy synchronizacji. Działa zarówno przeciw lokalnemu kontenerowi
 * (setup-ldap.sh), jak i zewnętrznemu ldap-prod.feer.org.pl.
 *
 * Co robi:
 *   1. Weryfikuje konfigurację (LDAP_* / LDAP_ENABLED).
 *   2. Łączy się i binduje kontem serwisowym.
 *   3. Zapewnia istnienie gałęzi ou=users (idempotentnie).
 *
 * Użycie:
 *   php cli/ldap_install.php            # połącz, zbinduj, utwórz OU jeśli brak
 *   php cli/ldap_install.php --dry-run  # tylko test połączenia, bez zmian
 *   php cli/ldap_install.php --help
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Tylko CLI.\n");
}

define('APP_CLI', true);
if (!defined('APP_INSTALLED')) {
    define('APP_INSTALLED', true);
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/ldap.php';

$opts = getopt('', ['dry-run', 'help']);

if (isset($opts['help'])) {
    echo "Użycie: php cli/ldap_install.php [--dry-run]\n";
    echo "  --dry-run   tylko test połączenia (bind), bez tworzenia OU\n";
    exit(0);
}

$dry = isset($opts['dry-run']);

// ── Wyjście (ANSI, z poszanowaniem NO_COLOR) ──────────────────────────────────
$color = getenv('NO_COLOR') === false || getenv('NO_COLOR') === '';
$c = fn (string $code, string $s) => $color ? "\033[{$code}m{$s}\033[0m" : $s;
$ok   = fn (string $s) => print($c('0;32', "  ✔ $s") . "\n");
$info = fn (string $s) => print($c('0;36', "  ▸ $s") . "\n");
$err  = fn (string $s) => fwrite(STDERR, $c('0;31', "  ✖ $s") . "\n");

echo "\n" . $c('1', 'Instalator LDAP — FEER SZO') . ($dry ? ' (DRY-RUN)' : '') . "\n";

// ── 1. Konfiguracja ───────────────────────────────────────────────────────────
if (!defined('LDAP_ENABLED') || !LDAP_ENABLED) {
    $err('LDAP_ENABLED nie jest włączone. Ustaw define(\'LDAP_ENABLED\', true) w config.local.php.');
    exit(1);
}

$ldap = new LdapDirectory();
if (!$ldap->is_configured()) {
    $err('Brak kompletu stałych LDAP_* (host/bind DN/hasło/OU) albo rozszerzenia php-ldap.');
    exit(1);
}
$ok('Konfiguracja kompletna: ' . LDAP_HOST . ':' . LDAP_PORT . ' (base ' . LDAP_BASE_DN . ')');

// ── 2. Połączenie i bind ──────────────────────────────────────────────────────
try {
    $info('Łączę i binduję jako ' . LDAP_BIND_DN . '…');
    $ldap->connect();
    $ok('Bind zakończony powodzeniem.');
} catch (\Throwable $e) {
    $err('Połączenie/bind nieudane: ' . $e->getMessage());
    exit(1);
}

// ── 3. Gałąź ou=users ─────────────────────────────────────────────────────────
if ($dry) {
    $info('DRY-RUN: pomijam tworzenie OU (' . LDAP_USERS_OU . ').');
} else {
    try {
        $res = $ldap->ensure_users_ou();
        $res === 'created'
            ? $ok('Utworzono gałąź ' . LDAP_USERS_OU)
            : $ok('Gałąź już istnieje: ' . LDAP_USERS_OU);
    } catch (\Throwable $e) {
        $err('Nie udało się zapewnić OU: ' . $e->getMessage());
        $ldap->close();
        exit(1);
    }
}

$ldap->close();

echo "\n" . $c('1;32', 'Gotowe.') . " Możesz uruchomić eksport: php cron/sync_ldap.php --dry-run\n\n";
exit(0);
