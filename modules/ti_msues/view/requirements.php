<?php
/**
 * modules/ti_msues/view/requirements.php — wymagania MSUES wszystkich usług: braki do uzupełnienia per grupa, oznaczanie grup jako usług MSUES.
 * Zmienne: $rows ([course, e]), $candidates (grupy jeszcze nieoznaczone), $actor. Kontroler: MsuesController::requirements.
 */
require __DIR__ . '/_parts.php';
require __DIR__ . '/_reqList.php';
require_once dirname(__DIR__) . '/lib/MsuesCatalog.php';
$title = 'Wymagania usług MSUES';
$kierLabel = 'Certyfikacja MSUES';
include __DIR__ . '/_head.php';
$open = array_sum(array_map(fn($r) => $r['e']['missing'] + $r['e']['warn'], $rows));
?>
<p class="mb-2"><a href="<?= h(route('msues.index')) ?>">&larr; Certyfikacja MSUES</a></p>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-clipboard2-check text-primary" aria-hidden="true"></i> Wymagania usług MSUES</h1>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-sm btn-outline-primary" href="<?= h(route('msues.orgdocs')) ?>"><i class="bi bi-building"></i> Dokumenty instytucji</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= h(route('msues.rooms')) ?>"><i class="bi bi-door-open"></i> Normy sal</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= h(MsuesCatalog::url()) ?>" target="_blank" rel="noopener"><i class="bi bi-journal-richtext"></i> Katalog publiczny (<?= MsuesCatalog::count() ?>)</a>
  </div>
</div>
<p class="small text-muted">Usługi MSUES: <?= count($rows) ?> · pozycji do uzupełnienia: <b><?= (int)$open ?></b>. Każdy brak ma przycisk prowadzący do miejsca, w którym się go uzupełnia.</p>

<?php foreach ($rows as $r): $c = $r['course']; $e = $r['e']; ?>
<details class="card mb-2"<?= $e['missing'] ? ' open' : '' ?>>
  <summary class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2" style="cursor:pointer">
    <span><b><?= h(MsuesSzoData::courseLabel($c)) ?></b> <span class="text-muted small">— <?= h($c['instructor_name'] ?? 'bez prowadzącego') ?> · <?= h((string)$e['hours']) ?> h</span>
      <?= $e['missing'] ? '<span class="badge text-bg-danger ms-1">do uzupełnienia: ' . (int)$e['missing'] . '</span>' : '<span class="badge text-bg-success ms-1">komplet</span>' ?></span>
    <span class="d-flex gap-2 align-items-center"><span style="width:160px"><?= msuesReqSummary($e) ?></span>
      <a class="btn btn-sm btn-outline-primary" href="<?= h(route('msues.profile', ['id' => $c['id']])) ?>">Profil usługi</a></span>
  </summary>
  <?= msuesReqList($e, true) ?: '<div class="card-body small text-success">Wszystkie wymagania spełnione.</div>' ?>
</details>
<?php endforeach; if (!$rows): ?>
<div class="alert alert-info">Żadna grupa nie jest jeszcze oznaczona jako usługa MSUES. Oznacz ją poniżej albo w profilu usługi.</div>
<?php endif; ?>

<?php if ($candidates): ?>
<section class="card mt-3"><div class="card-header fw-semibold">Oznacz grupę jako usługę MSUES</div><div class="card-body">
  <form method="get" action="msues.php" class="d-flex gap-2 flex-wrap align-items-end" onsubmit="this._route.value='/uslugi/'+this.g.value+'/profil'">
    <input type="hidden" name="_route" value="">
    <div><label class="form-label small mb-0" for="rqG">Aktywna grupa</label>
      <select class="form-select form-select-sm" id="rqG" name="g" required><option value="">— wybierz —</option>
      <?php foreach ($candidates as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h(MsuesSzoData::courseLabel($s)) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-sm btn-primary">Otwórz profil usługi</button>
  </form></div></section>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
