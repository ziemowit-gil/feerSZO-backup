<?php
/**
 * home.php — strona główna. Kafelki typu "social" trafiają do nagłówka,
 * pozostałe do siatki Bento albo do jednej kolumny (styl wizytówki).
 * Zmienne: $tiles, $layout
 */
$socials = array_values(array_filter($tiles, fn($t) => $t['type'] === 'social' && tile_href($t) !== ''));
$rest    = array_values(array_filter($tiles, fn($t) => $t['type'] !== 'social'));
$mode    = $layout;

require APP_ROOT . '/views/partials/hero.php';
?>

<div class="tiles tiles--<?= e($mode) ?>">
  <?php foreach ($rest as $t) { require APP_ROOT . '/views/partials/tile.php'; } ?>
</div>

<?php if (!$rest && is_logged_in()): ?>
  <p class="empty-hint">
    Nie masz jeszcze żadnych kafelków —
    <a href="<?= e(url('/admin/tiles')) ?>">dodaj pierwszy w panelu</a>.
  </p>
<?php endif; ?>
