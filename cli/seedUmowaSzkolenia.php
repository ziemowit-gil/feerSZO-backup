<?php
/**
 * cli/seedUmowaSzkolenia.php — przykładowe (testowe) umowy o świadczenie usług szkoleniowych TI i ich czyszczenie.
 *
 *   php cli/seedUmowaSzkolenia.php                      # stan: ile umów, ile testowych (nic nie zapisuje)
 *   php cli/seedUmowaSzkolenia.php --apply              # dodaje 2 umowy testowe (Pani — projekt; Pan — zarejestrowana, podpisana)
 *   php cli/seedUmowaSzkolenia.php --docx=/ścieżka/plik.docx [--plec=M]   # zapisuje przykładowy DOCX bez zapisu w bazie
 *   php cli/seedUmowaSzkolenia.php --delete             # lista umów testowych do usunięcia (podgląd)
 *   php cli/seedUmowaSzkolenia.php --delete --apply     # usuwa wszystkie umowy testowe (is_test=1)
 *
 * Umowy testowe mają numery TI/UMSZ/TEST-NNN/RRRR i RU/TEST-NNNN/RRRR/INI — nie zajmują prawdziwej numeracji RU.
 * To samo jest w panelu dydaktyka › Uczestnicy › Umowy szkoleniowe › „Dane przykładowe”.
 */
require_once dirname(__DIR__) . '/includes/cli_inproc.php';   // cli_exit(), cli_getopt() — także uruchamianie z Admin › Seedery
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) { http_response_code(403); exit('Tylko z CLI.'); }
defined('APP_CLI') || define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/ti_training_contract/logic/trainingContractStore.php';

$o = cli_getopt('', ['apply', 'delete', 'docx:', 'plec:']);
$apply = array_key_exists('apply', $o);
TiTrainingContractStore::migrate();

if (isset($o['docx'])) {
    $d = TiTrainingContract::sample(($o['plec'] ?? 'K') === 'M' ? 'M' : 'K');
    $d['nr'] = '';
    file_put_contents($o['docx'], TiTrainingContract::docx($d));
    echo "Zapisano {$o['docx']} (" . filesize($o['docx']) . " B).\n";
    cli_exit(0);
}

if (array_key_exists('delete', $o)) {
    $rows = array_filter(TiTrainingContractStore::list(), fn($r) => (int)$r['is_test'] === 1);
    if (!$rows) { echo "Brak umów testowych.\n"; cli_exit(0); }
    foreach ($rows as $r) echo "  #{$r['id']} " . ($r['numer_umowy'] ?: 'projekt') . ($r['nr_rejestru'] ? " / {$r['nr_rejestru']}" : '') . " — {$r['imie_nazwisko']} ({$r['status']})\n";
    if (!$apply) { echo "\nPodgląd — nic nie usunięto. Dodaj --apply.\n"; cli_exit(0); }
    echo "Usunięto: " . TiTrainingContractStore::purgeTest() . "\n";
    cli_exit(0);
}

if (!$apply) {
    $all = TiTrainingContractStore::list();
    echo "Umowy szkoleniowe: " . count($all) . ", w tym testowe: " . TiTrainingContractStore::countTest() . ".\n";
    echo "Dodaj --apply, aby utworzyć 2 umowy testowe; --delete [--apply], aby je usunąć.\n";
    cli_exit(0);
}
foreach (TiTrainingContractStore::seed('Seeder CLI', 0) as $line) echo "  + {$line}\n";
