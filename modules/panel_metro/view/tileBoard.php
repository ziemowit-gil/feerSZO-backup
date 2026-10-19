<?php
/**
 * modules/panel_metro/view/tileBoard.php — plansza kafli Start (panelMetroTileBoard).
 * Wejście: $tiles (z 'ord' i 'group'), $grouped, $sections (klucz grupy => kafle), $groups, $boardId.
 * Widok „Grupuj wg funkcji”: sekcja na grupę z nagłówkiem h2 (kolejność czytania = kolejność grup),
 * inaczej jedna siatka z ukrytym nagłówkiem „Skróty”. Skrypt przełącznika (groupingToggle.php)
 * przekłada te same kafle po data-pvm-group / data-pvm-ord, więc układ JS = układ z serwera.
 */
?>
<style>
.pvm{--pvm-steel:#647687}
@media(prefers-color-scheme:dark){:root:not([data-theme="light"]):not([data-theme="hc"]) .pvm{--pvm-steel:#9fb0c1}}
:root[data-theme="dark"] .pvm{--pvm-steel:#9fb0c1}
:root[data-theme="hc"] .pvm{--pvm-steel:#000}
.pvm .mt-sec + .mt-sec{margin-top:1.75rem}
.pvm .mt-sec__h{margin:0 0 .65rem;font-size:1.05rem;font-weight:300;line-height:1.2;text-transform:uppercase;letter-spacing:.16em;color:var(--pvm-steel)}
@media(max-width:480px){.pvm .mt-sec + .mt-sec{margin-top:1.25rem}.pvm .mt-sec__h{font-size:.95rem;letter-spacing:.12em}}
</style>
<div class="mt-board" id="<?= h($boardId) ?>" data-pvm-board data-pvm-groups="<?= h(json_encode($groups, JSON_UNESCAPED_UNICODE)) ?>">
<?php if ($grouped): ?>
  <?php foreach ($sections as $key => $list): ?>
  <section class="mt-sec" aria-labelledby="<?= h($boardId . '-' . $key) ?>">
    <h2 class="mt-sec__h" id="<?= h($boardId . '-' . $key) ?>"><?= h($groups[$key]) ?></h2>
    <div class="mt-grid"><?php foreach ($list as $t) panelMetroTile($t); ?></div>
  </section>
  <?php endforeach; ?>
<?php else: ?>
  <h2 class="visually-hidden">Skróty</h2>
  <div class="mt-grid"><?php foreach ($tiles as $t) panelMetroTile($t); ?></div>
<?php endif; ?>
</div>
