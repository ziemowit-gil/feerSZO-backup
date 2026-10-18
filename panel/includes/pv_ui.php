<?php
/**
 * panel/includes/pv_ui.php — Wspólne komponenty UI podstron panelu.
 *
 * Zapewnia spójny nagłówek strony i style kart, niezależnie od powłoki
 * (panel współpracownika header_panel.php LUB panel admina header.php).
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
    pv_ui_metro_szo();
}

/**
 * Warstwa METRO (pv_metro.php) także pod powłoką SZO — dla osób, które oglądają podstrony
 * panelu w ramce SZO (konsultanci TI, personel). pv_metro.php zakotwicza reguły treści
 * w #pv-main (tylko ramka panelu), więc tu bierzemy z niego tokeny (:root) i reguły treści,
 * podmieniamy zakres na .pv-wrap (opakowanie treści podstron) i emitujemy raz.
 * Pod ramką panelu (header_panel.php już załadował pv_metro.php) — nic nie robi.
 */
function pv_ui_metro_szo(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $file = __DIR__ . '/pv_metro.php';
    if (!is_file($file) || in_array(realpath($file), get_included_files(), true)) return;
    $css = (string)file_get_contents($file);
    if (!preg_match('~<style>(.*)</style>~s', $css, $m)) return;
    $css = preg_replace('~/\*.*?\*/~s', '', $m[1]);
    // Podział na reguły najwyższego poziomu (z uwzględnieniem zagnieżdżonych @media).
    $out = []; $depth = 0; $buf = '';
    foreach (str_split($css) as $ch) {
        $buf .= $ch;
        if ($ch === '{') $depth++;
        elseif ($ch === '}' && --$depth === 0) { $out[] = trim($buf); $buf = ''; }
    }
    $keep = array_filter($out, fn($r) => str_starts_with($r, ':root')
        || str_starts_with($r, '@media(prefers-color-scheme') || str_contains($r, 'pv-main'));
    $scoped = str_replace(['#pv-main.container', ':where(#pv-main)', '#pv-main'], ['.pv-wrap', ':where(.pv-wrap)', '.pv-wrap'], implode("\n", $keep));
    echo "<style>/* pv_metro.php → powłoka SZO (pv_ui_metro_szo) */\n" . $scoped . "\n</style>\n";
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
