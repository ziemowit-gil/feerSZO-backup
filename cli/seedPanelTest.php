<?php
/**
 * cli/seedPanelTest.php — konto testowe do oglądania „Mojego panelu” z UKRYTĄ umową wolontariacką.
 *
 * Zakłada: użytkownika test@local (rola viewer, logowanie lokalne) oraz umowę wolontariacką powiązaną z nim e-mailem, z flagą `ukryta`
 * (modules/contract_hidden) — bez numeru Rejestru Umów, więc poza rejestrem, licznikami i numeracją, a niewidoczną w listach personelu.
 * Właściciel widzi ją w swoim panelu, dlatego panel wolontariusza można przetestować bez wpływu na dane organizacji.
 *
 * Użycie:
 *   php cli/seedPanelTest.php create [--haslo=…] [--email=test@local]    # puste hasło = losowe (wypisane raz)
 *   php cli/seedPanelTest.php delete [--email=…]
 *   php cli/seedPanelTest.php status [--email=…]
 * create jest idempotentne. Istniejące konto o tym adresie (np. własne test@local) zostaje: hasło zmieniane tylko przy --haslo, dokładana jest ukryta umowa.
 * delete usuwa umowę WOL/TEST-PANEL/1 oraz konto TYLKO gdy założył je seeder (nazwa „Panel testowy (TEST)”).
 */
require_once dirname(__DIR__) . '/includes/cli_inproc.php';   // cli_exit(), cli_getopt() — także uruchamianie z Admin › Seedery
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}
$base = dirname(__DIR__);
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
defined('APP_INSTALLED') || define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/user_delete.php';
require_once $base . '/modules/contract_hidden/logic/contractHidden.php';

const PT_NAME = 'Panel testowy (TEST)';
const PT_NR   = 'WOL/TEST-PANEL/1';

// Argumenty parsujemy sami: getopt() przerywa na pierwszym nie-opcji (czyli na „create”), więc --haslo po poleceniu by przepadło.
$args = array_map('strval', array_slice($GLOBALS['__szoCliArgs'] ?? ($argv ?? []), defined('SZO_CLI_INPROC') ? 0 : 1));
$cmd = ''; $opts = [];
foreach ($args as $i => $a) {
    if (str_starts_with($a, '--')) { [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, ''); $opts[$k] = $v; }
    elseif ($cmd === '') $cmd = $a;
}
$email = strtolower(trim((string)($opts['email'] ?? '')) ?: 'test@local');
$pass  = (string)($opts['haslo'] ?? '');
$usage = "Użycie: php cli/seedPanelTest.php create|delete|status [--haslo=…] [--email=test@local]\n";

if (!preg_match('/^[^@\s]+@[^@\s]+$/', $email)) { fwrite(STDERR, "Nieprawidłowy --email.\n"); cli_exit(2); }
if (str_ends_with($email, '@feer.org.pl')) { fwrite(STDERR, "Adresy @feer.org.pl logują się tylko przez Microsoft — użyj np. test@local.\n"); cli_exit(2); }

$u = db_one("SELECT id, name FROM users WHERE lower(email)=?", [$email]);
$c = db_one("SELECT * FROM umowy_wolontariat WHERE numer_umowy=? AND lower(email)=?", [PT_NR, $email]);   // SELECT *: kolumna ukryta może jeszcze nie istnieć

switch ($cmd) {
    case 'create':
        // Konto założone przez seeder ma nazwę PT_NAME. Istniejące konto o tym adresie (np. własne „test@local” dewelopera) zostaje
        // nietknięte poza: hasło ustawiane TYLKO gdy podasz --haslo, oraz dołożeniem ukrytej umowy. delete nie usunie takiego konta.
        $generated = false; $created = false;
        if (!$u) {
            if ($pass === '') { $pass = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Ab'), '='); $generated = true; }
            if (strlen($pass) < 8) { fwrite(STDERR, "Hasło musi mieć co najmniej 8 znaków.\n"); cli_exit(2); }
            $uid = db_insert('users', ['name' => PT_NAME, 'email' => $email, 'password' => password_hash($pass, PASSWORD_DEFAULT), 'role' => 'viewer', 'is_active' => 1]);
            $created = true;
        } else {
            $uid = (int)$u['id'];
            db_exec("UPDATE users SET is_active=1 WHERE id=?", [$uid]);
            if ($pass !== '') {
                if (strlen($pass) < 8) { fwrite(STDERR, "Hasło musi mieć co najmniej 8 znaków.\n"); cli_exit(2); }
                db_exec("UPDATE users SET password=? WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $uid]);
            }
        }
        $table = table_for_type('wolontariat');
        contractHiddenEnsure($table);
        $c = db_one("SELECT id FROM umowy_wolontariat WHERE numer_umowy=? AND lower(email)=?", [PT_NR, $email]);
        if (!$c) {
            $today = date('Y-m-d');
            $cid = db_insert('umowy_wolontariat', [
                'numer_umowy' => PT_NR, 'status' => 'podpisana', 'imie_nazwisko' => $u['name'] ?? PT_NAME, 'email' => $email,
                'data_zawarcia' => $today, 'data_rozpoczecia' => $today, 'bezterminowa' => 1,
                'przedmiot_porozumienia' => 'Wolontariat testowy — oglądanie panelu',
                'uwagi' => 'TEST — ukryta; usuń: php cli/seedPanelTest.php delete', 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $cid = (int)$c['id'];
        }
        contractSetHidden('wolontariat', $cid, true, 0);
        echo $created ? "OK konto {$email} (id={$uid}, rola viewer).\n"
                      : "ISTNIEJE konto {$email} (id={$uid}, „{$u['name']}”) — " . ($pass !== '' ? 'hasło ustawione.' : 'hasło bez zmian (podaj --haslo, żeby je ustawić).') . "\n";
        if ($generated) echo "Hasło (wypisane tylko teraz): {$pass}\n";
        echo "Umowa wolontariacka #{$cid} (" . PT_NR . ") — ukryta, bez numeru RU.\n";
        echo "Usunięcie: php cli/seedPanelTest.php delete\n";
        cli_exit(0);

    case 'delete':
        if (!$u && !$c) { echo "{$email} — brak konta i umowy (już usunięte?).\n"; cli_exit(0); }
        if ($c) { db_exec("DELETE FROM umowy_wolontariat WHERE id=?", [(int)$c['id']]); echo "USUNIĘTO umowę #{$c['id']}.\n"; }
        if ($u && $u['name'] === PT_NAME) {
            $res = user_delete_execute((int)$u['id'], 0, 'Seeder panelTest — sprzątanie po testach');
            echo ($res['ok'] ? "USUNIĘTO konto {$email} (id={$u['id']}). " : 'BŁĄD: ') . $res['msg'] . "\n";
            cli_exit($res['ok'] ? 0 : 1);
        }
        if ($u) echo "Konto {$email} (id={$u['id']}, „{$u['name']}”) zostaje — nie zakładał go seeder.\n";
        cli_exit(0);

    case 'status':
        echo $u ? "Konto {$email}: id={$u['id']}.\n" : "Konto {$email}: brak.\n";
        echo $c ? "Umowa {$c['id']}: " . (!empty($c['ukryta']) ? 'ukryta' : 'WIDOCZNA (uwaga!)') . ".\n" : "Umowa " . PT_NR . ": brak.\n";
        cli_exit(0);

    default:
        fwrite(STDERR, $usage);
        cli_exit(2);
}
