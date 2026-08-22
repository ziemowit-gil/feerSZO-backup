<?php
/**
 * crm/settings/automations.php — CRUD reguł automatyzacji CRM (zdarzenie → akcja).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_automation.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!can_write('crm_ustawienia') && !is_admin()) { flash_set('danger', 'Brak uprawnień do ustawień CRM.'); header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
crm_migrate();

$PAGE_TITLE = 'CRM — Automatyzacje';

const EVENTS = [
    'contact_created'        => 'Nowy kontakt',
    'contact_status_changed' => 'Zmiana statusu kontaktu',
    'tag_added'              => 'Dodano tag',
    'case_created'           => 'Nowa sprawa',
    'case_status_changed'    => 'Zmiana statusu sprawy',
    'offer_sent'             => 'Oferta wysłana do klienta',
    'offer_accepted'         => 'Oferta zaakceptowana',
    'offer_rejected'         => 'Oferta odrzucona',
];
const ACTIONS = [
    'send_email_template'  => 'Wyślij e-mail z szablonu',
    'add_tag'               => 'Dodaj tag',
    'remove_tag'             => 'Usuń tag',
    'create_activity'        => 'Utwórz zadanie',
    'change_contact_status'  => 'Zmień status kontaktu',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $event  = $_POST['trigger_event'] ?? '';
        $action = $_POST['action_type'] ?? '';

        if (!$name || !array_key_exists($event, EVENTS) || !array_key_exists($action, ACTIONS)) {
            flash_set('danger', 'Nazwa, zdarzenie i akcja są wymagane.');
            goto redirect;
        }

        $trigger_config = match ($event) {
            'contact_status_changed' => ['to_status' => trim($_POST['t_to_status'] ?? '')],
            'tag_added'              => ['tag' => mb_strtolower(trim($_POST['t_tag'] ?? ''))],
            'case_status_changed'    => ['to_status' => trim($_POST['t_case_to_status'] ?? '')],
            default                  => [],
        };
        $action_config = match ($action) {
            'send_email_template'  => ['template_id' => (int)($_POST['a_template_id'] ?? 0)],
            'add_tag'               => ['tag' => trim($_POST['a_tag_add'] ?? '')],
            'remove_tag'            => ['tag' => trim($_POST['a_tag_remove'] ?? '')],
            'create_activity'      => ['title' => trim($_POST['a_title'] ?? 'Zadanie z automatyzacji')],
            'change_contact_status' => ['status' => trim($_POST['a_status'] ?? '')],
            default                 => [],
        };

        $data = [
            'name'           => $name,
            'trigger_event'  => $event,
            'trigger_config' => json_encode($trigger_config, JSON_UNESCAPED_UNICODE),
            'action_type'    => $action,
            'action_config'  => json_encode($action_config, JSON_UNESCAPED_UNICODE),
            'is_active'      => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($id) {
            db()->prepare(
                "UPDATE crm_automations SET name=?,trigger_event=?,trigger_config=?,action_type=?,action_config=?,is_active=?,updated_at=datetime('now') WHERE id=?"
            )->execute([...array_values($data), $id]);
            flash_set('success', 'Reguła zaktualizowana.');
        } else {
            $data['created_by'] = (int)(current_user()['id'] ?? 0);
            db_insert('crm_automations', $data);
            flash_set('success', "Reguła „{$name}\" dodana.");
        }
    } elseif ($op === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $cur = db_one("SELECT is_active FROM crm_automations WHERE id=?", [$id]);
        if ($cur) db()->prepare("UPDATE crm_automations SET is_active=? WHERE id=?")->execute([$cur['is_active'] ? 0 : 1, $id]);
    } elseif ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM crm_automations WHERE id=?")->execute([$id]);
        flash_set('success', 'Reguła usunięta.');
    }
    redirect:
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$rules    = db_all("SELECT * FROM crm_automations ORDER BY created_at DESC");
$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_row = $edit_id ? db_one("SELECT * FROM crm_automations WHERE id=?", [$edit_id]) : null;
$edit_trigger = $edit_row ? (json_decode($edit_row['trigger_config'] ?: '{}', true) ?: []) : [];
$edit_action  = $edit_row ? (json_decode($edit_row['action_config']  ?: '{}', true) ?: []) : [];

$templates   = db_all("SELECT id, name FROM crm_templates WHERE channel='email' AND is_active=1 ORDER BY name");
$all_tags    = array_column(db_all("SELECT DISTINCT tag FROM crm_tags ORDER BY tag"), 'tag');
$all_statuses = array_keys(crm_statuses());

$log = db_all(
    "SELECT l.*, a.name AS automation_name FROM crm_automation_log l
     JOIN crm_automations a ON a.id=l.automation_id
     ORDER BY l.created_at DESC LIMIT 30"
);

include __DIR__ . '/../includes/header_crm.php';
require_once __DIR__ . '/_nav.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb mb-0 small">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia CRM</a></li>
  <li class="breadcrumb-item active">Automatyzacje</li>
</ol></nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-lightning-charge-fill"></i></div>
  <div>
    <h1 class="crm-object-title">Automatyzacje</h1>
    <div class="crm-object-count">Reguły „jeśli zdarzenie → wykonaj akcję" — <?= count($rules) ?> reguł</div>
  </div>
  <div class="crm-object-actions">
    <button class="btn btn-crm-primary btn-sm" onclick="document.getElementById('add-form').classList.toggle('d-none')">
      <i class="bi bi-plus-lg me-1"></i>Nowa reguła
    </button>
  </div>
</div>

<?= flash_get() ?>

<div class="card border-0 shadow-sm mb-4">
  <div class="table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Nazwa</th><th>Zdarzenie</th><th>Akcja</th><th>Status</th><th class="text-end">Uruchomień</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($rules as $r): ?>
        <tr>
          <td class="fw-semibold"><?= h($r['name']) ?></td>
          <td><?= h(EVENTS[$r['trigger_event']] ?? $r['trigger_event']) ?></td>
          <td><?= h(ACTIONS[$r['action_type']] ?? $r['action_type']) ?></td>
          <td>
            <?php if ($r['is_active']): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle">aktywna</span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary border">wyłączona</span>
            <?php endif; ?>
          </td>
          <td class="text-end"><?= (int)$r['run_count'] ?></td>
          <td class="d-flex gap-1 justify-content-end">
            <a href="?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="_op" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm <?= $r['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> py-0 px-2">
                <?= $r['is_active'] ? '<i class="bi bi-pause"></i>' : '<i class="bi bi-play"></i>' ?>
              </button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć regułę „<?= h(addslashes($r['name'])) ?>”?')">
              <?= csrf_field() ?><input type="hidden" name="_op" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash3"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rules): ?><tr><td colspan="6" class="text-muted text-center py-4">Brak reguł.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="add-form" class="card border-0 shadow-sm mb-4 <?= $edit_row ? '' : 'd-none' ?>">
  <div class="card-header bg-white py-2 fw-semibold"><?= $edit_row ? 'Edytuj regułę' : 'Nowa reguła' ?></div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <?= csrf_field() ?><input type="hidden" name="_op" value="save">
      <?php if ($edit_row): ?><input type="hidden" name="id" value="<?= (int)$edit_row['id'] ?>"><?php endif; ?>

      <div class="col-md-6">
        <label class="form-label small mb-1">Nazwa <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control form-control-sm" required
               value="<?= h($edit_row['name'] ?? '') ?>" placeholder="np. Powitanie nowego kontaktu">
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <div class="form-check">
          <input type="checkbox" name="is_active" class="form-check-input" id="chkActive" value="1"
                 <?= ($edit_row['is_active'] ?? 1) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="chkActive">Aktywna</label>
        </div>
      </div>

      <div class="col-md-6">
        <label class="form-label small mb-1">Zdarzenie (trigger) <span class="text-danger">*</span></label>
        <select name="trigger_event" id="sel_event" class="form-select form-select-sm" required onchange="autoToggleFields()">
          <option value="">— wybierz —</option>
          <?php foreach (EVENTS as $ev => $label): ?>
          <option value="<?= $ev ?>" <?= ($edit_row['trigger_event'] ?? '') === $ev ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6" id="cfg_contact_status_changed" style="display:none">
        <label class="form-label small mb-1">Docelowy status kontaktu</label>
        <select name="t_to_status" class="form-select form-select-sm">
          <?php foreach ($all_statuses as $st): ?>
          <option value="<?= h($st) ?>" <?= ($edit_trigger['to_status'] ?? '') === $st ? 'selected' : '' ?>><?= h($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6" id="cfg_tag_added" style="display:none">
        <label class="form-label small mb-1">Tag</label>
        <input type="text" name="t_tag" class="form-control form-control-sm" list="dl_tags" value="<?= h($edit_trigger['tag'] ?? '') ?>">
      </div>
      <div class="col-md-6" id="cfg_case_status_changed" style="display:none">
        <label class="form-label small mb-1">Docelowy status sprawy</label>
        <select name="t_case_to_status" class="form-select form-select-sm">
          <?php foreach (['open' => 'Otwarta', 'in_progress' => 'W toku', 'closed' => 'Zamknięta', 'cancelled' => 'Anulowana'] as $sv => $sl): ?>
          <option value="<?= $sv ?>" <?= ($edit_trigger['to_status'] ?? '') === $sv ? 'selected' : '' ?>><?= $sl ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label small mb-1">Akcja <span class="text-danger">*</span></label>
        <select name="action_type" id="sel_action" class="form-select form-select-sm" required onchange="autoToggleFields()">
          <option value="">— wybierz —</option>
          <?php foreach (ACTIONS as $av => $label): ?>
          <option value="<?= $av ?>" <?= ($edit_row['action_type'] ?? '') === $av ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6" id="acfg_send_email_template" style="display:none">
        <label class="form-label small mb-1">Szablon e-mail</label>
        <select name="a_template_id" class="form-select form-select-sm">
          <option value="">— wybierz —</option>
          <?php foreach ($templates as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= (int)($edit_action['template_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6" id="acfg_add_tag" style="display:none">
        <label class="form-label small mb-1">Tag do dodania</label>
        <input type="text" name="a_tag_add" class="form-control form-control-sm" list="dl_tags" value="<?= h($edit_action['tag'] ?? '') ?>">
      </div>
      <div class="col-md-6" id="acfg_remove_tag" style="display:none">
        <label class="form-label small mb-1">Tag do usunięcia</label>
        <input type="text" name="a_tag_remove" class="form-control form-control-sm" list="dl_tags" value="<?= h($edit_action['tag'] ?? '') ?>">
      </div>
      <div class="col-md-6" id="acfg_create_activity" style="display:none">
        <label class="form-label small mb-1">Tytuł zadania</label>
        <input type="text" name="a_title" class="form-control form-control-sm" value="<?= h($edit_action['title'] ?? '') ?>">
      </div>
      <div class="col-md-6" id="acfg_change_contact_status" style="display:none">
        <label class="form-label small mb-1">Nowy status kontaktu</label>
        <select name="a_status" class="form-select form-select-sm">
          <?php foreach ($all_statuses as $st): ?>
          <option value="<?= h($st) ?>" <?= ($edit_action['status'] ?? '') === $st ? 'selected' : '' ?>><?= h($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <datalist id="dl_tags"><?php foreach ($all_tags as $tag): ?><option value="<?= h($tag) ?>"><?php endforeach; ?></datalist>

      <div class="col-12">
        <button type="submit" class="btn btn-crm-primary btn-sm">
          <?= $edit_row ? '<i class="bi bi-save me-1"></i>Zapisz' : '<i class="bi bi-plus-lg me-1"></i>Dodaj regułę' ?>
        </button>
        <a href="<?= APP_URL ?>/crm/settings/automations.php" class="btn btn-outline-secondary btn-sm ms-1">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white py-2 fw-semibold">Ostatnie uruchomienia</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead class="table-light"><tr><th>Kiedy</th><th>Reguła</th><th>Zdarzenie</th><th>Wynik</th><th>Szczegóły</th></tr></thead>
      <tbody>
      <?php foreach ($log as $l): ?>
      <tr>
        <td class="text-nowrap small"><?= h(date('d.m.Y H:i:s', strtotime($l['created_at']))) ?></td>
        <td><?= h($l['automation_name']) ?></td>
        <td><?= h(EVENTS[$l['event']] ?? $l['event']) ?></td>
        <td>
          <?php if ($l['status'] === 'ok'): ?><span class="badge bg-success-subtle text-success border">ok</span>
          <?php elseif ($l['status'] === 'skipped_loop_guard'): ?><span class="badge bg-warning-subtle text-warning border">pominięto (pętla)</span>
          <?php else: ?><span class="badge bg-danger-subtle text-danger border">błąd</span><?php endif; ?>
        </td>
        <td class="small text-muted"><?= h($l['detail']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$log): ?><tr><td colspan="5" class="text-muted text-center py-4">Brak wpisów.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function autoToggleFields() {
  var ev = document.getElementById('sel_event').value;
  var ac = document.getElementById('sel_action').value;
  ['cfg_contact_status_changed', 'cfg_tag_added', 'cfg_case_status_changed'].forEach(function (id) {
    document.getElementById(id).style.display = 'none';
  });
  ['acfg_send_email_template', 'acfg_add_tag', 'acfg_remove_tag', 'acfg_create_activity', 'acfg_change_contact_status'].forEach(function (id) {
    document.getElementById(id).style.display = 'none';
  });
  var evBox = document.getElementById('cfg_' + ev);
  if (evBox) evBox.style.display = '';
  var acBox = document.getElementById('acfg_' + ac);
  if (acBox) acBox.style.display = '';
}
autoToggleFields();
</script>

<?php require_once __DIR__ . '/_nav_end.php'; ?>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
