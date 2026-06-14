<?php
/**
 * panel/includes/pv_ui.php — Wspólne komponenty UI podstron panelu.
 *
 * Zapewnia spójny nagłówek strony i style kart, niezależnie od powłoki
 * (panel wolontariusza header_panel.php LUB panel admina header.php).
 * Style są samowystarczalne — używają zmiennej --vol-color z fallbackiem,
 * więc wyglądają poprawnie pod obiema powłokami.
 *
 * Użycie:
 *   require_once __DIR__ . '/includes/pv_ui.php';
 *   pv_page_header('Tytuł', ['icon'=>'bi-shield-lock', 'sub'=>'Podtytuł', 'actions'=>'<a ...>']);
 *   <div class="pv-card"><div class="pv-card-hd"><i class="bi bi-..."></i>Nagłówek</div>
 *     <div class="pv-card-bd"> ... </div></div>
 */

/** Jednorazowo wstrzykuje wspólny system stylów (wywoływane przez pv_page_header). */
function pv_ui_styles(): void {
    require_once __DIR__ . '/pv_styles.php';   // require_once => emisja <style> raz na żądanie
}

/**
 * Renderuje spójny nagłówek podstrony.
 *
 * @param string $title Tytuł strony (escapowany).
 * @param array  $o     Opcje: icon (klasa bi-*), sub (tekst), actions (HTML),
 *                      back (['url'=>, 'label'=>]).
 */
function pv_page_header(string $title, array $o = []): void {
    pv_ui_styles();
    $icon    = $o['icon']    ?? '';
    $sub     = $o['sub']     ?? '';
    $actions = $o['actions'] ?? '';
    $back    = $o['back']    ?? null;
    $hesc    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    ?>
<div class="pv-page-header">
  <div class="pv-page-head-main">
    <?php if ($back && !empty($back['url'])): ?>
    <a href="<?= $hesc($back['url']) ?>" class="pv-page-back"><i class="bi bi-arrow-left"></i><?= $hesc($back['label'] ?? 'Wstecz') ?></a>
    <?php endif; ?>
    <h1 class="pv-page-title"><?php if ($icon): ?><i class="bi <?= $hesc($icon) ?>" aria-hidden="true"></i><?php endif; ?><?= $hesc($title) ?></h1>
    <?php if ($sub !== ''): ?><div class="pv-page-sub"><?= $hesc($sub) ?></div><?php endif; ?>
  </div>
  <?php if ($actions): ?><div class="pv-page-actions"><?= $actions ?></div><?php endif; ?>
</div>
    <?php
}
