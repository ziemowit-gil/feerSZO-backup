#!/usr/bin/env php
<?php
/**
 * cli/gen_admin_code.php — Generowanie jednorazowego AdminCode (tokenu konfiguracyjnego IKA).
 *
 * AdminCode to 10-znakowy hex token (np. A3F9B2C1D4) który użytkownik wpisuje
 * w bramce IKA (ika_gate.php), żeby samodzielnie ustawić swój kod IKA i IKAKS.
 * Token jest jednorazowy i wygasa po 48 h (lub podanym czasie).
 *
 * Użycie:
 *   php cli/gen_admin_code.php                        # lista użytkowników z aktywnym tokenem
 *   php cli/gen_admin_code.php --user=ID|email        # wygeneruj token (ważny 48 h)
 *   php cli/gen_admin_code.php --user=ID --hours=72   # ważny N godzin
 *   php cli/gen_admin_code.php --user=ID --revoke     # unieważnij aktywny token
 *   php cli/gen_admin_code.php --list                 # lista wszystkich użytkowników
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); die("Tylko CLI.\n"); }

define('APP_INSTALLED', true);

// Minimalne środowisko CLI
if (!isset($_SERVER['HTTP_HOST']))     $_SERVER['HTTP_HOST']     = 'localhost';
if (!isset($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
if (!isset($_SERVER['HTTPS']))         $_SERVER['HTTPS']         = 'off';

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/cpc.php';

// ── Kolory ─────────────────────────────────────────────────────────────────────
$tty = stream_isatty(STDOUT);
function clr(string $t, string $c): string {
    global $tty;
    if (!$tty) return $t;
    $m = ['red'=>31,'green'=>32,'yellow'=>33,'cyan'=>36,'bold'=>1,'dim'=>2];
    return "\033[{$m[$c]}m{$t}\033[0m";
}

// ── Argumenty ──────────────────────────────────────────────────────────────────
$opts = getopt('', ['user:', 'hours:', 'revoke', 'list', 'help']);

if (isset($opts['help'])) {
    echo <<<HELP

  gen_admin_code.php — jednorazowy AdminCode do self-service ustawiania IKA

  Użycie:
    php cli/gen_admin_code.php                      Lista użytkowników z aktywnym tokenem
    php cli/gen_admin_code.php --list               Lista wszystkich kont
    php cli/gen_admin_code.php --user=ID|email      Wygeneruj token (48 h)
    php cli/gen_admin_code.php --user=ID --hours=N  Wygeneruj token ważny N godzin
    php cli/gen_admin_code.php --user=ID --revoke   Unieważnij aktywny token

  Format tokenu: 10 znaków HEX (np. A3F9B2C1D4) — łatwy do przepisania lub podyktowania.

HELP;
    exit(0);
}

// ── Helper: znajdź użytkownika ─────────────────────────────────────────────────
function find_user(string $ident): ?array {
    if (ctype_digit($ident)) {
        return db_one("SELECT id, name, email, role, ika_setup_token, ika_setup_token_expires
                       FROM users WHERE id = ? AND is_active = 1", [(int)$ident]) ?: null;
    }
    return db_one("SELECT id, name, email, role, ika_setup_token, ika_setup_token_expires
                   FROM users WHERE email = ? AND is_active = 1", [$ident]) ?: null;
}

// ── Helper: status tokenu ─────────────────────────────────────────────────────
function token_status(array $u): string {
    if (empty($u['ika_setup_token'])) return clr('brak', 'dim');
    $exp = $u['ika_setup_token_expires'] ?? '';
    if ($exp && $exp < date('Y-m-d H:i:s')) return clr('wygasł', 'yellow');
    $left = $exp ? round((strtotime($exp) - time()) / 3600, 1) : '?';
    return clr('aktywny', 'green') . clr(" (wygasa za {$left} h)", 'dim');
}

// ── --list ─────────────────────────────────────────────────────────────────────
if (isset($opts['list'])) {
    $users = db_all("SELECT id, name, email, role, ika_setup_token, ika_setup_token_expires
                     FROM users WHERE is_active = 1 ORDER BY name");
    if (!$users) { echo "Brak użytkowników.\n"; exit(0); }
    echo "\n";
    printf("  %-5s  %-28s  %-32s  %-12s  %s\n", 'ID', 'Imię i nazwisko', 'E-mail', 'Rola', 'AdminCode');
    echo '  ' . str_repeat('─', 100) . "\n";
    foreach ($users as $u) {
        printf("  %-5d  %-28s  %-32s  %-12s  %s\n",
            $u['id'],
            mb_strimwidth($u['name'] ?? '', 0, 27, '…'),
            mb_strimwidth($u['email'] ?? '', 0, 31, '…'),
            $u['role'],
            token_status($u)
        );
    }
    echo "\n";
    exit(0);
}

// ── Domyślnie (bez --user): pokaż użytkowników z aktywnym tokenem ─────────────
if (!isset($opts['user'])) {
    $users = db_all(
        "SELECT id, name, email, role, ika_setup_token, ika_setup_token_expires
         FROM users
         WHERE is_active = 1
           AND ika_setup_token IS NOT NULL
           AND ika_setup_token_expires > ?
         ORDER BY ika_setup_token_expires",
        [date('Y-m-d H:i:s')]
    );
    if (!$users) {
        echo clr("  Brak aktywnych tokenów AdminCode.\n", 'dim');
        echo "  Nadaj: php cli/gen_admin_code.php --user=ID|email\n\n";
        exit(0);
    }
    echo "\n";
    echo clr("  Aktywne tokeny AdminCode (" . count($users) . "):\n\n", 'bold');
    printf("  %-5s  %-28s  %-32s  %s\n", 'ID', 'Imię i nazwisko', 'E-mail', 'Wygasa');
    echo '  ' . str_repeat('─', 85) . "\n";
    foreach ($users as $u) {
        $exp  = $u['ika_setup_token_expires'];
        $left = round((strtotime($exp) - time()) / 3600, 1);
        printf("  %-5d  %-28s  %-32s  %s\n",
            $u['id'],
            mb_strimwidth($u['name'] ?? '', 0, 27, '…'),
            mb_strimwidth($u['email'] ?? '', 0, 31, '…'),
            clr(date('Y-m-d H:i', strtotime($exp)) . "  (za {$left} h)", 'cyan')
        );
    }
    echo "\n";
    exit(0);
}

// ── --user ─────────────────────────────────────────────────────────────────────
$ident = trim($opts['user']);
$u     = find_user($ident);

if (!$u) {
    echo clr("  ✖ Nie znaleziono użytkownika: {$ident}\n", 'red');
    echo "    Sprawdź listę: php cli/gen_admin_code.php --list\n\n";
    exit(1);
}

// ── --revoke ──────────────────────────────────────────────────────────────────
if (isset($opts['revoke'])) {
    db()->prepare("UPDATE users SET ika_setup_token=NULL, ika_setup_token_expires=NULL WHERE id=?")
        ->execute([$u['id']]);
    echo "\n";
    echo clr("  ✔ Token unieważniony\n", 'yellow');
    echo "    Użytkownik: {$u['name']} ({$u['email']})\n\n";
    exit(0);
}

// ── Generowanie tokenu ─────────────────────────────────────────────────────────
$hours = max(1, (int)($opts['hours'] ?? 48));
$token = cpc_setup_token_generate((int)$u['id'], $hours);
$exp   = date('Y-m-d H:i', time() + $hours * 3600);

echo "\n";
echo clr("  ┌─ AdminCode wygenerowany ──────────────────────────────────────────┐\n", 'green');
echo clr("  │                                                                   │\n", 'green');
printf(clr("  │   Kod:        %s%-10s%s%-39s│\n", 'green'),
    clr('', 'bold'), $token, clr('', 'dim'), '');
echo clr("  │                                                                   │\n", 'green');
printf(clr("  │   Użytkownik: %-51s│\n", 'green'), mb_strimwidth("{$u['name']} ({$u['email']})", 0, 50, '…'));
printf(clr("  │   Rola:       %-51s│\n", 'green'), $u['role']);
printf(clr("  │   Ważny do:   %-51s│\n", 'green'), "{$exp}  (za {$hours} h)");
echo clr("  │                                                                   │\n", 'green');
echo clr("  └───────────────────────────────────────────────────────────────────┘\n", 'green');
echo "\n";
echo clr("  Przekaż kod użytkownikowi bezpiecznym kanałem (nie e-mail).\n", 'yellow');
echo "  Użytkownik wpisuje go na stronie: /contracts/ika_gate.php\n";
echo "  Token jest jednorazowy — po użyciu traci ważność.\n\n";
