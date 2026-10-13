<?php
/** Nagłówek HTML ekranów rezerwacji (po _rezerwacje_nav.php). Wejście: $RZ_TITLE, opcjonalnie $KP_FULLCALENDAR. */
$KP_TITLE  = $RZ_TITLE . ' — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $byName, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $RZ_LABEL = $RZ_TITLE; include __DIR__ . '/_konsultacje_bar.php'; /* boczne menu sekcji Konsultacje ($RZ_CUR) */ ?>
