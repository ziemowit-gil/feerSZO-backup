<?php
/**
 * modules/ti_licencje_dydaktyczne/view/form.php — wniosek AM5/1 (nowy / edycja roboczego): sekcje jak na wzorze
 * (A miejsce i data, B licencjodawca, C wnioskodawca i kurs, D utwór, E zakres, F okres/terytorium/wynagrodzenie)
 * + osoba zainteresowana licencją (dostanie e-mail i SMS o wszczęciu) i limit miejsc w puli.
 * Zmienne: $l (null = nowy), $errors, $old, $courses, $people (['client' => …, 'user' => …]), $actor.
 */
require __DIR__ . '/_parts.php';
$title = $l ? 'Wniosek ' . ($l['case_number'] ?: LicenseDoc::Am5Request->symbol()) : 'Nowy wniosek AM5/1';
$kierLabel = $title;
include __DIR__ . '/_head.php';
$v = fn(string $k) => h((string)($old[$k] ?? ''));
$err = fn(string $k) => isset($errors[$k]) ? '<div id="e-' . $k . '" class="invalid-feedback d-block">' . h($errors[$k]) . '</div>' : '';
$inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid" aria-invalid="true" aria-describedby="e-' . $k : '';
$kind = (string)($old['interested_kind'] ?? '');
$iid = (int)($old['interested_id_' . $kind] ?? ($old['interested_id'] ?? 0));
$action = $l ? route('ld.edit', ['id' => $l['id']]) : route('ld.new');
?>
<div class="pivot"><a href="<?= h(route('ld.index')) ?>">Pulpit</a><a href="<?= h($action) ?>" aria-current="page"><?= h($title) ?></a></div>
<h1><span class="sym"><?= LicenseDoc::Am5Request->symbol() ?></span> <?= h(LicenseDoc::Am5Request->title()) ?></h1>
<?php if ($l): ?><p class="text-secondary">Sprawa <b><?= h($l['case_number']) ?></b> — wniosek roboczy. Po wszczęciu treść jest zamrożona.</p>
<?php else: ?><p class="text-secondary">Sprawa dostanie numer <b><?= h(LD_CASE_PREFIX) ?>/NNNNNN/XX</b> (kolejny numer + inicjały osoby zainteresowanej) przy zapisie.</p><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger" role="alert">Popraw zaznaczone pola (<?= count($errors) ?>).</div><?php endif; ?>

<form method="post" action="<?= h($action) ?>" novalidate>
<?= Router::csrfField() ?>
<section class="panel accent">
  <h2 class="mt-0">A. Miejsce i data · C. Wnioskodawca</h2>
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label" for="place">Miejscowość</label><input class="form-control<?= $inv('place') ?>" id="place" name="place" value="<?= $v('place') ?>"><?= $err('place') ?></div>
    <div class="col-md-3"><label class="form-label" for="date">Data wniosku</label><input class="form-control" type="date" id="date" name="date" value="<?= $v('date') ?>"></div>
    <div class="col-md-5"><label class="form-label" for="applicant_name">Wnioskodawca / przyszły licencjobiorca *</label><input class="form-control<?= $inv('applicant_name') ?>" id="applicant_name" name="applicant_name" value="<?= $v('applicant_name') ?>"><?= $err('applicant_name') ?></div>
    <div class="col-md-12"><label class="form-label" for="applicant_contact">Adres lub dane kontaktowe wnioskodawcy</label><textarea class="form-control" id="applicant_contact" name="applicant_contact" rows="2"><?= $v('applicant_contact') ?></textarea></div>
    <div class="col-md-6"><label class="form-label" for="course_id">Kurs / grupa TI</label>
      <select class="form-select" id="course_id" name="course_id"><option value="">— spoza SZO (wpisz nazwę obok) —</option>
        <?php foreach ($courses as $c): $lab = (trim((string)$c['display_name']) !== '' ? $c['display_name'] : $c['name']) . (trim((string)$c['group_code']) !== '' ? ' (' . $c['group_code'] . ')' : ''); ?>
          <option value="<?= (int)$c['id'] ?>"<?= (int)($old['course_id'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-6"><label class="form-label" for="course_name">Nazwa kursu / zajęć (do wniosku)</label><input class="form-control" id="course_name" name="course_name" value="<?= $v('course_name') ?>" placeholder="puste = nazwa wybranej grupy"></div>
  </div>
</section>

<section class="panel">
  <h2 class="mt-0">B. Licencjodawca</h2>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="licensor_name">Imię i nazwisko lub firma licencjodawcy *</label><input class="form-control<?= $inv('licensor_name') ?>" id="licensor_name" name="licensor_name" value="<?= $v('licensor_name') ?>"><?= $err('licensor_name') ?></div>
    <div class="col-md-3"><label class="form-label" for="licensor_email">E-mail (link do decyzji)</label><input class="form-control<?= $inv('licensor_email') ?>" type="email" id="licensor_email" name="licensor_email" value="<?= $v('licensor_email') ?>"><?= $err('licensor_email') ?></div>
    <div class="col-md-3"><label class="form-label" for="licensor_phone">Telefon (fallback SMS)</label><input class="form-control<?= $inv('licensor_phone') ?>" id="licensor_phone" name="licensor_phone" value="<?= $v('licensor_phone') ?>"><?= $err('licensor_phone') ?></div>
    <div class="col-md-12"><label class="form-label" for="licensor_address">Adres licencjodawcy</label><textarea class="form-control" id="licensor_address" name="licensor_address" rows="2"><?= $v('licensor_address') ?></textarea>
      <div class="form-text">Bez e-maila wniosek idzie obiegiem papierowym: biuro dostaje alert, decyzję wpisuje się z wydruku.</div></div>
  </div>
</section>

<section class="panel">
  <h2 class="mt-0">D. Utwór lub materiał</h2>
  <div class="row g-3">
    <div class="col-md-12"><label class="form-label" for="title">Tytuł *</label><input class="form-control<?= $inv('title') ?>" id="title" name="title" value="<?= $v('title') ?>"><?= $err('title') ?></div>
    <div class="col-md-12"><label class="form-label" for="work_description">Dokładny tytuł, autor i opis utworu lub materiału *</label><textarea class="form-control<?= $inv('work_description') ?>" id="work_description" name="work_description" rows="3"><?= $v('work_description') ?></textarea><?= $err('work_description') ?></div>
  </div>
</section>

<section class="panel">
  <h2 class="mt-0">E. Zakres wnioskowanej licencji</h2>
  <?= isset($errors['scopes']) ? '<div class="alert alert-danger py-1" role="alert">' . h($errors['scopes']) . '</div>' : '' ?>
  <?php foreach (LicenseRequestDto::SCOPES as $k => [$label, $hint]): $on = !empty($old['scope_' . $k]); ?>
    <div class="row g-2 align-items-start mb-2">
      <div class="col-md-5"><div class="form-check"><input class="form-check-input" type="checkbox" id="sc-<?= $k ?>" name="scope_<?= $k ?>" value="1"<?= $on ? ' checked' : '' ?>>
        <label class="form-check-label" for="sc-<?= $k ?>"><?= h($label) ?></label></div></div>
      <div class="col-md-7"><label class="visually-hidden" for="sd-<?= $k ?>"><?= h($hint) ?></label>
        <input class="form-control form-control-sm<?= $inv('scope_' . $k . '_detail') ?>" id="sd-<?= $k ?>" name="scope_<?= $k ?>_detail" value="<?= $v('scope_' . $k . '_detail') ?>" placeholder="<?= h($hint) ?>"><?= $err('scope_' . $k . '_detail') ?></div>
    </div>
  <?php endforeach; ?>
</section>

<section class="panel">
  <h2 class="mt-0">F. Okres, terytorium, wynagrodzenie · pula</h2>
  <div class="row g-3">
    <div class="col-md-3"><label class="form-label" for="period_from">Okres korzystania — od *</label><input class="form-control<?= $inv('period_from') ?>" type="date" id="period_from" name="period_from" value="<?= $v('period_from') ?>"><?= $err('period_from') ?></div>
    <div class="col-md-3"><label class="form-label" for="period_to">do *</label><input class="form-control<?= $inv('period_to') ?>" type="date" id="period_to" name="period_to" value="<?= $v('period_to') ?>"><?= $err('period_to') ?></div>
    <div class="col-md-3"><label class="form-label" for="territory">Terytorium *</label><input class="form-control<?= $inv('territory') ?>" id="territory" name="territory" value="<?= $v('territory') ?>" placeholder="np. Polska"><?= $err('territory') ?></div>
    <div class="col-md-3"><label class="form-label" for="seats">Liczba miejsc (0 = bez limitu)</label><input class="form-control" type="number" min="0" id="seats" name="seats" value="<?= $v('seats') ?: '0' ?>"></div>
    <div class="col-md-12"><label class="form-label" for="remuneration">Wynagrodzenie lub informacja o nieodpłatności *</label><input class="form-control<?= $inv('remuneration') ?>" id="remuneration" name="remuneration" value="<?= $v('remuneration') ?>" placeholder="np. licencja nieodpłatna"><?= $err('remuneration') ?></div>
  </div>
</section>

<section class="panel warn">
  <h2 class="mt-0">Osoba zainteresowana licencją</h2>
  <p class="small text-secondary">Przy wszczęciu dostaje e-mail i SMS: kto wszczął proces, numer sprawy, materiał i termin realizacji (do <?= LD_REALIZATION_DAYS ?> dni).
    Po udzieleniu licencji zostanie jej automatycznie przydzielona i zaproszona do oświadczeń AM5/2 i AM5/3.</p>
  <?= $err('interested_kind') ?>
  <div class="row g-3">
    <div class="col-md-3"><label class="form-label" for="interested_kind">Kto</label>
      <select class="form-select<?= $inv('interested_kind') ?>" id="interested_kind" name="interested_kind">
        <option value="">— wybierz —</option>
        <option value="client"<?= $kind === 'client' ? ' selected' : '' ?>>Uczestnik (kursant TI)</option>
        <option value="user"<?= $kind === 'user' ? ' selected' : '' ?>>Współpracownik / wolontariusz</option>
        <option value="external"<?= $kind === 'external' ? ' selected' : '' ?>>Osoba spoza SZO</option>
      </select></div>
    <div class="col-md-4" data-ld-kind="client"><label class="form-label" for="ic">Uczestnik<?= !empty($old['course_id']) ? ' (z wybranej grupy)' : '' ?></label>
      <select class="form-select" id="ic" name="interested_id_client"><option value="">—</option>
        <?php foreach ($people['client'] as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $kind === 'client' && $iid === (int)$p['id'] ? ' selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4" data-ld-kind="user"><label class="form-label" for="iu">Współpracownik</label>
      <select class="form-select" id="iu" name="interested_id_user"><option value="">—</option>
        <?php foreach ($people['user'] as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $kind === 'user' && $iid === (int)$p['id'] ? ' selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-5"><label class="form-label" for="interested_name">Imię i nazwisko (spoza SZO)</label><input class="form-control<?= $inv('interested_name') ?>" id="interested_name" name="interested_name" value="<?= $v('interested_name') ?>"><?= $err('interested_name') ?></div>
    <div class="col-md-4"><label class="form-label" for="interested_email">E-mail (puste = z SZO)</label><input class="form-control<?= $inv('interested_email') ?>" type="email" id="interested_email" name="interested_email" value="<?= $v('interested_email') ?>"><?= $err('interested_email') ?></div>
    <div class="col-md-3"><label class="form-label" for="interested_phone">Telefon (puste = z SZO)</label><input class="form-control<?= $inv('interested_phone') ?>" id="interested_phone" name="interested_phone" value="<?= $v('interested_phone') ?>"><?= $err('interested_phone') ?></div>
  </div>
</section>

<div class="d-flex flex-wrap gap-2 mb-4">
  <button class="btn btn-metro dark" type="submit">Zapisz roboczo</button>
  <button class="btn btn-metro" type="submit" name="start_now" value="1">Zapisz i wszcznij proces (e-mail + SMS)</button>
  <a class="btn btn-outline-secondary" href="<?= h($l ? route('ld.show', ['id' => $l['id']]) : route('ld.index')) ?>">Anuluj</a>
</div>
</form>
<script>
(function () {
  var sel = document.getElementById('interested_kind');
  function sync() { document.querySelectorAll('[data-ld-kind]').forEach(function (el) { el.hidden = el.getAttribute('data-ld-kind') !== sel.value; }); }
  sel.addEventListener('change', sync); sync();
})();
</script>
<?php include __DIR__ . '/_foot.php'; ?>
