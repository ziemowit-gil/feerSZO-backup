<?php
/** Kolejka zgłoszeń (Koordynator Projektu / Logistyk). Zmienne: $rows, $f, $budgets, $counts, $role. */
$PAGE_TITLE = 'Kolejka zgłoszeń noclegów'; $active = 'kolejka';
include __DIR__ . '/_head.php'; include __DIR__ . '/_nav.php';
$d = fn($x) => date('d.m.Y', strtotime($x));
?>
<h1>Kolejka zgłoszeń</h1>
<p class="ncl-lead"><?= $role === 'logistyk' ? 'Wszystkie zgłoszenia noclegów.' : 'Zgłoszenia przypisane do Twoich budżetów.' ?> Domyślnie widać otwarte (Wniosek i W trakcie).</p>
<div class="ncl-tiles" role="list" aria-label="Liczba zgłoszeń wg statusu">
  <?php foreach (noclStatusy() as $c => $l): ?><a role="listitem" class="ncl-tile" href="<?= h(route('nocl.queue') . (str_contains(route('nocl.queue'), '?') ? '&' : '?') . 'status=' . $c) ?>"><b><?= (int)($counts[$c] ?? 0) ?></b><span><?= h($l) ?></span></a><?php endforeach; ?>
</div>
<form method="get" action="<?= h(route('nocl.queue')) ?>" class="row g-2 align-items-end mb-3" role="search" aria-label="Filtr zgłoszeń">
  <?= route_field('nocl.queue') ?>
  <div class="col-sm-3"><label class="form-label" for="q">Szukaj</label><input class="form-control" id="q" name="q" value="<?= h($f['q']) ?>" placeholder="osoba, cel, miejscowość"></div>
  <div class="col-sm-2"><label class="form-label" for="st">Status</label><select class="form-select" id="st" name="status"><option value="">otwarte</option><?php foreach (noclStatusy() as $c => $l): ?><option value="<?= h($c) ?>" <?= $f['status'] === $c ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-3"><label class="form-label" for="bf">Budżet</label><select class="form-select" id="bf" name="budget_id"><option value="">wszystkie</option><?php foreach ($budgets as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)$f['budget_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['code']) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-2"><label class="form-label" for="od">Od</label><input class="form-control" type="date" id="od" name="od" value="<?= h($f['from']) ?>"></div>
  <div class="col-sm-2"><label class="form-label" for="do">Do</label><input class="form-control" type="date" id="do" name="do" value="<?= h($f['to']) ?>"></div>
  <div class="col-12"><button class="btn btn-primary">Filtruj</button></div>
</form>
<?php if ($role === 'logistyk'): ?>
<form method="post" action="<?= h(route('nocl.importti')) ?>" class="mb-3"><?= Router::csrfField() ?>
  <button class="btn btn-outline-primary btn-sm">Zaimportuj noclegi kadry z modułu TI</button>
  <span class="ncl-muted small ms-2">Zamienia flagi „noc przed / noc po” na zgłoszenia (bez duplikatów, terminy odświeżane z lekcji).</span></form>
<?php endif; ?>
<?php if (!$rows): ?><p class="ncl-muted">Brak zgłoszeń dla wybranych filtrów.</p><?php else: ?>
<div class="ncl-scroll"><table class="ncl-table">
  <caption>Zgłoszenia noclegów (<?= count($rows) ?>)</caption>
  <thead><tr><th scope="col">Nr</th><th scope="col">Osoba</th><th scope="col">Termin</th><th scope="col">Budżet</th><th scope="col">Obiekt / pokój</th><th scope="col">Koszt</th><th scope="col">Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= h(route('nocl.show', ['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?></a></td>
      <td><?= h($r['person_name']) ?><br><small class="ncl-muted"><?= h(noclRoleOsoby()[$r['person_role']] ?? '') ?><?= $r['needs_accessible'] ? ' · pokój dostępny' : '' ?></small></td>
      <td><?= h($d($r['date_from'])) ?> – <?= h($d($r['date_to'])) ?></td>
      <td><?= h($r['budget_code'] ?: '—') ?><?= $r['budget_code'] && !$r['budget_approved'] && in_array($r['status'], ['wniosek', 'w_trakcie'], true) ? ' <span class="badge text-bg-warning">do akceptacji</span>' : '' ?></td>
      <td><?= $r['room_name'] ? h($r['facility_name'] . ', ' . $r['room_name']) : '—' ?></td>
      <td><?= (float)$r['cost_total'] > 0 ? h(noclMoneyFmt((float)$r['cost_total'])) : '—' ?></td>
      <td><span class="badge <?= h(noclStatusBadge($r['status'])) ?>"><?= h(noclStatusLabel($r['status'])) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
