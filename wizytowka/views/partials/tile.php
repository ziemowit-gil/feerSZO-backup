<?php
/**
 * tile.php — pojedynczy kafelek. Zmienne: $t (wiersz bento_tiles), $mode ('bento'|'stack').
 * Jeden szablon dla obu układów; różnice wizualne robi CSS.
 */
$href  = tile_href($t);
$blank = tile_blank($t) ? ' target="_blank" rel="noopener noreferrer"' : '';
$style = $t['accent'] !== '' ? ' style="--tile-accent:' . e($t['accent']) . '"' : '';
$cls   = 'tile tile--' . e($t['type']) . ' ' . tile_size_class((string)$t['size']);
$icon  = $t['icon'] !== '' ? $t['icon'] : icon_for_type((string)$t['type']);

switch ($t['type']):

// ── Nagłówek sekcji ───────────────────────────────────────────────────
case 'heading': ?>
  <h2 class="section-heading <?= tile_size_class((string)$t['size']) ?>"<?= $style ?>>
    <strong><?= e($t['title']) ?></strong>
    <?php if ($t['subtitle'] !== ''): ?><span class="section-heading__sub"><?= e($t['subtitle']) ?></span><?php endif; ?>
  </h2>
<?php break;

// ── Linia rozdzielająca ───────────────────────────────────────────────
case 'divider': ?>
  <hr class="rule <?= tile_size_class((string)$t['size']) ?>">
<?php break;

// ── Widżet tekstowy ───────────────────────────────────────────────────
case 'text': ?>
  <div class="<?= $cls ?>"<?= $style ?>>
    <?php if ($icon !== ''): ?><span class="tile__icon"><?= icon_html($icon) ?></span><?php endif; ?>
    <?php if ($t['title'] !== ''): ?><h3 class="tile__title"><?= e($t['title']) ?></h3><?php endif; ?>
    <?php if ($t['subtitle'] !== ''): ?><p class="tile__subtitle"><?= e($t['subtitle']) ?></p><?php endif; ?>
    <?php if ($t['body'] !== ''): ?><div class="tile__body prose"><?= md_to_html((string)$t['body']) ?></div><?php endif; ?>
  </div>
<?php break;

// ── Obraz / grafika ───────────────────────────────────────────────────
case 'image': ?>
  <?php if ($t['image'] !== ''): ?>
    <?php $inner = '<img src="' . e(upload_url((string)$t['image'])) . '" alt="' . e($t['title']) . '" loading="lazy">'; ?>
    <div class="<?= $cls ?>"<?= $style ?>>
      <?php if ($href !== ''): ?><a href="<?= e($href) ?>"<?= $blank ?>><?= $inner ?></a><?php else: ?><?= $inner ?><?php endif; ?>
      <?php if ($t['title'] !== ''): ?><p class="tile__caption"><?= e($t['title']) ?></p><?php endif; ?>
    </div>
  <?php endif; ?>
<?php break;

// ── Kod / embed (treść zaufana — wpisana przez administratora) ─────────
case 'embed': ?>
  <div class="<?= $cls ?>"<?= $style ?>>
    <?php if ($t['title'] !== ''): ?><h3 class="tile__title"><?= e($t['title']) ?></h3><?php endif; ?>
    <div class="tile__embed"><?= $t['body'] ?></div>
  </div>
<?php break;

// ── Galeria: pasek miniatur + link do pełnej galerii ──────────────────
case 'gallery':
    $gid    = (int)($t['gallery_id'] ?? 0);
    $thumbs = $gid ? gallery_images($gid, $mode === 'bento' ? 4 : 8) : [];
    $lbData = array_map(fn($i) => [
        'src'     => upload_url('gallery/' . $i['filename']),
        'alt'     => (string)$i['alt'],
        'caption' => (string)$i['caption'],
    ], $thumbs);
    ?>
  <div class="<?= $cls ?>"<?= $style ?> x-data='lightbox(<?= e(json_encode($lbData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>)'>
    <?php if ($t['title'] !== ''): ?>
      <h3 class="tile__title">
        <?php if ($href !== ''): ?><a href="<?= e($href) ?>"><?= e($t['title']) ?></a><?php else: ?><?= e($t['title']) ?><?php endif; ?>
      </h3>
    <?php endif; ?>
    <?php if ($t['subtitle'] !== ''): ?><p class="tile__subtitle"><?= e($t['subtitle']) ?></p><?php endif; ?>

    <?php if ($thumbs): ?>
      <ul class="thumbs">
        <?php foreach ($thumbs as $k => $img): ?>
          <li>
            <button type="button" class="thumbs__btn" @click="show(<?= (int)$k ?>)">
              <img src="<?= e(upload_url('gallery/' . $img['filename'])) ?>" alt="<?= e($img['alt']) ?>" loading="lazy">
            </button>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php require APP_ROOT . '/views/partials/lightbox.php'; ?>
    <?php else: ?>
      <p class="tile__subtitle tile__empty">Galeria nie ma jeszcze zdjęć.</p>
    <?php endif; ?>

    <?php if ($href !== ''): ?>
      <p class="tile__more"><a href="<?= e($href) ?>">Zobacz całą galerię <?= icon_html('bi-arrow-right') ?></a></p>
    <?php endif; ?>
  </div>
<?php break;

// ── Link / podstrona / e-mail / telefon → przycisk-pigułka ────────────
default:
    if ($href === '' && $t['type'] !== 'social') break;
    if ($t['type'] === 'social') break; // ikony social renderuje hero
    $bg = $t['image'] !== '' ? ' style="--tile-image:url(' . e(upload_url((string)$t['image'])) . ')"' : $style;
    ?>
  <a class="<?= $cls ?> tile--action<?= $t['image'] !== '' ? ' tile--has-image' : '' ?>"
     href="<?= e($href) ?>"<?= $blank ?><?= $bg ?>>
    <?php if ($icon !== ''): ?><span class="tile__icon"><?= icon_html($icon) ?></span><?php endif; ?>
    <span class="tile__label">
      <span class="tile__title"><?= e($t['title']) ?></span>
      <?php if ($t['subtitle'] !== ''): ?><span class="tile__subtitle"><?= e($t['subtitle']) ?></span><?php endif; ?>
    </span>
    <span class="tile__chev"><?= icon_html($blank !== '' ? 'bi-box-arrow-up-right' : 'bi-chevron-right') ?></span>
  </a>
<?php break;

endswitch;
