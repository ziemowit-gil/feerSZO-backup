<?php
/**
 * cli/ika.php — zarządzanie wymogiem IKA z linii poleceń (wyjście awaryjne).
 *
 * Powstało po to, żeby dało się odblokować dostęp bez wchodzenia do panelu —
 * a do panelu bez IKA się nie wejdzie, więc przez WWW był to zamknięty krąg.
 *
 * Cztery niezależne rzeczy potrafią zablokować wejście mimo PRAWIDŁOWEGO kodu:
 *   1. blokada po 3 błędnych próbach (users.cpc_blocked_until) — 15 min, w tym
 *      czasie poprawny kod też jest odrzucany,
 *   2. unieważnienie sesji przez admina (users.ika_revoked_at),
 *   3. wymuszenie IKA dla konta w CRM (users.crm_ika_required = 1),
 *   4. brak przypisanego kodu (users.cpc_code puste).
 * Polecenie `status` pokazuje wszystkie cztery naraz.
 *
 * Użycie:
 *   php cli/ika.php status [<id|e-mail>]   — stan globalny i kont
 *   php cli/ika.php off                    — wyłącz wymóg IKA globalnie
 *   php cli/ika.php on                     — włącz wymóg IKA globalnie
 *   php cli/ika.php unblock <id|e-mail>    — zdejmij blokadę po błędnych próbach
 *   php cli/ika.php exempt <id|e-mail>     — zwolnij konto z IKA w CRM
 *   php cli/ika.php enforce <id|e-mail>    — wymuś IKA dla konta w CRM
 *   php cli/ika.php auto <id|e-mail>       — przywróć zachowanie domyślne (wg roli)
 *
 * Kody wyjścia: 0 = OK, 1 = błąd użycia / nie znaleziono, 2 = błąd krytyczny.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);
if (!file_exists($base . '/config.php')) {
    fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n");
    exit(2);
}

define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/cpc.php';

try { cpc_migrate(); } catch (\Throwable $e) { /* kolumny mogą już istnieć */ }

/** Znajduje użytkownika po id albo adresie e-mail. */
function ika_cli_user(string $needle): ?array
{
    $needle = trim($needle);
    if ($needle === '') return null;
    if (ctype_digit($needle)) {
        return db_one("SELECT * FROM users WHERE id = ?", [(int)$needle]);
    }
    return db_one("SELECT * FROM users WHERE LOWER(email) = LOWER(?)", [$needle]);
}

/** Jedna linia stanu konta. */
function ika_cli_row(array $u): string
{
    $flags = [];

    $blocked = (string)($u['cpc_blocked_until'] ?? '');
    if ($blocked !== '' && strtotime($blocked) > time()) {
        $mins = (int)ceil((strtotime($blocked) - time()) / 60);
        $flags[] = 'ZABLOKOWANY jeszcze ' . $mins . ' min (błędne próby: ' . (int)($u['cpc_fails'] ?? 0) . ')';
    } elseif ((int)($u['cpc_fails'] ?? 0) > 0) {
        $flags[] = 'błędne próby: ' . (int)$u['cpc_fails'];
    }

    if (trim((string)($u['cpc_code'] ?? '')) === '') $flags[] = 'BRAK KODU IKA';

    $ov = $u['crm_ika_required'] ?? null;
    if ($ov !== null && $ov !== '') {
        $flags[] = (int)$ov === 0 ? 'zwolniony z IKA w CRM' : 'IKA wymuszone w CRM';
    }

    $rev = (string)($u['ika_revoked_at'] ?? '');
    if ($rev !== '') $flags[] = 'sesje unieważnione ' . substr($rev, 0, 16);

    return sprintf(
        "  #%-5d %-34s %-10s %s\n",
        (int)$u['id'],
        mb_strimwidth((string)($u['email'] ?? ''), 0, 34, '…'),
        (string)($u['role'] ?? ''),
        $flags ? implode(' | ', $flags) : 'w porządku'
    );
}

/** Wpis do dziennika — z CLI nie ma zalogowanego operatora. */
function ika_cli_log(int $uid, string $action, string $detail): void
{
    try {
        require_once dirname(__DIR__) . '/includes/auth_security.php';
        if (function_exists('authlog_write')) authlog_write($uid, $action, '', $detail . ' [CLI]');
    } catch (\Throwable $e) { /* brak dziennika nie może blokować odblokowania */ }
}

$cmd  = $argv[1] ?? 'status';
$who  = $argv[2] ?? '';
$glob = module_enabled('ika_enabled');

switch ($cmd) {

case 'status':
    printf("Wymóg IKA globalnie: %s  (settings.ika_enabled = %s)\n",
        $glob ? 'WŁĄCZONY' : 'WYŁĄCZONY',
        var_export(org_setting('ika_enabled'), true));
    echo str_repeat('─', 78) . "\n";

    if ($who !== '') {
        $u = ika_cli_user($who);
        if (!$u) { fwrite(STDERR, "Nie znaleziono konta: {$who}\n"); exit(1); }
        echo ika_cli_row($u);
        break;
    }

    // Bez argumentu pokazujemy tylko konta, które mają cokolwiek nietypowego —
    // pełna lista użytkowników byłaby nieczytelna.
    $rows = db_all(
        "SELECT * FROM users
          WHERE COALESCE(cpc_fails,0) > 0
             OR cpc_blocked_until IS NOT NULL
             OR crm_ika_required IS NOT NULL
             OR ika_revoked_at IS NOT NULL
             OR COALESCE(cpc_code,'') = ''
       ORDER BY id"
    );
    if (!$rows) {
        echo "Żadne konto nie ma blokady, wymuszenia ani braku kodu IKA.\n";
        break;
    }
    echo "Konta wymagające uwagi:\n";
    foreach ($rows as $u) echo ika_cli_row($u);
    break;

case 'off':
    org_setting_set('ika_enabled', '0');
    echo "Wymóg IKA WYŁĄCZONY globalnie.\n";
    echo "UWAGA: to zdejmuje drugi czynnik z operacji krytycznych i całego CRM.\n";
    echo "Włącz ponownie: php cli/ika.php on\n";
    ika_cli_log(0, 'ika_disabled', 'Wyłączono wymóg IKA globalnie');
    break;

case 'on':
    org_setting_set('ika_enabled', '1');
    echo "Wymóg IKA WŁĄCZONY globalnie.\n";
    ika_cli_log(0, 'ika_enabled', 'Włączono wymóg IKA globalnie');
    break;

case 'unblock':
case 'exempt':
case 'enforce':
case 'auto':
    if ($who === '') { fwrite(STDERR, "Podaj id albo e-mail konta.\n"); exit(1); }
    $u = ika_cli_user($who);
    if (!$u) { fwrite(STDERR, "Nie znaleziono konta: {$who}\n"); exit(1); }
    $uid = (int)$u['id'];

    if ($cmd === 'unblock') {
        db()->prepare("UPDATE users SET cpc_fails = 0, cpc_blocked_until = NULL WHERE id = ?")->execute([$uid]);
        echo "Zdjęta blokada po błędnych próbach dla #{$uid} ({$u['email']}).\n";
        ika_cli_log($uid, 'ika_unblocked', 'Zdjęto blokadę IKA po błędnych próbach');
    } elseif ($cmd === 'exempt') {
        db()->prepare("UPDATE users SET crm_ika_required = 0 WHERE id = ?")->execute([$uid]);
        echo "Konto #{$uid} ({$u['email']}) zwolnione z IKA w CRM.\n";
        ika_cli_log($uid, 'ika_exempt', 'Zwolniono konto z IKA w CRM');
    } elseif ($cmd === 'enforce') {
        db()->prepare("UPDATE users SET crm_ika_required = 1 WHERE id = ?")->execute([$uid]);
        echo "Konto #{$uid} ({$u['email']}) — IKA w CRM wymuszone.\n";
        ika_cli_log($uid, 'ika_enforced', 'Wymuszono IKA dla konta w CRM');
    } else {
        db()->prepare("UPDATE users SET crm_ika_required = NULL WHERE id = ?")->execute([$uid]);
        echo "Konto #{$uid} ({$u['email']}) — przywrócone zachowanie domyślne (wg roli).\n";
        ika_cli_log($uid, 'ika_auto', 'Przywrócono domyślne zachowanie IKA dla konta');
    }

    $fresh = db_one("SELECT * FROM users WHERE id = ?", [$uid]);
    if ($fresh) { echo "Stan po zmianie:\n" . ika_cli_row($fresh); }
    break;

default:
    fwrite(STDERR, "Nieznane polecenie: {$cmd}\n\n");
    fwrite(STDERR, "php cli/ika.php status [<id|e-mail>]\n");
    fwrite(STDERR, "php cli/ika.php off | on\n");
    fwrite(STDERR, "php cli/ika.php unblock|exempt|enforce|auto <id|e-mail>\n");
    exit(1);
}

exit(0);
