<?php
/**
 * modules/it_portal/public/szukaj.php — Portal Usług IT: wyniki wyszukiwania.
 * Adres: <APP_URL>/uslugi-it/szukaj?q=… Szuka w artykułach, usługach i komunikatach (itPortalSearch).
 */
require_once __DIR__ . '/_layout.php';

$q   = trim((string)($_GET['q'] ?? ''));
$res = $q !== '' ? itPortalSearch($q) : ['articles' => [], 'categories' => [], 'notices' => []];
$n   = count($res['articles']) + count($res['categories']) + count($res['notices']);

itPortalPageStart($q !== '' ? 'Szukaj: ' . $q : 'Szukaj', '');
?>
<div class="itp-results">
  <h1 class="itp-h" style="font-size:1.3rem">
    <?php if ($q === ''): ?>Wpisz, czego szukasz
    <?php else: ?>Wyniki dla „<?= h($q) ?>”: <?= $n ?><?php endif; ?>
  </h1>
  <form class="itp-search" role="search" action="<?= h(itPortalUrl('szukaj')) ?>" method="get" style="margin-top:14px">
    <label class="itp-sr" for="itp-q">Szukaj na stronie</label>
    <input id="itp-q" type="search" name="q" value="<?= h($q) ?>" placeholder="Szukaj na stronie…" autocomplete="off">
    <button type="submit">Szukaj</button>
  </form>

  <?php if ($q !== '' && $n === 0): ?>
    <div class="itp-empty">
      <p>Nic nie znaleźliśmy. Spróbuj innego słowa albo przejrzyj <a href="<?= h(itPortalUrl('uslugi')) ?>">listę usług</a>.
      Jeśli potrzebujesz pomocy, <a href="<?= h(itPortalUrl('kontakt')) ?>">skontaktuj się z nami</a>.</p>
    </div>
  <?php endif; ?>

  <?php if ($res['articles']): ?>
    <h2>Instrukcje</h2>
    <ol>
      <?php foreach ($res['articles'] as $a): ?>
        <li>
          <span class="itp-crumb"><?= h($a['category_name']) ?></span><br>
          <a href="<?= h(itPortalUrl('uslugi/' . rawurlencode($a['category_slug']) . '/' . rawurlencode($a['slug']))) ?>"><?= h($a['title']) ?></a>
          <?php if ($a['body_plain'] !== ''): ?><p><?= h(itPortalExcerpt($a['body_plain'], 200)) ?></p><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <?php if ($res['categories']): ?>
    <h2>Usługi</h2>
    <ol>
      <?php foreach ($res['categories'] as $c): ?>
        <li><a href="<?= h(itPortalServiceUrl($c)) ?>"><?= h($c['name']) ?></a>
          <?php if ($c['intro_plain'] !== ''): ?><p><?= h(itPortalExcerpt($c['intro_plain'], 200)) ?></p><?php endif; ?></li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <?php if ($res['notices']): ?>
    <h2>Komunikaty</h2>
    <ol>
      <?php foreach ($res['notices'] as $k): ?>
        <li><span class="itp-crumb"><?= h($k['notice_date']) ?></span><br>
          <?= itPortalStatusDot($k['status']) ?><a href="<?= h(itPortalUrl('komunikaty/' . (int)$k['id'])) ?>"><?= h($k['title']) ?></a>
          <p><?= h(itPortalExcerpt($k['body_plain'], 200)) ?></p></li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</div>
<?php
itPortalPageEnd();
