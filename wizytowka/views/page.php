<?php
/**
 * page.php — widok podstrony (/p/{slug}). Zmienne: $page, $html
 */
?>
<article class="page">
  <p class="back"><a href="<?= e(url('/')) ?>"><?= icon_html('bi-arrow-left') ?> Strona główna</a></p>

  <?php if ($page['hero_image'] !== ''): ?>
    <div class="page__hero">
      <img src="<?= e(upload_url((string)$page['hero_image'])) ?>" alt="<?= e($page['title']) ?>">
    </div>
  <?php endif; ?>

  <h1 class="page__title"><strong><?= e($page['title']) ?></strong></h1>

  <?php if ($page['excerpt'] !== ''): ?>
    <p class="page__excerpt"><?= e($page['excerpt']) ?></p>
  <?php endif; ?>

  <hr class="rule">

  <div class="prose page__content"><?= $html ?></div>
</article>
