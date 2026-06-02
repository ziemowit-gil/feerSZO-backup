#!/usr/bin/env php
<?php
/**
 * cli/passwd.php — resetuje hasło konta lokalnego.
 *
 * Użycie:
 *   php cli/passwd.php                         # lista kont
 *   php cli/passwd.php serwis@local            # ustaw losowe hasło
 *   php cli/passwd.php serwis@local NoveHaslo1 # ustaw podane hasło
 *   php cli/passwd.php serwis@local --no-force # nie zmuszaj do zmiany przy logowaniu
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit('Tylko CLI.');
}

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// ── Helpers ───────────────────────────────────────────────────────────────────

function gen_password(int $len = 14): string {
    $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower   = 'abcdefghjkmnpqrstuvwxyz';
    $digits  = '23456789';
    $special = '!@#$%&*';
    $all     = $upper . $lower . $digits . $special;
    $pass    = $upper[random_int(0, strlen($upper)-1)]
             . $lower[random_int(0, strlen($lower)-1)]
             . $digits[random_int(0, strlen($digits)-1)]
             . $special[random_int(0, strlen($special)-1)];
    for ($i = 4; $i < $len; $i++) $pass .= $all[random_int(0, strlen($all)-1)];
    return str_shuffle($pass);
}

function validate_password(string $pass): ?string {
    if (strlen($pass) < 8) return 'Hasło musi mieć co najmniej 8 znaków.';
    return null;
}

function list_users(): void {
    $users = db_all("SELECT id, name, email, role, locked_until, must_change_password
                     FROM users ORDER BY name");
    if (!$users) { echo "Brak użytkowników w bazie.\n"; return; }
    echo "\n";
    printf("  %-4s  %-25s  %-30s  %-8s  %s\n", 'ID', 'Imię i nazwisko', 'Email', 'Rola', 'Flagi');
    echo '  ' . str_repeat('─', 85) . "\n";
    foreach ($users as $u) {
        $flags = [];
        if ($u['locked_until'] && strtotime($u['locked_until']) > time()) $flags[] = '🔒zablokowane';
        if ($u['must_change_password']) $flags[] = '⚠ zmiana hasła';
        printf("  %-4s  %-25s  %-30s  %-8s  %s\n",
            $u['id'], mb_strimwidth($u['name'], 0, 24, '…'),
            $u['email'], $u['role'], implode(', ', $flags));
    }
    echo "\n";
}

function reset_password(string $email, ?string $new_pass, bool $force_change): void {
    $u = db_one("SELECT id, name, email FROM users WHERE email = ?", [$email]);
    if (!$u) {
        echo "  ✗ Nie znaleziono użytkownika: {$email}\n";
        echo "    Sprawdź listę: php cli/passwd.php\n";
        exit(1);
    }

    $generated = false;
    if ($new_pass === null) {
        $new_pass  = gen_password();
        $generated = true;
    } else {
        $err = validate_password($new_pass);
        if ($err) { echo "  ✗ {$err}\n"; exit(1); }
    }

    $hash = password_hash($new_pass, PASSWORD_BCRYPT);

    db()->prepare(
        "UPDATE users SET password=?, must_change_password=?, locked_until=NULL WHERE id=?"
    )->execute([$hash, $force_change ? 1 : 0, $u['id']]);

    // Wyczyść też próby logowania
    db()->prepare("DELETE FROM login_attempts WHERE identifier = ?")->execute([$email]);

    echo "\n";
    echo "  ✓ Hasło zmienione dla: {$u['name']} <{$u['email']}>\n";
    echo "  ─────────────────────────────────────\n";
    echo "    Login:  {$u['email']}\n";
    echo "    Hasło:  {$new_pass}\n";
    if ($generated) echo "    (wygenerowane losowo)\n";
    echo "    Zmiana przy logowaniu: " . ($force_change ? 'TAK' : 'NIE') . "\n";
    echo "    Blokada: zdjęta\n";
    echo "\n";
}

// ── Argumenty ─────────────────────────────────────────────────────────────────
$args      = array_slice($argv, 1);
$no_force  = in_array('--no-force', $args);
$args      = array_values(array_filter($args, fn($a) => $a !== '--no-force'));

if (empty($args)) {
    list_users();
    echo "Użycie:\n";
    echo "  php cli/passwd.php email@domena.pl              — losowe hasło\n";
    echo "  php cli/passwd.php email@domena.pl NoweHaslo1   — podane hasło\n";
    echo "  php cli/passwd.php email@domena.pl --no-force   — bez przymusu zmiany\n\n";
    exit(0);
}

$email    = $args[0];
$new_pass = $args[1] ?? null;

reset_password($email, $new_pass, !$no_force);
