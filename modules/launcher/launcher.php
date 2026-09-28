<?php
/**
 * modules/launcher/launcher.php — wspólny launcher modułów (partial).
 *
 * Renderuje przycisk-wyzwalacz (#fl-trigger, w #mod-sw) i kontener #fl-root,
 * dokłada CSS/JS komponentu (raz na stronę) i konfigurację window.__feerLauncher.
 * Trzy układy do wyboru przez użytkownika (lista / szuflada / pełny ekran),
 * wyszukiwarka, nawigacja klawiaturą, „Ostatnio używane” (localStorage).
 *
 * Parametry (ustaw PRZED include):
 *   $launcherActive — klucz aktywnego modułu (z launcherCatalog()); '' = wykryj z URI
 *   $launcherDark   — true, gdy pasek nagłówka jest ciemny (jasny wariant przycisku)
 * Zgodność wstecz: includes/module_switcher.php mapuje $msw_active/$msw_dark.
 *
 * Efekt uboczny: ustawia $_sw_items (lista pozycji) — czytają ją offcanvas
 * w includes/header.php i kafle „Moduły” na pulpicie (index.php).
 */
require_once __DIR__ . '/logic/registry.php';

$launcherActive = $launcherActive ?? ($msw_active ?? '');
$launcherDark   = $launcherDark   ?? ($msw_dark ?? false);

$_sw_items = launcherItems((string)$launcherActive);
if (!$_sw_items) return;

$_fl_cur = ['label' => 'Moduły', 'icon' => 'bi-grid-3x3-gap-fill'];
foreach ($_sw_items as $_m) if ($_m['on']) { $_fl_cur = $_m; break; }
$_fl_badges = 0; foreach ($_sw_items as $_m) if (!$_m['on']) $_fl_badges += (int)$_m['badge'];

$_fl_dir = dirname(dirname(__DIR__));
$_fl_css = @filemtime(__DIR__ . '/launcher.css') ?: '1';
$_fl_js  = @filemtime(__DIR__ . '/launcher.js')  ?: '1';
$_fl_once = defined('_FEER_LAUNCHER_ASSETS');
if (!$_fl_once) define('_FEER_LAUNCHER_ASSETS', true);
?>
<?php if (!$_fl_once): ?>
<link rel="stylesheet" href="<?= APP_URL ?>/modules/launcher/launcher.css?v=<?= $_fl_css ?>">
<?php endif; ?>
<div id="mod-sw">
  <button type="button" class="fl-trigger<?= $launcherDark ? ' fl-trigger--dark' : '' ?>" id="fl-trigger"
          aria-haspopup="dialog" aria-expanded="false"
          title="Moduły — przełącz (aktualnie: <?= h($_fl_cur['label']) ?>)"
          aria-label="Przełącz moduł — aktualnie: <?= h($_fl_cur['label']) ?>">
    <span class="fl-trigger-grid" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
    <span class="fl-trigger-cur"><?= h($_fl_cur['label']) ?></span>
    <?php if ($_fl_badges): ?><span class="fl-trigger-dot" aria-label="<?= $_fl_badges ?> spraw w innych modułach"><?= $_fl_badges > 99 ? '99+' : $_fl_badges ?></span><?php endif; ?>
  </button>
</div>
<?php if (!$_fl_once): ?>
<div id="fl-root" hidden></div>
<script>window.__feerLauncher = <?= json_encode([
    'appUrl' => APP_URL,
    'portal' => APP_URL . '/portal.php',
    'items'  => $_sw_items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= APP_URL ?>/modules/launcher/launcher.js?v=<?= $_fl_js ?>" defer></script>
<?php endif; ?>
