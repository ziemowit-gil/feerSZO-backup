<?php
/**
 * karty30/ti/dydaktyk/_konsultacje_bar.php — boczne menu sekcji Konsultacje (jak _analityka_bar.php):
 * Rezerwacje, Kalendarz, Szybka / Nowa rezerwacja, Kolejka oczekujących, Karty konsultacji, Nowa konsultacja,
 * Wydruki i zestawienia, Ustawienia (kierownik), Szkolenia krótkie SzK (kierownik). Wejście: $RZ_CUR (adres jak w menu), $RZ_LABEL.
 */
$RZ_CUR   = $RZ_CUR   ?? '';
$RZ_LABEL = $RZ_LABEL ?? '';
$_kb_staff = function_exists('dyd_is_staff') && dyd_is_staff();
$_kb = [
    ['Rezerwacje terminów',   'rezerwacje.php',               'calendar-check'],
    ['Kalendarz rezerwacji',  'rezerwacjeKalendarz.php',      'calendar3'],
    ['Szybka rezerwacja',     'rezerwacja.php?szybka=1',      'lightning'],
    ['Nowa rezerwacja',       'rezerwacja.php?nowa=1',        'plus-lg'],
    ['Kolejka oczekujących',  'kolejka.php',                  'hourglass-split'],
    ['Karty konsultacji',     'konsultacje.php',              'clipboard2-check'],
    ['Nowa konsultacja',      'konsultacja.php?nowa=1',       'clipboard2-plus'],
    ['Wydruki i zestawienia', 'konsultacjeZestawienia.php',   'printer'],
    ['Komunikacja (e-mail / SMS)', 'konsultacjeKomunikacja.php', 'send'],
    ['Komunikaty',            'index.php?tab=komunikaty',     'megaphone'],
];
if ($_kb_staff) {
    $_kb[] = ['Szkolenia krótkie (SzK)', 'grupaUproszczona.php', 'lightning-charge'];
    $_kb[] = ['Ustawienia konsultacji',  'konsultacjeUstawienia.php', 'gear'];
    $_kb[] = ['Panel kierownika',        'index.php?tab=grupy', 'people'];
}
$KIER_ITEMS = [];
foreach ($_kb as $_d) {
    $KIER_ITEMS[] = ['label' => $_d[0], 'href' => $_d[1], 'icon' => $_d[2], 'n' => 0, 'v' => 'secondary', 'blank' => false, 'active' => $_d[1] === $RZ_CUR];
}
$KIER_EMBED = false; $KIER_FLAT = true; $KIER_CUR = $RZ_CUR; $KIER_LABEL = $RZ_LABEL;
$KIER_TITLE = 'Konsultacje'; $KIER_CRUMB = ['Konsultacje', 'rezerwacje.php'];
include __DIR__ . '/_kierownik_bar.php';
