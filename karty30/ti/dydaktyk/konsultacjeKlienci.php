<?php
/**
 * karty30/ti/dydaktyk/konsultacjeKlienci.php — Klienci (beneficjenci) w trybie Konsultacje: lista z filtrami.
 * Domyślnie osoby, które mają cokolwiek w konsultacjach (rezerwacja, karta, kolejka, konto); „cała kartoteka” — wszyscy.
 * Szczegóły i edycja: konsultacjeKlient.php. Logika: modules/ti_consult_clients/logic/consultClients.php.
 */
require __DIR__ . '/_rezerwacje_nav.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consult_clients/logic/consultClients.php';

$f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => (string)($_GET['status'] ?? ''), 'zakres' => ($_GET['zakres'] ?? 'kons') === 'all' ? 'all' : 'kons', 'test' => !empty($_GET['test'])];
$rows = TiConsultClients::list($f);
$RZ_TITLE = 'Klienci'; $RZ_CUR = 'konsultacjeKlienci.php';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1300px">
<div class="d-flex align-items-center gap-2 flex-wrap mb-2">
  <h1 class="h4 fw-bold mb-0"><i class="bi bi-people text-primary me-2" aria-hidden="true"></i>Klienci konsultacji</h1>
  <a class="btn btn-sm btn-primary ms-auto" href="konsultacjeKlient.php?nowy=1"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Nowy klient</a>
</div>
<?= flash_html() ?>
<form method="get" class="row g-2 align-items-end mb-3" role="search" aria-label="Filtry klientów">
  <div class="col-sm-4 col-lg-3"><label class="form-label small mb-0" for="kk-q">Nazwisko, e-mail lub telefon</label><input class="form-control form-control-sm" id="kk-q" name="q" value="<?= h($f['q']) ?>"></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="kk-st">Status</label>
    <select class="form-select form-select-sm" id="kk-st" name="status"><option value="">wszystkie</option><?php foreach (K30_CLIENT_STATUSES as $k => $v): ?><option value="<?= h($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($v['label']) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-3 col-lg-2"><label class="form-label small mb-0" for="kk-z">Zakres</label>
    <select class="form-select form-select-sm" id="kk-z" name="zakres"><option value="kons">osoby z konsultacji</option><option value="all"<?= $f['zakres'] === 'all' ? ' selected' : '' ?>>cała kartoteka</option></select></div>
  <div class="col-auto"><div class="form-check small"><input class="form-check-input" type="checkbox" name="test" value="1" id="kk-t"<?= $f['test'] ? ' checked' : '' ?>><label class="form-check-label" for="kk-t">pokaż osoby testowe</label></div></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Pokaż</button> <a class="btn btn-sm btn-link" href="konsultacjeKlienci.php">Wyczyść</a></div>
</form>
<p class="small text-body-secondary">Znaleziono: <?= count($rows) ?><?= count($rows) >= 200 ? ' (pokazuję pierwsze 200 — zawęź filtr)' : '' ?></p>
<?php if (!$rows): ?><div class="alert alert-secondary">Brak klientów dla wybranych filtrów.</div><?php else: ?>
<div class="table-responsive"><table class="table table-sm table-hover align-middle">
  <caption class="visually-hidden">Klienci konsultacji</caption>
  <thead class="table-light"><tr><th scope="col">Klient</th><th scope="col">Kontakt</th><th scope="col">Status</th><th scope="col" class="text-end">Przyszłe terminy</th><th scope="col">Ostatnia wizyta</th><th scope="col" class="text-end">Karty</th><th scope="col">Konto</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
  <tbody><?php foreach ($rows as $r): $st = K30_CLIENT_STATUSES[$r['status']] ?? ['label' => $r['status'], 'color' => '#555', 'bg' => '#eee']; ?>
    <tr>
      <th scope="row"><a href="konsultacjeKlient.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a>
        <?php if ($r['queued']): ?><span class="badge text-bg-warning ms-1">w kolejce</span><?php endif; ?><?php if ((int)$r['groups_n']): ?><span class="badge text-bg-light border ms-1" title="Ma też zajęcia w grupach">grupy: <?= (int)$r['groups_n'] ?></span><?php endif; ?></th>
      <td class="small"><?= h($r['email'] ?: '') ?><?= $r['email'] && $r['phone'] ? '<br>' : '' ?><?= h($r['phone'] ?: '') ?><?= !$r['email'] && !$r['phone'] ? '<span class="text-danger">brak</span>' : '' ?></td>
      <td><span class="badge" style="background:<?= h($st['bg']) ?>;color:#111827;border:1px solid <?= h($st['color']) ?>"><?= h($st['label']) ?></span></td>
      <td class="text-end"><?= (int)$r['future'] ?></td>
      <td class="small text-nowrap"><?= $r['last_booking'] ? h(date('d.m.Y', strtotime((string)$r['last_booking']))) : '—' ?></td>
      <td class="text-end"><?= (int)$r['cards'] ?></td>
      <td class="small font-monospace"><?= h($r['login'] ?: '—') ?></td>
      <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary py-0" href="rezerwacja.php?nowa=1&amp;client_id=<?= (int)$r['id'] ?>" title="Nowa rezerwacja"><i class="bi bi-calendar-plus" aria-hidden="true"></i><span class="visually-hidden">Nowa rezerwacja</span></a>
        <a class="btn btn-sm btn-outline-secondary py-0" href="konsultacjeKlient.php?id=<?= (int)$r['id'] ?>&amp;edytuj=1" title="Edytuj"><i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Edytuj</span></a></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
