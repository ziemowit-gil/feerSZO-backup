<?php
/**
 * modules/it_portal/public/usluga.php — Portal Usług IT: strona usługi i artykułu.
 *
 * Adresy: <APP_URL>/uslugi-it/uslugi/{usługa}            — wstęp usługi (intro_html)
 *         <APP_URL>/uslugi-it/uslugi/{usługa}/{artykuł}  — artykuł
 * Układ: po lewej artykuły usługi na osi, w środku treść, po prawej ikona usługi,
 * „Dostępność usługi” (grupy odbiorców) i „Powiązane tematy”.
 * Usługa bez wstępu → od razu pierwszy artykuł.
 * Krótki adres (2026-11-14, polecenie „dodaj krótki adres it.feer.org.pl do artykułów”): it.feer.org.pl/X przekierowuje
 * na szo.feer.org.pl/uslugi-it/X (Worker Cloudflare „przekierowanie-it”, repozytorium feer-dns), więc w prawej kolumnie
 * jest it.feer.org.pl/uslugi/{usługa}[/{artykuł}] z przyciskiem kopiowania.
 */
require_once __DIR__ . '/_layout.php';
if (!defined('ITP_SHORT_HOST')) define('ITP_SHORT_HOST', 'it.feer.org.pl');

$cat = itPortalCategoryBySlug((string)($_GET['k'] ?? ''));
if (!$cat) itPortalNotFound('Ta usługa nie istnieje albo została ukryta.');

$articles = itPortalHideLocked(itPortalArticles((int)$cat['id']));   // osoba bez roli IT nie widzi tytułów artykułów tylko dla IT
$artSlug  = (string)($_GET['a'] ?? '');
$art      = null;
if ($artSlug !== '') {
    $art = itPortalArticleBySlug((int)$cat['id'], $artSlug);
    if (!$art) itPortalNotFound('Ten artykuł nie istnieje albo został wycofany.');
} elseif (!itPortalBlocksHas($cat, 'intro_blocks', 'intro_html') && $articles) {
    header('Location: ' . itPortalServiceUrl($cat, $articles[0]), true, 302);
    exit;
}
if ($art) itPortalArticleTouch((int)$art['id']);
// Artykuł tylko dla IT (oznaczony albo w usłudze z samą grupą IT): osoba bez roli IT nie ma go w menu ani w „Zobacz też” (2026-11-15),
// a po wejściu wprost na adres widzi tytuł i komunikat zamiast treści.
$itLocked = $art && itPortalArticleLocked($art);

$audiences = itPortalAudiences(true);
$availIds  = itPortalIds($cat['audiences']);
$related   = itPortalArticlesByIds(itPortalIds($art ? $art['related'] : $cat['related']));
$shortPath = 'uslugi/' . rawurlencode($cat['slug']) . ($art ? '/' . rawurlencode($art['slug']) : '');
$shortUrl  = 'https://' . ITP_SHORT_HOST . '/' . $shortPath;

itPortalPageStart($art ? $art['title'] : $cat['name'], 'uslugi', $itLocked ? '' : ($art ? $art['body_plain'] : $cat['intro_plain']));
?>
<div class="itp-service">
  <nav class="itp-side" aria-label="Artykuły: <?= h($cat['name']) ?>">
    <p class="itp-side__title"><a href="<?= h(itPortalUrl('uslugi/' . rawurlencode($cat['slug']))) ?>"<?= !$art ? ' aria-current="page"' : '' ?>><?= h($cat['name']) ?></a></p>
    <?php foreach (itPortalArticleGroups($articles) as $gi => $grp):
      $hasCur = $art && in_array((int)$art['id'], array_map('intval', array_column($grp['items'], 'id')), true);
      ob_start(); ?>
      <ul class="itp-axis">
        <?php foreach ($grp['items'] as $a): $cur = $art && (int)$a['id'] === (int)$art['id']; ?>
          <li<?= $cur ? ' aria-current="page"' : '' ?>><a href="<?= h(itPortalServiceUrl($cat, $a)) ?>"<?= $cur ? ' aria-current="page"' : '' ?>><?= h($a['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php $list = ob_get_clean();
      if ($grp['group'] === ''): echo $list; else: ?>
      <details class="itp-group"<?= $hasCur || (!$art && $gi === 0) ? ' open' : '' ?>>
        <summary><span class="itp-group__t"><?= h($grp['group']) ?></span> <span class="itp-group__n">(<?= count($grp['items']) ?>)</span></summary>
        <?= $list ?>
      </details>
    <?php endif; endforeach; ?>
  </nav>

  <article class="itp-content">
    <?php if ($art): ?>
      <h1><?= h($art['title']) ?></h1>
      <?php if ($itLocked): $viewer = itPortalViewer(); ?>
      <div class="itp-body">
        <aside class="itp-callout itp-callout--info" role="note">
          <strong class="itp-callout__t">Artykuł dla działu IT</strong>
          <div>
          <?php if ($viewer === null): ?>
            <p>Ten artykuł mogą czytać tylko osoby z rolą IT w SZO. Jeśli ją masz, <a href="<?= h(APP_URL . '/auth/login.php?redirect=' . urlencode((string)($_SERVER['REQUEST_URI'] ?? ''))) ?>">zaloguj się do SZO</a> i wróć na tę stronę.</p>
          <?php else: ?>
            <p>Ten artykuł mogą czytać tylko osoby z rolą IT w SZO, a Twoje konto jej nie ma.</p>
          <?php endif; ?>
            <p>Potrzebujesz pomocy w tej sprawie? Napisz do <a href="<?= h(APP_URL . '/helpdesk/new.php') ?>">Helpdesku IT</a>.
              Więcej o tym, kto czyta takie artykuły, w artykule <a href="<?= h(itPortalUrl('uslugi/koordynatorzy-it/rola-it')) ?>">Rola IT</a>.</p>
          </div>
        </aside>
      </div>
      <?php else: ?>
      <div class="itp-body"><?= itPortalRenderBlocks($art, 'blocks', 'body_html', '<p>Treść w przygotowaniu.</p>') ?></div>
      <?php endif; ?>
    <?php else: ?>
      <h1 class="itp-sr"><?= h($cat['name']) ?></h1>
      <div class="itp-body"><?= itPortalRenderBlocks($cat, 'intro_blocks', 'intro_html', '<p>Instrukcje do tej usługi są w przygotowaniu.</p>') ?></div>
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
    <section class="itp-short" aria-labelledby="itp-short-h">
      <h2 class="itp-h" id="itp-short-h">Krótki adres</h2>
      <p class="itp-short__url"><a href="<?= h($shortUrl) ?>"><?= h(ITP_SHORT_HOST . '/' . $shortPath) ?></a></p>
      <button type="button" class="itp-short__copy" data-copy="<?= h($shortUrl) ?>" hidden>Kopiuj adres</button>
      <span class="itp-sr" role="status" aria-live="polite" id="itp-short-st"></span>
    </section>
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
<script>
(function () {
  var b = document.querySelector('.itp-short__copy'), st = document.getElementById('itp-short-st');
  if (!b || !navigator.clipboard || !window.isSecureContext) return;   // bez schowka zostaje sam link do zaznaczenia
  b.hidden = false;
  b.addEventListener('click', function () {
    navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () {
      b.textContent = 'Skopiowano';
      st.textContent = 'Krótki adres skopiowany do schowka.';
      setTimeout(function () { b.textContent = 'Kopiuj adres'; }, 2500);
    }, function () {
      st.textContent = 'Nie udało się skopiować. Zaznacz adres i skopiuj go ręcznie.';
    });
  });
})();
</script>
<?php
itPortalPageEnd();
