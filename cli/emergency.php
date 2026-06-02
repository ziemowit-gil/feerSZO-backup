#!/usr/bin/env php
<?php
/**
 * cli/emergency.php — tworzy / resetuje konto awaryjne administratora.
 *
 * Konto:
 *   Login:  administratorratunkowy@local
 *   Hasło:  ratunek
 *   Rola:   admin
 *   CPC:    generowany losowo i wyświetlany na ekranie
 *
 * Użycie:
 *   php cli/emergency.php           # utwórz / zresetuj konto i pokaż dane
 *   php cli/emergency.php --status  # pokaż aktualny stan konta (bez zmian)
 *   php cli/emergency.php --remove  # usuń konto awaryjne
 *
 * UWAGA: Uruchamiaj tylko przy braku dostępu do panelu admina.
 *        Po odzyskaniu dostępu usuń lub zablokuj to konto.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit('Tylko CLI.');
}

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// ── Konfiguracja konta awaryjnego ─────────────────────────────────────────────

const EMERGENCY_EMAIL = 'administratorratunkowy@local';
const EMERGENCY_NAME  = 'Administrator Ratunkowy';
const EMERGENCY_PASS  = 'ratunek';

// ── Kolory ANSI ───────────────────────────────────────────────────────────────

$tty = stream_isatty(STDOUT);
function c(string $text, string $color): string {
    global $tty;
    if (!$tty) return $text;
    $codes = ['red'=>31,'green'=>32,'yellow'=>33,'blue'=>34,'cyan'=>36,'magenta'=>35,'gray'=>90,'bold'=>1];
    return "\033[{$codes[$color]}m{$text}\033[0m";
}
function sep(): void { echo c(str_repeat('─', 50), 'gray') . PHP_EOL; }

// ── Migracje — upewniamy się że kolumny istnieją ──────────────────────────────

$migrations = [
    'must_change_password' => "ALTER TABLE users ADD COLUMN must_change_password INTEGER DEFAULT 0",
    'locked_until'         => "ALTER TABLE users ADD COLUMN locked_until DATETIME DEFAULT NULL",
    'cpc_code'             => "ALTER TABLE users ADD COLUMN cpc_code TEXT NULL",
    'cpc_fails'            => "ALTER TABLE users ADD COLUMN cpc_fails INTEGER DEFAULT 0",
    'cpc_blocked_until'    => "ALTER TABLE users ADD COLUMN cpc_blocked_until DATETIME NULL",
];
foreach ($migrations as $sql) {
    try { db()->exec($sql); } catch (\Throwable $e) {}
}

// ── Argumenty ─────────────────────────────────────────────────────────────────

$arg = $argv[1] ?? '';

// ── STATUS ────────────────────────────────────────────────────────────────────
if ($arg === '--status') {
    $u = db_one("SELECT * FROM users WHERE email = ?", [EMERGENCY_EMAIL]);
    echo PHP_EOL;
    if (!$u) {
        echo c('! Konto awaryjne nie istnieje.', 'yellow') . PHP_EOL;
        echo c('  Utwórz je: php cli/emergency.php', 'gray') . PHP_EOL;
    } else {
        sep();
        echo c('Konto awaryjne — stan:', 'bold') . PHP_EOL;
        sep();
        printf("  %-18s %s\n", 'Login (e-mail):',  c($u['email'], 'cyan'));
        printf("  %-18s %s\n", 'Nazwa:',            $u['name']);
        printf("  %-18s %s\n", 'Rola:',             c($u['role'], $u['role'] === 'admin' ? 'green' : 'yellow'));
        printf("  %-18s %s\n", 'Aktywne:',          $u['is_active'] ? c('TAK', 'green') : c('NIE', 'red'));
        printf("  %-18s %s\n", 'CPC:',              $u['cpc_code'] ?? c('brak', 'red'));
        $lock = $u['locked_until'] ?? null;
        $lockstr = ($lock && strtotime($lock) > time()) ? c('ZABLOKOWANE do ' . $lock, 'red') : c('brak', 'gray');
        printf("  %-18s %s\n", 'Blokada:',          $lockstr);
        sep();
    }
    echo PHP_EOL;
    exit(0);
}

// ── REMOVE ────────────────────────────────────────────────────────────────────
if ($arg === '--remove') {
    $u = db_one("SELECT id, name FROM users WHERE email = ?", [EMERGENCY_EMAIL]);
    if (!$u) {
        echo c('! Konto awaryjne nie istnieje — nic do usunięcia.', 'yellow') . PHP_EOL;
        exit(0);
    }
    db()->prepare("DELETE FROM users WHERE email = ?")->execute([EMERGENCY_EMAIL]);
    try { db()->prepare("DELETE FROM login_attempts WHERE identifier = ?")->execute([EMERGENCY_EMAIL]); } catch (\Throwable $e) {}
    echo c('✔ Konto awaryjne zostało usunięte.', 'green') . PHP_EOL;
    exit(0);
}

// ── CREATE / RESET ────────────────────────────────────────────────────────────

$hash = password_hash(EMERGENCY_PASS, PASSWORD_BCRYPT);
$cpc  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$now  = date('Y-m-d H:i:s');

$existing = db_one("SELECT id FROM users WHERE email = ?", [EMERGENCY_EMAIL]);

if ($existing) {
    // Reset istniejącego konta
    db()->prepare(
        "UPDATE users
         SET name=?, password=?, role='admin', is_active=1,
             must_change_password=0, locked_until=NULL,
             cpc_code=?, cpc_fails=0, cpc_blocked_until=NULL,
             totp_secret=NULL, totp_confirmed=0, twofa_method='', totp_backup_codes=NULL, twofa_phone=NULL
         WHERE email=?"
    )->execute([EMERGENCY_NAME, $hash, $cpc, EMERGENCY_EMAIL]);
    $action = 'ZRESETOWANE';
} else {
    // Nowe konto
    db()->prepare(
        "INSERT INTO users
             (name, email, password, role, is_active,
              must_change_password, locked_until,
              cpc_code, cpc_fails, cpc_blocked_until,
              twofa_method, created_at)
         VALUES (?,?,?,'admin',1, 0,NULL, ?,0,NULL, ?,?)"
    )->execute([EMERGENCY_NAME, EMERGENCY_EMAIL, $hash, $cpc, '', $now]);
    $action = 'UTWORZONE';
}

// Wyczyść próby logowania
try { db()->prepare("DELETE FROM login_attempts WHERE identifier = ?")->execute([EMERGENCY_EMAIL]); } catch (\Throwable $e) {}

// ── Wyświetl dane ─────────────────────────────────────────────────────────────

echo PHP_EOL;
sep();
echo c("  KONTO AWARYJNE — {$action}", 'bold') . PHP_EOL;
sep();
printf("  %-18s %s\n", 'Login:',   c(EMERGENCY_EMAIL, 'cyan'));
printf("  %-18s %s\n", 'Hasło:',   c(EMERGENCY_PASS,  'cyan'));
printf("  %-18s %s\n", 'Rola:',    c('admin', 'green'));
printf("  %-18s %s\n", 'Kod CPC:', c($cpc, 'magenta'));
sep();
echo c('  ! Zapamiętaj kod CPC — nie będzie wyświetlony ponownie.', 'yellow') . PHP_EOL;
echo c('  ! Po odzyskaniu dostępu usuń konto: php cli/emergency.php --remove', 'yellow') . PHP_EOL;
sep();
echo PHP_EOL;
