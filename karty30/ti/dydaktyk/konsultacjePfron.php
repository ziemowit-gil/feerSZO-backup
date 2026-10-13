<?php
/**
 * karty30/ti/dydaktyk/konsultacjePfron.php — umowy PFRON (Aktywny Samorząd) w trybie Konsultacje.
 * Lista umów (filtry: beneficjent/numer, status, kończące się), karta umowy (?id=): godziny limit/wykorzystane/zostało
 * z podziałem zdalnie–stacjonarnie, plan godzin, terminy rozliczane z umowy ze statusem rozliczenia PFRON,
 * nowa umowa (?client_id=) i edycja (?id=&edytuj=1). Dokumenty, podpis i portal beneficjenta — karty30/pfron/* (linki).
 * Logika: modules/ti_consult_pfron/logic/consultPfron.php. Edycja i usuwanie — kadra.
 */
require __DIR__ . '/_rezerwacje_nav.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consult_pfron/logic/consultPfron.php';
if (!k30_pfron_enabled()) { flash_set('warning', 'Moduł PFRON jest wyłączony w ustawieniach.'); header('Location: rezerwacje.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
$c = $id ? TiConsultPfron::get($id) : null;
if ($id && !$c) { flash_set('danger', 'Nie znaleziono umowy.'); header('Location: konsultacjePfron.php'); exit; }
$newClient = (int)($_GET['client_id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'save' && $staff) {
        $r = TiConsultPfron::save($_POST, $c ? $id : null, $uid);
        if ($r['ok']) { flash_set('success', $c ? 'Umowa PFRON zapisana.' : 'Umowa PFRON dodana.'); header('Location: konsultacjePfron.php?id=' . $r['id']); exit; }
        $errors = $r['errors'];
    } elseif ($op === 'booking_status' && $c) {
        TiConsultPfron::setBookingStatus((int)($_POST['schedule_id'] ?? 0), (string)($_POST['pfron_status'] ?? ''));
        flash_set('success', 'Status rozliczenia terminu zmieniony.'); header('Location: konsultacjePfron.php?id=' . $id); exit;
    } elseif ($op === 'delete' && $c && $staff) {
        $why = TiConsultPfron::delete($id);
        if ($why === '') { flash_set('success', 'Umowa usunięta.'); header('Location: konsultacjePfron.php'); exit; }
        flash_set('danger', $why); header('Location: konsultacjePfron.php?id=' . $id); exit;
    }
}
$edit = $staff && (($c && !empty($_GET['edytuj'])) || (!$c && $newClient));
$v = fn(string $k, $d = '') => $_POST[$k] ?? ($c[$k] ?? $d);
$clients = $edit && !$c ? db_all("SELECT id, name FROM k30_clients WHERE " . ti_test_client_sql('id') . " ORDER BY name") : [];
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => (string)($_GET['status'] ?? ''), 'days' => (int)($_GET['days'] ?? 0)];
$rows = !$c && !$edit ? TiConsultPfron::list($f) : [];
$num = fn($x) => rtrim(rtrim(number_format((float)$x, 2, ',', ''), '0'), ',');

$RZ_TITLE = 'Umowy PFRON'; $RZ_CUR = 'konsultacjePfron.php';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
<?= flash_html() ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($edit): ?>
<h1 class="h4 fw-bold"><i class="bi bi-building-fill-check text-primary me-2" aria-hidden="true"></i><?= $c ? 'Edycja umowy ' . h($c['contract_number']) : 'Nowa umowa PFRON' ?></h1>
<form method="post" class="card border-0 shadow-sm" style="max-width:820px"><div class="card-body">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="save">
  <?php if ($c): ?><input type="hidden" name="client_id" value="<?= (int)$c['client_id'] ?>"><p class="mb-3">Beneficjent: <strong><?= h($c['client_name']) ?></strong></p>
  <?php else: ?><div class="mb-3"><label class="form-label fw-semibold" for="pf-cl">Beneficjent</label><select class="form-select" id="pf-cl" name="client_id" required><option value="">— wybierz —</option><?php foreach ($clients as $cl): ?><option value="<?= (int)$cl['id'] ?>"<?= (int)$v('client_id', $newClient) === (int)$cl['id'] ? ' selected' : '' ?>><?= h($cl['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold" for="pf-no">Numer umowy <span class="text-danger" aria-hidden="true">*</span></label><input class="form-control" id="pf-no" name="contract_number" required maxlength="100" value="<?= h((string)$v('contract_number')) ?>"></div>
    <div class="col-md-3"><label class="form-label" for="pf-lim">Limit godzin</label><input class="form-control" type="number" step="0.5" min="0" id="pf-lim" name="hours_limit" value="<?= h((string)$v('hours_limit', '0')) ?>"></div>
    <div class="col-md-3"><label class="form-label" for="pf-st">Status</label><select class="form-select" id="pf-st" name="status"><?php foreach (K30_PFRON_CONTRACT_STATUSES as $k => $s): ?><option value="<?= h($k) ?>"<?= (string)$v('status', 'active') === $k ? ' selected' : '' ?>><?= h($s['label']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="pf-vf">Ważna od</label><input class="form-control" type="date" id="pf-vf" name="valid_from" value="<?= h((string)$v('valid_from')) ?>"></div>
    <div class="col-md-3"><label class="form-label" for="pf-vt">Ważna do</label><input class="form-control" type="date" id="pf-vt" name="valid_to" value="<?= h((string)$v('valid_to')) ?>"></div>
    <div class="col-md-6"><label class="form-label" for="pf-n">Uwagi</label><input class="form-control" id="pf-n" name="notes" value="<?= h((string)$v('notes')) ?>"></div>
    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="pf-pl" name="hours_plan_enabled" value="1"<?= $v('hours_plan_enabled') ? ' checked' : '' ?>><label class="form-check-label" for="pf-pl">Plan godzin: podział na zdalne i stacjonarne</label></div></div>
    <div class="col-md-3" id="pf-pl-r"><label class="form-label" for="pf-pr">Planowane zdalnie (h)</label><input class="form-control" type="number" step="0.5" min="0" id="pf-pr" name="planned_hours_remote" value="<?= h((string)$v('planned_hours_remote', '0')) ?>"></div>
    <div class="col-md-3" id="pf-pl-o"><label class="form-label" for="pf-po">Planowane stacjonarnie (h)</label><input class="form-control" type="number" step="0.5" min="0" id="pf-po" name="planned_hours_onsite" value="<?= h((string)$v('planned_hours_onsite', '0')) ?>"></div>
  </div>
  <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button> <a class="btn btn-outline-secondary" href="<?= $c ? 'konsultacjePfron.php?id=' . $id : 'konsultacjePfron.php' ?>">Anuluj</a></div>
</div></form>
<script>(function(){var c=document.getElementById('pf-pl');function s(){document.getElementById('pf-pl-r').hidden=document.getElementById('pf-pl-o').hidden=!c.checked;}c.addEventListener('change',s);s();})();</script>

<?php elseif ($c): $H = TiConsultPfron::hours($c); $st = K30_PFRON_CONTRACT_STATUSES[$c['status']] ?? ['label' => $c['status'], 'color' => '#555', 'bg' => '#eee']; $bk = TiConsultPfron::bookings($id); ?>
<nav aria-label="Okruszki" class="small mb-2"><a href="konsultacjePfron.php">Umowy PFRON</a> › <?= h($c['contract_number']) ?></nav>
<div class="d-flex flex-wrap align-items-start gap-2 mb-3">
  <div><h1 class="h4 fw-bold mb-0">Umowa <?= h($c['contract_number']) ?></h1>
    <div class="small">Beneficjent: <a href="konsultacjeKlient.php?id=<?= (int)$c['client_id'] ?>"><?= h($c['client_name']) ?></a><?= $c['valid_from'] || $c['valid_to'] ? ' · ważna ' . h($c['valid_from'] ? date('d.m.Y', strtotime((string)$c['valid_from'])) : '…') . ' – ' . h($c['valid_to'] ? date('d.m.Y', strtotime((string)$c['valid_to'])) : '…') : '' ?></div>
    <span class="badge mt-1" style="background:<?= h($st['bg']) ?>;color:#111827;border:1px solid <?= h($st['color']) ?>"><?= h($st['label']) ?></span><?= $c['doc_number'] ? ' <span class="badge text-bg-light border mt-1">dokument ' . h($c['doc_number']) . '</span>' : '' ?><?= $c['signed_at'] ? ' <span class="badge text-bg-success mt-1">podpisana</span>' : '' ?></div>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-primary" href="rezerwacja.php?nowa=1&amp;client_id=<?= (int)$c['client_id'] ?>&amp;billing_type=pfron&amp;pfron_contract_id=<?= $id ?>"><i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>Termin z PFRON</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h(rtrim(APP_URL, '/')) ?>/karty30/pfron/docs.php?pfron_id=<?= $id ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Dokumenty i podpis</a>
    <?php if ($staff): ?><a class="btn btn-sm btn-outline-secondary" href="konsultacjePfron.php?id=<?= $id ?>&amp;edytuj=1"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj</a><?php endif; ?>
  </div>
</div>
<div class="row g-3">
  <div class="col-md-4"><section class="card border-0 shadow-sm" aria-labelledby="ph-h"><div class="card-header fw-semibold" id="ph-h">Godziny</div><div class="card-body small"><dl class="row mb-0">
    <dt class="col-7 fw-normal text-body-secondary">Limit</dt><dd class="col-5 text-end"><?= h($num($H['limit'])) ?> h</dd>
    <dt class="col-7 fw-normal text-body-secondary">Wykorzystane</dt><dd class="col-5 text-end"><?= h($num($H['used'])) ?> h</dd>
    <dt class="col-7 fw-normal text-body-secondary ps-4">w tym zdalnie</dt><dd class="col-5 text-end"><?= h($num($H['remote'])) ?> h</dd>
    <dt class="col-7 fw-normal text-body-secondary ps-4">w tym stacjonarnie</dt><dd class="col-5 text-end"><?= h($num($H['onsite'])) ?> h</dd>
    <dt class="col-7 fw-semibold">Zostało</dt><dd class="col-5 text-end fw-semibold"><?= h($num($H['left'])) ?> h</dd>
    <?php if ($H['plan']): ?><dt class="col-7 fw-normal text-body-secondary mt-2">Plan: zdalnie / stacjonarnie</dt><dd class="col-5 text-end mt-2"><?= h($num($H['plan_remote'])) ?> / <?= h($num($H['plan_onsite'])) ?> h</dd><?php endif; ?>
  </dl><?php if ($c['notes']): ?><hr><?= nl2br(h($c['notes'])) ?><?php endif; ?></div></section></div>
  <div class="col-md-8"><section class="card border-0 shadow-sm" aria-labelledby="pb-h"><div class="card-header fw-semibold" id="pb-h">Terminy rozliczane z umowy <span class="badge text-bg-secondary"><?= count($bk) ?></span></div>
    <?php if (!$bk): ?><div class="card-body small text-body-secondary">Brak terminów — zarezerwuj „Termin z PFRON”.</div><?php else: ?><div class="table-responsive"><table class="table table-sm mb-0 small"><caption class="visually-hidden">Terminy PFRON</caption>
      <thead><tr><th scope="col">Termin</th><th scope="col">Konsultant</th><th scope="col">Tryb</th><th scope="col">Status wizyty</th><th scope="col" class="text-end">Godz. PFRON</th><th scope="col">Rozliczenie PFRON</th></tr></thead>
      <tbody><?php foreach ($bk as $b): $ps = K30_PFRON_STATUSES[$b['pfron_status']] ?? null; ?><tr>
        <th scope="row" class="fw-normal text-nowrap"><a href="rezerwacja.php?id=<?= (int)$b['id'] ?>"><?= h(date('d.m.Y H:i', strtotime((string)$b['start_time']))) ?></a></th><td><?= h($b['consultant'] ?: '—') ?></td><td><?= $b['resource_id'] ? 'stacjonarnie' : 'zdalnie' ?></td><td><?= rz_badge((string)$b['status']) ?></td><td class="text-end"><?= h($num($b['free_hours'])) ?></td>
        <td><form method="post" class="d-flex gap-1"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="booking_status"><input type="hidden" name="schedule_id" value="<?= (int)$b['id'] ?>">
          <select class="form-select form-select-sm" name="pfron_status" aria-label="Status rozliczenia PFRON terminu <?= h(date('d.m.Y', strtotime((string)$b['start_time']))) ?>" onchange="this.form.submit()"><?php foreach (K30_PFRON_STATUSES as $k => $s): ?><option value="<?= h($k) ?>"<?= (string)$b['pfron_status'] === $k ? ' selected' : '' ?>><?= h($s['label']) ?></option><?php endforeach; ?></select></form></td>
      </tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php if ($staff): ?><form method="post" class="mt-2" onsubmit="return confirm('Usunąć umowę PFRON?')"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="delete"><button class="btn btn-sm btn-link text-danger px-0">Usuń umowę</button></form><?php endif; ?>
  </div>
</div>

<?php else: ?>
<div class="d-flex align-items-center gap-2 flex-wrap mb-2"><h1 class="h4 fw-bold mb-0"><i class="bi bi-building-fill-check text-primary me-2" aria-hidden="true"></i>Umowy PFRON</h1>
  <?php if ($staff): ?><a class="btn btn-sm btn-primary ms-auto" href="konsultacjeKlienci.php?zakres=all"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowa umowa (wybierz klienta)</a><?php endif; ?></div>
<p class="small text-body-secondary">Umowy w ramach programu Aktywny Samorząd. Godziny zużywają się z terminów rozliczanych jako PFRON; „do złożenia” = odbyte terminy jeszcze nie zgłoszone.</p>
<form method="get" class="row g-2 align-items-end mb-3" role="search" aria-label="Filtry umów">
  <div class="col-sm-4 col-lg-3"><label class="form-label small mb-0" for="pf-q">Beneficjent lub numer umowy</label><input class="form-control form-control-sm" id="pf-q" name="q" value="<?= h($f['q']) ?>"></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="pf-fs">Status</label><select class="form-select form-select-sm" id="pf-fs" name="status"><option value="">wszystkie</option><?php foreach (K30_PFRON_CONTRACT_STATUSES as $k => $s): ?><option value="<?= h($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($s['label']) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="pf-d">Kończy się w ciągu</label><select class="form-select form-select-sm" id="pf-d" name="days"><option value="0">—</option><?php foreach ([30, 60, 90] as $d): ?><option value="<?= $d ?>"<?= $f['days'] === $d ? ' selected' : '' ?>><?= $d ?> dni</option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Pokaż</button> <a class="btn btn-sm btn-link" href="konsultacjePfron.php">Wyczyść</a></div>
</form>
<?php if (!$rows): ?><div class="alert alert-secondary">Brak umów dla wybranych filtrów.</div><?php else: ?>
<div class="table-responsive"><table class="table table-sm table-hover align-middle"><caption class="visually-hidden">Umowy PFRON</caption>
  <thead class="table-light"><tr><th scope="col">Umowa</th><th scope="col">Beneficjent</th><th scope="col">Status</th><th scope="col">Ważna do</th><th scope="col" class="text-end">Wykorzystane / limit</th><th scope="col" class="text-end">Terminy</th><th scope="col" class="text-end">Do złożenia</th></tr></thead>
  <tbody><?php foreach ($rows as $r): $st = K30_PFRON_CONTRACT_STATUSES[$r['status']] ?? ['label' => $r['status'], 'color' => '#555', 'bg' => '#eee']; ?>
    <tr><th scope="row"><a href="konsultacjePfron.php?id=<?= (int)$r['id'] ?>"><?= h($r['contract_number']) ?></a></th><td><a href="konsultacjeKlient.php?id=<?= (int)$r['client_id'] ?>"><?= h($r['client_name']) ?></a></td>
      <td><span class="badge" style="background:<?= h($st['bg']) ?>;color:#111827;border:1px solid <?= h($st['color']) ?>"><?= h($st['label']) ?></span></td>
      <td class="small text-nowrap"><?= $r['valid_to'] ? h(date('d.m.Y', strtotime((string)$r['valid_to']))) : '—' ?></td>
      <td class="text-end"><?= h($num($r['hours_used'])) ?> / <?= h($num($r['hours_limit'])) ?> h</td><td class="text-end"><?= (int)$r['bookings'] ?></td>
      <td class="text-end"><?= (int)$r['to_submit'] ? '<span class="badge text-bg-warning">' . (int)$r['to_submit'] . '</span>' : '—' ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
