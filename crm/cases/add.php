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

$all_contacts = db_all("SELECT id, imie_nazwisko, type FROM crm_contacts WHERE crm_active=1 ORDER BY imie_nazwisko");

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
  <div class="card border-0 shadow-sm mb-3">
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
      <label class="form-label fw-semibold">Kontakt <span class="text-danger">*</span></label>
      <select name="contact_id" class="form-select" required>
        <option value="">— wybierz kontakt —</option>
        <?php foreach ($all_contacts as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (($_POST['contact_id']??0)==$c['id'])?'selected':'' ?>>
          <?= h($c['imie_nazwisko']) ?> <?= $c['type']==='organizacja'?'[org]':'' ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tytuł i opis -->
  <div class="card border-0 shadow-sm mb-3">
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
  <div class="card border-0 shadow-sm mb-3">
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

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
