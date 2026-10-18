<?php
/**
 * modules/it_portal/public/index.php — Portal Usług IT: Start.
 * Adres: <APP_URL>/uslugi-it/ (reguła w .htaccess). Wyszukiwarka, „Popularne instrukcje”,
 * baner ostrzegawczy (ustawienia), ostatnie komunikaty z plakietką statusu i linkiem do wszystkich.
 */
require_once __DIR__ . '/_layout.php';

$popular = itPortalPopular(12);
$notices = itPortalNotices(3);
$auds    = itPortalAudiences(true);
$banner  = itPortalSetting('banner_on') === '1' ? itPortalSanitizeHtml(itPortalSetting('banner_html')) : '';

itPortalPageStart('', 'start', 'Instrukcje, usługi informatyczne i komunikaty o awariach — ' . ORG_NAME);
?>
<div class="itp-kicker"><?= h(ORG_NAME) ?></div>
<h1 class="itp-title"><?= h(itPortalSetting('title')) ?></h1>
<p class="itp-lead">Instrukcje, usługi informatyczne i komunikaty o przerwach w działaniu.</p>
<form class="itp-search" role="search" action="<?= h(itPortalUrl('szukaj')) ?>" method="get">
  <label class="itp-sr" for="itp-q">Szukaj na stronie</label>
  <input id="itp-q" type="search" name="q" placeholder="Szukaj na stronie…" autocomplete="off">
  <button type="submit">Szukaj</button>
</form>
<?php if ($auds): ?>
  <nav class="itp-groups" aria-label="Usługi dla grupy">
    <span class="itp-groups__label" aria-hidden="true">Usługi dla:</span>
    <ul>
      <?php foreach ($auds as $a): ?>
        <li><a href="<?= h(itPortalAudienceUrl($a)) ?>"><?= itPortalAudienceIcon($a['icon']) ?><?= h($a['name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </nav>
<?php endif; ?>

<div class="itp-start">
  <section aria-labelledby="itp-pop-h">
    <h2 class="itp-h" id="itp-pop-h">Popularne instrukcje</h2>
    <div class="itp-panel itp-panel--tall">
      <?php if ($popular): ?>
        <ul class="itp-circles">
          <?php foreach ($popular as $a): ?>
            <li><a href="<?= h(itPortalUrl('uslugi/' . rawurlencode($a['category_slug']) . '/' . rawurlencode($a['slug']))) ?>"><?= h($a['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p>Instrukcje pojawią się wkrótce. Zobacz <a href="<?= h(itPortalUrl('uslugi')) ?>">listę usług</a>.</p>
      <?php endif; ?>
    </div>
  </section>

  <div>
    <?php if ($banner !== ''): ?>
      <div class="itp-alert" role="note"><?= $banner ?></div>
    <?php endif; ?>
    <section aria-labelledby="itp-not-h">
      <h2 class="itp-h" id="itp-not-h">Komunikaty</h2>
      <div class="itp-notes">
        <?php if (!$notices): ?>
          <p class="itp-note" style="margin:0">Brak bieżących komunikatów — wszystkie usługi działają normalnie.</p>
        <?php endif; ?>
        <?php foreach ($notices as $n): ?>
          <article class="itp-note">
            <div class="itp-note__head">
              <h3 class="itp-note__title"><?= itPortalStatusDot($n['status']) ?><?= h($n['title']) ?></h3>
              <time class="itp-note__date" datetime="<?= h($n['notice_date']) ?>"><?= h($n['notice_date']) ?></time>
            </div>
            <p class="itp-note__lead"><?= h(itPortalExcerpt($n['body_plain'], 330)) ?></p>
            <p class="itp-note__more"><a href="<?= h(itPortalUrl('komunikaty/' . (int)$n['id'])) ?>">Czytaj dalej<span class="itp-sr"> o: <?= h($n['title']) ?></span></a></p>
          </article>
        <?php endforeach; ?>
      </div>
      <p class="itp-all"><a href="<?= h(itPortalUrl('komunikaty')) ?>">Wszystkie komunikaty →</a></p>
    </section>
  </div>
</div>
<?php
itPortalPageEnd();
