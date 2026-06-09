#!/usr/bin/env php
<?php
/**
 * cli/delete_user.php — Trwałe usuwanie konta użytkownika
 *
 * Użycie:
 *   php cli/delete_user.php --list
 *   php cli/delete_user.php --id=42
 *   php cli/delete_user.php --email=jan@feer.org.pl
 *   php cli/delete_user.php --id=42 --force
 *   php cli/delete_user.php --id=42 --reason="Konto testowe"
 *
 * Opcje:
 *   --id=N        Usuń użytkownika o podanym ID
 *   --email=ADDR  Usuń użytkownika o podanym adresie e-mail
 *   --force       Pomiń pytanie o potwierdzenie
 *   --reason=TEXT Opcjonalny powód usunięcia (zapisany w logu)
 *   --list        Pokaż listę wszystkich użytkowników i zakończ
 *   --dry-run     Pokaż co zostanie usunięte bez wykonywania
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit("Tylko CLI.\n");
}

define('APP_CLI', true);
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/approval.php';
require_once $root . '/includes/user_delete.php';

// ── ANSI helpers ───────────────────────────────────────────────────────────────
$NO_COLOR = !stream_isatty(STDIN) || getenv('NO_COLOR');
function clr(string $code, string $text): string {
    global $NO_COLOR;
    return $NO_COLOR ? $text : "\033[{$code}m{$text}\033[0m";
}
function ok(string $s):    string { return clr('32', "✓ $s"); }
function err(string $s):   string { return clr('31', "✗ $s"); }
function warn(string $s):  string { return clr('33', "⚠ $s"); }
function info(string $s):  string { return clr('36', "ℹ $s"); }
function bold(string $s):  string { return clr('1',  $s); }

// ── Opcje CLI ──────────────────────────────────────────────────────────────────
$opts = getopt('', ['id:', 'email:', 'force', 'reason:', 'list', 'dry-run', 'help']);

if (isset($opts['help'])) {
    echo <<<HELP

Trwałe usuwanie konta użytkownika z systemu FEER

Użycie:
  php cli/delete_user.php --list
  php cli/delete_user.php --id=42
  php cli/delete_user.php --email=jan@feer.org.pl
  php cli/delete_user.php --id=42 --force
  php cli/delete_user.php --id=42 --reason="Konto testowe"
  php cli/delete_user.php --id=42 --dry-run

Opcje:
  --id=N        Usuń użytkownika o podanym ID
  --email=ADDR  Usuń po adresie e-mail
  --force       Pomiń pytanie o potwierdzenie
  --reason=TEXT Powód (zapisywany w logu audytowym)
  --list        Pokaż listę użytkowników i zakończ
  --dry-run     Pokaż co zostanie usunięte, ale nie usuwaj
  --help        Ta pomoc

Zabezpieczenia:
  • Nie można usunąć konta serwis@local
  • Nie można usunąć ostatniego aktywnego administratora
  • Przed usunięciem wyświetlane jest podsumowanie powiązanych danych

HELP;
    exit(0);
}

// ── --list ─────────────────────────────────────────────────────────────────────
if (isset($opts['list'])) {
    list_users_table();
    exit(0);
}

// ── Wymagaj --id lub --email ───────────────────────────────────────────────────
$target_id    = isset($opts['id'])    ? (int)$opts['id']       : null;
$target_email = isset($opts['email']) ? trim($opts['email'])   : null;

if (!$target_id && !$target_email) {
    echo err('Podaj --id=N lub --email=ADRES. Użyj --list żeby wyświetlić użytkowników.') . "\n";
    echo "  php cli/delete_user.php --help\n";
    exit(1);
}

// ── Znajdź użytkownika ─────────────────────────────────────────────────────────
if ($target_id) {
    $user = db_one("SELECT * FROM users WHERE id = ?", [$target_id]);
} else {
    $user = db_one("SELECT * FROM users WHERE email = ?", [$target_email]);
}

if (!$user) {
    echo err('Nie znaleziono użytkownika: ' . ($target_id ? "#$target_id" : $target_email)) . "\n";
    exit(1);
}

$uid = (int)$user['id'];

// ── Preflight ──────────────────────────────────────────────────────────────────
$check = user_delete_preflight($uid, 0);
if (!$check['ok']) {
    echo err($check['msg']) . "\n";
    exit(1);
}

// ── Wyświetl informacje o użytkowniku ─────────────────────────────────────────
echo "\n";
echo bold("Użytkownik do usunięcia:") . "\n";
echo "  ID    : " . bold((string)$uid) . "\n";
echo "  Nazwa : " . bold($user['name']) . "\n";
echo "  Email : " . bold($user['email']) . "\n";
echo "  Rola  : " . $user['role'] . "\n";
echo "  Status: " . ($user['is_active'] ? clr('32', 'aktywny') : clr('33', 'nieaktywny')) . "\n";
if (!empty($user['microsoft_id'])) {
    echo "  M365  : " . clr('34', $user['microsoft_id']) . "\n";
    echo warn("  Konto Azure AD NIE zostanie usunięte — tylko rekord lokalny.") . "\n";
}

// ── Analiza powiązanych danych ─────────────────────────────────────────────────
echo "\n";
$impact = user_delete_impact($uid);

if ($impact['contracts']) {
    echo clr('1;31', "⛔ AKTYWNE UMOWY — rozważ dezaktywację zamiast usunięcia:") . "\n";
    foreach ($impact['contracts'] as $tbl => $cnt) {
        echo "  • umowy_$tbl: $cnt aktywnych\n";
    }
    echo "\n";
}

if ($impact['cascade']) {
    echo warn("Powiązane dane które ZOSTANĄ USUNIĘTE razem z kontem (CASCADE):") . "\n";
    foreach ($impact['cascade'] as $label => $cnt) {
        echo "  - $label: $cnt rekordów\n";
    }
    echo "\n";
}

if ($impact['set_null']) {
    echo info("Powiązane dane które ZOSTANĄ (user_id = NULL, dane nie są usuwane):") . "\n";
    foreach ($impact['set_null'] as $label => $cnt) {
        echo "  ○ $label: $cnt rekordów\n";
    }
    echo "\n";
}

if (!$impact['cascade'] && !$impact['set_null'] && !$impact['contracts']) {
    echo ok("Brak powiązanych danych — usunięcie jest bezpieczne.") . "\n\n";
}

$reason = $opts['reason'] ?? '';

// ── --dry-run ──────────────────────────────────────────────────────────────────
if (isset($opts['dry-run'])) {
    echo info("Tryb dry-run — żadne zmiany nie zostały wprowadzone.") . "\n\n";
    exit(0);
}

// ── Potwierdzenie ──────────────────────────────────────────────────────────────
if (!isset($opts['force'])) {
    echo clr('1;31', "UWAGA: Operacja jest NIEODWRACALNA!") . "\n";
    echo "Wpisz adres e-mail użytkownika aby potwierdzić: ";
    $input = trim(fgets(STDIN));
    if ($input !== $user['email']) {
        echo err("E-mail niezgodny — anulowano.") . "\n";
        exit(1);
    }
} else {
    echo warn("Tryb --force: pomijam potwierdzenie.") . "\n";
}

// ── Wykonaj usunięcie ──────────────────────────────────────────────────────────
$result = user_delete_execute($uid, 0, $reason);

if ($result['ok']) {
    echo "\n" . ok($result['msg']) . "\n\n";
    exit(0);
} else {
    echo "\n" . err($result['msg']) . "\n";
    exit(1);
}

// ── Helper: tabela użytkowników ───────────────────────────────────────────────
function list_users_table(): void
{
    $users = db_all(
        "SELECT id, name, email, role, is_active, microsoft_id, created_at
         FROM users
         WHERE email != 'serwis@local'
         ORDER BY role, name"
    );
    if (!$users) {
        echo "Brak użytkowników.\n";
        return;
    }
    echo "\n";
    printf("  %-5s  %-28s  %-32s  %-10s  %-6s  %s\n",
        'ID', 'Nazwa', 'Email', 'Rola', 'Aktywny', 'M365');
    echo '  ' . str_repeat('─', 100) . "\n";
    foreach ($users as $u) {
        printf("  %-5s  %-28s  %-32s  %-10s  %-6s  %s\n",
            $u['id'],
            mb_strimwidth($u['name']  ?? '', 0, 27, '…'),
            mb_strimwidth($u['email'] ?? '', 0, 31, '…'),
            $u['role'] ?? '',
            $u['is_active'] ? 'tak' : '—',
            $u['microsoft_id'] ? '●' : ''
        );
    }
    echo "\n  Łącznie: " . count($users) . " użytkowników\n\n";
}
