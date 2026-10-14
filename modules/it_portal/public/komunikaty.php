<?php
/**
 * modules/it_portal/public/komunikaty.php — Portal Usług IT: komunikaty o awariach i pracach.
 * Adresy: <APP_URL>/uslugi-it/komunikaty[?s=N]  — karty w dwóch kolumnach, po 10 na stronę
 *         <APP_URL>/uslugi-it/komunikaty/{id}   — pojedynczy komunikat (link „więcej” ze Start)
 */
require_once __DIR__ . '/_layout.php';

$perPage = 10;
$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $n = itPortalNotice($id);
    if (!$n || !(int)$n['is_published']) itPortalNotFound('Ten komunikat nie jest już dostępny.');
    $items = [$n];
    $pages = 1; $page = 1;
} else {
    $total = (int)(db_one("SELECT COUNT(*) AS c FROM itp_notices WHERE is_published = 1")['c'] ?? 0);
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min($pages, max(1, (int)($_GET['s'] ?? 1)));
    $items = db_all("SELECT * FROM itp_notices WHERE is_published = 1 ORDER BY notice_date DESC, id DESC LIMIT ? OFFSET ?",
                    [$perPage, ($page - 1) * $perPage]);
}

itPortalPageStart($id ? $items[0]['title'] : 'Komunikaty', 'komunikaty', $id ? $items[0]['body_plain'] : '');
?>
<h1 class="itp-sr"><?= $id ? h($items[0]['title']) : 'Komunikaty' ?></h1>
<?php if (!$items): ?>
  <div class="itp-empty"><p>Brak komunikatów — wszystkie usługi działają normalnie.</p></div>
<?php else: ?>
  <div class="itp-cards"<?= $id ? ' style="grid-template-columns:1fr;max-width:820px"' : '' ?>>
    <?php foreach ($items as $n): ?>
      <article class="itp-card" id="k<?= (int)$n['id'] ?>">
        <div class="itp-note__head">
          <h2 class="itp-note__title"><?= itPortalStatusDot($n['status']) ?><?php if ($id): ?><?= h($n['title']) ?><?php else: ?><a href="<?= h(itPortalUrl('komunikaty/' . (int)$n['id'])) ?>"><?= h($n['title']) ?></a><?php endif; ?></h2>
          <time class="itp-note__date" datetime="<?= h($n['notice_date']) ?>"><?= h($n['notice_date']) ?></time>
        </div>
        <div class="itp-body"><?= itPortalSanitizeHtml($n['body_html']) ?></div>
        <?php if ($n['signature'] !== ''): ?><p class="itp-card__sign"><?= h($n['signature']) ?></p><?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if ($id): ?>
    <p style="margin-top:20px"><a href="<?= h(itPortalUrl('komunikaty')) ?>">&lt; Wszystkie komunikaty</a></p>
  <?php elseif ($pages > 1): ?>
    <nav class="itp-pager" aria-label="Strony komunikatów">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <?php if ($p === $page): ?><span aria-current="page"><?= $p ?></span>
        <?php else: ?><a href="<?= h(itPortalUrl('komunikaty') . ($p > 1 ? '?s=' . $p : '')) ?>"><span class="itp-sr">Strona </span><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>
<?php
itPortalPageEnd();
