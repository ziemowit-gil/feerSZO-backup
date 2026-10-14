#!/usr/bin/env php
<?php
/**
 * cli/ykReset.php — reset kluczy sprzętowych (YubiKey, WebAuthn) konta, np. po zgubieniu klucza.
 *
 * Po resecie konto nie ma żadnego klucza, więc przy następnym wejściu do panelu dydaktyka (kierownik, zastępca,
 * admin — karty30/ti/dydaktyk/yk_gate.php) zarejestruje nowy klucz. Uwaga: te same klucze (tabela
 * webauthn_credentials) służą w SZO — bramce logowania (webauthn_required) i zatwierdzaniu protokołów TI.
 *
 * Użycie:
 *   php cli/ykReset.php                       # konta z kluczami (ile, ostatnie użycie)
 *   php cli/ykReset.php list <email>          # klucze konta z numerami
 *   php cli/ykReset.php reset <email>         # usuń WSZYSTKIE klucze konta (pyta o potwierdzenie)
 *   php cli/ykReset.php reset <email> --id=N  # usuń jeden klucz (numer z „list”)
 *   … --yes                                   # bez pytania o potwierdzenie
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit('Tylko CLI.');
}

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/webauthn.php';
require_once dirname(__DIR__) . '/includes/approval.php'; // log_user_action()

webauthn_migrate();

$args  = array_slice($argv, 1);
$yes   = in_array('--yes', $args, true);
$keyId = 0;
foreach ($args as $a) if (preg_match('/^--id=(\d+)$/', $a, $m)) $keyId = (int)$m[1];
$pos   = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$cmd   = $pos[0] ?? '';
$email = $pos[1] ?? '';

function ykUser(string $email): array {
    $u = $email !== '' ? db_one("SELECT id, name, email, role FROM users WHERE LOWER(email) = LOWER(?)", [$email]) : null;
    if (!$u) { fwrite(STDERR, "Nie znaleziono konta: {$email}\n"); exit(1); }
    return $u;
}

function ykPrintKeys(array $u): array {
    $keys = webauthn_get_credentials((int)$u['id']);
    echo "{$u['name']} <{$u['email']}> (id {$u['id']}, rola {$u['role']}) — kluczy: " . count($keys) . PHP_EOL;
    foreach ($keys as $k) {
        printf("  #%-5d %-28s dodany %s, ostatnio użyty %s\n",
            $k['id'], (string)$k['name'], (string)$k['created_at'], (string)($k['last_used_at'] ?: '—'));
    }
    return $keys;
}

if ($cmd === '') {
    $rows = db_all(
        "SELECT u.name, u.email, COUNT(*) AS n, MAX(w.last_used_at) AS last_used
         FROM webauthn_credentials w JOIN users u ON u.id = w.user_id
         GROUP BY u.id ORDER BY u.name"
    );
    if (!$rows) { echo "Żadne konto nie ma zarejestrowanego klucza.\n"; exit(0); }
    foreach ($rows as $r) printf("%-32s %-36s kluczy: %d, ostatnio: %s\n", $r['name'], $r['email'], $r['n'], $r['last_used'] ?: '—');
    exit(0);
}

if ($cmd === 'list') {
    ykPrintKeys(ykUser($email));
    exit(0);
}

if ($cmd === 'reset') {
    $u    = ykUser($email);
    $keys = ykPrintKeys($u);
    if (!$keys) { echo "Nic do usunięcia.\n"; exit(0); }
    if ($keyId && !in_array($keyId, array_map(fn($k) => (int)$k['id'], $keys), true)) {
        fwrite(STDERR, "Klucz #{$keyId} nie należy do tego konta.\n"); exit(1);
    }
    $what = $keyId ? "klucz #{$keyId}" : 'WSZYSTKIE klucze (' . count($keys) . ')';
    if (!$yes) {
        echo "Usunąć {$what} konta {$u['email']}? [t/N] ";
        if (!in_array(strtolower(trim((string)fgets(STDIN))), ['t', 'tak', 'y', 'yes'], true)) { echo "Przerwano.\n"; exit(1); }
    }
    if ($keyId) {
        webauthn_delete_credential($keyId, (int)$u['id']);
    } else {
        db()->prepare("DELETE FROM webauthn_credentials WHERE user_id = ?")->execute([(int)$u['id']]);
    }
    if (function_exists('log_user_action')) {
        log_user_action((int)$u['id'], (int)$u['id'], 'webauthn_reset', "CLI: usunięto {$what} (cli/ykReset.php)");
    }
    echo "Usunięto {$what}. " . (count($keys) > 1 && $keyId
        ? 'Pozostałe klucze konta działają dalej.'
        : 'Przy następnym logowaniu do panelu dydaktyka konto zarejestruje nowy klucz.') . PHP_EOL;
    exit(0);
}

fwrite(STDERR, "Nieznane polecenie: {$cmd}\nUżycie: php cli/ykReset.php [list <email> | reset <email> [--id=N] [--yes]]\n");
exit(1);
