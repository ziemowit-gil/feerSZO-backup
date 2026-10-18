<?php
/**
 * modules/it_portal/public/usluga.php — Portal Usług IT: strona usługi i artykułu.
 *
 * Adresy: <APP_URL>/uslugi-it/uslugi/{usługa}            — wstęp usługi (intro_html)
 *         <APP_URL>/uslugi-it/uslugi/{usługa}/{artykuł}  — artykuł
 * Układ: po lewej artykuły usługi na osi, w środku treść, po prawej ikona usługi,
 * „Dostępność usługi” (grupy odbiorców) i „Powiązane tematy”.
 * Usługa bez wstępu → od razu pierwszy artykuł.
 */
require_once __DIR__ . '/_layout.php';

$cat = itPortalCategoryBySlug((string)($_GET['k'] ?? ''));
if (!$cat) itPortalNotFound('Ta usługa nie istnieje albo została ukryta.');

$articles = itPortalArticles((int)$cat['id']);
$artSlug  = (string)($_GET['a'] ?? '');
$art      = null;
if ($artSlug !== '') {
    $art = itPortalArticleBySlug((int)$cat['id'], $artSlug);
    if (!$art) itPortalNotFound('Ten artykuł nie istnieje albo został wycofany.');
} elseif (trim($cat['intro_html']) === '' && $articles) {
    header('Location: ' . itPortalServiceUrl($cat, $articles[0]), true, 302);
    exit;
}
if ($art) itPortalArticleTouch((int)$art['id']);

$audiences = itPortalAudiences(true);
$availIds  = itPortalIds($cat['audiences']);
$related   = itPortalArticlesByIds(itPortalIds($art ? $art['related'] : $cat['related']));

itPortalPageStart($art ? $art['title'] : $cat['name'], 'uslugi', $art ? $art['body_plain'] : $cat['intro_plain']);
?>
<div class="itp-service">
  <nav class="itp-side" aria-label="Artykuły: <?= h($cat['name']) ?>">
    <p class="itp-side__title"><a href="<?= h(itPortalUrl('uslugi/' . rawurlencode($cat['slug']))) ?>"<?= !$art ? ' aria-current="page"' : '' ?>><?= h($cat['name']) ?></a></p>
    <?php if ($articles): ?>
      <ul class="itp-axis">
        <?php foreach ($articles as $a): $cur = $art && (int)$a['id'] === (int)$art['id']; ?>
          <li<?= $cur ? ' aria-current="page"' : '' ?>><a href="<?= h(itPortalServiceUrl($cat, $a)) ?>"<?= $cur ? ' aria-current="page"' : '' ?>><?= h($a['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </nav>

  <article class="itp-content">
    <?php if ($art): ?>
      <h1><?= h($art['title']) ?></h1>
      <div class="itp-body"><?= itPortalSanitizeHtml($art['body_html']) ?: '<p>Treść w przygotowaniu.</p>' ?></div>
    <?php else: ?>
      <h1 class="itp-sr"><?= h($cat['name']) ?></h1>
      <div class="itp-body"><?= itPortalSanitizeHtml($cat['intro_html']) ?: '<p>Instrukcje do tej usługi są w przygotowaniu.</p>' ?></div>
    <?php endif; ?>
  </article>

  <aside class="itp-aside" aria-label="Informacje o usłudze">
    <div class="itp-pic"><?= itPortalIcon($cat['icon']) ?></div>
    <?php if ($audiences): ?>
      <section class="itp-avail" aria-labelledby="itp-av-h">
        <h2 class="itp-h" id="itp-av-h">Dla kogo</h2>
        <ul class="itp-avail__list">
          <?php foreach ($audiences as $au): $on = in_array((int)$au['id'], $availIds, true); ?>
            <li class="<?= $on ? 'is-on' : 'is-off' ?>"><?= itPortalAudienceIcon($au['icon']) ?><?php if ($on): ?><a href="<?= h(itPortalAudienceUrl($au)) ?>" title="Wszystkie usługi dla: <?= h($au['name']) ?>"><?= h($au['name']) ?></a><?php else: ?><span><?= h($au['name']) ?></span><?php endif; ?><span class="itp-sr">: <?= $on ? 'dostępna' : 'niedostępna' ?></span></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
    <?php if ($related): ?>
      <section class="itp-related" aria-labelledby="itp-rel-h">
        <h2 class="itp-h" id="itp-rel-h">Zobacz też</h2>
        <ul class="itp-circles">
          <?php foreach ($related as $r): ?>
            <li><a href="<?= h(itPortalUrl('uslugi/' . rawurlencode($r['category_slug']) . '/' . rawurlencode($r['slug']))) ?>"><?= h($r['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  </aside>
</div>
<?php
itPortalPageEnd();
