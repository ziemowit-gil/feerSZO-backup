<?php
/**
 * karty30/ti/dydaktyk/_analityka_bar.php — OSOBNE menu boczne Analityki TI (zarząd / koordynatorzy).
 * Wejście: $AN_CUR — adres bieżącej strony tak, jak w menu (np. 'analityka.php' albo 'analitykaRaport.php?r=licencje'),
 * $AN_LABEL — nazwa strony do okruszków. Menu nie pokazuje ekranów kierownika — odbiorcą może być osoba bez uprawnień kierownika.
 */
$AN_CUR   = $AN_CUR   ?? '';
$AN_LABEL = $AN_LABEL ?? '';
$_an_defs = [['Przegląd wskaźników', 'analityka.php', 'speedometer2']];
foreach (AnalyticsManager::REPORTS as $_k => $_m) $_an_defs[] = [$_m[0], 'analitykaRaport.php?r=' . $_k, $_m[1]];
$_an_defs[] = ['Mój panel', 'index.php?tab=pulpit', 'arrow-left-circle'];
if (function_exists('dyd_is_staff') && dyd_is_staff()) $_an_defs[] = ['Panel kierownika', 'index.php?tab=grupy', 'people'];
$KIER_ITEMS = [];
foreach ($_an_defs as $_d) {
    $KIER_ITEMS[] = ['label' => $_d[0], 'href' => $_d[1], 'icon' => $_d[2], 'n' => 0, 'v' => 'secondary', 'blank' => false, 'active' => ($_d[1] === $AN_CUR)];
}
$KIER_EMBED = false; $KIER_CUR = $AN_CUR; $KIER_LABEL = $AN_LABEL;
$KIER_TITLE = 'Analityka TI'; $KIER_CRUMB = ['Analityka', 'analityka.php'];
include __DIR__ . '/_kierownik_bar.php';
