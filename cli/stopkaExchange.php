<?php
/**
 * cli/stopkaExchange.php — obsługa automatycznej stopki Exchange z konsoli.
 *
 *   php cli/stopkaExchange.php check                 # diagnostyka uprawnień (Graph + Exchange), tylko odczyty
 *   php cli/stopkaExchange.php status                # ustawienia, tryb, ostatnia synchronizacja
 *   php cli/stopkaExchange.php preview [--email=x]   # HTML stopki (z danymi użytkownika z SZO albo z placeholderami)
 *   php cli/stopkaExchange.php push                  # wypchnij regułę do Exchange teraz (nawet gdy synchronizacja wyłączona)
 *   php cli/stopkaExchange.php enable|disable        # włącz/wyłącz synchronizację (dispatcher + zapis w panelu)
 *   php cli/stopkaExchange.php mode audit|enforce    # tryb reguły (Audit = nic nie dopisuje do wiadomości)
 * Kody wyjścia: 0 = OK, 1 = błąd/niezaliczona kontrola, 2 = zła składnia.
 */
if (php_sapi_name() !== 'cli') { die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n"); }

$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/m365.php';
require_once $base . '/modules/stopka/logic/stopka.php';
require_once $base . '/modules/stopka/logic/stopkaExchange.php';
stopkaExMigrate();

$cmd  = $argv[1] ?? '';
$opts = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) $opts[$m[1]] = $m[2];
    else $opts['_'][] = $a;
}
$usage = fn() => fwrite(STDERR, "Użycie: php cli/stopkaExchange.php check|status|preview [--email=x]|push|enable|disable|mode audit|enforce\n");

switch ($cmd) {
    case 'check':
        $bad = 0;
        foreach (stopkaExCheck() as [$label, $ok, $detail]) {
            echo ($ok === true ? '[ OK ] ' : ($ok === false ? '[BŁĄD] ' : '[info] ')) . $label . "\n       " . $detail . "\n";
            if ($ok === false) $bad++;
        }
        echo $bad ? "\nNiezaliczone kontrole: $bad\n" : "\nWszystkie kontrole zaliczone.\n";
        exit($bad ? 1 : 0);

    case 'status':
        $s = stopkaExSettings();
        echo 'Synchronizacja:   ' . (!empty($s['ex_enabled']) ? 'WŁĄCZONA' : 'wyłączona') . "\n"
           . 'Tryb reguły:      ' . ($s['ex_mode'] ?? 'Audit') . "\n"
           . 'Atrybut telefonu: ' . ($s['ex_phone_attr'] ?? 'MobilePhone') . "\n"
           . 'Reguła:           ' . STOPKA_EX_RULE . "\n"
           . 'Ostatnia synch.:  ' . (($s['ex_last_sync'] ?? '') ?: '—') . ' ' . ($s['ex_last_msg'] ?? '') . "\n"
           . 'Rozmiar szablonu: ' . mb_strlen(stopkaExBuildHtml($s)) . ' / ' . STOPKA_EX_MAX_CHARS . " znaków\n";
        exit(0);

    case 'preview':
        $s = stopkaExSettings();
        if (!empty($opts['email'])) {
            $u = db_one("SELECT * FROM users WHERE lower(email)=lower(?)", [$opts['email']]);
            if (!$u) { fwrite(STDERR, "Nie ma użytkownika {$opts['email']}.\n"); exit(1); }
            echo stopkaExPreviewHtml($s, stopkaProfile($u)) . "\n";
        } else {
            echo stopkaExBuildHtml($s) . "\n";
        }
        exit(0);

    case 'push':
        [$msg, $err] = stopkaExPush();
        echo ($err ? 'BŁĄD: ' : 'OK: ') . $msg . "\n";
        exit($err ? 1 : 0);

    case 'enable':
    case 'disable':
        db_exec("UPDATE stopka_settings SET ex_enabled=? WHERE id=1", [$cmd === 'enable' ? 1 : 0]);
        echo 'Synchronizacja ' . ($cmd === 'enable' ? 'włączona (agent CRON odświeży regułę w nocy; push — od razu).' : 'wyłączona (reguła w Exchange zostaje).') . "\n";
        exit(0);

    case 'mode':
        $m = strtolower($opts['_'][0] ?? '');
        if (!in_array($m, ['audit', 'enforce'], true)) { $usage(); exit(2); }
        db_exec("UPDATE stopka_settings SET ex_mode=? WHERE id=1", [ucfirst($m)]);
        echo 'Tryb reguły: ' . ucfirst($m) . ". Uruchom „push”, żeby zmiana trafiła do Exchange.\n";
        exit(0);

    default:
        $usage();
        exit(2);
}
