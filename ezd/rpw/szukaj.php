<?php
/**
 * Szukaj w Rejestrze Przychodzących (RPW) — wersja zawężona wyłącznie do
 * dziennika podawczego (bez koszulek/pism/segregatorów), przeznaczona m.in.
 * dla roli 'ezd_biuro' ograniczonej do RPW. Odpowiednik ezd/szukaj.php, ale
 * przeszukujący tylko ezd_rpw.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$q   = trim($_GET['q'] ?? '');
$rok = trim($_GET['rok'] ?? '');
$PAGE_TITLE = 'Szukaj w RPW' . ($q !== '' ? ' — ' . $q : '');

$rows = (mb_strlen($q) >= 2) ? ezd_rpw_all(['q' => $q, 'rok' => $rok]) : [];
$lata = db_all("SELECT DISTINCT rok FROM ezd_rpw ORDER BY rok DESC");

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.rpw-srch-row{display:flex;align-items:center;gap:.75rem;padding:.55rem 1rem;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;font-size:.83rem;}
.rpw-srch-row:last-child{border-bottom:none;}
.rpw-srch-row:hover{background:#f8fafc;}
.rpw-srch-sign{font-family:monospace;font-size:.74rem;font-weight:700;color:#2563eb;white-space:nowrap;flex-shrink:0;}
.rpw-srch-meta{font-size:.71rem;color:#94a3b8;white-space:nowrap;flex-shrink:0;}
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/rpw/index.php">Dziennik podawczy</a></li>
  <li class="breadcrumb-item active">Szukaj</li>
</ol></nav>

<h4 class="fw-bold mb-3"><i class="bi bi-search text-primary me-2"></i>Szukaj w Rejestrze Przychodzących</h4>

<form method="get" role="search" aria-label="Szukaj w RPW" class="mb-4">
  <div class="d-flex gap-2 flex-wrap align-items-end">
    <div class="flex-grow-1" style="min-width:280px;max-width:680px">
      <label class="form-label fw-semibold mb-1" style="font-size:.76rem">Szukaj</label>
      <div class="input-group">
        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
        <input type="search" name="q" class="form-control" value="<?= h($q) ?>"
               minlength="2" autofocus placeholder="Opis, nadawca, znak pisma…" autocomplete="off">
        <button type="submit" class="btn btn-primary px-4">Szukaj</button>
      </div>
    </div>
    <div style="min-width:120px">
      <label class="form-label fw-semibold mb-1" style="font-size:.76rem">Rok</label>
      <select name="rok" class="form-select">
        <option value="">Wszystkie</option>
        <?php foreach ($lata as $l): ?>
        <option value="<?= h((string)$l['rok']) ?>" <?= $rok === (string)$l['rok'] ? 'selected' : '' ?>><?= h($l['rok']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</form>

<?php if ($q !== '' && mb_strlen($q) < 2): ?>
<div class="alert alert-warning py-2">Wpisz co najmniej 2 znaki.</div>
<?php elseif ($q !== ''): ?>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <span class="text-muted" style="font-size:.85rem">Wyników dla „<strong><?= h($q) ?></strong>": <strong><?= count($rows) ?></strong></span>
</div>

<div class="card shadow-sm">
  <?php if (!$rows): ?>
  <div class="card-body text-center text-muted py-5">
    <i class="bi bi-search" style="font-size:3rem;display:block;margin-bottom:.5rem;opacity:.2"></i>
    Nic nie znaleziono. Spróbuj innego fragmentu lub zmień rok.
  </div>
  <?php else: ?>
  <div>
  <?php foreach ($rows as $r): ?>
  <a href="<?= APP_URL ?>/ezd/rpw/view.php?id=<?= (int)$r['id'] ?>" class="rpw-srch-row">
    <span class="rpw-srch-sign"><?= h(ezd_rpw_label($r)) ?></span>
    <span class="flex-grow-1 fw-semibold text-truncate"><?= h($r['opis'] ?: ($r['nadawca'] ?: '—')) ?></span>
    <?php if ($r['nadawca']): ?><span class="rpw-srch-meta text-truncate" style="max-width:200px"><?= h($r['nadawca']) ?></span><?php endif; ?>
    <span class="rpw-srch-meta"><?= h(date_pl($r['data_wplywu'])) ?></span>
    <?= ezd_rpw_status_badge($r['status']) ?>
    <i class="bi bi-chevron-right text-muted" style="font-size:.72rem"></i>
  </a>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
