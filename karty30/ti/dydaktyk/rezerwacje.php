<?php
/**
 * karty30/ti/dydaktyk/rezerwacje.php — lista rezerwacji terminów konsultacji (przeniesione z karty30/schedules/index.php).
 * Filtry: beneficjent, status, od–do, konsultant, beneficjent (client_id), seria; 25 na stronę.
 * Logika: modules/ti_bookings/logic/bookings.php.
 */
require __DIR__ . '/_rezerwacje_nav.php';

$f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => (string)($_GET['status'] ?? ''), 'from' => (string)($_GET['from'] ?? ''),
      'to' => (string)($_GET['to'] ?? ''), 'consultant' => (int)($_GET['consultant'] ?? 0), 'client_id' => (int)($_GET['client_id'] ?? 0),
      'series' => (string)($_GET['series'] ?? '')];
$per = 25; $page = max(1, (int)($_GET['page'] ?? 1));
$total = TiBookings::count($f);
$rows  = TiBookings::list($f, $per, ($page - 1) * $per);
$consultants = k30_get_consultants();
$qs = fn(array $x = []) => http_build_query(array_filter(array_merge($f, $x), fn($v) => $v !== '' && $v !== 0));

$RZ_TITLE = 'Rezerwacje terminów';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
<h1 class="h4 fw-bold mb-2"><i class="bi bi-calendar-check text-primary me-2" aria-hidden="true"></i>Rezerwacje terminów konsultacji</h1>
<?php rz_nav('lista'); ?>
<?= flash_html() ?>

<form method="get" class="row g-2 align-items-end mb-2" role="search" aria-label="Filtry rezerwacji">
  <div class="col-sm-4 col-lg-3"><label class="form-label small mb-0" for="rz-q">Beneficjent</label><input class="form-control form-control-sm" id="rz-q" name="q" value="<?= h($f['q']) ?>"></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="rz-st">Status</label>
    <select class="form-select form-select-sm" id="rz-st" name="status"><option value="">wszystkie</option><?php foreach (K30_SCHEDULE_STATUSES as $k => $v): ?><option value="<?= h($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($v['label']) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="rz-c">Konsultant</label>
    <select class="form-select form-select-sm" id="rz-c" name="consultant"><option value="0">wszyscy</option><?php foreach ($consultants as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $f['consultant'] === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['display_name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="rz-from">Od</label><input class="form-control form-control-sm" type="date" id="rz-from" name="from" value="<?= h($f['from']) ?>"></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="rz-to">Do</label><input class="form-control form-control-sm" type="date" id="rz-to" name="to" value="<?= h($f['to']) ?>"></div>
  <?php if ($f['series'] !== ''): ?><input type="hidden" name="series" value="<?= h($f['series']) ?>"><?php endif; ?>
  <?php if ($f['client_id']): ?><input type="hidden" name="client_id" value="<?= $f['client_id'] ?>"><?php endif; ?>
  <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Pokaż</button> <a class="btn btn-sm btn-link" href="rezerwacje.php">Wyczyść</a></div>
</form>
<?php if ($f['series'] !== ''): ?><p class="small"><span class="badge text-bg-info">Seria</span> pokazuję terminy jednej serii. <a href="rezerwacje.php">Wszystkie rezerwacje</a></p><?php endif; ?>
<p class="small text-body-secondary mb-2">Znaleziono: <?= $total ?></p>

<?php if (!$rows): ?>
<div class="alert alert-secondary">Brak rezerwacji dla wybranych filtrów.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
  <caption class="visually-hidden">Rezerwacje terminów konsultacji</caption>
  <thead class="table-light"><tr><th scope="col">Termin</th><th scope="col">Beneficjent</th><th scope="col">Konsultant</th><th scope="col" class="text-end">Min</th><th scope="col">Rozliczenie</th><th scope="col">Status</th><th scope="col">Karta</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $bt = K30_BILLING_TYPES[$r['billing_type'] ?? 'free'] ?? null; ?>
  <tr>
    <th scope="row" class="fw-normal text-nowrap"><a href="rezerwacja.php?id=<?= (int)$r['id'] ?>"><?= h(date('d.m.Y H:i', strtotime((string)$r['start_time']))) ?></a>
      <?php if ((string)$r['series_id'] !== ''): ?><a class="badge text-bg-info text-decoration-none" href="rezerwacje.php?series=<?= h($r['series_id']) ?>" title="Pokaż całą serię">seria <?= (int)$r['series_index'] + 1 ?></a><?php endif; ?></th>
    <td><?= h($r['client_name'] ?? '') ?></td>
    <td class="small"><?= h($r['consultant_name'] ?? '—') ?></td>
    <td class="text-end small"><?= (int)$r['duration_minutes'] ?></td>
    <td class="small"><?= h($bt['label'] ?? '') ?><?= (float)($r['amount_due'] ?? 0) > 0 ? ' · ' . h(number_format((float)$r['amount_due'], 2, ',', ' ')) . ' zł' : '' ?></td>
    <td><?= rz_badge((string)$r['status']) ?></td>
    <td class="small"><?= (int)$r['cards'] ? '<i class="bi bi-clipboard2-check text-success" aria-hidden="true"></i> jest' : '—' ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($total > $per): $pages = (int)ceil($total / $per); ?>
<nav aria-label="Strony"><ul class="pagination pagination-sm">
  <?php for ($i = max(1, $page - 4); $i <= min($pages, $page + 4); $i++): ?>
  <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="rezerwacje.php?<?= h($qs(['page' => $i])) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
