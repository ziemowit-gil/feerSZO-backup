<?php
/**
 * cli/import_rachunki_zlecenie_edok.php — import rachunków do umów zlecenie z systemu do EODoK
 * (typ „Rachunek do umowy zlecenie”, okres od 2026-11). Domyślnie TYLKO podgląd.
 *   php cli/import_rachunki_zlecenie_edok.php [--potwierdz] [--od=2026-11]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/modules/edok_przelewy_rachunki/logic/przelewyRachunki.php';

$potwierdz = in_array('--potwierdz', $argv, true);
$od = '';
foreach ($argv as $a) if (str_starts_with($a, '--od=')) $od = substr($a, 5);
$w = prz_rach_import_edok(null, $od, !$potwierdz);
echo ($potwierdz ? 'ZAIMPORTOWANO: ' . $w['ok'] . ' z ' . $w['kandydaci'] : 'PODGLĄD (dodaj --potwierdz): do importu ' . $w['kandydaci']) . "\n";
foreach (array_merge($w['pominiete'], $w['bledy']) as $l) echo " - $l\n";
