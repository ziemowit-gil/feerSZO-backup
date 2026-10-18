<?php
/**
 * modules/ti_msues/view/index.php — pulpit kierownika „Certyfikacja MSUES”: uruchomienie (e-mail informacyjny), przełącznik
 * wymagalności, kolejka weryfikacji, gotowość usług do audytu, kadra, ustawienia EZD.
 * Zmienne: $services, $overview, $trainers, $candidates, $pending, $counts, $launched, $recipients, $ezdActive, $actor. Kontroler: MsuesController::index.
 */
require __DIR__ . '/_parts.php';
$title = 'Certyfikacja MSUES';
$req = MsuesComplianceService::declarationRequired();
include __DIR__ . '/_head.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-award text-primary" aria-hidden="true"></i> Certyfikacja MSUES 2.0 <small class="text-muted fs-6">— kadra, oświadczenia, gotowość do audytu</small></h1>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-sm btn-primary" href="<?= h(route('msues.reqs')) ?>"><i class="bi bi-clipboard2-check"></i> Wymagania usług</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= h(route('msues.orgdocs')) ?>"><i class="bi bi-building"></i> Dokumenty instytucji</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= h(route('msues.decls')) ?>"><i class="bi bi-list-check"></i> Wszystkie oświadczenia</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h(route('msues.mine')) ?>"><i class="bi bi-person"></i> Moje dokumenty</a>
  </div>
</div>

<?php if (!$launched): ?>
<section class="card launch mb-3" aria-labelledby="msuLaunchH">
  <div class="card-body">
    <h2 id="msuLaunchH" class="h5"><i class="bi bi-rocket-takeoff" aria-hidden="true"></i> Uruchom przygotowania do certyfikacji MSUES</h2>
    <p class="mb-2">MSUES (Małopolskie Standardy Usług Edukacyjno-Szkoleniowych) to znak jakości przyznawany przez WUP w Krakowie po audycie, który sprawdza m.in. kwalifikacje osób prowadzących.
       Uruchomienie wyśle kadrze aktywnych usług e-mail informacyjny (co to jest, po co, co zrobić) i może od razu założyć każdemu roboczą wersję oświadczenia wypełnioną danymi z SZO.</p>
    <form method="post" action="<?= h(route('msues.launch')) ?>" class="row g-2 align-items-end"><?= Router::csrfField() ?>
      <div class="col-sm-3"><label class="form-label small mb-0" for="msuDl">Termin dla kadry</label>
        <input type="date" id="msuDl" name="deadline" class="form-control form-control-sm" value="<?= h(date('Y-m-d', strtotime('+30 days'))) ?>"></div>
      <div class="col-sm-9">
        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="msuMail" name="send_mail" value="1" checked><label class="form-check-label" for="msuMail">wyślij e-mail informacyjny</label></div>
        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="msuSms" name="send_sms" value="1" checked><label class="form-check-label" for="msuSms">wyślij SMS</label></div>
        <span class="small text-muted">(<?= count($recipients) ?> os., każda osobno)</span><br>
        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="msuDr" name="create_drafts" value="1" checked><label class="form-check-label" for="msuDr">załóż robocze oświadczenia</label></div>
        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="msuEnf" name="enforce" value="1"><label class="form-check-label" for="msuEnf">od razu włącz wymóg oświadczenia</label></div>
      </div>
      <div class="col-12 d-flex gap-2 flex-wrap">
        <button class="btn btn-primary" onclick="return confirm('Uruchomić certyfikację MSUES i wysłać e-mail informacyjny do kadry?')"><i class="bi bi-rocket-takeoff"></i> Uruchom certyfikację MSUES</button>
        <a class="btn btn-outline-secondary" href="<?= h(route('msues.mail')) ?>" target="_blank" rel="noopener"><i class="bi bi-envelope-open"></i> Podgląd e-maila</a>
      </div>
    </form>
    <?php if ($recipients): ?><details class="mt-2 small"><summary>Odbiorcy e-maila (<?= count($recipients) ?>)</summary><?= h(implode(', ', array_map(fn($t) => $t['name'], $recipients))) ?></details><?php endif; ?>
  </div>
</section>
<?php else: ?>
<div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div><i class="bi bi-rocket-takeoff" aria-hidden="true"></i> Certyfikację uruchomił(a) <b><?= h($launched['name']) ?></b> <?= h(substr($launched['at'], 0, 16)) ?><?= $launched['deadline'] !== '' ? ' · termin dla kadry: <b>' . h(DeclarationForm::dateLong($launched['deadline'])) . '</b>' : '' ?>.</div>
  <div class="d-flex gap-2">
    <form method="post" action="<?= h(route('msues.remind')) ?>" class="d-flex align-items-center gap-2"><?= Router::csrfField() ?>
      <label class="small"><input type="checkbox" name="send_mail" value="1" checked> e-mail</label>
      <label class="small"><input type="checkbox" name="send_sms" value="1"> SMS</label>
      <button class="btn btn-sm btn-outline-primary" onclick="return confirm('Wysłać przypomnienie do osób bez zatwierdzonego oświadczenia?')"><i class="bi bi-envelope"></i> Przypomnij kadrze</button></form>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h(route('msues.mail')) ?>" target="_blank" rel="noopener">Podgląd e-maila</a>
  </div>
</div>
<?php endif; ?>

<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="tile"><h3>Do weryfikacji</h3><div class="n"><?= (int)($counts['pending'] ?? 0) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="tile"><h3>Zatwierdzone pod audyt</h3><div class="n"><?= (int)($counts['approved'] ?? 0) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="tile"><h3>Robocze / odrzucone</h3><div class="n"><?= (int)($counts['draft'] ?? 0) + (int)($counts['rejected'] ?? 0) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="tile">
    <h3>Wymóg formalnego oświadczenia</h3>
    <form method="post" action="<?= h(route('msues.toggle')) ?>" class="d-flex align-items-center gap-2"><?= Router::csrfField() ?>
      <span class="n" style="font-size:1.2rem"><?= $req ? '<span class="ok">WŁĄCZONY</span>' : '<span class="text-secondary">wyłączony</span>' ?></span>
      <button class="btn btn-sm <?= $req ? 'btn-outline-danger' : 'btn-outline-success' ?>" onclick="return confirm('<?= $req ? 'Wyłączyć wymóg? Braki staną się tylko ostrzeżeniami.' : 'Włączyć wymóg? Prowadzący bez zatwierdzonego oświadczenia nie zostanie przypisany do usługi, a paczka audytowa się nie wygeneruje.' ?>')"><?= $req ? 'Wyłącz' : 'Włącz' ?></button>
    </form>
    <div class="small text-muted">ustawienie <code>require_trainer_formal_declaration</code></div>
  </div></div>
</div>

<?php if ($pending): ?>
<section class="card mb-3"><div class="card-header fw-semibold">Oczekują na weryfikację</div>
<div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>Prowadzący</th><th>Usługa</th><th>Wersja</th><th>Wysłano</th><th>Skan</th><th></th></tr></thead>
  <tbody><?php foreach ($pending as $d): ?>
    <tr><td><?= h($d['trainer_name']) ?></td><td><?= h(msuesCourseName($d)) ?></td><td>v<?= (int)$d['version'] ?></td><td><?= h(substr((string)$d['submitted_at'], 0, 16)) ?></td>
      <td><?= $d['scan_path'] !== '' ? '<span class="ok">jest</span>' : '<span class="no">brak</span>' ?></td>
      <td class="text-end"><a class="btn btn-sm btn-primary" href="<?= h(route('msues.decl', ['id' => $d['id']])) ?>">Weryfikuj</a></td></tr>
  <?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>

<section class="card mb-3"><div class="card-header fw-semibold d-flex justify-content-between"><span>Gotowość usług do audytu</span><span class="small text-muted">aktywne grupy · % spełnionych kryteriów kadry</span></div>
<div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Usługa (grupa)</th><th>Prowadzący</th><th>Kadra</th><th style="width:220px">Gotowość</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($services as $s): $o = $overview[(int)$s['id']] ?? null; ?>
    <tr><td><a href="<?= h(route('msues.service', ['id' => $s['id']])) ?>"><?= h(MsuesSzoData::courseLabel($s)) ?></a></td>
      <td><?= h($s['instructor_name'] ?? '—') ?></td><td><?= (int)($o['staff'] ?? 0) ?></td>
      <td><?= $o ? msuesBar((int)$o['score'], (bool)$o['ready'], (bool)$o['blocked']) : '—' ?></td>
      <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= h(route('msues.service', ['id' => $s['id']])) ?>">Checklista</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(route('msues.service.json', ['id' => $s['id']])) ?>" title="Status gotowości w JSON">JSON</a></td></tr>
  <?php endforeach; if (!$services): ?><tr><td colspan="5" class="text-muted">Brak aktywnych usług.</td></tr><?php endif; ?>
  </tbody></table></div></section>

<div class="row g-3">
  <div class="col-lg-7">
    <section class="card h-100"><div class="card-header fw-semibold">Kadra</div>
    <ul class="list-group list-group-flush">
      <?php foreach ($trainers as $t): $a = $svc->declarations()->approvedFor((int)$t['id'], null); ?>
        <li class="list-group-item d-flex justify-content-between align-items-center gap-2 flex-wrap"><a href="<?= h(route('msues.trainer', ['id' => $t['id']])) ?>"><?= h($t['name']) ?></a>
          <span class="d-flex align-items-center gap-2">
          <?= $a ? '<span class="badge text-bg-success">oświadczenie ogólne v' . (int)$a['version'] . '</span>' : '<span class="badge text-bg-light border">bez ogólnego oświadczenia</span>' ?>
          <form method="post" action="<?= h(route('msues.notify', ['id' => $t['id']])) ?>" class="d-inline"><?= Router::csrfField() ?><input type="hidden" name="_back" value="<?= h(route('msues.index')) ?>">
            <input type="hidden" name="send_mail" value="1"><input type="hidden" name="send_sms" value="1">
            <button class="btn btn-sm btn-outline-secondary" title="Wyślij tej osobie e-mail i SMS o certyfikacji MSUES" onclick="return confirm('Wysłać e-mail i SMS do: <?= h(addslashes((string)$t['name'])) ?>?')"><i class="bi bi-send"></i><span class="visually-hidden"> Powiadom <?= h($t['name']) ?></span></button></form>
          </span></li>
      <?php endforeach; if (!$trainers): ?><li class="list-group-item text-muted">Brak kadry w aktywnych usługach.</li><?php endif; ?>
    </ul></section>
  </div>
  <div class="col-lg-5">
    <section class="card mb-3"><div class="card-header fw-semibold">Nowe oświadczenie</div><div class="card-body">
      <form method="post" action="<?= h(route('msues.decl.create')) ?>" class="row g-2"><?= Router::csrfField() ?>
        <div class="col-12"><label class="form-label small mb-0" for="msuT">Prowadzący</label>
          <select id="msuT" name="trainer_id" class="form-select form-select-sm" required><option value="">— wybierz —</option>
          <?php foreach ($candidates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small mb-0" for="msuC">Usługa (puste = oświadczenie ogólne)</label>
          <select id="msuC" name="course_id" class="form-select form-select-sm"><option value="">ogólne — wszystkie usługi</option>
          <?php foreach ($services as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h(MsuesSzoData::courseLabel($s)) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small mb-0" for="msuP">Projekt (opcjonalnie)</label><input id="msuP" name="project" class="form-control form-control-sm" maxlength="190"></div>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-plus"></i> Utwórz i wypełnij z SZO</button></div>
      </form></div></section>
    <section class="card"><div class="card-header fw-semibold">Ustawienia EZD</div><div class="card-body">
      <?php if (!$ezdActive): ?><p class="small text-muted mb-2">Moduł EZD jest wyłączony — oświadczenia nie będą rejestrowane.</p><?php endif; ?>
      <form method="post" action="<?= h(route('msues.settings')) ?>" class="row g-2"><?= Router::csrfField() ?>
        <div class="col-6"><label class="form-label small mb-0" for="msuJ1">JRWA oświadczeń</label><input id="msuJ1" name="ezd_jrwa" class="form-control form-control-sm" value="<?= h(org_setting('msues_ezd_jrwa')) ?>" placeholder="561"></div>
        <div class="col-6"><label class="form-label small mb-0" for="msuJ2">JRWA paczek audytowych</label><input id="msuJ2" name="ezd_jrwa_audit" class="form-control form-control-sm" value="<?= h(org_setting('msues_ezd_jrwa_audit')) ?>" placeholder="562"></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="msuAuto" name="ezd_auto" value="1" <?= MsuesEzdService::autoOnApprove() ? 'checked' : '' ?>><label class="form-check-label small" for="msuAuto">po zatwierdzeniu pod audyt zakładaj koszulkę i rejestruj pismo w EZD</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="msuEj" name="ensure_jrwa" value="1"><label class="form-check-label small" for="msuEj">dodaj brakujące hasła JRWA 56 / 561 / 562</label></div></div>
        <div class="col-12"><button class="btn btn-sm btn-outline-primary">Zapisz</button></div>
      </form></div></section>
  </div>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
