<?php
/**
 * crm/cases/add.php — Formularz nowej sprawy CRM.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_case_extras.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
require_once dirname(dirname(__DIR__)) . '/includes/crm_perms.php';
crm_require('cases', 'write');
if (!can_write('crm') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień.');
    header('Location: ' . APP_URL . '/crm/cases/index.php'); exit;
}
crm_migrate();

$PAGE_TITLE   = 'Nowa sprawa';
$contact_id   = (int)($_GET['contact_id'] ?? 0);
$errors       = [];

// Wstępnie wybrany kontakt
$prefill_contact = $contact_id
    ? db_one("SELECT id, imie_nazwisko, type FROM crm_contacts WHERE id=? AND crm_active=1", [$contact_id])
    : null;

// Szablon sprawy — wypełnia formularz; użytkownik może wszystko poprawić przed zapisem
$case_tpls  = crm_case_templates();
$case_types = crm_case_types();
$case_users = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");
$tpl_id    = (int)($_GET['tpl'] ?? 0);
$prefill   = ['title' => '', 'description' => '', 'priority' => 'medium'];
if ($tpl_id > 0 && ($tpl = crm_case_template($tpl_id))) {
    $prefill = crm_case_template_apply($tpl, (string)($contact['imie_nazwisko'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $cid   = (int)($_POST['contact_id'] ?? 0);
    $title = trim($_POST['title']       ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $status   = in_array($_POST['status']   ?? '', ['open','in_progress','closed','cancelled']) ? $_POST['status'] : 'open';
    $priority = in_array($_POST['priority'] ?? '', ['low','medium','high']) ? $_POST['priority'] : 'medium';

    if (!$cid)   $errors[] = 'Wybierz kontakt.';
    if (!$title) $errors[] = 'Tytuł sprawy jest wymagany.';

    if (!$errors) {
        $case_id = db_insert('crm_cases', [
            'contact_id'  => $cid,
            'title'       => $title,
            'description' => $desc ?: null,
            'status'      => $status,
            'priority'    => $priority,
            'type_id'     => (int)($_POST['type_id'] ?? 0) ?: null,
            'owner_id'    => (int)($_POST['owner_id'] ?? 0) ?: ((int)(current_user()['id'] ?? 0) ?: null),
            'due_date'    => trim((string)($_POST['due_date'] ?? '')) ?: null,
            'created_by'  => (int)(current_user()['id'] ?? 0),
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        require_once dirname(dirname(__DIR__)) . '/includes/crm_automation.php';
        crm_automation_fire('case_created', $cid, ['case_id' => $case_id]);
        flash_set('success', 'Sprawa „' . $title . '" została utworzona.');
        header('Location: ' . APP_URL . '/crm/cases/view.php?id=' . $case_id); exit;
    }
}

// Kontakty pobiera wyszukiwarka (crm/api/contacts_search.php), nie ładujemy całej bazy

include dirname(__DIR__) . '/includes/header_crm.php';
?>

<nav aria-label="breadcrumb" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="index.php">Sprawy</a></li>
    <li class="breadcrumb-item active">Nowa sprawa</li>
  </ol>
</nav>

<div class="crm-page-header mb-4">
  <div>
    <div class="crm-page-title"><i class="bi bi-briefcase-fill" style="color:#0176D3"></i> Nowa sprawa</div>
    <div class="crm-page-subtitle">Utwórz sprawę i powiąż ją z kontaktem</div>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger d-flex gap-2 mb-3">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
  <ul class="mb-0 ps-2"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<form method="post" novalidate>
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="row g-3">
<div class="col-lg-8">

  <!-- Kontakt -->
  <div class="card mb-3">
    <div class="card-body">
      <div class="step-label d-flex align-items-center gap-2 mb-3" style="font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6B7280;padding-bottom:.4rem;border-bottom:1px solid #F3F4F6">
        <i class="bi bi-person-fill" style="color:#0176D3"></i> Kontakt
      </div>
      <?php if ($prefill_contact): ?>
      <input type="hidden" name="contact_id" value="<?= $prefill_contact['id'] ?>">
      <div class="d-flex align-items-center gap-2 p-2 border rounded" style="background:#EEF4FF;border-color:#93C5FD!important">
        <div class="crm-avatar <?= $prefill_contact['type']==='organizacja'?'org':'' ?>"
             style="background:<?= $prefill_contact['type']==='organizacja'?'var(--crm-navy)':'var(--crm-primary)' ?>">
          <?= h(CrmManager::makeInitials($prefill_contact['imie_nazwisko'])) ?>
        </div>
        <div class="fw-semibold"><?= h($prefill_contact['imie_nazwisko']) ?></div>
        <a href="add.php" class="ms-auto text-muted small"><i class="bi bi-x"></i> Zmień</a>
      </div>
      <?php else: ?>
      <?php /* Wyszukiwarka zamiast listy wszystkich kontaktów: przy kilku tysiącach
               kartotek select ważył pół megabajta i i tak nikt nie scrollował. */ ?>
      <label class="form-label fw-semibold" for="ccSearch">Kontakt <span class="text-danger">*</span></label>
      <div class="position-relative">
        <input type="text" id="ccSearch" class="form-control" autocomplete="off"
               placeholder="Wpisz nazwisko, e-mail albo organizację…"
               aria-describedby="ccHint" aria-expanded="false" aria-autocomplete="list" role="combobox">
        <input type="hidden" name="contact_id" id="ccId" value="<?= (int)($_POST['contact_id'] ?? 0) ?>" required>
        <div id="ccDrop" class="list-group shadow-sm"
             style="display:none;position:absolute;z-index:1050;width:100%;max-height:260px;overflow-y:auto;top:calc(100% + 4px)"></div>
      </div>
      <?php
        $cc_prev = (int)($_POST['contact_id'] ?? 0)
            ? db_one("SELECT imie_nazwisko, organizacja FROM crm_contacts WHERE id=?", [(int)$_POST['contact_id']])
            : null;
      ?>
      <div id="ccPicked" class="mt-2" style="display:<?= $cc_prev ? '' : 'none' ?>">
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle d-inline-flex align-items-center gap-2"
              style="font-size:.85rem;padding:.4rem .6rem">
          <i class="bi bi-person-fill" aria-hidden="true"></i>
          <span id="ccPickedName"><?= $cc_prev ? h($cc_prev['imie_nazwisko'] . ($cc_prev['organizacja'] ? ' · ' . $cc_prev['organizacja'] : '')) : '' ?></span>
          <button type="button" class="btn-close btn-sm" id="ccClear" aria-label="Wyczyść wybór"></button>
        </span>
      </div>
      <div class="form-text" id="ccHint" style="font-size:.74rem">
        Nie ma takiego kontaktu? <a href="<?= APP_URL ?>/crm/contact/quick_add.php">Dodaj kartotekę</a>
        albo użyj szybkiej akcji (Alt+N).
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tytuł i opis -->
  <div class="card mb-3">
    <div class="card-body">
      <div class="step-label d-flex align-items-center gap-2 mb-3" style="font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6B7280;padding-bottom:.4rem;border-bottom:1px solid #F3F4F6">
        <i class="bi bi-briefcase" style="color:#0176D3"></i> Sprawa
      </div>

      <?php if ($case_tpls): ?>
      <!-- Szablon wypełnia pola; wszystko dalej można poprawić przed zapisem -->
      <div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
        <label class="form-label fw-semibold mb-0" for="tplPick" style="font-size:.8rem">Szablon sprawy</label>
        <select id="tplPick" class="form-select form-select-sm" style="max-width:280px"
                onchange="var u=new URL(window.location.href); if(this.value){u.searchParams.set('tpl',this.value);}else{u.searchParams.delete('tpl');} window.location.href=u.toString();">
          <option value="">— bez szablonu —</option>
          <?php foreach ($case_tpls as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $tpl_id === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <a href="<?= APP_URL ?>/crm/cases/templates.php" class="text-muted" style="font-size:.75rem">
          <i class="bi bi-gear me-1"></i>Zarządzaj szablonami
        </a>
      </div>
      <?php endif; ?>

      <div class="mb-3">
        <label class="form-label fw-semibold">Tytuł sprawy <span class="text-danger">*</span></label>
        <input name="title" class="form-control" value="<?= h($_POST['title'] ?? $prefill['title']) ?>"
               placeholder="np. Wniosek o zaświadczenie, Reklamacja faktury…" required autofocus>
      </div>
      <div>
        <label class="form-label fw-semibold">Opis / szczegóły</label>
        <textarea name="description" class="form-control" rows="7"
                  placeholder="Opisz sprawę — co trzeba zrobić, jaki jest cel, jakie kroki…"><?= h($_POST['description'] ?? $prefill['description']) ?></textarea>
      </div>
    </div>
  </div>

</div><!-- /col-8 -->

<!-- Prawa: status, priorytet, akcje -->
<div class="col-lg-4">
  <div class="card mb-3">
    <div class="card-body">
      <?php if ($case_types): ?>
      <div class="mb-3">
        <label class="form-label fw-semibold" for="type_id">Typ sprawy</label>
        <select class="form-select" id="type_id" name="type_id">
          <option value="">— bez typu —</option>
          <?php foreach ($case_types as $ct): ?>
          <option value="<?= (int)$ct['id'] ?>" <?= (int)($_POST['type_id'] ?? 0) === (int)$ct['id'] ? 'selected' : '' ?>>
            <?= h($ct['name']) ?><?= (int)$ct['sla_response_h'] || (int)$ct['sla_close_d']
                ? ' (SLA: ' . ((int)$ct['sla_response_h'] ? (int)$ct['sla_response_h'] . ' h odp.' : '')
                  . ((int)$ct['sla_response_h'] && (int)$ct['sla_close_d'] ? ', ' : '')
                  . ((int)$ct['sla_close_d'] ? (int)$ct['sla_close_d'] . ' dni' : '') . ')'
                : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text" style="font-size:.74rem">Typ wyznacza SLA i pozwala liczyć sprawy w podziale na rodzaje.</div>
      </div>
      <?php endif; ?>

      <div class="mb-3">
        <label class="form-label fw-semibold" for="owner_id">Prowadzi sprawę</label>
        <select class="form-select" id="owner_id" name="owner_id">
          <?php $me = (int)(current_user()['id'] ?? 0); ?>
          <option value="">— nieprzypisana —</option>
          <?php foreach ($case_users as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (int)($_POST['owner_id'] ?? $me) === (int)$u['id'] ? 'selected' : '' ?>>
            <?= h($u['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold" for="due_date">Termin</label>
        <input type="date" class="form-control" id="due_date" name="due_date"
               value="<?= h($_POST['due_date'] ?? '') ?>">
        <div class="form-text" style="font-size:.74rem">Na 2 dni przed terminem prowadzący dostanie e-mail.</div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">Status</label>
        <?php foreach (['open'=>'Otwarta','in_progress'=>'W toku'] as $sv=>$sl): ?>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="status" value="<?= $sv ?>"
                 id="s_<?= $sv ?>" <?= (($_POST['status']??'open')===$sv)?'checked':'' ?>>
          <label class="form-check-label" for="s_<?= $sv ?>"><?= $sl ?></label>
        </div>
        <?php endforeach; ?>
      </div>
      <div>
        <label class="form-label fw-semibold">Priorytet</label>
        <?php foreach (['low'=>['Niski','#6B7280'],'medium'=>['Średni','#D97706'],'high'=>['Wysoki','#DC2626']] as $pv=>[$pl,$pc]): ?>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="priority" value="<?= $pv ?>"
                 id="p_<?= $pv ?>" <?= (($_POST['priority'] ?? $prefill['priority'])===$pv)?'checked':'' ?>>
          <label class="form-check-label d-flex align-items-center gap-2" for="p_<?= $pv ?>">
            <span style="width:8px;height:8px;border-radius:50%;background:<?= $pc ?>;display:inline-block;flex-shrink:0"></span>
            <?= $pl ?>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="d-grid gap-2">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-check-lg me-1"></i>Utwórz sprawę
    </button>
    <a href="index.php" class="btn btn-outline-secondary">Anuluj</a>
  </div>
</div>
</div>
</form>

<style>.step-label{margin-bottom:.75rem}</style>

<script>
/* Wyszukiwarka kontaktu — ten sam endpoint co w module Komunikacji. */
(function () {
  var inp   = document.getElementById('ccSearch');
  var hid   = document.getElementById('ccId');
  var drop  = document.getElementById('ccDrop');
  var box   = document.getElementById('ccPicked');
  var nameE = document.getElementById('ccPickedName');
  var clr   = document.getElementById('ccClear');
  if (!inp || !hid) return;

  var timer = null, active = -1, rows = [];

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  function close() { drop.style.display = 'none'; inp.setAttribute('aria-expanded', 'false'); active = -1; }

  function pick(r) {
    hid.value = r.id;
    nameE.textContent = r.name + (r.organizacja ? ' · ' + r.organizacja : '');
    box.style.display = '';
    inp.value = '';
    inp.placeholder = 'Zmień kontakt — zacznij pisać…';
    close();
  }

  function render() {
    if (!rows.length) {
      drop.innerHTML = '<div class="list-group-item text-muted small">Brak pasujących kontaktów</div>';
    } else {
      drop.innerHTML = rows.map(function (r, i) {
        return '<button type="button" class="list-group-item list-group-item-action py-1' +
               (i === active ? ' active' : '') + '" data-i="' + i + '">' +
               '<span style="font-size:.86rem">' + esc(r.name) + '</span>' +
               (r.organizacja ? '<span class="text-muted ms-1" style="font-size:.76rem">' + esc(r.organizacja) + '</span>' : '') +
               (r.email ? '<span class="text-muted d-block" style="font-size:.74rem">' + esc(r.email) + '</span>' : '') +
               '</button>';
      }).join('');
      drop.querySelectorAll('button').forEach(function (b) {
        b.addEventListener('click', function () { pick(rows[Number(this.dataset.i)]); });
      });
    }
    drop.style.display = '';
    inp.setAttribute('aria-expanded', 'true');
  }

  inp.addEventListener('input', function () {
    var q = this.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { close(); return; }
    timer = setTimeout(function () {
      fetch('<?= APP_URL ?>/crm/api/contacts_search.php?q=' + encodeURIComponent(q) + '&limit=12')
        .then(function (r) { return r.json(); })
        .then(function (d) { rows = d || []; active = -1; render(); })
        .catch(close);
    }, 220);
  });

  inp.addEventListener('keydown', function (e) {
    if (drop.style.display === 'none') return;
    if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, rows.length - 1); render(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); render(); }
    else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); pick(rows[active]); }
    else if (e.key === 'Escape') { close(); }
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('#ccDrop') && e.target !== inp) close();
  });

  if (clr) clr.addEventListener('click', function () {
    hid.value = ''; box.style.display = 'none'; inp.value = ''; inp.focus();
  });

})();
</script>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
