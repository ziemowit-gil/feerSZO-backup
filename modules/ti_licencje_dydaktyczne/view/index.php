<?php
/**
 * modules/ti_licencje_dydaktyczne/view/index.php — pulpit „Licencje dydaktyczne (AM5)”: kafle Metro (pula, wnioski, terminy 7 dni),
 * zakładki: Sprawy (lista z filtrem), Operacje AM11–AM24, Korekty AMSK, Do działania (jedna skrzynka: weryfikacja, po terminie, operacje do decyzji, korekty do podpisu), Wzory do druku, Alerty i przypomnienia.
 * Zmienne: $rows, $f, $counts, $toVerify, $overdueAsg, $alertEmails, $alertSetting, $agentOn, $actor.
 */
require __DIR__ . '/_parts.php';
require_once dirname(__DIR__) . '/lib/LicenseCorrection.php';
require_once dirname(__DIR__) . '/lib/LicenseOperationService.php';
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
];
?>
<?php
$opSvc = new LicenseOperationService();
$opCounts = $opSvc->counts();
$opOpen = array_merge($opSvc->search(['status' => 'pending']), $opSvc->search(['status' => 'signing']), $opSvc->search(['status' => 'approved']));
usort($opOpen, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
$opN = (int)$opCounts['pending'] + (int)($opCounts['signing'] ?? 0) + (int)$opCounts['approved'];
$corrPending = LicenseCorrection::pendingCount();
$corrRows = LicenseCorrection::search('', 15);
$corrNoPkg = LicenseCorrection::withoutPackage();
$actN = count($toVerify) + count($overdueAsg) + (int)$opCounts['pending'] + $corrPending + count($corrNoPkg);
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="me-auto mb-0"><span class="sym">AM5</span> <span class="sym" style="background:#0050ef">AM7</span> Licencje dydaktyczne i oprogramowanie</h1>
  <form class="d-flex" method="get" action="<?= h(route('ld.scan')) ?>" role="search" aria-label="Skanuj kod formularza AM (Code128 w stopce: LD…, OP…, AMSK-…)">
    <label class="visually-hidden" for="kod">Skanuj kod formularza AM (Code128 w stopce: LD…, OP…, AMSK-…)</label>
    <div class="input-group input-group-sm"><span class="input-group-text"><i class="bi bi-upc-scan" aria-hidden="true"></i></span>
      <input class="form-control" id="kod" name="kod" autocomplete="off" autocapitalize="characters" placeholder="Skanuj kod formularza" style="min-width:13rem"><button class="btn btn-metro dark">Otwórz</button></div></form>
  <a class="btn btn-metro" href="<?= h(route('ld.new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Wniosek AM5 — materiały</a>
  <a class="btn btn-metro" style="background:#0050ef" href="<?= h(route('ld.new7')) ?>"><i class="bi bi-microsoft" aria-hidden="true"></i> Wniosek AM7 — oprogramowanie</a>
</div>

<nav class="tiles" aria-label="Podsumowanie">
  <?php foreach ($tiles as [$label, $n, $color, $q]): ?>
    <a class="tile" style="background:<?= $color ?>" href="<?= $u($q) ?>"><span class="n"><?= (int)$n ?></span><span class="l"><?= h($label) ?></span></a>
  <?php endforeach; ?>
</nav>

<?php $_tabs = [
    'sprawy'     => ['Sprawy', count($rows), false],
    'operacje'   => ['Operacje AM11–AM24', $opN, false],
    'korekty'    => ['Korekty AMSK', $corrPending, true],
    'dzialania'  => ['Do działania', $actN, true],
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

<details class="mt-3 text-secondary"><summary class="fw-semibold">Jak to działa (AM5, AM7, numer sprawy, termin 7 dni)</summary><p class="mt-1 mb-0"><b>AM5</b> — licencje na materiały dydaktyczne: wniosek do licencjodawcy (AM5/1), oświadczenia kursanta (AM5/2, AM5/3).
  <b>AM7</b> — licencja / dostęp do oprogramowania i usług cyfrowych (dowolny dostawca; konto w tenancie ośrodka lub inna aktywacja): wniosek podpisywany przez kursanta (AM7/1), decyzja placówki zatwierdzana przez kierownika i wysyłana w PDF (AM7/2 / AM7/3), zlecenie Helpdesku IT, oświadczenia (AM7/4, AM7/5).
  Każda sprawa ma numer <b><?= h(LD_CASE_PREFIX) ?>/NNNNNN/AB</b>, każdy etap — termin <b>do <?= LD_REALIZATION_DAYS ?> dni</b>; wszczęcie wysyła e-mail i SMS do osoby zainteresowanej. Kod Code128 w stopce każdego formularza łączy go ze sprawą (pole „Skanuj kod”).</p></details>
</div>

<div class="ld-tabpanel" id="ldp-operacje" data-ld-panel="operacje" role="tabpanel" aria-labelledby="ldt-operacje" hidden>
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <p class="text-secondary mb-0 me-auto">Zmiana płatnika (AM20), planu, zawieszenie, cofnięcie, przedłużenie oraz wnioski AM11–AM13. Otwarte operacje: do decyzji, czekające na potwierdzenie osoby, w realizacji.</p>
    <a class="btn btn-metro green" href="<?= h(route('ld.am20')) ?>"><i class="bi bi-cash-coin" aria-hidden="true"></i> AM20 + AM20-1 — zmiana płatnika</a>
    <a class="btn btn-metro dark" href="<?= h(route('ld.accounts')) ?>"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Wszystkie operacje i konta</a>
  </div>
  <div class="d-flex flex-wrap gap-2 mb-2" aria-label="Kolejka operacji">
    <?= ldChip('Czeka na decyzję: ' . (int)$opCounts['pending'], '#f0a30a') ?> <?= ldChip('Czeka na potwierdzenie osoby: ' . (int)($opCounts['signing'] ?? 0), '#fa6800') ?> <?= ldChip('W realizacji: ' . (int)$opCounts['approved'], '#1ba1e2') ?>
  </div>
  <div class="table-responsive panel p-0"><table class="table table-hover mb-0">
    <thead><tr><th>Nr sprawy</th><th>Operacja</th><th>Osoba</th><th>Stan</th><th>Termin</th></tr></thead><tbody>
    <?php if (!$opOpen): ?><tr><td colspan="5" class="text-secondary p-3">Brak otwartych operacji.</td></tr><?php endif; ?>
    <?php foreach (array_slice($opOpen, 0, 15) as $o): $of = $o['formEnum']; ?>
      <tr><td class="text-nowrap"><a href="<?= h(route('ld.op', ['id' => $o['id']])) ?>"><?= h($o['number']) ?></a></td>
        <td><span class="sym" style="background:<?= h($of->color()) ?>"><?= h($of->value) ?></span> <?= h($of->short()) ?></td>
        <td><?= h($o['account_name']) ?></td><td><?= ldChip($o['statusEnum']->label(), $o['statusEnum']->color()) ?></td><td class="small text-nowrap"><?= h(ldDate($o['due_on'])) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php if (count($opOpen) > 15): ?><p class="small text-secondary mt-1">Pokazano 15 z <?= count($opOpen) ?> — pozostałe w „Wszystkie operacje i konta”.</p><?php endif; ?>
</div>

<div class="ld-tabpanel" id="ldp-korekty" data-ld-panel="korekty" role="tabpanel" aria-labelledby="ldt-korekty" hidden>
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <p class="text-secondary mb-0 me-auto">Korekta danych w dokumentach AM czeka na <b>odręczny podpis kierownika</b> na druku kontrolnym AMSK/1 (QR + Code128). Wystawisz ją z karty sprawy lub operacji (zakładka / sekcja Korekty AMSK).</p>
    <a class="btn btn-metro" style="background:#7c2d12" href="<?= h(route('ld.corr.index')) ?>"><i class="bi bi-pencil-square" aria-hidden="true"></i> Wszystkie korekty i wyszukiwarka</a>
  </div>
  <div class="table-responsive panel p-0"><table class="table table-hover mb-0">
    <thead><tr><th>Kod</th><th>Sprawa / dokument</th><th>Zakres</th><th>Stan</th><th>Wystawiono</th></tr></thead><tbody>
    <?php if (!$corrRows): ?><tr><td colspan="5" class="text-secondary p-3">Brak korekt.</td></tr><?php endif; ?>
    <?php foreach ($corrRows as $c): ?>
      <tr><td class="text-nowrap"><a href="<?= h(route('ld.corr', ['id' => $c['id']])) ?>"><?= h(LicenseCorrection::code((int)$c['id'])) ?></a></td>
        <td><?= h($c['target_number']) ?> <span class="small text-secondary"><?= $c['scope'] === 'operation' ? '(operacja)' : '(sprawa)' ?></span></td>
        <td class="small"><?= h(implode(', ', array_map(fn($i) => $i['label'], $c['itemsList']))) ?></td>
        <td><?= ldChip(LicenseCorrection::statusLabel($c['status']), LicenseCorrection::statusColor($c['status'])) ?></td>
        <td class="small text-nowrap"><?= h(ldDt($c['created_at'])) ?> · <?= h($c['created_name']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
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

<?php $opPend = array_values(array_filter($opOpen, fn($o) => $o['status'] === 'pending')); $corrPend = array_values(array_filter($corrRows, fn($c) => $c['status'] === LicenseCorrection::PENDING)); ?>
<?php if ($opPend): ?>
<section class="panel accent">
  <h2 class="mt-0">Operacje czekające na decyzję (<?= count($opPend) ?>)</h2>
  <ul class="mb-0"><?php foreach ($opPend as $o): ?><li><a href="<?= h(route('ld.op', ['id' => $o['id']])) ?>"><?= h($o['number']) ?></a> — <?= h($o['formEnum']->short()) ?>, <?= h($o['account_name']) ?>, termin <?= h(ldDate($o['due_on'])) ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php if ($corrPend): ?>
<section class="panel warn">
  <h2 class="mt-0">Korekty AMSK czekające na podpis kierownika (<?= count($corrPend) ?>)</h2>
  <ul class="mb-0"><?php foreach ($corrPend as $c): ?><li><a href="<?= h(route('ld.corr', ['id' => $c['id']])) ?>"><?= h(LicenseCorrection::code((int)$c['id'])) ?></a> — <?= h($c['target_number']) ?>, <?= h(implode(', ', array_map(fn($i) => $i['label'], $c['itemsList']))) ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php if ($corrNoPkg): ?>
<section class="panel warn">
  <h2 class="mt-0">Zatwierdzone korekty bez pakietu druków — ręczne generowanie (<?= count($corrNoPkg) ?>)</h2>
  <ul class="mb-0"><?php foreach ($corrNoPkg as $c): ?><li><a href="<?= h(route('ld.corr', ['id' => $c['id']])) ?>#pakiet"><?= h(LicenseCorrection::code((int)$c['id'])) ?></a> — <?= h($c['target_number']) ?>: wygeneruj pakiet poprawionych druków AM i wyślij ZIP z hasłem</li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php if (!$toVerify && !$overdueAsg && !$opPend && !$corrPend && !$corrNoPkg): ?><p class="panel text-secondary">Nic nie czeka na działanie: brak oświadczeń do weryfikacji, spraw po terminie 7 dni, operacji do decyzji i korekt do podpisu.</p><?php endif; ?>
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
