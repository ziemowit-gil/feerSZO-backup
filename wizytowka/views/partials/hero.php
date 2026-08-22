<?php
/**
 * hero.php — nagłówek wizytówki: avatar, imię i nazwisko, tytuł/bio,
 * dane kontaktowe, ikony social media, przycisk vCard.
 * Zmienne: $socials (lista kafelków typu social)
 */
$avatar  = setting('avatar');
$shape   = setting('avatar_shape', 'circle');
$name    = setting('owner_name', setting('site_name'));
$bold    = setting('owner_name_bold');
?>
<header class="hero">
  <div class="hero__text">
    <h1 class="hero__name">
      <?php if ($bold !== ''): ?>
        <?= e($name) ?> <strong><?= e($bold) ?></strong>
      <?php else: ?>
        <?= e($name) ?>
      <?php endif; ?>
    </h1>

    <?php if (setting('tagline') !== ''): ?>
      <p class="hero__tagline"><?= e(setting('tagline')) ?></p>
    <?php endif; ?>

    <?php if (setting('headline') !== ''): ?>
      <p class="hero__headline"><strong><?= e(setting('headline')) ?></strong></p>
    <?php endif; ?>

    <?php if (setting('bio') !== ''): ?>
      <div class="hero__bio prose"><?= md_to_html(setting('bio')) ?></div>
    <?php endif; ?>

    <?php if (!empty($socials)): ?>
      <ul class="icons">
        <?php foreach ($socials as $s):
            $href = tile_href($s);
            if ($href === '') continue; ?>
          <li>
            <a href="<?= e($href) ?>" <?= tile_blank($s) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
               title="<?= e($s['title']) ?>">
              <?= icon_html($s['icon'] !== '' ? $s['icon'] : 'bi-link-45deg') ?>
              <span class="sr-only"><?= e($s['title']) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php
    // Dane kontaktowe + vCard
    $contact = [];
    if (setting('email') !== '')    $contact[] = ['mailto:' . setting('email'), 'bi-envelope-fill', setting('email')];
    if (setting('phone') !== '')    $contact[] = ['tel:' . preg_replace('~[^0-9+]~', '', setting('phone')), 'bi-telephone-fill', setting('phone')];
    if (setting('location') !== '') $contact[] = ['', 'bi-geo-alt-fill', setting('location')];
    if (setting('website') !== '')  $contact[] = [setting('website'), 'bi-globe2', preg_replace('~^https?://~', '', setting('website'))];
    ?>
    <?php if ($contact || setting_bool('vcard_enabled', true)): ?>
      <ul class="hero__contact">
        <?php foreach ($contact as [$href, $ic, $label]): ?>
          <li>
            <?php if ($href !== ''): ?>
              <a href="<?= e($href) ?>"><?= icon_html($ic) ?><span><?= e($label) ?></span></a>
            <?php else: ?>
              <span><?= icon_html($ic) ?><span><?= e($label) ?></span></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php if (setting_bool('vcard_enabled', true)): ?>
          <li>
            <a class="btn btn--accent" href="<?= e(url('/vcard.vcf')) ?>">
              <?= icon_html('bi-person-vcard') ?>
              <span><?= e(setting('vcard_label', 'Zapisz kontakt (vCard)')) ?></span>
            </a>
          </li>
        <?php endif; ?>
      </ul>
    <?php endif; ?>
  </div>

  <?php if ($avatar !== ''): ?>
    <div class="hero__avatar hero__avatar--<?= e($shape) ?>">
      <img src="<?= e(upload_url($avatar)) ?>" alt="<?= e($name) ?>" width="480" height="480">
    </div>
  <?php endif; ?>
</header>
