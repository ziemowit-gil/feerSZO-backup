<?php
/**
 * modules/ti_msues/view/profile.php — profil usługi MSUES grupy: oznaczenie, role, analiza potrzeb, efekty, materiały, wsparcie, ewaluacja, dokumenty.
 * Zmienne: $e (MsuesRequirements::evaluate(..., true)), $people, $staffUsers, $actor. Kontroler: MsuesController::profileForm.
 */
require __DIR__ . '/_parts.php';
require __DIR__ . '/_reqList.php';
$c = $e['course']; $p = $e['profile']; $cid = (int)$c['id'];
$title = 'Profil usługi MSUES — ' . MsuesSzoData::courseLabel($c);
$kierLabel = 'Certyfikacja MSUES';
include __DIR__ . '/_head.php';
$opts = [];
foreach (array_merge($people, $staffUsers) as $u) $opts[(int)$u['id']] = (string)$u['name'];
asort($opts);
$sel = function (string $name, $cur) use ($opts) {
    $o = '<option value="">— wybierz —</option>';
    foreach ($opts as $id => $n) $o .= '<option value="' . $id . '"' . ((int)$cur === $id ? ' selected' : '') . '>' . h($n) . '</option>';
    return $o;
};
$v = fn(string $k) => h((string)($p[$k] ?? ''));
?>
<p class="mb-2"><a href="<?= h(route('msues.reqs')) ?>">&larr; Wymagania usług MSUES</a> · <a href="kurs.php?id=<?= $cid ?>">karta grupy</a></p>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Profil usługi MSUES: <?= h(MsuesSzoData::courseLabel($c)) ?></h1>
    <div class="small text-muted">Szkolenie: <?= h((string)$e['hours']) ?> h zajęć<?= $p['updated_at'] ? ' · ostatnia zmiana: ' . h((string)$p['updated_name']) . ', ' . h(substr((string)$p['updated_at'], 0, 16)) : '' ?></div>
  </div>
  <?php if (!empty($p['is_msues'])): ?><div style="min-width:240px"><?= msuesReqSummary($e) ?></div><?php endif; ?>
</div>

<form method="post" action="<?= h(route('msues.profile.save', ['id' => $cid])) ?>"><?= Router::csrfField() ?>
<section class="card mb-3"><div class="card-body d-flex flex-wrap gap-4 align-items-center">
  <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="pfMs" name="is_msues" value="1" <?= !empty($p['is_msues']) ? 'checked' : '' ?>>
    <label class="form-check-label fw-semibold" for="pfMs">Grupa jest usługą w standardzie MSUES</label></div>
  <fieldset class="d-flex gap-3 align-items-center"><legend class="small fw-semibold mb-0 float-none w-auto me-2">Rodzaj szkolenia</legend>
    <div class="form-check m-0"><input class="form-check-input" type="radio" name="service_kind" id="pfOpen" value="open" <?= ($p['service_kind'] ?? 'open') !== 'closed' ? 'checked' : '' ?>><label class="form-check-label" for="pfOpen">otwarte</label></div>
    <div class="form-check m-0"><input class="form-check-input" type="radio" name="service_kind" id="pfClosed" value="closed" <?= ($p['service_kind'] ?? '') === 'closed' ? 'checked' : '' ?>><label class="form-check-label" for="pfClosed">zamknięte</label></div>
  </fieldset>
</div></section>

<section class="card mb-3" id="roles"><div class="card-header fw-semibold">Role (III. Infrastruktura i obsługa, II. Kadra)</div><div class="card-body row g-2">
  <div class="col-md-6"><label class="form-label small mb-0" for="pfOp">Opiekun merytoryczny</label>
    <select class="form-select form-select-sm" id="pfOp" name="opiekun_id"><?= $sel('opiekun_id', $p['opiekun_id']) ?></select>
    <div class="form-text">Min. 3 lata doświadczenia albo 300 h szkoleń / 500 h doradztwa — potwierdza zatwierdzone OKK-1.</div></div>
  <div class="col-md-6"><label class="form-label small mb-0" for="pfKo">Koordynator organizacyjny</label>
    <select class="form-select form-select-sm" id="pfKo" name="koordynator_id"><?= $sel('koordynator_id', $p['koordynator_id']) ?></select>
    <div class="form-text">Przy szkoleniu dłuższym niż 16 h musi to być inna osoba niż opiekun merytoryczny.</div></div>
</div></section>

<section class="card mb-3"><div class="card-header fw-semibold">I. Usługa</div><div class="card-body row g-3">
  <div class="col-12" id="needs"><h2 class="h6 mb-1">Analiza potrzeb uczestników</h2>
    <div class="row g-2"><div class="col-sm-3"><label class="form-label small mb-0" for="pfNa">Data analizy</label><input type="date" class="form-control form-control-sm" id="pfNa" name="needs_analysis_on" value="<?= $v('needs_analysis_on') ?>"></div>
    <div class="col-sm-9"><label class="form-label small mb-0" for="pfNaN">Sposób i wnioski</label><textarea class="form-control form-control-sm" id="pfNaN" name="needs_analysis_note" rows="2" placeholder="np. ankieta wstępna w naborze + rozmowy telefoniczne z 4 uczestnikami; wnioski: …"><?= $v('needs_analysis_note') ?></textarea></div></div></div>
  <div class="col-12" id="outcomes"><h2 class="h6 mb-1">Efekty uczenia się (mierzalne cele)</h2>
    <textarea class="form-control form-control-sm" id="pfLo" name="learning_outcomes" rows="5" aria-label="Efekty uczenia się" placeholder="Po szkoleniu uczestnik:&#10;W — zna … (sprawdzenie: test)&#10;U — potrafi … (sprawdzenie: zadanie praktyczne)&#10;K — jest gotów … (sprawdzenie: obserwacja)"><?= $v('learning_outcomes') ?></textarea>
    <label class="form-label small mb-0 mt-2" for="pfRq">Wymagania wstępne wobec uczestników (do katalogu)</label>
    <textarea class="form-control form-control-sm" id="pfRq" name="requirements" rows="2"><?= $v('requirements') ?></textarea></div>
  <div class="col-12" id="materials"><h2 class="h6 mb-1">Materiały szkoleniowe</h2>
    <div class="row g-2"><div class="col-sm-3"><label class="form-label small mb-0" for="pfMr">Data przeglądu</label><input type="date" class="form-control form-control-sm" id="pfMr" name="materials_reviewed_on" value="<?= $v('materials_reviewed_on') ?>"></div>
    <div class="col-sm-9"><label class="form-label small mb-0" for="pfMn">Źródła i prawa autorskie</label><textarea class="form-control form-control-sm" id="pfMn" name="materials_note" rows="2" placeholder="Materiały własne FEER (licencja OKK-1), źródła podane na slajdach, zasoby zewnętrzne z licencją …"><?= $v('materials_note') ?></textarea></div></div></div>
  <div class="col-12" id="support"><h2 class="h6 mb-1">Wsparcie po szkoleniu</h2>
    <textarea class="form-control form-control-sm" id="pfSf" name="support_form" rows="2" aria-label="Forma wsparcia po szkoleniu" placeholder="np. 30 dni konsultacji e-mail z prowadzącym, jedna konsultacja online po miesiącu"><?= $v('support_form') ?></textarea></div>
  <div class="col-12" id="evaluation"><h2 class="h6 mb-1">Ewaluacja</h2>
    <div class="row g-2"><div class="col-12"><label class="form-label small mb-0" for="pfEm">Ankieta ewaluacyjna (forma, link)</label><input class="form-control form-control-sm" id="pfEm" name="evaluation_method" value="<?= $v('evaluation_method') ?>" placeholder="np. Microsoft Forms https://…"></div>
    <div class="col-sm-3"><label class="form-label small mb-0" for="pfEr">Data raportu</label><input type="date" class="form-control form-control-sm" id="pfEr" name="evaluation_report_on" value="<?= $v('evaluation_report_on') ?>"></div>
    <div class="col-sm-9"><label class="form-label small mb-0" for="pfEs">Podsumowanie wyników</label><textarea class="form-control form-control-sm" id="pfEs" name="evaluation_summary" rows="2"><?= $v('evaluation_summary') ?></textarea></div></div></div>
</div></section>

<section class="card mb-3" id="docs"><div class="card-header fw-semibold">IV. Dokumentacja szkolenia</div><div class="card-body">
  <label class="form-label small mb-0" for="pfCd">Dokument wydawany po szkoleniu (zaświadczenie / certyfikat — wzór, podstawa)</label>
  <input class="form-control form-control-sm" id="pfCd" name="certificate_doc" maxlength="255" value="<?= $v('certificate_doc') ?>" placeholder="np. zaświadczenie o ukończeniu wg wzoru MEN / własny wzór Z-01">
  <div class="form-text">Program bierze się z planu nauczania grupy, lista obecności z dziennika („Licz frekwencję” w edycji kursu).</div>
</div></section>
<button class="btn btn-primary"><i class="bi bi-save"></i> Zapisz profil</button>
</form>

<?php if (!empty($p['is_msues'])): ?>
<section class="card mt-4"><div class="card-header fw-semibold">Wymagania MSUES tej usługi</div><?= msuesReqList($e) ?></section>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
