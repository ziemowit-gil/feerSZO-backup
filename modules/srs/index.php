<?php
/**
 * modules/srs/index.php — System Rezerwacji Sal (SRS): lista zasobów.
 *
 * Powłoka jak w [[project_crm_views_wcag]]/panel/rodo.php: wolontariusz dostaje
 * powłokę panelu (header_panel.php), edytor/admin powłokę SZO. Treść w języku
 * wizualnym modułu „Tożsamość" (.tz-card/.tz-btn/.tz-badge, pv_ui.php).
 *
 * Niezalogowany gość dostaje samodzielny ekran powitalny (bez wspólnego
 * nagłówka SZO — ten zakłada zalogowanego użytkownika) z zachętą do
 * zalogowania się, zamiast twardego przekierowania na /auth/login.php.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/srs/logic/srs.php';

resources_migrate();

$user = current_user();
if (!$user) {
    require_once dirname(__DIR__, 2) . '/includes/branding.php';
    $_b = branding_load();
    $org_name = $_b['org_name'] ?: (defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : 'FEER');
    $login_url = APP_URL . '/auth/login.php?redirect=' . urlencode(APP_URL . '/modules/srs/');
    ?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>System Rezerwacji Sal — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
body{background:#F1F5F9;min-height:100vh}
.srs-topbar{background:#0f172a;color:#fff;padding:.65rem 1.25rem;display:flex;align-items:center;gap:.75rem}
.srs-topbar .brand{font-weight:800;font-size:1rem;flex-grow:1}
.srs-topbar .brand img{max-height:28px;margin-right:.5rem;vertical-align:middle}
.srs-topbar .btn-login{background:var(--c,#2563eb);color:#fff;border:none;border-radius:8px;padding:.45rem 1rem;font-weight:700;font-size:.85rem;text-decoration:none}
.srs-topbar .btn-login:hover{background:var(--c-dark,#1d4ed8);color:#fff}
.srs-wrap{max-width:680px;margin:0 auto;padding:3rem 1.25rem}
.srs-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:2.25rem;box-shadow:0 4px 24px rgba(15,23,42,.06)}
.srs-icon{width:56px;height:56px;border-radius:14px;background:var(--c-bg,#eff6ff);color:var(--c,#2563eb);display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin-bottom:1.25rem}
.srs-card h1{font-size:1.5rem;font-weight:800;color:#0f172a;margin:0 0 1rem;letter-spacing:-.01em}
.srs-card p{color:#334155;font-size:.95rem;line-height:1.65}
.srs-card ul{margin:0 0 1.25rem;padding-left:1.25rem}
.srs-card ul li{color:#334155;font-size:.9rem;line-height:1.7;margin-bottom:.3rem}
.srs-actions{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:1.5rem}
.srs-actions .btn{font-weight:700}
</style>
</head>
<body>
<div class="srs-topbar">
  <span class="brand">
    <?php if ($_b['logo_url']): ?><img src="<?= h($_b['logo_url']) ?>" alt=""><?php endif; ?>
    <?= h($org_name) ?> — System Rezerwacji Sal
  </span>
  <a href="<?= h($login_url) ?>" class="btn-login"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj się</a>
</div>
<div class="srs-wrap">
  <div class="srs-card">
    <div class="srs-icon"><i class="bi bi-door-open-fill" aria-hidden="true"></i></div>
    <h1>Witamy w Systemie Rezerwacji Sal</h1>
    <p>Zaloguj się poprzez pasek logowania umieszczony w górnej części ekranu.</p>
    <p>Po zalogowaniu otrzymasz dostęp do systemu. Jego funkcje to:</p>
    <ul>
      <li>Interaktywny kalendarz wyświetlający wszystkie zdarzenia w wybranym dniu / tygodniu.</li>
      <li>System filtrów umożliwiający ograniczenie wyświetlania zdarzeń do tych, którymi jesteśmy aktualnie zainteresowani.</li>
      <li>Składanie próśb o rezerwację sali we wskazanym terminie (tylko dla uprawnionych).</li>
    </ul>
    <p class="mb-0">Nowych użytkowników zachęcamy do lektury <a href="<?= APP_URL ?>/modules/srs/podrecznik.php">podręcznika</a>.</p>
    <div class="srs-actions">
      <a href="<?= h($login_url) ?>" class="btn btn-primary"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj się</a>
      <a href="<?= APP_URL ?>/modules/srs/podrecznik.php" class="btn btn-outline-secondary"><i class="bi bi-book me-1" aria-hidden="true"></i>Podręcznik</a>
    </div>
  </div>
</div>
</body>
</html><?php
    exit;
}

$PAGE_TITLE = 'Rezerwacja zasobów';
$cat_filter = (int)($_GET['cat'] ?? 0);
$categories = res_categories();
$resources  = res_list($cat_filter);

// Oczekujące rezerwacje bieżącego użytkownika
$my_pending = array_filter(
    res_reservations_for_user((int)$user['id']),
    fn($r) => !in_array($r['status'], ['odmowa','anulowana','rezerwacja'])
);

// Panel decyzji: admin (wszystkie) albo dysponent (własne zasoby)
$_res_admin     = is_admin() || can_write('resources');
$_res_dysponent = res_is_dysponent((int)$user['id']);

// Powłoka: panel wolontariusza dla samych wolontariuszy, powłoka SZO dla reszty
$_is_volunteer_only = is_viewer()
    && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

if ($_is_volunteer_only) {
    include dirname(__DIR__, 2) . '/panel/includes/header_panel.php';
} else {
    include dirname(__DIR__, 2) . '/includes/header.php';
}
require_once dirname(__DIR__, 2) . '/panel/includes/pv_ui.php';

$_actions = '<a href="' . APP_URL . '/modules/srs/kalendarz.php" class="tz-btn tz-btn--ghost">'
    . '<i class="bi bi-calendar3" aria-hidden="true"></i>Kalendarz</a> '
    . '<a href="' . APP_URL . '/modules/srs/my.php" class="tz-btn tz-btn--ghost">'
    . '<i class="bi bi-list-check" aria-hidden="true"></i>Moje rezerwacje'
    . ($my_pending ? '<span class="tz-badge tz-badge--warn">' . count($my_pending) . '</span>' : '')
    . '</a>';
if ($_res_admin || $_res_dysponent) {
    $_pending_dec = res_pending_count_for((int)$user['id'], $_res_admin);
    $_actions .= ' <a href="' . APP_URL . '/modules/srs/admin/index.php" class="tz-btn">'
        . '<i class="bi bi-shield-check" aria-hidden="true"></i>Decyzje'
        . ($_pending_dec ? '<span class="tz-badge tz-badge--warn">' . $_pending_dec . '</span>' : '')
        . '</a>';
}
$_actions .= ' <a href="' . APP_URL . '/modules/srs/podrecznik.php" class="tz-btn tz-btn--ghost" title="Podręcznik">'
    . '<i class="bi bi-book" aria-hidden="true"></i></a>';

pv_page_header('Rezerwacja zasobów', [
    'icon'    => 'bi-calendar-check',
    'sub'     => 'Sale, sprzęt i pozostałe zasoby organizacji — wybierz zasób i wskaż termin',
    'actions' => $_actions,
]);
?>

<div class="pv-wrap">

<?= flash_html() ?>

<!-- Filtr kategorii -->
<?php if ($categories): ?>
<div class="pv-stats-bar" role="group" aria-label="Filtr kategorii">
  <a href="?" class="pv-stat-pill<?= !$cat_filter ? ' pv-stat-pill--on' : '' ?>"
     <?= !$cat_filter ? 'aria-current="true"' : '' ?>>
    <i class="bi bi-grid" aria-hidden="true"></i>Wszystkie
  </a>
  <?php foreach ($categories as $cat): ?>
  <a href="?cat=<?= (int)$cat['id'] ?>"
     class="pv-stat-pill<?= $cat_filter === (int)$cat['id'] ? ' pv-stat-pill--on' : '' ?>"
     <?= $cat_filter === (int)$cat['id'] ? 'aria-current="true"' : '' ?>>
    <i class="bi <?= h($cat['icon']) ?>" aria-hidden="true"></i><?= h($cat['name']) ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$resources): ?>
<div class="tz-empty">
  <i class="bi bi-box-seam" aria-hidden="true"></i>
  <p class="fw-semibold mb-1">Brak zasobów do rezerwacji<?= $cat_filter ? ' w tej kategorii' : '' ?>.</p>
  <p class="small mb-0">
    <?php if ($cat_filter): ?><a href="?">Pokaż wszystkie kategorie</a>
    <?php else: ?>Zasoby dodaje administrator w zarządzaniu rezerwacjami.<?php endif; ?>
  </p>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($resources as $r): ?>
  <div class="col-sm-6 col-lg-4">
    <div class="tz-card h-100 mb-0 d-flex flex-column">
      <div class="tz-card__hd">
        <span class="tz-card__icon" aria-hidden="true"><i class="bi <?= h($r['cat_icon'] ?? 'bi-box') ?>"></i></span>
        <div class="flex-grow-1" style="min-width:0">
          <div class="tz-card__title"><?= h($r['name']) ?></div>
          <?php if ($r['cat_name'] ?? ''): ?><div class="tz-card__sub"><?= h($r['cat_name']) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="tz-card__bd d-flex flex-column gap-2 flex-grow-1">
        <div>
          <?php if ($r['requires_approval']): ?>
          <span class="tz-badge tz-badge--warn"><i class="bi bi-shield-check" aria-hidden="true"></i>Wymaga zgody</span>
          <?php else: ?>
          <span class="tz-badge tz-badge--ok"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i>Bezpośrednio</span>
          <?php endif; ?>
        </div>
        <?php if ($r['description']): ?>
        <p class="mb-0" style="font-size:.85rem;color:var(--tz-muted)"><?= nl2br(h($r['description'])) ?></p>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-3 mt-auto" style="font-size:.79rem;color:var(--tz-muted)">
          <?php if ($r['location']): ?>
          <span><i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= h($r['location']) ?></span>
          <?php endif; ?>
          <?php if ($r['capacity']): ?>
          <span><i class="bi bi-people me-1" aria-hidden="true"></i><?= (int)$r['capacity'] ?> os.</span>
          <?php endif; ?>
          <?php if ($r['dysponent_name']): ?>
          <span><i class="bi bi-person-check me-1" aria-hidden="true"></i><?= h($r['dysponent_name']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="tz-card__ft">
        <a href="<?= APP_URL ?>/modules/srs/reserve.php?id=<?= (int)$r['id'] ?>" class="tz-card__link">
          <i class="bi bi-calendar-plus" aria-hidden="true"></i>Zarezerwuj<span class="visually-hidden"> zasób <?= h($r['name']) ?></span>
        </a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /pv-wrap -->

<?php if ($_is_volunteer_only): ?>
<?php include dirname(__DIR__, 2) . '/panel/includes/footer_panel.php'; ?>
<?php else: ?>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
<?php endif; ?>
