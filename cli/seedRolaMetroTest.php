<?php
/**
 * cli/seedRolaMetroTest.php — zakłada i kasuje testowego użytkownika „rolaMetroTest”
 * (konto lokalne @feer.test, do sprawdzania widoków Metro w danej roli).
 *
 * Użycie:
 *   php cli/seedRolaMetroTest.php create '<haslo>' [rola] [domena]
 *   php cli/seedRolaMetroTest.php delete [domena]
 *   php cli/seedRolaMetroTest.php status [domena]
 *
 * Przykłady:
 *   php cli/seedRolaMetroTest.php create 'MojeHaslo123!'          # rola viewer
 *   php cli/seedRolaMetroTest.php create 'MojeHaslo123!' editor
 *   php cli/seedRolaMetroTest.php delete
 *
 * Login: rolametrotest@<domena> (domyślnie feer.test — RFC 2606, żaden e-mail nie wyjdzie).
 * create jest idempotentne: gdy konto istnieje, aktualizuje tylko rolę (hasła nie nadpisuje).
 * delete kasuje WYŁĄCZNIE konto o tym e-mailu i nazwie „Rola Metro (TEST)”, przez
 * user_delete_execute() — razem z danymi powiązanymi (CASCADE / SET NULL jak w panelu admina).
 * Hasło podajesz w argumencie — nie trafia do repozytorium.
 */

require_once dirname(__DIR__) . '/includes/cli_inproc.php';   // cli_exit(), cli_getopt() — także uruchamianie z Admin › Seedery
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);
if (!file_exists($base . '/config.php')) {
    fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n");
    cli_exit(2);
}
defined('APP_CLI') || define('APP_CLI', true);
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/auth.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/approval.php';
require_once $base . '/includes/user_delete.php';

const RMT_NAME  = 'Rola Metro (TEST)';
const RMT_ROLES = ['viewer', 'editor', 'admin'];

$usage = "Użycie:\n"
       . "  php cli/seedRolaMetroTest.php create '<haslo>' [rola: viewer|editor|admin] [domena]\n"
       . "  php cli/seedRolaMetroTest.php delete [domena]\n"
       . "  php cli/seedRolaMetroTest.php status [domena]\n";

$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'create':
        $password = $argv[2] ?? '';
        $role     = $argv[3] ?? 'viewer';
        $email    = 'rolametrotest@' . ($argv[4] ?? 'feer.test');
        if (strlen($password) < 8) {
            fwrite(STDERR, "Podaj hasło (min. 8 znaków).\n" . $usage);
            cli_exit(2);
        }
        if (!in_array($role, RMT_ROLES, true)) {
            fwrite(STDERR, "Nieznana rola „{$role}” — dozwolone: " . implode(', ', RMT_ROLES) . ".\n");
            cli_exit(2);
        }
        $u = db_one("SELECT id, name, role FROM users WHERE email=?", [$email]);
        if ($u) {
            if ($u['name'] !== RMT_NAME) {
                fwrite(STDERR, "Konto {$email} istnieje, ale nie jest testowe (nazwa „{$u['name']}”) — nie ruszam.\n");
                cli_exit(1);
            }
            db()->prepare("UPDATE users SET role=?, is_active=1 WHERE id=?")->execute([$role, $u['id']]);
            echo "ISTNIEJE {$email} (id={$u['id']}) — rola ustawiona na {$role}, hasło bez zmian.\n";
            cli_exit(0);
        }
        $id = db_insert('users', [
            'name'      => RMT_NAME,
            'email'     => $email,
            'password'  => password_hash($password, PASSWORD_DEFAULT),
            'role'      => $role,
            'is_active' => 1,
        ]);
        echo "OK utworzono {$email} (id={$id}, rola {$role}).\n";
        echo "Usunięcie: php cli/seedRolaMetroTest.php delete\n";
        cli_exit(0);

    case 'delete':
        $email = 'rolametrotest@' . ($argv[2] ?? 'feer.test');
        $u = db_one("SELECT id FROM users WHERE email=? AND name=?", [$email, RMT_NAME]);
        if (!$u) {
            echo "{$email} — nie znaleziono (już usunięty?).\n";
            cli_exit(0);
        }
        $res = user_delete_execute((int)$u['id'], 0, 'Seeder rolaMetroTest — sprzątanie po testach');
        echo ($res['ok'] ? "USUNIĘTO {$email} (id={$u['id']}). " : "BŁĄD: ") . $res['msg'] . "\n";
        cli_exit($res['ok'] ? 0 : 1);

    case 'status':
        $email = 'rolametrotest@' . ($argv[2] ?? 'feer.test');
        $u = db_one("SELECT id, role, is_active, created_at FROM users WHERE email=? AND name=?", [$email, RMT_NAME]);
        echo $u
            ? "{$email}: id={$u['id']}, rola {$u['role']}, " . ($u['is_active'] ? 'aktywne' : 'nieaktywne') . ", od {$u['created_at']}\n"
            : "{$email}: brak konta.\n";
        cli_exit(0);

    default:
        fwrite(STDERR, $usage);
        cli_exit(2);
}
