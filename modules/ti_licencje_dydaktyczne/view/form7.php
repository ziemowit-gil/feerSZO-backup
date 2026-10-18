<?php
/**
 * modules/ti_licencje_dydaktyczne/view/form7.php — wniosek AM7/1 (Office 365) w panelu: adresat, kursant, kurs, konto, plan,
 * sposób dostępu (tenant ośrodka / inny), okres, uzasadnienie. Po wszczęciu kursant dostaje e-mail + SMS i podpisuje wniosek w portalu.
 * Zmienne: $l (null = nowy), $errors, $old, $courses, $people, $actor.
 */
require __DIR__ . '/_parts.php';
$title = $l ? 'Wniosek ' . $l['case_number'] : 'Nowy wniosek AM7 (Office 365)';
$kierLabel = $title;
include __DIR__ . '/_head.php';
$v = fn(string $k) => h((string)($old[$k] ?? ''));
$err = fn(string $k) => isset($errors[$k]) ? '<div id="e-' . $k . '" class="invalid-feedback d-block">' . h($errors[$k]) . '</div>' : '';
$inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid" aria-invalid="true" aria-describedby="e-' . $k : '';
$kind = (string)($old['interested_kind'] ?? 'client');
$iid = (int)($old['interested_id_client'] ?? ($old['interested_id'] ?? 0));
$action = $l ? route('ld.edit', ['id' => $l['id']]) : route('ld.new7');
?>
<div class="pivot"><a href="<?= h(route('ld.index')) ?>">Pulpit</a><a href="<?= h($action) ?>" aria-current="page"><?= h($title) ?></a></div>
<h1><span class="sym" style="background:#0050ef">AM7</span> <?= h(LicenseDoc::Am7Request->title()) ?></h1>
<p class="text-secondary">Dostęp w <b>tenancie Microsoft 365 ośrodka</b>. Sprawa dostanie numer <b><?= h($l['case_number'] ?? LD_CASE_PREFIX . '/NNNNNN/XX') ?></b>.
  Po wszczęciu kursant dostaje e-mail i SMS z linkiem do podpisu wniosku (realizacja do <?= LD_REALIZATION_DAYS ?> dni); decyzję zatwierdza kierownik i wysyłamy ją e-mailem w PDF.</p>
<?php if ($errors): ?><div class="alert alert-danger" role="alert">Popraw zaznaczone pola (<?= count($errors) ?>).</div><?php endif; ?>

<form method="post" action="<?= h($action) ?>" novalidate>
<?= Router::csrfField() ?>
<section class="panel accent">
  <h2 class="mt-0">Miejsce, data i adresat</h2>
  <div class="row g-3">
    <div class="col-md-3"><label class="form-label" for="place">Miejscowość</label><input class="form-control" id="place" name="place" value="<?= $v('place') ?>"></div>
    <div class="col-md-3"><label class="form-label" for="date">Data</label><input class="form-control" type="date" id="date" name="date" value="<?= $v('date') ?>"></div>
    <div class="col-md-6"><label class="form-label" for="to">Do (podmiot udostępniający)</label><input class="form-control" id="to" name="to" value="<?= $v('to') ?>"></div>
  </div>
</section>

<section class="panel">
  <h2 class="mt-0">Kursant i kurs</h2>
  <?= $err('interested_kind') ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="course_id">Kurs / grupa TI</label>
      <select class="form-select" id="course_id" name="course_id"><option value="">— spoza SZO (wpisz nazwę) —</option>
        <?php foreach ($courses as $c): $lab = (trim((string)$c['display_name']) !== '' ? $c['display_name'] : $c['name']) . (trim((string)$c['group_code']) !== '' ? ' (' . $c['group_code'] . ')' : ''); ?>
          <option value="<?= (int)$c['id'] ?>"<?= (int)($old['course_id'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label" for="course_name">Kurs lub program dydaktyczny (do wniosku)</label><input class="form-control<?= $inv('course_name') ?>" id="course_name" name="course_name" value="<?= $v('course_name') ?>" placeholder="puste = nazwa grupy"><?= $err('course_name') ?></div>
    <div class="col-md-3"><label class="form-label" for="interested_kind">Kursant</label><select class="form-select" id="interested_kind" name="interested_kind">
      <option value="client"<?= $kind === 'client' ? ' selected' : '' ?>>uczestnik TI</option><option value="external"<?= $kind === 'external' ? ' selected' : '' ?>>spoza SZO</option></select></div>
    <div class="col-md-5" data-ld-kind="client"><label class="form-label" for="ic">Uczestnik</label><select class="form-select" id="ic" name="interested_id_client"><option value="">—</option>
      <?php foreach ($people['client'] as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $iid === (int)$p['id'] ? ' selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4" data-ld-kind="external"><label class="form-label" for="interested_name">Imię i nazwisko kursanta</label><input class="form-control<?= $inv('interested_name') ?>" id="interested_name" name="interested_name" value="<?= $v('interested_name') ?>"><?= $err('interested_name') ?></div>
    <div class="col-md-4"><label class="form-label" for="interested_email">E-mail do powiadomień (puste = z SZO)</label><input class="form-control<?= $inv('interested_email') ?>" type="email" id="interested_email" name="interested_email" value="<?= $v('interested_email') ?>"><?= $err('interested_email') ?></div>
    <div class="col-md-3"><label class="form-label" for="interested_phone">Telefon do SMS (puste = z SZO)</label><input class="form-control<?= $inv('interested_phone') ?>" id="interested_phone" name="interested_phone" value="<?= $v('interested_phone') ?>"><?= $err('interested_phone') ?></div>
  </div>
</section>

<section class="panel">
  <h2 class="mt-0">Wnioskowany dostęp</h2>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="account">Adres e-mail lub identyfikator konta</label><input class="form-control" id="account" name="account" value="<?= $v('account') ?>" placeholder="np. jan.kowalski@… (konto w tenancie ośrodka)"></div>
    <div class="col-md-6"><label class="form-label" for="plan">Wnioskowany plan Microsoft 365 *</label><input class="form-control<?= $inv('plan') ?>" id="plan" name="plan" value="<?= $v('plan') ?>" placeholder="np. Microsoft 365 A3"><?= $err('plan') ?></div>
    <div class="col-md-6"><span class="form-label d-block">Wnioskowany sposób dostępu</span>
      <div class="form-check"><input class="form-check-input" type="radio" name="access" value="tenant" id="ac1"<?= ($old['access'] ?? 'tenant') !== 'other' ? ' checked' : '' ?>><label class="form-check-label" for="ac1">konto w tenancie ośrodka</label></div>
      <div class="form-check"><input class="form-check-input" type="radio" name="access" value="other" id="ac2"<?= ($old['access'] ?? '') === 'other' ? ' checked' : '' ?>><label class="form-check-label" for="ac2">inny sposób:</label></div>
      <label class="visually-hidden" for="access_other">Inny sposób dostępu</label><input class="form-control form-control-sm<?= $inv('access_other') ?>" id="access_other" name="access_other" value="<?= $v('access_other') ?>"><?= $err('access_other') ?></div>
    <div class="col-md-3"><label class="form-label" for="period_from">Wnioskowany okres — od *</label><input class="form-control<?= $inv('period_from') ?>" type="date" id="period_from" name="period_from" value="<?= $v('period_from') ?>"><?= $err('period_from') ?></div>
    <div class="col-md-3"><label class="form-label" for="period_to">do *</label><input class="form-control<?= $inv('period_to') ?>" type="date" id="period_to" name="period_to" value="<?= $v('period_to') ?>"><?= $err('period_to') ?></div>
    <div class="col-12"><label class="form-label" for="justification">Uzasadnienie lub opis potrzeb dydaktycznych</label><textarea class="form-control" id="justification" name="justification" rows="3"><?= $v('justification') ?></textarea>
      <div class="form-text">Kursant może je uzupełnić przy podpisie w portalu.</div></div>
  </div>
</section>

<div class="d-flex flex-wrap gap-2 mb-4">
  <button class="btn btn-metro dark" type="submit">Zapisz roboczo</button>
  <button class="btn btn-metro" type="submit" name="start_now" value="1">Zapisz i wszcznij — e-mail + SMS do kursanta</button>
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
