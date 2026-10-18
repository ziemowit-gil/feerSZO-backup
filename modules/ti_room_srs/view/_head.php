<?php
/**
 * modules/ti_room_srs/view/_head.php — nagłówek: w panelu dydaktyka (TI_ROOM_SRS_PANEL) skórka ti-skin z menu panelu,
 * w SZO zwykła powłoka SZO (includes/header.php). Wejście: $title, $me (['id','name','staff']).
 */
if (defined('TI_ROOM_SRS_PANEL')) {
    $_dyd = dirname(__DIR__, 3) . '/karty30/ti/dydaktyk';
    $KP_TITLE  = $title . ' — Panel dydaktyka';
    $KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $me['name'], 'logout' => 'logout.php'];
    $KP_BODY_CLASS = 'dyd-usos ti-skin';
    include dirname($_dyd) . '/kursant/_layout_head.php';
    $_skin_css = dirname($_dyd) . '/assets/ti_skin.css';
    echo '<link rel="stylesheet" href="../assets/ti_skin.css?v=' . (is_file($_skin_css) ? (int)filemtime($_skin_css) : 1) . '">';
    $KIER_CUR = 'saleFormularz.php'; $KIER_LABEL = 'Formularze rezerwacji sal';
    include $_dyd . '/_kierownik_bar.php';
    echo '<main id="main" class="dyd-wrap"><div class="container-fluid py-3" style="max-width:1200px">';
} else {
    $PAGE_TITLE = $title;
    include dirname(__DIR__, 3) . '/includes/header.php';
}
echo flash_html();
