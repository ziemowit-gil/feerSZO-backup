<?php
/**
 * crm/settings/permissions.php — uprawnienia CRM per rola.
 *
 * Macierz: role × obszary modułu (brak / odczyt / zapis / zapis + usuwanie)
 * oraz role × pola kartoteki (podgląd, edycja).
 *
 * Dopóki dla roli nie zapisano żadnej reguły, obowiązuje dotychczasowe zachowanie
 * (can_read/can_write na cały moduł) — dlatego ekran wyraźnie pokazuje, która rola
 * jest już skonfigurowana, a która dziedziczy stare ustawienia.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_perms.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('settings', 'write');
require_role('admin');   // konfiguracją uprawnień zarządza wyłącznie administrator

$PAGE_TITLE = 'Uprawnienia CRM';

$roles  = crm_perm_roles();
$areas  = crm_perm_areas();
$fields = crm_perm_fields();

$role_sel = (string)($_GET['role'] ?? ($roles[0]['name'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op   = (string)($_POST['_op'] ?? '');
    $role = (string)($_POST['role'] ?? '');

    if ($op === 'save' && $role !== '') {
        crm_perms_save_areas($role, (array)($_POST['area'] ?? []));

        $fperm = [];
        foreach (array_keys($fields) as $f) {
            $fperm[$f] = [
                'view' => !empty($_POST['fview'][$f]),
                'edit' => !empty($_POST['fedit'][$f]),
            ];
        }
        crm_perms_save_fields($role, $fperm);

        flash_set('success', 'Uprawnienia roli zapisane. Od teraz ta rola widzi w CRM dokładnie to, co zaznaczono.');
        header('Location: ' . APP_URL . '/crm/settings/permissions.php?role=' . urlencode($role)); exit;
    }

    if ($op === 'reset' && $role !== '') {
        crm_perms_reset($role);
        flash_set('success', 'Reguły roli usunięte — wraca zachowanie sprzed konfiguracji (uprawnienia całego modułu).');
        header('Location: ' . APP_URL . '/crm/settings/permissions.php?role=' . urlencode($role)); exit;
    }
}

$cur_areas  = crm_perms_for_role($role_sel);
$cur_fields = crm_field_perms_for_role($role_sel);
$configured = crm_perms_configured($role_sel);

include dirname(__DIR__) . '/includes/header_crm.php';
?>
<style>
.pm-card { background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1.1rem 1.25rem;margin-bottom:.9rem }
.pm-tabs { display:flex;gap:2px;padding:3px;background:#F3F4F6;border-radius:10px;margin-bottom:1rem;flex-wrap:wrap }
.pm-tab { padding:.3rem .7rem;border-radius:8px;font-size:.8rem;color:#6B7280;text-decoration:none }
.pm-tab:hover { color:#111827;background:rgba(255,255,255,.7) }
.pm-tab.is-on { background:#fff;color:#111827;font-weight:600;box-shadow:0 1px 2px rgba(16,24,40,.1) }
.pm-tbl { width:100%;border-collapse:collapse;font-size:.84rem }
.pm-tbl th { font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#9CA3AF;text-align:left;
  padding:.4rem .5rem;border-bottom:1px solid #E5E7EB }
.pm-tbl td { padding:.45rem .5rem;border-bottom:1px solid #F3F4F6;vertical-align:middle }
.pm-desc { font-size:.74rem;color:#9CA3AF }
.pm-sens { font-size:.66rem;font-weight:700;color:#B45309;background:#FEF3C7;border-radius:2rem;padding:.05rem .4rem }
.pm-state { font-size:.75rem;padding:.15rem .5rem;border-radius:2rem }
</style>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-shield-lock me-2" style="color:#0176D3"></i>Uprawnienia CRM</h1>
  <span class="text-muted small">per rola — obszary modułu i pola kartoteki</span>
</div>

<div class="pm-tabs">
  <?php foreach ($roles as $r): ?>
  <a class="pm-tab<?= $role_sel === $r['name'] ? ' is-on' : '' ?>"
     href="?role=<?= urlencode($r['name']) ?>"><?= h($r['display_name'] ?: $r['name']) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$roles): ?>
<div class="pm-card text-muted small">Brak ról do konfiguracji.</div>
<?php else: ?>

<div class="pm-card d-flex align-items-center gap-2 flex-wrap">
  <div>
    <strong><?= h(($roles[array_search($role_sel, array_column($roles, 'name'), true)]['display_name'] ?? $role_sel)) ?></strong>
    <span class="pm-state <?= $configured ? '' : 'text-muted' ?>"
          style="background:<?= $configured ? '#EFF7ED' : '#F3F4F6' ?>;color:<?= $configured ? '#2E844A' : '#6B7280' ?>">
      <?= $configured ? 'reguły ustawione' : 'dziedziczy uprawnienia całego modułu' ?>
    </span>
  </div>
  <?php if ($configured): ?>
  <form method="post" class="ms-auto"
        onsubmit="return confirm('Usunąć reguły tej roli? Wróci zachowanie sprzed konfiguracji.')">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_op" value="reset">
    <input type="hidden" name="role" value="<?= h($role_sel) ?>">
    <button class="btn btn-outline-secondary btn-sm">Usuń reguły roli</button>
  </form>
  <?php endif; ?>
</div>

<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_op" value="save">
  <input type="hidden" name="role" value="<?= h($role_sel) ?>">

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="pm-card">
        <h2 class="h6 fw-bold mb-1">Obszary modułu</h2>
        <p class="pm-desc mb-3">
          Obszar bez wybranego poziomu jest dla tej roli zamknięty — po zapisaniu reguł
          rola widzi wyłącznie to, co tu zaznaczono.
        </p>
        <table class="pm-tbl">
          <thead><tr><th>Obszar</th><th style="width:190px">Poziom</th></tr></thead>
          <tbody>
            <?php foreach ($areas as $ak => $a): $lvl = $cur_areas[$ak] ?? 'none'; ?>
            <tr>
              <td>
                <div><?= h($a['label']) ?></div>
                <div class="pm-desc"><?= h($a['desc']) ?></div>
              </td>
              <td>
                <select name="area[<?= h($ak) ?>]" class="form-select form-select-sm">
                  <?php foreach (CRM_PERM_LEVELS as $lk => $ll): ?>
                  <option value="<?= h($lk) ?>" <?= $lvl === $lk ? 'selected' : '' ?>><?= h($ll) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="pm-card">
        <h2 class="h6 fw-bold mb-1">Pola kartoteki</h2>
        <p class="pm-desc mb-3">
          Pole bez podglądu znika z karty, list, eksportu i API. Edycja bez podglądu nie ma sensu,
          więc odznaczenie podglądu wyłącza też edycję.
        </p>
        <table class="pm-tbl">
          <thead>
            <tr><th>Pole</th><th style="width:70px">Podgląd</th><th style="width:70px">Edycja</th></tr>
          </thead>
          <tbody>
            <?php foreach ($fields as $fk => $f):
              $fv = $cur_fields[$fk]['view'] ?? true;
              $fe = $cur_fields[$fk]['edit'] ?? true; ?>
            <tr>
              <td>
                <?= h($f['label']) ?>
                <?php if ($f['sensitive']): ?><span class="pm-sens ms-1">wrażliwe</span><?php endif; ?>
              </td>
              <td class="text-center">
                <input type="checkbox" class="form-check-input pm-view" name="fview[<?= h($fk) ?>]" value="1"
                       data-f="<?= h($fk) ?>" <?= $fv ? 'checked' : '' ?>
                       aria-label="Podgląd pola <?= h($f['label']) ?>">
              </td>
              <td class="text-center">
                <input type="checkbox" class="form-check-input pm-edit" name="fedit[<?= h($fk) ?>]" value="1"
                       data-f="<?= h($fk) ?>" <?= $fe ? 'checked' : '' ?>
                       aria-label="Edycja pola <?= h($f['label']) ?>">
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="pm-card d-flex align-items-center gap-2">
    <button class="btn btn-crm-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz uprawnienia roli</button>
    <span class="pm-desc">Administrator systemu zawsze ma pełny dostęp — inaczej dałoby się odciąć od tego ekranu.</span>
  </div>
</form>
<?php endif; ?>

<script>
// Edycja bez podglądu nie istnieje — odznaczenie podglądu gasi też edycję
document.querySelectorAll('.pm-view').forEach(function (v) {
  v.addEventListener('change', function () {
    var e = document.querySelector('.pm-edit[data-f="' + this.dataset.f + '"]');
    if (e && !this.checked) e.checked = false;
    if (e) e.disabled = !this.checked;
  });
  var e = document.querySelector('.pm-edit[data-f="' + v.dataset.f + '"]');
  if (e) e.disabled = !v.checked;
});
</script>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
