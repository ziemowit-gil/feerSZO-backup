<?php
/**
 * Publiczna tablica ogłoszeń wolontariackich — bez logowania, bez menu.
 */
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/rekrutacja.php';

// Aktywne ogłoszenia
$rm     = new VolunteerModuleManager();
$offers = $rm->listOffers('active', '', 50, 0);

$org_logo = org_setting('org_logo');
$logo_url = $org_logo ? APP_URL . '/assets/logo/' . h($org_logo) : null;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Wolontariat — <?= h(ORG_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background: #f1f5f9; min-height: 100vh; }
  .pub-topbar {
    background: #1e293b; color: #e2e8f0; padding: .65rem 1.5rem;
    display: flex; align-items: center; gap: .75rem; font-size: .9rem;
    position: sticky; top: 0; z-index: 100;
  }
  .pub-topbar .brand { color: #fff; font-weight: 700; font-size: 1rem; }
  .pub-hero {
    background: linear-gradient(135deg, #0f2044, #1d4ed8);
    color: #fff; text-align: center; padding: 3rem 1.5rem;
  }
  .pub-hero h1 { font-size: clamp(1.5rem, 4vw, 2.2rem); font-weight: 800; }
  .pub-hero p { opacity: .75; max-width: 520px; margin: .5rem auto 0; font-size: .95rem; }
  .pub-wrap { max-width: 900px; margin: 0 auto; padding: 2rem 1rem 3rem; }
  .offer-card {
    background: #fff; border-radius: 1rem; box-shadow: 0 1px 4px rgba(0,0,0,.08);
    padding: 1.4rem 1.5rem; margin-bottom: 1.25rem; transition: box-shadow .2s;
    text-decoration: none; display: block; color: inherit;
  }
  .offer-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.12); color: inherit; }
  .offer-card .title { font-size: 1.1rem; font-weight: 700; color: #0f172a; margin-bottom: .3rem; }
  .offer-card .desc { color: #475569; font-size: .9rem; line-height: 1.5; }
  .offer-card .meta { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .85rem; align-items: center; }
  .chip { display: inline-flex; align-items: center; gap: .3rem;
          background: #f1f5f9; border-radius: 999px; padding: .2rem .65rem; font-size: .78rem; color: #64748b; }
  .chip-green { background: #dcfce7; color: #166534; }
  .btn-apply { background: #1d4ed8; color: #fff; border: none; border-radius: .5rem;
               padding: .45rem 1.1rem; font-size: .875rem; font-weight: 600;
               text-decoration: none; margin-left: auto; white-space: nowrap; }
  .btn-apply:hover { background: #1e40af; color: #fff; }
  .empty-state { text-align: center; padding: 4rem 1rem; color: #94a3b8; }
</style>
</head>
<body>

<!-- Topbar -->
<div class="pub-topbar">
  <?php if ($logo_url): ?>
  <img src="<?= $logo_url ?>" alt="" style="height:24px;width:auto;object-fit:contain">
  <?php endif; ?>
  <span class="brand"><?= h(ORG_NAME) ?></span>
</div>

<!-- Hero -->
<div class="pub-hero">
  <div style="font-size:2.2rem;margin-bottom:.5rem">🤝</div>
  <h1>Zostań wolontariuszem</h1>
  <p>Dołącz do naszego zespołu wolontariuszy. Poniżej znajdziesz aktualne ogłoszenia rekrutacyjne.</p>
</div>

<div class="pub-wrap">

<?php if (!$offers): ?>
<div class="empty-state">
  <i class="bi bi-inbox" style="font-size:3rem;display:block;margin-bottom:.75rem"></i>
  <div class="fw-semibold">Brak aktywnych ogłoszeń</div>
  <div class="small mt-1">Sprawdź ponownie wkrótce.</div>
</div>

<?php else: ?>
<div class="mb-3 text-muted small">
  <i class="bi bi-megaphone me-1"></i>
  <?= count($offers) ?> <?= count($offers) === 1 ? 'ogłoszenie' : (count($offers) < 5 ? 'ogłoszenia' : 'ogłoszeń') ?>
</div>

<?php foreach ($offers as $o):
  $desc = strip_tags(mb_substr($o['content'] ?? '', 0, 200));
  if (mb_strlen($o['content'] ?? '') > 200) $desc .= '…';
?>
<a href="apply.php?id=<?= $o['id'] ?>" class="offer-card">
  <div class="d-flex align-items-start gap-3">
    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
         style="width:44px;height:44px;background:#dbeafe">
      <i class="bi bi-megaphone-fill text-primary"></i>
    </div>
    <div class="flex-grow-1 min-w-0">
      <div class="title"><?= h($o['title']) ?></div>
      <?php if ($desc): ?>
      <div class="desc"><?= h($desc) ?></div>
      <?php endif; ?>
      <div class="meta">
        <?php if ($o['avail_from'] || $o['avail_to']): ?>
        <span class="chip">
          <i class="bi bi-calendar3"></i>
          <?= $o['avail_from'] ? date_pl($o['avail_from']) : '?' ?>
          <?= $o['avail_to'] ? ' — ' . date_pl($o['avail_to']) : '' ?>
        </span>
        <?php endif; ?>
        <?php if ($o['max_candidates']): ?>
        <span class="chip"><i class="bi bi-people"></i>Limit: <?= (int)$o['max_candidates'] ?></span>
        <?php endif; ?>
        <?php if ((int)$o['app_count'] > 0): ?>
        <span class="chip"><i class="bi bi-person-check"></i><?= (int)$o['app_count'] ?> zgłoszeń</span>
        <?php endif; ?>
        <span class="chip chip-green"><i class="bi bi-circle-fill" style="font-size:.45rem"></i>Rekrutacja otwarta</span>
        <a href="apply.php?id=<?= $o['id'] ?>" class="btn-apply" onclick="event.stopPropagation()">
          Aplikuj <i class="bi bi-arrow-right ms-1"></i>
        </a>
      </div>
    </div>
  </div>
</a>
<?php endforeach; ?>
<?php endif; ?>

</div><!-- /pub-wrap -->

<footer style="text-align:center;padding:1.5rem;color:#94a3b8;font-size:.8rem;border-top:1px solid #e2e8f0;background:#fff">
  <?= h(ORG_NAME) ?> · Rekrutacja wolontariuszy
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
