<?php
/**
 * crm/cases/add.php — Formularz nowej sprawy CRM.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

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
            'created_by'  => (int)(current_user()['id'] ?? 0),
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
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
      <div class="mb-3">
        <label class="form-label fw-semibold">Tytuł sprawy <span class="text-danger">*</span></label>
        <input name="title" class="form-control" value="<?= h($_POST['title']??'') ?>"
               placeholder="np. Wniosek o zaświadczenie, Reklamacja faktury…" required autofocus>
      </div>
      <div>
        <label class="form-label fw-semibold">Opis / szczegóły</label>
        <textarea name="description" class="form-control" rows="5"
                  placeholder="Opisz sprawę — co trzeba zrobić, jaki jest cel, jakie kroki…"><?= h($_POST['description']??'') ?></textarea>
      </div>
    </div>
  </div>

</div><!-- /col-8 -->

<!-- Prawa: status, priorytet, akcje -->
<div class="col-lg-4">
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
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
                 id="p_<?= $pv ?>" <?= (($_POST['priority']??'medium')===$pv)?'checked':'' ?>>
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
