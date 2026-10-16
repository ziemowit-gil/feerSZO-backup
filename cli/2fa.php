#!/usr/bin/env php
<?php
/**
 * cli/2fa.php — zarządzanie dwuskładnikowym uwierzytelnianiem (2FA)
 *
 * Użycie:
 *   php cli/2fa.php                     # lista użytkowników ze statusem 2FA
 *   php cli/2fa.php disable <email>     # wyłącz 2FA (TOTP + SMS) — także w panelu dydaktyka (zwolnienie z TOTP)
 *   php cli/2fa.php disable --all       # wyłącz 2FA wszystkim (j.w.)
 *   php cli/2fa.php status <email>      # szczegółowy status dla użytkownika
 *   php cli/2fa.php dyd-exempt <email> [powód]  # panel dydaktyka: wejście BEZ TOTP dla tego konta
 *   php cli/2fa.php dyd-require <email>         # panel dydaktyka: przywróć obowiązkowe TOTP
 *   php cli/2fa.php dyd-list                    # konta zwolnione z TOTP w panelu dydaktyka
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit('Tylko CLI.');
}

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/dyd_totp_exempt/logic/dydTotpExempt.php';

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

function find_user(string $email): ?array {
    return db_one("SELECT * FROM users WHERE email = ?", [$email]);
}

function twofa_status(array $u): string {
    $method = $u['twofa_method'] ?? '';
    if ($method === 'totp' && !empty($u['totp_confirmed'])) {
        return c('TOTP', 'green') . c(' (aplikacja)', 'gray');
    }
    if ($method === 'sms' && !empty($u['twofa_phone'])) {
        return c('SMS', 'cyan') . c(' (' . $u['twofa_phone'] . ')', 'gray');
    }
    return c('brak', 'gray');
}

function disable_2fa(array $u): void {
    // Panel dydaktyka wymusza TOTP — bez zwolnienia kazałby je od razu założyć od nowa.
    dydTotpExemptSet((int)$u['id'], true, 'cli: 2fa disable');
    $method = $u['twofa_method'] ?? '';
    if (empty($method)) {
        info("{$u['name']} ({$u['email']}) — 2FA nie było włączone; panel dydaktyka: wejście bez TOTP.");
        return;
    }
    db()->prepare(
        "UPDATE users
         SET totp_secret=NULL, totp_confirmed=0, twofa_method='', totp_backup_codes=NULL, twofa_phone=NULL
         WHERE id=?"
    )->execute([$u['id']]);

    $label = $method === 'totp' ? 'TOTP' : 'SMS';
    ok("Wyłączono 2FA ({$label}) dla {$u['name']} ({$u['email']}), także w panelu dydaktyka (przywrócenie: dyd-require).");
}

// ── Komendy ───────────────────────────────────────────────────────────────────

$cmd  = $argv[1] ?? 'list';
$arg2 = $argv[2] ?? '';

// ── LIST ──────────────────────────────────────────────────────────────────────
if ($cmd === 'list' || !in_array($cmd, ['disable', 'status', 'dyd-exempt', 'dyd-require', 'dyd-list'], true)) {
    $users = db_all(
        "SELECT id, name, email, role, is_active, twofa_method, totp_confirmed, twofa_phone, totp_backup_codes
         FROM users
         ORDER BY role DESC, name"
    );

    if (!$users) {
        warn('Brak użytkowników.');
        exit(0);
    }

    $col = [20, 32, 8, 6, 22];
    $hdr = [
        str_pad('Nazwa',   $col[0]),
        str_pad('E-mail',  $col[1]),
        str_pad('Rola',    $col[2]),
        str_pad('Aktyw.',  $col[3]),
        'Status 2FA',
    ];
    echo PHP_EOL;
    echo c(implode('  ', $hdr), 'bold') . PHP_EOL;
    echo c(str_repeat('─', array_sum($col) + count($col) * 2), 'gray') . PHP_EOL;

    foreach ($users as $u) {
        $aktywny = $u['is_active'] ? c('tak', 'green') : c('nie', 'red');
        printf(
            "%s  %s  %s  %s  %s\n",
            str_pad(mb_substr($u['name'],  0, $col[0]), $col[0]),
            str_pad(mb_substr($u['email'], 0, $col[1]), $col[1]),
            str_pad($u['role'],                         $col[2]),
            str_pad('', 1) . $aktywny . str_pad('', $col[3] - 3),
            twofa_status($u)
        );
    }
    echo PHP_EOL;
    exit(0);
}

// ── PANEL DYDAKTYKA: zwolnienie z TOTP ────────────────────────────────────────
if ($cmd === 'dyd-list') {
    $rows = dydTotpExemptList();
    if (!$rows) { ok('Brak kont zwolnionych z TOTP w panelu dydaktyka.'); exit(0); }
    echo PHP_EOL;
    foreach ($rows as $r) {
        echo ($r['name'] ?? '?') . ' <' . ($r['email'] ?? 'usunięte konto #' . $r['user_id'] . '') . '>  '
            . c($r['created_at'], 'gray') . ($r['reason'] !== '' ? '  ' . $r['reason'] : '') . PHP_EOL;
    }
    echo PHP_EOL;
    exit(0);
}
if ($cmd === 'dyd-exempt' || $cmd === 'dyd-require') {
    if (!$arg2) { err("Podaj e-mail: php cli/2fa.php {$cmd} <email>"); exit(1); }
    $u = find_user($arg2);
    if (!$u) { err("Nie znaleziono użytkownika: {$arg2}"); exit(1); }
    $exempt = $cmd === 'dyd-exempt';
    $reason = trim(implode(' ', array_slice($argv, 3)));
    dydTotpExemptSet((int)$u['id'], $exempt, $reason);
    if (function_exists('audit_log')) { try { audit_log('dyd.totp_exempt', ['user_id' => (int)$u['id'], 'exempt' => $exempt, 'reason' => $reason, 'via' => 'cli']); } catch (\Throwable $e) {} }
    $exempt ? ok("Panel dydaktyka: {$u['name']} ({$u['email']}) wchodzi BEZ TOTP.")
            : ok("Panel dydaktyka: {$u['name']} ({$u['email']}) — TOTP znów obowiązkowe.");
    exit(0);
}

// ── STATUS ────────────────────────────────────────────────────────────────────
if ($cmd === 'status') {
    if (!$arg2) { err('Podaj e-mail: php cli/2fa.php status <email>'); exit(1); }
    $u = find_user($arg2);
    if (!$u) { err("Nie znaleziono użytkownika: {$arg2}"); exit(1); }

    echo PHP_EOL;
    echo c("Użytkownik: ", 'bold') . "{$u['name']} <{$u['email']}>" . PHP_EOL;
    echo c("Rola:       ", 'bold') . $u['role'] . PHP_EOL;
    echo c("Status 2FA: ", 'bold') . twofa_status($u) . PHP_EOL;

    $method = $u['twofa_method'] ?? '';
    if ($method === 'totp') {
        $backup = json_decode($u['totp_backup_codes'] ?? '[]', true) ?? [];
        $remaining = count(array_filter($backup));
        echo c("Kody zapas.: ", 'bold') . ($remaining > 0 ? c("{$remaining} pozostałych", 'green') : c('brak', 'red')) . PHP_EOL;
    }
    if ($method === 'sms') {
        echo c("Telefon:    ", 'bold') . ($u['twofa_phone'] ?? '–') . PHP_EOL;
    }
    echo PHP_EOL;
    exit(0);
}

// ── DISABLE ───────────────────────────────────────────────────────────────────
if ($cmd === 'disable') {
    if ($arg2 === '--all') {
        $users = db_all(
            "SELECT id, name, email, twofa_method, twofa_phone
             FROM users
             WHERE twofa_method IS NOT NULL AND twofa_method != ''"
        );
        if (!$users) {
            ok('Żaden użytkownik nie ma włączonego 2FA.');
            exit(0);
        }
        echo PHP_EOL;
        warn('Wyłączam 2FA dla ' . count($users) . ' użytkownik(ów):');
        echo PHP_EOL;
        foreach ($users as $u) {
            disable_2fa($u);
        }
        echo PHP_EOL;
        exit(0);
    }

    if (!$arg2) { err('Podaj e-mail lub --all: php cli/2fa.php disable <email|--all>'); exit(1); }

    $u = find_user($arg2);
    if (!$u) { err("Nie znaleziono użytkownika: {$arg2}"); exit(1); }
    disable_2fa($u);
    exit(0);
}
