#!/usr/bin/env php
<?php
/**
 * cli/assign_ika.php — Nadaj / wyświetl kody IKA użytkownikom CRM-only przez CLI.
 *
 * Użycie:
 *   php cli/assign_ika.php                     # lista crm_only bez IKA
 *   php cli/assign_ika.php --list              # lista WSZYSTKICH crm_only + ich kody
 *   php cli/assign_ika.php --user=5            # nadaj losowy kod użytkownikowi ID=5
 *   php cli/assign_ika.php --user=jan@org.pl   # nadaj losowy kod (email)
 *   php cli/assign_ika.php --user=5 --code=123456   # nadaj konkretny kod
 *   php cli/assign_ika.php --all               # nadaj kody WSZYSTKIM bez IKA
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Tylko CLI.\n");
}

define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/cpc.php';

// ── Parse args ────────────────────────────────────────────────────────────────
$opts = getopt('', ['list', 'show', 'user:', 'code:', 'all', 'help']);

if (isset($opts['help'])) {
    echo <<<HELP
Zarządzaj kodami IKA użytkowników CRM-only

Użycie:
  php cli/assign_ika.php                     Pokaż crm_only BEZ kodu IKA
  php cli/assign_ika.php --list              Pokaż wszystkich crm_only (bez kodów)
  php cli/assign_ika.php --show              Pokaż wszystkich crm_only Z kodami IKA
  php cli/assign_ika.php --user=ID|EMAIL     Nadaj losowy kod
  php cli/assign_ika.php --user=ID --code=XXXXXX  Nadaj konkretny kod (6 cyfr)
  php cli/assign_ika.php --all               Nadaj kody wszystkim bez IKA

HELP;
    exit(0);
}

// ── Pobierz listę crm_only ────────────────────────────────────────────────────
function get_crm_only_users(bool $all = false): array {
    $where = $all ? '' : "AND (u.cpc_code IS NULL OR u.cpc_code = '')";
    return db_all(
        "SELECT u.id, u.name, u.first_name, u.last_name, u.email, u.role, u.cpc_code
         FROM users u
         LEFT JOIN roles r ON r.name = u.role
         WHERE u.is_active = 1
           AND (u.role = 'crm_user' OR r.crm_only = 1)
           $where
         ORDER BY u.name"
    );
}

function display_name(array $u): string {
    $n = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    return $n ?: ($u['name'] ?? '?');
}

function assign_ika(int $uid, string $code): void {
    db()->prepare(
        "UPDATE users SET cpc_code=?, cpc_fails=0, cpc_blocked_until=NULL WHERE id=?"
    )->execute([$code, $uid]);
}

// ── --show : lista z kodami ───────────────────────────────────────────────────
if (isset($opts['show'])) {
    $users = get_crm_only_users(true);
    if (!$users) {
        echo "Brak użytkowników CRM-only.\n";
        exit(0);
    }
    echo "\033[1;33mUWAGA: poniżej widoczne są kody IKA w jawnej postaci.\033[0m\n\n";
    printf("%-5s %-25s %-35s %-10s %s\n", 'ID', 'Imię i nazwisko', 'E-mail', 'Rola', 'Kod IKA');
    echo str_repeat('-', 95) . "\n";
    foreach ($users as $u) {
        $code = !empty($u['cpc_code']) ? "\033[1;32m" . $u['cpc_code'] . "\033[0m" : "\033[1;31mBRAK\033[0m";
        printf("%-5d %-25s %-35s %-10s %s\n",
            $u['id'],
            mb_substr(display_name($u), 0, 24),
            mb_substr($u['email'] ?? '', 0, 34),
            $u['role'],
            $code
        );
    }
    exit(0);
}

// ── --list : lista bez kodów ──────────────────────────────────────────────────
if (isset($opts['list'])) {
    $users = get_crm_only_users(true);
    if (!$users) {
        echo "Brak użytkowników CRM-only.\n";
        exit(0);
    }
    printf("%-5s %-30s %-35s %-10s %s\n", 'ID', 'Imię i nazwisko', 'E-mail', 'Rola', 'IKA');
    echo str_repeat('-', 100) . "\n";
    foreach ($users as $u) {
        $has_ika = !empty($u['cpc_code']) ? '✓ ustawiony' : '✗ BRAK';
        printf("%-5d %-30s %-35s %-10s %s\n",
            $u['id'],
            mb_substr(display_name($u), 0, 29),
            mb_substr($u['email'] ?? '', 0, 34),
            $u['role'],
            $has_ika
        );
    }
    exit(0);
}

// ── --all ─────────────────────────────────────────────────────────────────────
if (isset($opts['all'])) {
    $users = get_crm_only_users(false);
    if (!$users) {
        echo "Wszyscy użytkownicy CRM-only mają już ustawione kody IKA.\n";
        exit(0);
    }
    echo "Nadaję kody IKA dla " . count($users) . " użytkowników:\n\n";
    foreach ($users as $u) {
        $code = cpc_generate();
        assign_ika((int)$u['id'], $code);
        printf("  ID %-4d  %-30s  %-35s  KOD: %s\n",
            $u['id'], display_name($u), $u['email'] ?? '', $code
        );
    }
    echo "\nGotowe. Przekaż kody użytkownikom bezpiecznym kanałem.\n";
    exit(0);
}

// ── --user ────────────────────────────────────────────────────────────────────
if (isset($opts['user'])) {
    $ident = trim($opts['user']);
    // Szukaj po ID lub emailu
    if (ctype_digit($ident)) {
        $u = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [(int)$ident]);
    } else {
        $u = db_one("SELECT * FROM users WHERE email=? AND is_active=1", [$ident]);
    }

    if (!$u) {
        echo "Błąd: nie znaleziono użytkownika '{$ident}'.\n";
        exit(1);
    }

    // Walidacja kodu jeśli podany
    if (isset($opts['code'])) {
        $code = trim($opts['code']);
        if (!preg_match('/^\d{6}$/', $code)) {
            echo "Błąd: kod IKA musi składać się z dokładnie 6 cyfr.\n";
            exit(1);
        }
    } else {
        $code = cpc_generate();
    }

    assign_ika((int)$u['id'], $code);

    $name  = display_name($u);
    $email = $u['email'] ?? '';
    echo "\n";
    echo "✓ Kod IKA nadany\n";
    echo "  Użytkownik : {$name} ({$email})\n";
    echo "  ID         : {$u['id']}\n";
    echo "  Rola       : {$u['role']}\n";
    echo "  Kod IKA    : \033[1;32m{$code}\033[0m\n";
    echo "\nPrzekaż kod użytkownikowi bezpiecznym kanałem (nie e-mail).\n\n";
    exit(0);
}

// ── Domyślnie: pokaż bez IKA ──────────────────────────────────────────────────
$users = get_crm_only_users(false);
if (!$users) {
    echo "✓ Wszyscy użytkownicy CRM-only mają ustawione kody IKA.\n";
    exit(0);
}

echo "Użytkownicy CRM-only BEZ kodu IKA (" . count($users) . "):\n\n";
printf("%-5s %-30s %-35s %s\n", 'ID', 'Imię i nazwisko', 'E-mail', 'Rola');
echo str_repeat('-', 85) . "\n";
foreach ($users as $u) {
    printf("%-5d %-30s %-35s %s\n",
        $u['id'],
        mb_substr(display_name($u), 0, 29),
        mb_substr($u['email'] ?? '', 0, 34),
        $u['role']
    );
}
echo "\nNadaj kody:\n";
echo "  Wszystkim naraz : php cli/assign_ika.php --all\n";
echo "  Jednemu         : php cli/assign_ika.php --user=ID\n";
