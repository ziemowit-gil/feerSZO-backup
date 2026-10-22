<?php
/** Obieg faktur i potwierdzeń. Zmienne: $rows, $f, $budgets, $facs, $errors, $form, $role. */
$PAGE_TITLE = 'Faktury — noclegi'; $active = 'faktury';
include __DIR__ . '/_head.php'; include __DIR__ . '/_nav.php';
$isLog = $role === 'logistyk';
$fv = fn(string $k, $d = '') => $form[$k] ?? $d;
$fe = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="ie-' . h($k) . '">' . h($errors[$k]) . '</div>' : '';
$next = ['otrzymana' => [['zatwierdzona', 'Zatwierdź'], ['odrzucona', 'Odrzuć']], 'zatwierdzona' => [['zaplacona', 'Oznacz jako zapłaconą']], 'oczekuje' => [['otrzymana', 'Otrzymano']], 'odrzucona' => [['oczekuje', 'Przywróć']], 'zaplacona' => []];
$base = route('nocl.invoices');
?>
<h1>Faktury i potwierdzenia kosztów</h1>
<p class="ncl-lead">Obieg: otrzymana → zatwierdzona (Koordynator Projektu budżetu albo Logistyk) → zapłacona (Logistyk). Fakturę można powiązać ze zgłoszeniem — wtedy dziedziczy budżet i obiekt.</p>
<form method="get" action="<?= h($base) ?>" class="row g-2 align-items-end mb-3" aria-label="Filtr faktur"><?= route_field('nocl.invoices') ?>
  <div class="col-sm-3"><label class="form-label" for="fs">Status</label><select class="form-select" id="fs" name="status"><option value="">wszystkie</option><?php foreach (noclFakturaStatusy() as $c => $l): ?><option value="<?= h($c) ?>" <?= $f['status'] === $c ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-4"><label class="form-label" for="fb">Budżet</label><select class="form-select" id="fb" name="budget_id"><option value="">wszystkie</option><?php foreach ($budgets as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)$f['budget_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['code'] . ' — ' . $b['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-primary">Filtruj</button></div>
</form>
<?php if (!$rows): ?><p class="ncl-muted">Brak faktur.</p><?php else: ?>
<div class="ncl-scroll"><table class="ncl-table"><caption>Faktury noclegowe (<?= count($rows) ?>)</caption>
  <thead><tr><th scope="col">Numer</th><th scope="col">Kategoria</th><th scope="col">Budżet</th><th scope="col">Obiekt / zgłoszenie</th><th scope="col">Kwota</th><th scope="col">Termin płatności</th><th scope="col">Status</th><th scope="col">Akcje</th></tr></thead><tbody>
  <?php foreach ($rows as $i): ?><tr>
    <td><?= h($i['number']) ?><?php if ($i['file_path']): ?><br><a href="<?= h(route('nocl.invoice.file', ['id' => $i['id']])) ?>">pobierz plik<span class="visually-hidden"> faktury <?= h($i['number']) ?></span></a><?php endif; ?></td>
    <td><?= h(noclKategorieKosztu()[$i['category']] ?? $i['category']) ?></td><td><?= h($i['budget_code'] ?: '—') ?></td>
    <td><?= h($i['facility_name'] ?: '—') ?><?= $i['request_id'] ? '<br><a href="' . h(route('nocl.show', ['id' => $i['request_id']])) . '">#' . (int)$i['request_id'] . ' ' . h($i['person_name']) . '</a>' : '' ?></td>
    <td><?= h(noclMoneyFmt(noclMoney((string)$i['amount']))) ?></td><td><?= $i['due_date'] ? h(date('d.m.Y', strtotime($i['due_date']))) : '—' ?></td>
    <td><span class="badge <?= h(noclFakturaBadge($i['status'])) ?>"><?= h(noclFakturaStatusy()[$i['status']] ?? $i['status']) ?></span></td>
    <td><div class="d-flex flex-wrap gap-1"><?php foreach ($next[$i['status']] ?? [] as [$to, $lab]):
        if ($to === 'zaplacona' && !$isLog) continue; if (in_array($to, ['otrzymana', 'oczekuje'], true) && !$isLog) continue; ?>
      <form method="post" action="<?= h(route('nocl.invoice.status', ['id' => $i['id']])) ?>"><?= Router::csrfField() ?><input type="hidden" name="to" value="<?= h($to) ?>"><button class="btn btn-sm <?= $to === 'odrzucona' ? 'btn-outline-danger' : 'btn-outline-primary' ?>"><?= h($lab) ?><span class="visually-hidden"> — faktura <?= h($i['number']) ?></span></button></form>
    <?php endforeach; ?></div></td>
  </tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php if ($isLog): ?>
<form method="post" enctype="multipart/form-data" action="<?= h(route('nocl.invoice.store')) ?>" class="ncl-card mt-3" novalidate>
  <?= Router::csrfField() ?>
  <h2 class="mt-0">Dodaj fakturę / potwierdzenie</h2>
  <div class="row g-3">
    <div class="col-sm-4"><label class="form-label" for="number">Numer *</label><input class="form-control<?= isset($errors['number']) ? ' is-invalid' : '' ?>" id="number" name="number" required value="<?= h($fv('number')) ?>"><?= $fe('number') ?></div>
    <div class="col-sm-4"><label class="form-label" for="category">Kategoria</label><select class="form-select" id="category" name="category"><?php foreach (noclKategorieKosztu() as $c => $l): ?><option value="<?= h($c) ?>" <?= $fv('category', 'nocleg') === $c ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-4"><label class="form-label" for="amount">Kwota brutto (zł) *</label><input class="form-control<?= isset($errors['amount']) ? ' is-invalid' : '' ?>" inputmode="decimal" id="amount" name="amount" required value="<?= h($fv('amount')) ?>"><?= $fe('amount') ?></div>
    <div class="col-sm-3"><label class="form-label" for="issue_date">Data wystawienia</label><input class="form-control" type="date" id="issue_date" name="issue_date" value="<?= h($fv('issue_date')) ?>"></div>
    <div class="col-sm-3"><label class="form-label" for="due_date">Termin płatności</label><input class="form-control" type="date" id="due_date" name="due_date" value="<?= h($fv('due_date')) ?>"></div>
    <div class="col-sm-3"><label class="form-label" for="ibud">Budżet</label><select class="form-select" id="ibud" name="budget_id"><option value="">z powiązanego zgłoszenia</option><?php foreach ($budgets as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)$fv('budget_id') === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['code']) ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-3"><label class="form-label" for="request_id">Nr zgłoszenia</label><input class="form-control<?= isset($errors['request_id']) ? ' is-invalid' : '' ?>" type="number" min="1" id="request_id" name="request_id" value="<?= h($fv('request_id')) ?>"><?= $fe('request_id') ?></div>
    <div class="col-sm-6"><label class="form-label" for="ifac">Obiekt</label><select class="form-select" id="ifac" name="facility_id"><option value="">—</option><?php foreach ($facs as $fa): ?><option value="<?= (int)$fa['id'] ?>" <?= (int)$fv('facility_id') === (int)$fa['id'] ? 'selected' : '' ?>><?= h($fa['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-6"><label class="form-label" for="file">Plik (PDF, JPG, PNG, DOCX)</label><input class="form-control<?= isset($errors['file']) ? ' is-invalid' : '' ?>" type="file" id="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.docx"><?= $fe('file') ?></div>
    <div class="col-12"><label class="form-label" for="inote">Uwagi</label><input class="form-control" id="inote" name="note" value="<?= h($fv('note')) ?>"></div>
  </div>
  <button class="btn btn-primary mt-3">Zapisz fakturę</button>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
