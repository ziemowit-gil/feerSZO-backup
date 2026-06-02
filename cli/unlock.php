#!/usr/bin/env php
<?php
/**
 * cli/unlock.php — zdejmuje blokadę brute-force z konta użytkownika.
 *
 * Użycie:
 *   php cli/unlock.php                      # lista wszystkich zablokowanych
 *   php cli/unlock.php serwis@local         # odblokuj konkretne konto
 *   php cli/unlock.php --all                # odblokuj wszystkie
 *   php cli/unlock.php --ip 1.2.3.4         # wyczyść blokadę IP
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit('Tylko CLI.');
}

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// ── Inicjuj tabele jeśli nie istnieją ────────────────────────────────────────
try { db()->exec("CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, identifier TEXT NOT NULL,
    ip TEXT NOT NULL DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch(\Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN locked_until DATETIME DEFAULT NULL"); } catch(\Throwable $e) {}

// ── Helpers ───────────────────────────────────────────────────────────────────
function now_fmt(string $dt): string {
    $t = strtotime($dt);
    if (!$t) return $dt;
    $rem = $t - time();
    if ($rem <= 0) return 'już odblokowane (wygasło)';
    $min = (int)ceil($rem / 60);
    return date('H:i:s', $t) . " (jeszcze ~{$min} min)";
}

function unlock_email(string $email): void {
    $u = db_one("SELECT id, name, locked_until FROM users WHERE email=?", [$email]);
    if (!$u) { echo "  ✗ Nie znaleziono użytkownika: {$email}\n"; return; }

    db()->prepare("UPDATE users SET locked_until=NULL WHERE email=?")->execute([$email]);
    db()->prepare("DELETE FROM login_attempts WHERE identifier=?")->execute([$email]);
    echo "  ✓ Odblokowano: {$u['name']} <{$email}>\n";
}

function unlock_ip(string $ip): void {
    $cnt = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier=? OR ip=?");
    $cnt->execute([$ip, $ip]);
    $n = (int)$cnt->fetchColumn();
    db()->prepare("DELETE FROM login_attempts WHERE identifier=? OR ip=?")->execute([$ip, $ip]);
    echo "  ✓ Usunięto {$n} prób logowania dla IP: {$ip}\n";
}

// ── Argumenty ─────────────────────────────────────────────────────────────────
$args = array_slice($argv, 1);

// Brak argumentów → pokaż zablokowanych
if (empty($args)) {
    $locked = db_all(
        "SELECT id, name, email, locked_until FROM users
         WHERE locked_until IS NOT NULL AND locked_until > datetime('now')
         ORDER BY locked_until"
    );
    $ip_blocks = db_all(
        "SELECT identifier AS ip, COUNT(*) AS cnt, MAX(created_at) AS last_at
         FROM login_attempts
         WHERE created_at > datetime('now', '-900 seconds')
           AND identifier NOT LIKE '%@%'
         GROUP BY identifier
         HAVING cnt >= 5
         ORDER BY cnt DESC"
    );
    $email_attempts = db_all(
        "SELECT identifier AS email, COUNT(*) AS cnt, MAX(created_at) AS last_at
         FROM login_attempts
         WHERE created_at > datetime('now', '-900 seconds')
           AND identifier LIKE '%@%'
         GROUP BY identifier
         ORDER BY cnt DESC
         LIMIT 20"
    );

    echo "\n";
    if ($locked) {
        echo "=== Zablokowane konta (locked_until) ===\n";
        foreach ($locked as $u) {
            printf("  %-30s  %-25s  do: %s\n",
                $u['name'], $u['email'], now_fmt($u['locked_until']));
        }
    } else {
        echo "=== Brak kont zablokowanych przez locked_until ===\n";
    }

    if ($ip_blocks) {
        echo "\n=== Zablokowane IP (≥5 prób / 15 min) ===\n";
        foreach ($ip_blocks as $r) {
            printf("  %-20s  prób: %d  ostatnia: %s\n", $r['ip'], $r['cnt'], $r['last_at']);
        }
    }

    if ($email_attempts) {
        echo "\n=== Próby logowania (e-mail, ostatnie 15 min) ===\n";
        foreach ($email_attempts as $r) {
            printf("  %-35s  prób: %d\n", $r['email'], $r['cnt']);
        }
    }

    echo "\nUżycie:\n";
    echo "  php cli/unlock.php email@domena.pl    — odblokuj konto\n";
    echo "  php cli/unlock.php --all              — odblokuj wszystkie\n";
    echo "  php cli/unlock.php --ip 1.2.3.4       — wyczyść blokadę IP\n\n";
    exit(0);
}

// --all
if (in_array('--all', $args)) {
    $locked = db_all(
        "SELECT email FROM users WHERE locked_until IS NOT NULL AND locked_until > datetime('now')"
    );
    if (!$locked) { echo "Brak zablokowanych kont.\n"; }
    foreach ($locked as $u) unlock_email($u['email']);
    // Wyczyść też wszystkie próby
    $del = db()->exec("DELETE FROM login_attempts");
    echo "  ✓ Wyczyszczono login_attempts ({$del} wpisów)\n";
    exit(0);
}

// --ip 1.2.3.4
if (in_array('--ip', $args)) {
    $idx = array_search('--ip', $args);
    $ip  = $args[$idx + 1] ?? '';
    if (!$ip) { echo "Podaj adres IP: php cli/unlock.php --ip 1.2.3.4\n"; exit(1); }
    unlock_ip($ip);
    exit(0);
}

// email jako argument
foreach ($args as $arg) {
    if (str_contains($arg, '@') || str_contains($arg, '--') === false) {
        unlock_email($arg);
    }
}

echo "\n";
