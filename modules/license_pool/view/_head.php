<?php
/**
 * modules/license_pool/view/_head.php — nagłówek wspólny dla TI (panel dydaktyka) i IT SZO.
 * Wejście: $title, $actor, $ctx ('ti'|'it').
 */
if ($ctx === 'ti') {
    $_dyd = dirname(__DIR__, 3) . '/karty30/ti/dydaktyk';
    $KP_TITLE  = $title . ' — Panel dydaktyka';
    $KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $actor['name'], 'logout' => 'logout.php'];
    $KP_BODY_CLASS = 'dyd-usos ti-skin';
    include dirname($_dyd) . '/kursant/_layout_head.php';
    $_skin_css = dirname($_dyd) . '/assets/ti_skin.css';
    echo '<link rel="stylesheet" href="../assets/ti_skin.css?v=' . (is_file($_skin_css) ? (int)filemtime($_skin_css) : 1) . '">';
    $KIER_CUR = 'licencjeFundacji.php';
    include $_dyd . '/_kierownik_bar.php';
    echo '<main id="main" class="dyd-wrap"><div class="container-fluid py-3" style="max-width:1240px">';
} else {
    $PAGE_TITLE = $title;
    include dirname(__DIR__, 3) . '/includes/header.php';
    echo '<main id="main"><div class="container-fluid py-4" style="max-width:1240px">';
}
echo flash_html();
?>
<nav class="mb-3" aria-label="Pula licencji">
  <a class="btn btn-sm btn-outline-primary me-1" href="<?= h(route('lp.index')) ?>"><i class="bi bi-key"></i> Pula</a>
  <a class="btn btn-sm btn-outline-primary" href="<?= h(route('lp.import')) ?>"><i class="bi bi-file-earmark-arrow-up"></i> Import z TXT</a>
</nav>
