<?php
/**
 * cli/tiLoginyNowyFormat.php — zmiana loginów TI na nowe formaty (modules/ti_login/logic/TiLoginFormat.php):
 *   kursant imie.nazwisko · SzK/konsultacje 1234k · rodzic/opiekun 1234r · upoważniony 1234u.
 * Stary login przestaje działać od razu, aliasy logowania kursantów są czyszczone (zostaje imie.nazwisko i numer kursanta).
 *
 *   php cli/tiLoginyNowyFormat.php                       # podgląd planu (nic nie zmienia)
 *   php cli/tiLoginyNowyFormat.php --apply               # zmiana loginów bez SMS
 *   php cli/tiLoginyNowyFormat.php --apply --sms         # zmiana + SMS z nowym loginem (rodzaj access_data)
 *   --csv=plik.csv                                       # zapis planu / wyniku (do ręcznego przekazania, np. bez telefonu)
 * Historia zmian: tabela ti_login_changes, dziennik konta (login_changed), audit_log ti.login_format_migration.
 */
require_once dirname(__DIR__) . '/includes/cli_inproc.php';
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) { http_response_code(403); exit("Tylko CLI.\n"); }
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
defined('APP_INSTALLED') || define('APP_INSTALLED', true);
$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/karty30.php';
require_once $base . '/includes/ti_messages.php';
require_once $base . '/modules/ti_panel_urls/lib/tiPanelUrl.php';
require_once $base . '/modules/ti_login/logic/TiLoginFormat.php';

$opts  = cli_getopt('', ['apply', 'sms', 'csv::']);
$apply = array_key_exists('apply', $opts);
$sms   = array_key_exists('sms', $opts);
$csv   = (string)($opts['csv'] ?? '');

$plan = TiLoginFormat::plan();
$label = ['student' => 'kursant', 'szk' => 'SzK/konsultacje', 'parent' => 'rodzic', 'authp' => 'upoważniony'];
echo ($apply ? 'ZMIANA' : 'PODGLĄD (dodaj --apply)') . ($sms ? ' + SMS' : '') . ' — kont do zmiany: ' . count($plan) . "\n";
$rows = $apply ? TiLoginFormat::apply($plan, $sms) : $plan;
$cnt = [];
foreach ($rows as $r) {
    $cnt[$r['kind']] = ($cnt[$r['kind']] ?? 0) + 1;
    printf("  %-16s %-28s %-22s → %-18s %s%s\n", $label[$r['kind']], mb_substr($r['name'], 0, 28), $r['old'], $r['new'],
        $r['phone'] !== '' ? 'tel. ' . $r['phone'] : 'BEZ TELEFONU', isset($r['sms']) && $r['sms'] !== '' ? ' · SMS: ' . $r['sms'] : '');
}
foreach ($cnt as $k => $n) echo "  razem {$label[$k]}: {$n}\n";
if ($csv !== '') {
    $fp = fopen($csv, 'w');
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, ['rodzaj', 'osoba', 'stary_login', 'nowy_login', 'telefon', 'sms'], ';', '"', '');
    foreach ($rows as $r) fputcsv($fp, [$label[$r['kind']], $r['name'], $r['old'], $r['new'], $r['phone'], $r['sms'] ?? ''], ';', '"', '');
    fclose($fp);
    echo "Zapisano: {$csv}\n";
}
if (!$apply) echo "Nic nie zmieniono.\n";
