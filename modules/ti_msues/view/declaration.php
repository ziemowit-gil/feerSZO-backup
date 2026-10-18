<?php
/**
 * modules/ti_msues/view/declaration.php — oświadczenie OKK-1: formularz (robocze / odrzucone), wydruk PDF/DOCX, skan podpisu,
 * obieg (wyślij / wycofaj / zatwierdź / odrzuć), nowa wersja, EZD, wersje i historia akceptacji.
 * Zmienne: $d, $versions, $events, $ezdActive, $readiness (TrainerReadiness), $actor. Kontroler: MsuesController::show.
 */
require __DIR__ . '/_parts.php';
$s = $d['statusEnum'];
$staff = !empty($actor['staff']);
$title = 'Oświadczenie OKK-1 — ' . ($d['trainer_name'] ?? '');
$kierLabel = 'Certyfikacja MSUES';
include __DIR__ . '/_head.php';
$v = $d['data'];
$edit = $s->editable();
$F = DeclarationForm::FIELDS;
$field = function (string $k, int $rows = 3) use ($v, $F, $edit): string {
    [$label, $hint, $type, $max] = $F[$k];
    $id = 'okk_' . $k;
    $dis = $edit ? '' : ' disabled';
    if ($type === 'textarea') return '<label class="form-label small mb-0" for="' . $id . '">' . h($label) . '</label><textarea id="' . $id . '" name="' . $k . '" rows="' . $rows . '" maxlength="' . $max . '" class="form-control form-control-sm" placeholder="' . h($hint) . '"' . $dis . '>' . h($v[$k]) . '</textarea>';
    if ($type === 'date') return '<label class="form-label small mb-0" for="' . $id . '">' . h($label) . '</label><input type="date" id="' . $id . '" name="' . $k . '" value="' . h($v[$k]) . '" class="form-control form-control-sm"' . $dis . '>';
    if ($type === 'bool') return '<div class="form-check"><input type="checkbox" class="form-check-input" id="' . $id . '" name="' . $k . '" value="1"' . (!empty($v[$k]) ? ' checked' : '') . $dis . '><label class="form-check-label small" for="' . $id . '">' . h($label) . '</label></div>';
    return '<label class="form-label small mb-0" for="' . $id . '">' . h($label) . '</label><input id="' . $id . '" name="' . $k . '" value="' . h($v[$k]) . '" maxlength="' . $max . '" class="form-control form-control-sm" placeholder="' . h($hint) . '"' . $dis . '>';
};
$labels = ['create' => 'utworzenie', 'edit' => 'edycja', 'status' => 'zmiana statusu', 'scan' => 'skan', 'ezd' => 'EZD', 'superseded' => 'zastąpienie'];
?>
<p class="mb-2"><a href="<?= h(route('msues.trainer', ['id' => $d['trainer_id']])) ?>">&larr; <?= h($d['trainer_name']) ?> — dokumentacja MSUES</a></p>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
  <div>
    <h1 class="h4 mb-1">Oświadczenie o kwalifikacjach i kompetencjach <span class="text-muted fs-6"><?= h(DeclarationDocument::number($d)) ?></span></h1>
    <div class="small">Usługa: <b><?= h(msuesCourseName($d)) ?></b> · wersja <?= (int)$d['version'] ?> · <?= msuesBadge($s) ?>
      <?php if ($d['ezd_sprawa_id']): ?> · <a href="<?= h(MsuesEzdService::url((int)$d['ezd_sprawa_id'])) ?>" target="_blank" rel="noopener">koszulka EZD</a><?php endif; ?></div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-sm btn-primary" href="<?= h(route('msues.decl.pdf', ['id' => $d['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf"></i> PDF do podpisu</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= h(route('msues.decl.docx', ['id' => $d['id']])) ?>"><i class="bi bi-filetype-docx"></i> DOCX</a>
  </div>
</div>

<?php if ($s === DeclarationStatus::Rejected && trim((string)$d['decision_note']) !== ''): ?>
  <div class="alert alert-danger"><b>Odrzucone przez <?= h($d['decided_name']) ?>:</b> <?= nl2br(h($d['decision_note'])) ?><br><span class="small">Popraw treść, wgraj nowy skan i wyślij ponownie.</span></div>
<?php endif; ?>
<?php if (!$readiness->allowed || ($readiness->reasons && $s !== DeclarationStatus::Approved)): ?>
  <div class="alert <?= $readiness->allowed ? 'alert-warning' : 'alert-danger' ?> small"><?= h($readiness->message()) ?></div>
<?php endif; ?>

<div class="row g-3">
<div class="col-lg-8">
<form method="post" action="<?= h(route('msues.decl.save', ['id' => $d['id']])) ?>" class="card"><?= Router::csrfField() ?>
  <div class="card-header fw-semibold">Treść oświadczenia<?= $edit ? '' : ' <span class="small text-muted">(tylko do odczytu — do zmian utwórz nową wersję)</span>' ?></div>
  <div class="card-body row g-2">
    <div class="col-md-6"><?= $field('miejscowosc') ?></div>
    <div class="col-md-3"><?= $field('data') ?></div>
    <div class="col-md-3"><label class="form-label small mb-0" for="okk_project">Projekt</label><input id="okk_project" name="project" value="<?= h($d['project']) ?>" maxlength="190" class="form-control form-control-sm"<?= $edit ? '' : ' disabled' ?>></div>
    <div class="col-md-5"><?= $field('imie_nazwisko') ?></div>
    <div class="col-md-7"><?= $field('dane_identyfikacyjne') ?></div>
    <fieldset class="col-md-7"><legend class="form-label small mb-0">Prowadzę działania jako</legend>
      <?php foreach (DeclarationForm::PODSTAWY as $k => $l): ?><div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="podstawa" id="okk_p_<?= h($k) ?>" value="<?= h($k) ?>"<?= $v['podstawa'] === $k ? ' checked' : '' ?><?= $edit ? '' : ' disabled' ?>><label class="form-check-label small" for="okk_p_<?= h($k) ?>"><?= h($l) ?></label></div><?php endforeach; ?>
    </fieldset>
    <div class="col-md-5"><?= $field('podstawa_inna') ?></div>
    <div class="col-12"><?= $field('podmiot') ?></div>
    <div class="col-12"><?= $field('zakres', 3) ?></div>
    <div class="col-12"><?= $field('wyksztalcenie', 2) ?></div>
    <div class="col-12"><?= $field('przygotowanie', 3) ?></div>
    <div class="col-12"><?= $field('doswiadczenie', 3) ?></div>
    <div class="col-12"><?= $field('uprawnienia', 2) ?></div>
    <div class="col-md-6"><?= $field('dok_zalaczone', 3) ?></div>
    <div class="col-md-6"><?= $field('dok_na_zadanie', 3) ?></div>
    <div class="col-12"><span class="form-label small mb-0 d-block">Oświadczenia dodatkowe (opcjonalne — drukują się tylko zaznaczone)</span><?= $field('zgoda_materialy') ?><?= $field('zgoda_rodo') ?></div>
    <details class="col-12 small"><summary>Pełna treść punktów 1–8 wzoru</summary>
      <ol class="mt-2"><?php foreach (DeclarationForm::points() as [$t]): ?><li><?= h($t) ?></li><?php endforeach; ?></ol><p><?= h(DeclarationForm::CLOSING) ?></p></details>
  </div>
  <?php if ($edit): ?>
  <div class="card-footer d-flex gap-2 flex-wrap">
    <button class="btn btn-primary" name="then" value=""><i class="bi bi-save"></i> Zapisz</button>
    <button class="btn btn-success" name="then" value="submit"><i class="bi bi-send"></i> Zapisz i wyślij do weryfikacji</button>
  </div>
  <?php endif; ?>
</form>
</div>

<div class="col-lg-4">
  <section class="card mb-3"><div class="card-header fw-semibold">Skan podpisanego oświadczenia</div><div class="card-body">
    <?php if ($d['scan_path'] !== ''): ?><p class="small mb-2"><i class="bi bi-check-lg ok"></i> <a href="<?= h(route('msues.decl.scan', ['id' => $d['id']])) ?>" target="_blank" rel="noopener"><?= h($d['scan_name']) ?></a><br><span class="text-muted"><?= h(substr((string)$d['scan_at'], 0, 16)) ?></span></p>
    <?php else: ?><p class="small text-muted mb-2">Pobierz PDF, podpisz odręcznie (lub podpisem kwalifikowanym) i wgraj skan — bez skanu oświadczenie nie zostanie zatwierdzone pod audyt.</p><?php endif; ?>
    <?php if (in_array($s, [DeclarationStatus::Draft, DeclarationStatus::Pending, DeclarationStatus::Rejected], true)): ?>
    <form method="post" enctype="multipart/form-data" action="<?= h(route('msues.decl.scan.upload', ['id' => $d['id']])) ?>"><?= Router::csrfField() ?>
      <label class="visually-hidden" for="okkScan">Plik skanu</label><input type="file" id="okkScan" name="scan" class="form-control form-control-sm mb-2" accept=".pdf,.jpg,.jpeg,.png" required>
      <button class="btn btn-sm btn-outline-primary"><i class="bi bi-upload"></i> <?= $d['scan_path'] !== '' ? 'Zastąp skan' : 'Wgraj skan' ?></button></form>
    <?php endif; ?>
  </div></section>

  <section class="card mb-3"><div class="card-header fw-semibold">Obieg</div><div class="card-body d-grid gap-2">
    <?php if ($s === DeclarationStatus::Pending): ?>
      <form method="post" action="<?= h(route('msues.decl.status', ['id' => $d['id']])) ?>"><?= Router::csrfField() ?><input type="hidden" name="to" value="draft">
        <button class="btn btn-sm btn-outline-secondary w-100">Wycofaj do poprawy</button></form>
      <?php if ($staff): ?>
      <form method="post" action="<?= h(route('msues.decl.status', ['id' => $d['id']])) ?>"><?= Router::csrfField() ?><input type="hidden" name="to" value="approved">
        <label class="form-label small mb-0" for="okkAn">Uwagi (opcjonalnie)</label><input id="okkAn" name="note" class="form-control form-control-sm mb-1">
        <button class="btn btn-sm btn-success w-100"<?= $d['scan_path'] === '' ? ' disabled title="Najpierw wgraj skan podpisu"' : '' ?>><i class="bi bi-patch-check"></i> Zatwierdź pod audyt</button></form>
      <form method="post" action="<?= h(route('msues.decl.status', ['id' => $d['id']])) ?>"><?= Router::csrfField() ?><input type="hidden" name="to" value="rejected">
        <label class="form-label small mb-0" for="okkRn">Powód odrzucenia</label><textarea id="okkRn" name="note" rows="2" class="form-control form-control-sm mb-1" required></textarea>
        <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-x-octagon"></i> Odrzuć</button></form>
      <?php endif; ?>
    <?php elseif ($edit): ?>
      <p class="small text-muted mb-0">Po uzupełnieniu treści i wgraniu skanu użyj „Zapisz i wyślij do weryfikacji”.</p>
      <?php if ($s === DeclarationStatus::Draft && (int)$d['version'] === 1 && !$d['ezd_sprawa_id']): ?>
      <form method="post" action="<?= h(route('msues.decl.delete', ['id' => $d['id']])) ?>"><?= Router::csrfField() ?><button class="btn btn-sm btn-outline-danger w-100" onclick="return confirm('Usunąć to robocze oświadczenie?')">Usuń wersję roboczą</button></form>
      <?php endif; ?>
    <?php else: ?>
      <?php if ($d['decided_name'] !== ''): ?><p class="small mb-0">Zweryfikował(a): <b><?= h($d['decided_name']) ?></b>, <?= h(substr((string)$d['decided_at'], 0, 16)) ?><?= trim((string)$d['decision_note']) !== '' ? '<br>' . h($d['decision_note']) : '' ?></p><?php endif; ?>
      <form method="post" action="<?= h(route('msues.decl.version', ['id' => $d['id']])) ?>"><?= Router::csrfField() ?><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-files"></i> Nowa wersja (zmiana treści)</button></form>
    <?php endif; ?>
    <?php if ($staff && $ezdActive): ?>
      <form method="post" action="<?= h(route('msues.decl.ezd', ['id' => $d['id']])) ?>"><?= Router::csrfField() ?>
        <button class="btn btn-sm btn-outline-dark w-100"><i class="bi bi-folder-plus"></i> <?= $d['ezd_sprawa_id'] ? 'Dołóż pismo do koszulki EZD' : 'Załóż sprawę w EZD' ?></button></form>
    <?php endif; ?>
  </div></section>

  <section class="card mb-3"><div class="card-header fw-semibold">Wersje</div><ul class="list-group list-group-flush">
    <?php foreach ($versions as $x): ?><li class="list-group-item small d-flex justify-content-between<?= $x['id'] === $d['id'] ? ' active-row fw-semibold' : '' ?>">
      <a href="<?= h(route('msues.decl', ['id' => $x['id']])) ?>">wersja <?= (int)$x['version'] ?></a><?= msuesBadge($x['statusEnum']) ?></li><?php endforeach; ?>
  </ul></section>

  <section class="card"><div class="card-header fw-semibold">Historia (kto, kiedy)</div><ul class="list-group list-group-flush">
    <?php foreach ($events as $e): ?><li class="list-group-item small"><span class="text-muted"><?= h(substr((string)$e['created_at'], 0, 16)) ?> · v<?= (int)$e['version'] ?></span><br>
      <b><?= h($e['actor_name']) ?></b> — <?= h($labels[$e['action']] ?? $e['action']) ?><?php if ($e['action'] === 'status'): ?>: <?= h(DeclarationStatus::tryFrom((string)$e['from_status'])?->label() ?? '') ?> → <?= h(DeclarationStatus::tryFrom((string)$e['to_status'])?->label() ?? '') ?><?php endif; ?>
      <?= trim((string)$e['note']) !== '' ? '<br>' . h($e['note']) : '' ?></li><?php endforeach; ?>
  </ul></section>
</div>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
