<?php
/**
 * modules/ti_msues/view/trainer.php — dokumentacja MSUES osoby z kadry: checklista per usługa, oświadczenia (wersje), dokumenty
 * (CV, dyplomy, certyfikaty, umowy spoza rejestru), umowy z Rejestru Umów. Prowadzący widzi tylko siebie (Mój panel › Certyfikacja MSUES).
 * Zmienne: $u, $services, $check [serviceId => items], $declarations, $documents, $contracts, $launched, $actor.
 */
require __DIR__ . '/_parts.php';
$self = (int)$u['id'] === (int)$actor['id'];
$title = $self ? 'Moja certyfikacja MSUES' : 'MSUES — ' . $u['name'];
$kierLabel = 'Certyfikacja MSUES';
include __DIR__ . '/_head.php';
$kinds = MsuesChecklist::documentKinds();
$back = Router::current()->url('/kadra/' . (int)$u['id']);
?>
<?php if (!empty($actor['staff'])): ?><p class="mb-2"><a href="<?= h(route('msues.index')) ?>">&larr; Certyfikacja MSUES</a></p><?php endif; ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
<h1 class="h4 mb-0"><i class="bi bi-person-badge text-primary" aria-hidden="true"></i> <?= h($title) ?></h1>
<?php if (!empty($actor['staff'])): ?>
<form method="post" action="<?= h(route('msues.notify', ['id' => $u['id']])) ?>" class="d-flex align-items-center gap-2"><?= Router::csrfField() ?><input type="hidden" name="_back" value="<?= h($back) ?>">
  <label class="small"><input type="checkbox" name="send_mail" value="1" checked<?= trim((string)$u['email']) === '' ? ' disabled' : '' ?>> e-mail</label>
  <label class="small"><input type="checkbox" name="send_sms" value="1" checked<?= trim((string)$u['phone_number']) === '' ? ' disabled' : '' ?>> SMS<?= trim((string)$u['phone_number']) === '' ? ' (brak numeru)' : '' ?></label>
  <button class="btn btn-sm btn-outline-primary"><i class="bi bi-send"></i> Wyślij informację o MSUES</button>
</form>
<?php endif; ?>
</div>
<?php if ($self): ?>
<div class="alert alert-light border mb-3 small">
  <b>Co to jest?</b> Fundacja przygotowuje się do znaku jakości <b>MSUES</b> (Małopolskie Standardy Usług Edukacyjno-Szkoleniowych, WUP Kraków).
  Audyt sprawdza kwalifikacje kadry, dlatego potrzebujemy: <b>CV</b>, <b>dyplomów / certyfikatów</b>, <b>umowy</b> i podpisanego <b>oświadczenia o kwalifikacjach</b>.
  Kroki: 1) uzupełnij oświadczenie, 2) pobierz PDF, podpisz i wgraj skan, 3) wyślij do weryfikacji.
  <?= $launched && $launched['deadline'] !== '' ? '<b>Termin: ' . h(DeclarationForm::dateLong($launched['deadline'])) . '.</b>' : '' ?>
</div>
<?php endif; ?>

<section class="card mb-3"><div class="card-header fw-semibold">Checklista MSUES</div>
<div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>Usługa</th><?php foreach (MsuesChecklist::criteria() as $c): ?><th class="text-center small"><?= h($c['label']) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($check as $sid => $items): $s = $sid ? current(array_filter($services, fn($x) => (int)$x['id'] === (int)$sid)) : null; ?>
    <tr><td><?= $s ? h(MsuesSzoData::courseLabel($s)) : '<span class="text-muted">bez usługi (ogólnie)</span>' ?></td>
      <?php foreach ($items as $it): ?><td class="text-center" title="<?= h($it['detail']) ?>"><?= msuesCheckIcon($it['ok'], $it['required']) ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
  </tbody></table></div>
<div class="card-body small text-muted py-2"><?php foreach (MsuesChecklist::criteria() as $c): ?><div><b><?= h($c['label']) ?>:</b> <?= h($c['description']) ?></div><?php endforeach; ?></div>
</section>

<section class="card mb-3"><div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2"><span>Oświadczenia o kwalifikacjach i kompetencjach (OKK-1)</span>
  <form method="post" action="<?= h(route('msues.decl.create')) ?>" class="d-flex gap-2 align-items-center"><?= Router::csrfField() ?>
    <input type="hidden" name="trainer_id" value="<?= (int)$u['id'] ?>">
    <label class="visually-hidden" for="msuNc">Usługa</label>
    <select id="msuNc" name="course_id" class="form-select form-select-sm" style="max-width:260px"><option value="">ogólne — wszystkie usługi</option>
      <?php foreach ($services as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h(MsuesSzoData::courseLabel($s)) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-file-earmark-plus"></i> Nowe oświadczenie</button>
  </form></div>
<div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>Usługa</th><th>Wersja</th><th>Status</th><th>Skan</th><th>Zmiana</th><th></th></tr></thead>
  <tbody><?php foreach ($declarations as $d): ?>
    <tr class="<?= $d['statusEnum'] === DeclarationStatus::Superseded ? 'text-muted' : '' ?>"><td><?= h(msuesCourseName($d)) ?></td><td>v<?= (int)$d['version'] ?></td><td><?= msuesBadge($d['statusEnum']) ?></td>
      <td><?= $d['scan_path'] !== '' ? '<i class="bi bi-check-lg ok"></i>' : '—' ?></td><td class="small"><?= h(substr((string)$d['updated_at'], 0, 16)) ?></td>
      <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= h(route('msues.decl', ['id' => $d['id']])) ?>"><?= $d['statusEnum']->editable() ? 'Uzupełnij' : 'Otwórz' ?></a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(route('msues.decl.pdf', ['id' => $d['id']])) ?>" target="_blank" rel="noopener">PDF</a></td></tr>
  <?php endforeach; if (!$declarations): ?><tr><td colspan="6" class="text-muted">Brak oświadczeń — utwórz je przyciskiem powyżej (dane z SZO wpiszą się same).</td></tr><?php endif; ?></tbody>
</table></div></section>

<section class="card mb-3"><div class="card-header fw-semibold">Dokumenty kadry</div>
<div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>Rodzaj</th><th>Tytuł</th><th>Wydany</th><th>Ważny do</th><th>Plik</th><th></th></tr></thead>
  <tbody><?php foreach ($documents as $doc): $valid = MsuesStaffDocuments::isValid($doc); ?>
    <tr><td><?= h($kinds[$doc['kind']] ?? $doc['kind']) ?></td><td><?= h($doc['title']) ?></td><td><?= h($doc['issued_on']) ?></td>
      <td><?= $doc['valid_to'] !== '' ? h($doc['valid_to']) . ($valid ? '' : ' <span class="badge text-bg-danger">po terminie</span>') : 'bezterminowo' ?></td>
      <td><a href="<?= h(route('msues.doc', ['id' => $doc['id']])) ?>" target="_blank" rel="noopener"><?= h($doc['file_name']) ?></a></td>
      <td class="text-end"><form method="post" action="<?= h(route('msues.doc.delete', ['id' => $doc['id']])) ?>" class="d-inline"><?= Router::csrfField() ?><input type="hidden" name="_back" value="<?= h($back) ?>">
        <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Usunąć dokument?')" aria-label="Usuń <?= h($doc['title']) ?>"><i class="bi bi-trash"></i></button></form></td></tr>
  <?php endforeach; if (!$documents): ?><tr><td colspan="6" class="text-muted">Brak dokumentów.</td></tr><?php endif; ?></tbody>
</table></div>
<div class="card-body border-top">
  <form method="post" enctype="multipart/form-data" action="<?= h(route('msues.doc.add', ['id' => $u['id']])) ?>" class="row g-2 align-items-end"><?= Router::csrfField() ?><input type="hidden" name="_back" value="<?= h($back) ?>">
    <div class="col-md-2"><label class="form-label small mb-0" for="msuK">Rodzaj</label><select id="msuK" name="kind" class="form-select form-select-sm"><?php foreach ($kinds as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-0" for="msuTt">Tytuł / opis</label><input id="msuTt" name="title" class="form-control form-control-sm" maxlength="255" placeholder="np. Dyplom magistra informatyki"></div>
    <div class="col-md-2"><label class="form-label small mb-0" for="msuI">Wydany</label><input type="date" id="msuI" name="issued_on" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label small mb-0" for="msuV">Ważny do</label><input type="date" id="msuV" name="valid_to" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small mb-0" for="msuF">Plik (PDF, JPG, PNG, DOCX; do 20 MB)</label><input type="file" id="msuF" name="file" class="form-control form-control-sm" required accept=".pdf,.jpg,.jpeg,.png,.docx,.doc,.odt"></div>
    <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-upload"></i> Dodaj dokument</button></div>
  </form>
</div></section>

<section class="card mb-3"><div class="card-header fw-semibold">Umowy z Rejestru Umów</div>
<ul class="list-group list-group-flush">
  <?php foreach ($contracts as $c): ?><li class="list-group-item small"><?= h($c['label']) ?> <b><?= h($c['numer_umowy']) ?></b> — <?= h($c['status']) ?> (od <?= h($c['data_zawarcia']) ?><?= $c['data_zakonczenia'] !== '' ? ' do ' . h($c['data_zakonczenia']) : '' ?>)
    <?= $c['active'] ? '<span class="badge text-bg-success">aktywna</span>' : '' ?></li><?php endforeach; ?>
  <?php if (!$contracts): ?><li class="list-group-item small text-muted">Brak umów powiązanych z kontem (po e-mailu, koncie M365 albo wskazaniu prowadzącego w umowie zlecenia). Umowę spoza rejestru wgraj jako dokument „Umowa”.</li><?php endif; ?>
</ul></section>
<?php include __DIR__ . '/_foot.php'; ?>
