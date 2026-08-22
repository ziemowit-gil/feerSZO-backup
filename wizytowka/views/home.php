<?php
/**
 * home.php — strona główna.
 *
 * Kafelki typu „social” trafiają do nagłówka. Pozostałe są dzielone na SEKCJE:
 * kafelek „heading” lub „divider” zamyka bieżącą siatkę i otwiera nową. Dzięki
 * temu nagłówki nie zajmują komórek siatki Bento (żadnych pustych wierszy),
 * a układ jednokolumnowy dostaje naturalny podział na sekcje.
 *
 * Zmienne: $tiles, $layout
 */
$socials = array_values(array_filter($tiles, fn($t) => $t['type'] === 'social' && tile_href($t) !== ''));
$rest    = array_values(array_filter($tiles, fn($t) => $t['type'] !== 'social'));
$mode    = $layout;

require APP_ROOT . '/views/partials/hero.php';

/** Podziel kafelki na grupy: separatory (heading/divider) i grupy zwykłych kafelków. */
$sections = [];
$buffer   = [];
foreach ($rest as $t) {
    if (in_array($t['type'], ['heading', 'divider'], true)) {
        if ($buffer) { $sections[] = ['grid', $buffer]; $buffer = []; }
        $sections[] = ['break', $t];
        continue;
    }
    $buffer[] = $t;
}
if ($buffer) $sections[] = ['grid', $buffer];

?>
<div class="stream">
<?php
foreach ($sections as [$kind, $payload]):
    if ($kind === 'break'):
        $t = $payload;
        require APP_ROOT . '/views/partials/tile.php';
    else: ?>
      <div class="tiles tiles--<?= e($mode) ?>">
        <?php foreach ($payload as $t) { require APP_ROOT . '/views/partials/tile.php'; } ?>
      </div>
    <?php endif;
endforeach; ?>
</div>
<?php ?>

<?php if (!$rest && is_logged_in()): ?>
  <p class="empty-hint">
    Nie masz jeszcze żadnych kafelków —
    <a href="<?= e(url('/admin/tiles')) ?>">dodaj pierwszy w panelu</a>.
  </p>
<?php endif; ?>
