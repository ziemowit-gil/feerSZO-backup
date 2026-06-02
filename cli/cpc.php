#!/usr/bin/env php
<?php
/**
 * cli/cpc.php — zarządzanie kodami CPC (Critical Process Code)
 *
 * Użycie:
 *   php cli/cpc.php                          # lista adminów/edytorów z statusem CPC
 *   php cli/cpc.php set <email> [kod]        # ustaw kod (lub losowy 6-cyfrowy)
 *   php cli/cpc.php remove <email>           # usuń kod CPC
 *   php cli/cpc.php unblock <email>          # odblokuj konto (wyczyść blokadę i licznik)
 *   php cli/cpc.php unblock --all            # odblokuj wszystkich
 *   php cli/cpc.php bulk                     # nadaj losowe kody wszystkim bez CPC (admin+editor)
 *   php cli/cpc.php bulk --force             # nadpisz kody także tym, którzy już mają
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
    $codes = ['red'=>31,'green'=>32,'yellow'=>33,'blue'=>34,'cyan'=>36,'gray'=>90,'bold'=>1,'reset'=>0];
    return "\033[{$codes[$color]}m{$text}\033[0m";
}
function ok(string $msg)   { echo c('✔ ', 'green')  . $msg . PHP_EOL; }
function err(string $msg)  { echo c('✘ ', 'red')    . $msg . PHP_EOL; }
function warn(string $msg) { echo c('! ', 'yellow')  . $msg . PHP_EOL; }
function info(string $msg) { echo c('  ', 'gray')    . $msg . PHP_EOL; }

// ── Helpers ───────────────────────────────────────────────────────────────────

function gen_cpc(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function find_user(string $email): ?array {
    return db_one("SELECT * FROM users WHERE email = ?", [$email]);
}

function cpc_status(array $u): string {
    global $tty;
    if (empty($u['cpc_code']))          return c('BRAK',        'red');
    if (!empty($u['cpc_blocked_until'])
        && $u['cpc_blocked_until'] > date('Y-m-d H:i:s'))
                                         return c('ZABLOKOWANY', 'yellow') . c(' do ' . $u['cpc_blocked_until'], 'gray');
    $fails = (int)($u['cpc_fails'] ?? 0);
    $label = c('OK', 'green');
    if ($fails > 0) $label .= c(" ({$fails} błęd.)", 'yellow');
    return $label;
}

// ── Komendy ───────────────────────────────────────────────────────────────────

$cmd  = $argv[1] ?? 'list';
$arg2 = $argv[2] ?? '';
$arg3 = $argv[3] ?? '';

// ── LIST ──────────────────────────────────────────────────────────────────────
if ($cmd === 'list' || ($cmd !== 'set' && $cmd !== 'remove' && $cmd !== 'unblock' && $cmd !== 'bulk')) {
    $users = db_all(
        "SELECT id, name, email, role, is_active, cpc_code, cpc_fails, cpc_blocked_until
         FROM users
         WHERE role IN ('admin','editor')
         ORDER BY role DESC, name"
    );

    if (!$users) {
        warn('Brak użytkowników z rolą admin lub editor.');
        exit(0);
    }

    $col = [18, 30, 8, 6, 25];
    $hdr = [
        str_pad('Nazwa',    $col[0]),
        str_pad('E-mail',   $col[1]),
        str_pad('Rola',     $col[2]),
        str_pad('Aktywny',  $col[3]),
        'Status CPC',
    ];
    echo PHP_EOL;
    echo c(implode('  ', $hdr), 'bold') . PHP_EOL;
    echo c(str_repeat('─', array_sum($col) + count($col) * 2), 'gray') . PHP_EOL;

    foreach ($users as $u) {
        $aktywny = $u['is_active'] ? c('tak', 'green') : c('nie', 'red');
        printf(
            "%s  %s  %s  %s  %s\n",
            str_pad(mb_substr($u['name'], 0, $col[0]), $col[0]),
            str_pad(mb_substr($u['email'], 0, $col[1]), $col[1]),
            str_pad($u['role'], $col[2]),
            str_pad('', 1) . $aktywny . str_pad('', $col[3] - 3),
            cpc_status($u)
        );
    }
    echo PHP_EOL;
    exit(0);
}

// ── SET ───────────────────────────────────────────────────────────────────────
if ($cmd === 'set') {
    if (!$arg2) { err('Podaj e-mail: php cli/cpc.php set <email> [kod]'); exit(1); }

    $user = find_user($arg2);
    if (!$user) { err("Nie znaleziono użytkownika: {$arg2}"); exit(1); }
    if (!in_array($user['role'], ['admin', 'editor'], true)) {
        warn("Użytkownik {$arg2} ma rolę \"{$user['role']}\". CPC działa tylko dla admin/editor.");
    }

    $kod = $arg3 ?: gen_cpc();

    if (!preg_match('/^\d{6}$/', $kod)) {
        err("Kod CPC musi składać się z dokładnie 6 cyfr. Podano: „{$kod}"");
        exit(1);
    }

    db()->prepare(
        "UPDATE users SET cpc_code = ?, cpc_fails = 0, cpc_blocked_until = NULL WHERE id = ?"
    )->execute([$kod, $user['id']]);

    ok("Kod CPC dla {$user['name']} ({$arg2}) ustawiony na: " . c($kod, 'cyan'));
    info('Przekaż kod użytkownikowi bezpiecznym kanałem (np. SMS, spotkanie osobiste).');
    exit(0);
}

// ── REMOVE ────────────────────────────────────────────────────────────────────
if ($cmd === 'remove') {
    if (!$arg2) { err('Podaj e-mail: php cli/cpc.php remove <email>'); exit(1); }

    $user = find_user($arg2);
    if (!$user) { err("Nie znaleziono użytkownika: {$arg2}"); exit(1); }

    db()->prepare(
        "UPDATE users SET cpc_code = NULL, cpc_fails = 0, cpc_blocked_until = NULL WHERE id = ?"
    )->execute([$user['id']]);

    ok("Usunięto kod CPC dla {$user['name']} ({$arg2}).");
    exit(0);
}

// ── UNBLOCK ───────────────────────────────────────────────────────────────────
if ($cmd === 'unblock') {
    if ($arg2 === '--all') {
        $res = db()->exec(
            "UPDATE users SET cpc_fails = 0, cpc_blocked_until = NULL
             WHERE role IN ('admin','editor') AND (cpc_fails > 0 OR cpc_blocked_until IS NOT NULL)"
        );
        ok("Odblokowano {$res} kont(a).");
        exit(0);
    }

    if (!$arg2) { err('Podaj e-mail lub --all: php cli/cpc.php unblock <email|--all>'); exit(1); }

    $user = find_user($arg2);
    if (!$user) { err("Nie znaleziono użytkownika: {$arg2}"); exit(1); }

    $was_blocked = !empty($user['cpc_blocked_until'])
                   && $user['cpc_blocked_until'] > date('Y-m-d H:i:s');
    $fails = (int)($user['cpc_fails'] ?? 0);

    db()->prepare(
        "UPDATE users SET cpc_fails = 0, cpc_blocked_until = NULL WHERE id = ?"
    )->execute([$user['id']]);

    if ($was_blocked) {
        ok("Odblokowano {$user['name']} ({$arg2}). Blokada trwała do: {$user['cpc_blocked_until']}.");
    } elseif ($fails > 0) {
        ok("Zresetowano licznik błędów ({$fails}) dla {$user['name']} ({$arg2}).");
    } else {
        info("{$user['name']} ({$arg2}) — konto nie było zablokowane. Zresetowano na wszelki wypadek.");
    }
    exit(0);
}

// ── BULK ──────────────────────────────────────────────────────────────────────
if ($cmd === 'bulk') {
    $force = ($arg2 === '--force' || $arg3 === '--force');

    $where = $force
        ? "role IN ('admin','editor') AND is_active = 1"
        : "role IN ('admin','editor') AND is_active = 1 AND (cpc_code IS NULL OR cpc_code = '')";

    $users = db_all("SELECT id, name, email, role FROM users WHERE {$where} ORDER BY role DESC, name");

    if (!$users) {
        ok($force
            ? 'Brak aktywnych adminów/edytorów.'
            : 'Wszyscy aktywni adminowie/edytorzy mają już kody CPC. Użyj --force aby nadpisać.');
        exit(0);
    }

    $action = $force ? 'Nadpisuję' : 'Nadaję nowe';
    echo PHP_EOL;
    echo c("{$action} kody CPC dla " . count($users) . " użytkownik(ów):", 'bold') . PHP_EOL;
    echo PHP_EOL;

    $stmt = db()->prepare(
        "UPDATE users SET cpc_code = ?, cpc_fails = 0, cpc_blocked_until = NULL WHERE id = ?"
    );

    $col = [20, 32, 10];
    $hdr = [str_pad('Nazwa', $col[0]), str_pad('E-mail', $col[1]), 'Kod CPC'];
    echo c(implode('  ', $hdr), 'bold') . PHP_EOL;
    echo c(str_repeat('─', array_sum($col) + count($col) * 2), 'gray') . PHP_EOL;

    foreach ($users as $u) {
        $kod = gen_cpc();
        $stmt->execute([$kod, $u['id']]);
        printf(
            "%s  %s  %s\n",
            str_pad(mb_substr($u['name'], 0, $col[0]), $col[0]),
            str_pad(mb_substr($u['email'], 0, $col[1]), $col[1]),
            c($kod, 'cyan')
        );
    }

    echo PHP_EOL;
    warn('Wydrukuj lub zapisz tę listę — kody nie będą wyświetlone ponownie.');
    warn('Przekaż każdemu użytkownikowi jego kod bezpiecznym kanałem.');
    echo PHP_EOL;
    exit(0);
}
