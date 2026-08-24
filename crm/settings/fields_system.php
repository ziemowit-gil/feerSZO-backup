<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!can_write('crm_ustawienia') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do ustawień CRM.');
    header('Location: ' . APP_URL . '/crm/dashboard.php');
    exit;
}
crm_migrate();
crm_require('settings', 'write');

$PAGE_TITLE = 'CRM — Pola systemowe';
$all_roles  = crm_all_roles();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    foreach (array_keys(CRM_SYSTEM_FIELDS) as $fkey) {
        $vis  = json_encode(array_values(array_filter((array)($_POST['visible_roles'][$fkey] ?? []), fn($r) => $r !== '')));
        $edit = json_encode(array_values(array_filter((array)($_POST['edit_roles'][$fkey]   ?? []), fn($r) => $r !== '')));
        $vis  = $vis === '[]' ? '' : $vis;
        $edit = $edit === '[]' ? '' : $edit;

        $exists = crm_one("SELECT field_key FROM crm_system_field_perms WHERE field_key=?", [$fkey]);
        if ($exists) {
            crm_db()->prepare("UPDATE crm_system_field_perms SET visible_roles=?, edit_roles=? WHERE field_key=?")
                    ->execute([$vis, $edit, $fkey]);
        } else {
            crm_db()->prepare("INSERT INTO crm_system_field_perms (field_key, visible_roles, edit_roles) VALUES (?,?,?)")
                    ->execute([$fkey, $vis, $edit]);
        }
    }
    flash_set('success', 'Uprawnienia pól systemowych zapisane.');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Załaduj aktualne ustawienia
$perms = [];
foreach (crm_all("SELECT field_key, visible_roles, edit_roles FROM crm_system_field_perms") as $r) {
    $perms[$r['field_key']] = $r;
}

// Pogrupuj pola wg 'group'
$grouped = [];
foreach (CRM_SYSTEM_FIELDS as $key => $def) {
    $grouped[$def['group']][$key] = $def;
}

include __DIR__ . '/../includes/header_crm.php';
require_once __DIR__ . '/_nav.php';
?>

<nav aria-label="Ścieżka nawigacji" class="mb-2">
  <ol class="breadcrumb mb-0" style="font-size:.82rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php"><i class="bi bi-diagram-2-fill me-1" style="color:var(--crm-primary)"></i>CRM</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/field_groups.php">Grupy pól</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/fields.php">Pola</a></li>
    <li class="breadcrumb-item active">Systemowe</li>
  </ol>
</nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-database-lock"></i></div>
  <div>
    <h1 class="crm-object-title">Uprawnienia pól systemowych</h1>
    <div class="crm-object-count">Wbudowane pola kontaktu — kontrola widoczności i edycji</div>
  </div>
  <div class="crm-object-actions d-flex gap-2">
    <a href="<?= APP_URL ?>/crm/settings/fields.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-list-columns me-1"></i>Pola niestandardowe
    </a>
    <a href="<?= APP_URL ?>/crm/settings/field_groups.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-layers me-1"></i>Grupy pól
    </a>
  </div>
</div>

<?= flash_html() ?>

<form method="post">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

  <?php foreach ($grouped as $group_label => $fields): ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold small d-flex align-items-center gap-2">
      <i class="bi bi-folder2-open text-primary" aria-hidden="true"></i>
      <?= h($group_label) ?>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="min-width:160px">Pole</th>
            <th>Dotyczy</th>
            <th>
              <i class="bi bi-eye me-1 text-secondary"></i>Kto może <u>widzieć</u>?
              <span class="text-muted fw-normal" style="font-size:.75rem">(puste = wszyscy)</span>
            </th>
            <th>
              <i class="bi bi-pencil me-1 text-secondary"></i>Kto może <u>edytować</u>?
              <span class="text-muted fw-normal" style="font-size:.75rem">(puste = jak widzi)</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fields as $fkey => $fdef):
            $fp_vis  = json_decode($perms[$fkey]['visible_roles'] ?? '', true) ?: [];
            $fp_edit = json_decode($perms[$fkey]['edit_roles']    ?? '', true) ?: [];
          ?>
          <tr>
            <td>
              <span class="fw-semibold"><?= h($fdef['label']) ?></span>
              <code class="text-muted ms-1" style="font-size:.72rem"><?= h($fkey) ?></code>
            </td>
            <td class="text-muted small">
              <?= $fdef['applies_to'] === 'both' ? 'Wszystkie' : ($fdef['applies_to'] === 'osoba' ? 'Osoby' : 'Organizacje') ?>
            </td>
            <td>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($all_roles as $rname => $rlabel): ?>
                <div class="form-check mb-0">
                  <input type="checkbox" class="form-check-input"
                         id="vis_<?= h($fkey) ?>_<?= h($rname) ?>"
                         name="visible_roles[<?= h($fkey) ?>][]"
                         value="<?= h($rname) ?>"
                         <?= in_array($rname, $fp_vis, true) ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="vis_<?= h($fkey) ?>_<?= h($rname) ?>">
                    <?= h($rlabel) ?>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            </td>
            <td>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($all_roles as $rname => $rlabel): ?>
                <div class="form-check mb-0">
                  <input type="checkbox" class="form-check-input"
                         id="edit_<?= h($fkey) ?>_<?= h($rname) ?>"
                         name="edit_roles[<?= h($fkey) ?>][]"
                         value="<?= h($rname) ?>"
                         <?= in_array($rname, $fp_edit, true) ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="edit_<?= h($fkey) ?>_<?= h($rname) ?>">
                    <?= h($rlabel) ?>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="d-flex gap-2 mb-5">
    <button type="submit" class="btn btn-crm-primary">
      <i class="bi bi-check-lg me-1"></i>Zapisz uprawnienia
    </button>
    <a href="<?= APP_URL ?>/crm/settings/fields.php" class="btn btn-outline-secondary">Anuluj</a>
  </div>
</form>

<?php require_once __DIR__ . '/_nav_end.php'; ?>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
