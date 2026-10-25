<?php
/**
 * modules/ti_licencje_dydaktyczne/view/index.php — pulpit „Licencje dydaktyczne (AM5)”: kafle Metro (pula, wnioski, terminy 7 dni),
 * zakładki: Sprawy (lista z filtrem), Do działania (weryfikacja, po terminie), Wzory do druku, Alerty i przypomnienia.
 * Zmienne: $rows, $f, $counts, $toVerify, $overdueAsg, $alertEmails, $alertSetting, $agentOn, $actor.
 */
require __DIR__ . '/_parts.php';
$title = 'Licencje AM5 / AM7 i zarządzanie';
include __DIR__ . '/_head.php';
$u = fn(array $q) => h(route('ld.index', $q));
$tiles = [
    ['Wnioski robocze', $counts['draft'], '#647687', ['status' => 'draft']],
    ['Wszczęte — czekają na decyzję', $counts['started'], '#f0a30a', ['status' => 'started']],
    ['Po terminie 7 dni', $counts['overdue'], '#a20025', ['overdue' => 1]],
    ['Wolne', $counts['free'], '#1ba1e2', ['status' => 'free']],
    ['Przydzielone', $counts['assigned'], '#008a00', ['status' => 'assigned']],
    ['Zawieszone', $counts['suspended'], '#fa6800', ['status' => 'suspended']],
    ['Wygasłe', $counts['expired'], '#76608a', ['status' => 'expired']],
    ['Oświadczenia do weryfikacji', $counts['toVerify'], '#0050ef', null],
];
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0"><span class="sym">AM5</span> <span class="sym" style="background:#0050ef">AM7</span> Licencje dydaktyczne i oprogramowanie</h1>
  <a class="btn btn-metro green" href="<?= h(route('ld.am20')) ?>"><i class="bi bi-cash-coin" aria-hidden="true"></i> AM20 + AM20-1 — zmiana płatnika (formularz elektroniczny)</a>
  <a class="btn btn-metro green" href="<?= h(route('ld.accounts')) ?>"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Operacje na licencjach (AM11–AM24)</a>
  <a class="btn btn-metro" href="<?= h(route('ld.new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Wniosek AM5 — materiały</a>
  <a class="btn btn-metro" style="background:#0050ef" href="<?= h(route('ld.new7')) ?>"><i class="bi bi-microsoft" aria-hidden="true"></i> Wniosek AM7 — oprogramowanie</a>
</div>
<details class="mb-2 text-secondary"><summary class="fw-semibold">Jak to działa (AM5, AM7, numer sprawy, termin 7 dni)</summary><p class="mt-1 mb-0"><b>AM5</b> — licencje na materiały dydaktyczne: wniosek do licencjodawcy (AM5/1), oświadczenia kursanta (AM5/2, AM5/3).
  <b>AM7</b> — licencja / dostęp do oprogramowania i usług cyfrowych (dowolny dostawca; konto w tenancie ośrodka lub inna aktywacja): wniosek podpisywany przez kursanta (AM7/1), decyzja placówki zatwierdzana przez kierownika i wysyłana w PDF (AM7/2 / AM7/3), zlecenie Helpdesku IT, oświadczenia (AM7/4, AM7/5).
  Każda sprawa ma numer <b><?= h(LD_CASE_PREFIX) ?>/NNNNNN/AB</b>, każdy etap — termin <b>do <?= LD_REALIZATION_DAYS ?> dni</b>; wszczęcie wysyła e-mail i SMS do osoby zainteresowanej.</p></details>

<nav class="tiles" aria-label="Podsumowanie">
  <?php foreach ($tiles as [$label, $n, $color, $q]): ?>
    <a class="tile" style="background:<?= $color ?>" href="<?= $q === null ? '#dzialania' : $u($q) ?>"<?= $q === null ? ' data-ld-goto="dzialania"' : '' ?>><span class="n"><?= (int)$n ?></span><span class="l"><?= h($label) ?></span></a>
  <?php endforeach; ?>
</nav>

<?php $_tabs = [
    'sprawy'     => ['Sprawy', count($rows), false],
    'dzialania'  => ['Do działania', count($toVerify) + count($overdueAsg), true],
    'wzory'      => ['Wzory do druku', null, false],
    'ustawienia' => ['Alerty i przypomnienia', null, false],
]; $_tabDefault = 'sprawy'; $_tabsLabel = 'Sekcje pulpitu licencji';
include __DIR__ . '/_tabs.php'; ?>

<div class="ld-tabpanel" id="ldp-sprawy" data-ld-panel="sprawy" role="tabpanel" aria-labelledby="ldt-sprawy">
<form class="row g-2 align-items-end mb-2" method="get" action="licencjeDydaktyczne.php" role="search">
  <div class="col-md-5"><label class="form-label small" for="q">Szukaj</label><input class="form-control" id="q" name="q" value="<?= h($f['q']) ?>" placeholder="tytuł, licencjodawca, osoba, kurs"></div>
  <div class="col-md-4"><label class="form-label small" for="st">Stan</label>
    <select class="form-select" id="st" name="status"><option value="">wszystkie</option>
      <optgroup label="Wniosek"><?php foreach (LicenseRequestStatus::cases() as $s): ?><option value="<?= $s->value ?>"<?= $f['status'] === $s->value ? ' selected' : '' ?>><?= h($s->label()) ?></option><?php endforeach; ?></optgroup>
      <optgroup label="Pula licencji"><?php foreach (LicenseStatus::cases() as $s): ?><option value="<?= $s->value ?>"<?= $f['status'] === $s->value ? ' selected' : '' ?>><?= h($s->label()) ?></option><?php endforeach; ?></optgroup>
    </select></div>
  <div class="col-md-3 d-flex gap-2"><button class="btn btn-metro dark">Filtruj</button><?php if ($f['q'] !== '' || $f['status'] !== '' || $f['overdue']): ?><a class="btn btn-outline-secondary" href="licencjeDydaktyczne.php">Wyczyść</a><?php endif; ?></div>
</form>

<div class="table-responsive panel p-0">
<table class="table table-hover mb-0">
  <thead><tr><th>Nr sprawy</th><th>Utwór / materiał / dostęp</th><th>Licencjodawca / podmiot</th><th>Osoba zainteresowana</th><th>Okres</th><th>Miejsca</th><th>Stan</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-secondary p-3">Brak licencji<?= $f['q'] !== '' || $f['status'] !== '' ? ' dla tego filtra' : ' — zacznij od wniosku AM5/1' ?>.</td></tr><?php endif; ?>
  <?php foreach ($rows as $l): ?>
    <tr>
      <td class="text-nowrap"><a href="<?= h(route('ld.show', ['id' => $l['id']])) ?>"><?= h($l['case_number']) ?></a> <span class="sym"<?= $l['form'] === LicenseForm::Am7 ? ' style="background:#0050ef"' : '' ?>><?= h($l['form']->value) ?></span></td>
      <td><a href="<?= h(route('ld.show', ['id' => $l['id']])) ?>"><?= h($l['title']) ?></a><?php if ($l['course_name'] !== ''): ?><div class="small text-secondary"><?= h($l['course_name']) ?></div><?php endif; ?></td>
      <td><?= h($l['licensor_name']) ?></td>
      <td><?= h($l['interested_name']) ?></td>
      <td class="text-nowrap small"><?= h(trim(ldDate($l['period_from']) . ' — ' . ldDate($l['period_to']), ' —')) ?><?php if ($l['request_status'] === 'started'): ?><div>decyzja do <?= h(ldDate($l['due_on'])) ?></div><?php endif; ?></td>
      <td><?= (int)$l['activeAssignments'] ?><?= (int)$l['seats'] > 0 ? ' / ' . (int)$l['seats'] : '' ?></td>
      <td><?= ldStateChip($l) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

</div>

<div class="ld-tabpanel" id="ldp-dzialania" data-ld-panel="dzialania" role="tabpanel" aria-labelledby="ldt-dzialania" hidden>
<?php if ($toVerify): ?>
<section class="panel accent" id="weryfikacja">
  <h2 class="mt-0">Oświadczenia do weryfikacji</h2>
  <table class="table table-sm mb-0"><thead><tr><th>Osoba</th><th>Licencja</th><th>Złożono</th><th></th></tr></thead><tbody>
  <?php foreach ($toVerify as $a): ?>
    <tr><td><?= h($a['holder_name']) ?></td><td><?= h($a['license_title']) ?></td><td><?= h(ldDt($a['submitted_at'])) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-metro" href="<?= h(route('ld.show', ['id' => $a['license_id']])) ?>#przydzialy">Weryfikuj</a></td></tr>
  <?php endforeach; ?></tbody></table>
</section>
<?php endif; ?>

<?php if ($overdueAsg): ?>
<section class="panel warn">
  <div class="d-flex flex-wrap align-items-center gap-2"><h2 class="mt-0 me-auto">Oświadczenia po terminie 7 dni (<?= count($overdueAsg) ?>)</h2>
    <?= ldPost(route('ld.remind'), 'Wyślij przypomnienia (e-mail + SMS)', 'btn-metro orange', [], 'Wysłać przypomnienia do wszystkich osób po terminie? Każda dostanie nowy link i nowy termin 7 dni.') ?></div>
  <ul class="mb-0"><?php foreach ($overdueAsg as $a): ?>
    <li><a href="<?= h(route('ld.show', ['id' => $a['license_id']])) ?>#przydzialy"><?= h($a['holder_name']) ?></a> — <?= h($a['license_title']) ?>, termin minął <?= h(ldDate($a['due_on'])) ?></li>
  <?php endforeach; ?></ul>
</section>
<?php endif; ?>

<?php if (!$toVerify && !$overdueAsg): ?><p class="panel text-secondary">Nic nie czeka na działanie: brak oświadczeń do weryfikacji i brak spraw po terminie 7 dni.</p><?php endif; ?>
</div>

<div class="ld-tabpanel" id="ldp-wzory" data-ld-panel="wzory" role="tabpanel" aria-labelledby="ldt-wzory" hidden>
  <section><div class="panel">
    <h2 class="mt-0">Wzory do druku (obieg papierowy)</h2>
    <p class="small text-secondary">Puste formularze — gdy licencjodawca lub uczestnik nie korzysta z portalu. Decyzję z papieru wpisuje się na karcie licencji.</p>
    <ul class="list-unstyled mb-0">
      <?php foreach (LicenseDoc::cases() as $d): ?><li class="mb-1"><span class="sym"<?= $d->form() === LicenseForm::Am7 ? ' style="background:#0050ef"' : '' ?>><?= h($d->symbol()) ?></span> <a href="<?= h(route('ld.blank', ['doc' => $d->slug()])) ?>" target="_blank" rel="noopener"><?= h($d->title()) ?></a></li><?php endforeach; ?>
      <?php foreach (OperationForm::cases() as $of): ?><li class="mb-1"><span class="sym" style="background:<?= h($of->color()) ?>"><?= h($of->value) ?></span> <a href="<?= h(route('ld.op.blank', ['form' => $of->value])) ?>" target="_blank" rel="noopener"><?= h($of->title()) ?></a></li>
        <?php if ($of === OperationForm::Am20): ?><li class="mb-1"><span class="sym" style="background:<?= h($of->color()) ?>">AM20-1</span> <a href="<?= h(route('ld.op.blank', ['form' => 'AM20-1'])) ?>" target="_blank" rel="noopener">Wykaz dokumentów księgowych wystawionych dotąd przez FEER (załącznik do AM20)</a></li><?php endif; ?>
      <?php endforeach; ?>
    </ul>
  </div></section>
</div>

<div class="ld-tabpanel" id="ldp-ustawienia" data-ld-panel="ustawienia" role="tabpanel" aria-labelledby="ldt-ustawienia" hidden>
  <section><div class="panel">
    <h2 class="mt-0">Alerty dla biura</h2>
    <p class="small text-secondary">Nowe oświadczenia, decyzje licencjodawców i niedoręczone powiadomienia. Puste pole — fallback: e-mail organizacji, potem administratorzy.
      Teraz trafiają do: <b><?= h($alertEmails ? implode(', ', $alertEmails) : 'nikogo (uzupełnij!)') ?></b>.</p>
    <form method="post" action="<?= h(route('ld.settings')) ?>" class="d-flex gap-2"><?= Router::csrfField() ?>
      <label class="visually-hidden" for="ae">Adresy e-mail alertów</label>
      <input class="form-control" id="ae" name="alert_email" value="<?= h($alertSetting) ?>" placeholder="biuro@…, kierownik@…">
      <button class="btn btn-metro">Zapisz</button></form>
    <h2 class="mt-3">Automatyczne przypomnienia</h2>
    <p class="small text-secondary">Agent CRON codziennie od 7:00: oświadczenia po terminie (nowy link i nowe <?= LD_REALIZATION_DAYS ?> dni), niepodpisane wnioski AM7,
      a biuru — zestawienie wniosków AM5 bez decyzji licencjodawcy i projektów decyzji AM7 bez zatwierdzenia. Każda osoba i sprawa najwyżej raz na <?= LicenseDeadlineAgent::GAP_DAYS ?> dni.
      Teraz: <b><?= $agentOn ? 'włączone' : 'wyłączone' ?></b>.</p>
    <form method="post" action="<?= h(route('ld.settings.agent')) ?>" class="d-flex flex-wrap align-items-center gap-2"><?= Router::csrfField() ?>
      <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="ldRem" name="reminders_enabled" value="1"<?= $agentOn ? ' checked' : '' ?>>
        <label class="form-check-label" for="ldRem">Wysyłaj przypomnienia automatycznie</label></div>
      <button class="btn btn-metro">Zapisz</button></form>
  </div></section>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
