<?php
/**
 * gallery.php — widok galerii (/g/{slug}) z Lightboxem opartym na Alpine.
 * Zmienne: $gallery, $images
 */
$lbData = array_map(fn($i) => [
    'src'     => upload_url('gallery/' . $i['filename']),
    'alt'     => (string)$i['alt'],
    'caption' => (string)$i['caption'] !== '' ? (string)$i['caption'] : (string)$i['alt'],
], $images);
?>
<section class="gallery" x-data='lightbox(<?= e(json_encode($lbData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>)'>
  <p class="back"><a href="<?= e(url('/')) ?>"><?= icon_html('bi-arrow-left') ?> Strona główna</a></p>

  <h1 class="page__title"><strong><?= e($gallery['title']) ?></strong></h1>

  <?php if ($gallery['description'] !== ''): ?>
    <div class="page__excerpt prose"><?= md_to_html((string)$gallery['description']) ?></div>
  <?php endif; ?>

  <hr class="rule">

  <?php if (!$images): ?>
    <p class="empty-hint">Ta galeria nie ma jeszcze zdjęć.</p>
  <?php else: ?>
    <ul class="grid grid--<?= e($gallery['layout'] === 'grid' ? 'grid' : 'masonry') ?>">
      <?php foreach ($images as $k => $img): ?>
        <li class="grid__item">
          <button type="button" class="grid__btn" @click="show(<?= (int)$k ?>)"
                  aria-label="Powiększ zdjęcie<?= $img['alt'] !== '' ? ': ' . e($img['alt']) : '' ?>">
            <img src="<?= e(upload_url('gallery/' . $img['filename'])) ?>"
                 alt="<?= e($img['alt']) ?>"
                 <?= (int)$img['width'] > 0 ? 'width="' . (int)$img['width'] . '" height="' . (int)$img['height'] . '"' : '' ?>
                 loading="lazy">
            <span class="grid__zoom"><?= icon_html('bi-arrows-fullscreen') ?></span>
          </button>
          <?php if ($img['caption'] !== ''): ?>
            <p class="grid__caption"><?= e($img['caption']) ?></p>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php require APP_ROOT . '/views/partials/lightbox.php'; ?>
  <?php endif; ?>
</section>
