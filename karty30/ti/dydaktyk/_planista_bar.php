<?php
/**
 * karty30/ti/dydaktyk/_planista_bar.php — OSOBNE menu boczne planisty (kierownik).
 * Osobne od menu kierownika: tylko narzędzia planowania zajęć. Wejście: $PL_CUR — plik bieżącej
 * strony (np. 'planista.php'), $PL_LABEL — nazwa strony do okruszków.
 */
$PL_CUR   = $PL_CUR   ?? '';
$PL_LABEL = $PL_LABEL ?? '';
try { require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerWorkflow.php'; $_pl_pending = sc_pending_count(); } catch (\Throwable $e) { $_pl_pending = 0; }
/** [etykieta, adres, ikona bi-*, licznik, wariant, grupa] */
$_pl_defs = [
    ['Planista (kalendarz, Gantt)',   'planista.php',          'calendar3-range', 0, '', 'Planowanie'],
    ['Zgłoszenia trenerów',           'zgloszenia.php',        'inbox',           $_pl_pending, 'warning', 'Planowanie'],
    ['Generator ciągów zajęć',        'generatorCiagow.php',   'magic',           0, '', 'Planowanie'],
    ['Raporty planisty',              'raportyPlanisty.php',   'bar-chart-line',  0, '', 'Planowanie'],
    ['Limity i kwalifikacje kadry',   'kadraLimity.php',       'person-gear',     0, '', 'Kadra i zasoby'],
    ['Urlopy prowadzących',           'urlopy.php',            'airplane',        0, '', 'Kadra i zasoby'],
    ['Noclegi kadry',                 'noclegi.php',           'moon-stars',      0, '', 'Kadra i zasoby'],
    ['Dostępności prowadzących',      'dostepnosci.php',       'clock-history',   0, '', 'Kadra i zasoby'],
    ['Sale / lokalizacje',            'sale.php',              'geo-alt',         0, '', 'Kadra i zasoby'],
    ['Okresy nauczania',              'okresy.php',            'calendar-range',  0, '', 'Kalendarz'],
    ['Dni wolne',                     'dni_wolne.php',         'calendar-x',      0, '', 'Kalendarz'],
    ['Panel kierownika',              'index.php?tab=grupy',   'arrow-left-circle', 0, '', 'Wróć'],
];
$KIER_ITEMS = [];
foreach ($_pl_defs as $_d) {
    $KIER_ITEMS[] = ['label' => $_d[0], 'href' => $_d[1], 'icon' => $_d[2], 'n' => $_d[3], 'v' => $_d[4] ?: 'secondary', 'blank' => false, 'active' => ($_d[1] === $PL_CUR)];
}
$KIER_EMBED = false; $KIER_CUR = $PL_CUR; $KIER_LABEL = $PL_LABEL;
$KIER_TITLE = 'Planista'; $KIER_CRUMB = ['Planowanie', 'planista.php'];
include __DIR__ . '/_kierownik_bar.php';
