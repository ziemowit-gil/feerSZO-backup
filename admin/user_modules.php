<?php
/**
 * admin/user_modules.php — Przypisywanie użytkownikowi DODATKOWYCH modułów
 * (ponad uprawnienia wynikające z roli). Efektywny dostęp = rola ∪ konto.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';

require_role('admin');

$uid = (int)($_GET['uid'] ?? $_POST['user_id'] ?? 0);
$user = $uid ? db_one("SELECT * FROM users WHERE id = ?", [$uid]) : null;
if (!$user || $user['email'] === 'serwis@local') {
    flash_set('danger', 'Nie znaleziono użytkownika.');
    header('Location: ' . APP_URL . '/admin/users.php');
    exit;
}

// Rola użytkownika — etykieta + flagi zakresu
$role = db_one("SELECT * FROM roles WHERE name = ?", [$user['role']]);
$role_label = $role['display_name'] ?? $user['role'];
$is_scoped  = (int)($role['crm_only'] ?? 0) === 1 || (int)($role['ezd_only'] ?? 0) === 1;
$is_admin_role = $user['role'] === 'admin';

// ── Zapis ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $by = (int)current_user()['id'];
    $r_in = $_POST['r'] ?? [];
    $w_in = $_POST['w'] ?? [];
    $d_in = $_POST['d'] ?? [];
    foreach (array_keys(PERMISSION_MODULES) as $mod) {
        $r = !empty($r_in[$mod]);
        $w = !empty($w_in[$mod]);
        $d = !empty($d_in[$mod]);
        // Zapis odczytuje stan po włączeniu: write/delete implikują odczyt.
        if ($w || $d) $r = true;
        user_permission_set($uid, $mod, $r, $w, $d, $by);
    }
    log_user_action($uid, $by, 'note', 'Zmieniono dodatkowe moduły (ponad rolę)');
    flash_set('success', 'Zapisano dodatkowe moduły użytkownika ' . $user['name'] . '.');
    header('Location: ' . APP_URL . '/admin/user_modules.php?uid=' . $uid);
    exit;
}

$role_perms = $is_admin_role ? [] : role_permissions($user['role']);
$user_perms = user_permissions($uid);

$PAGE_TITLE = 'Dodatkowe moduły — ' . $user['name'];
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-grid-3x3-gap text-primary"></i> Dodatkowe moduły użytkownika</h4>
  <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Wróć do użytkowników
  </a>
</div>

<?= flash_html() ?>

<div class="card shadow-sm mb-3">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-person-circle fs-3 text-secondary"></i>
      <div>
        <div class="fw-bold"><?= h($user['name']) ?></div>
        <div class="small text-muted"><?= h($user['email']) ?></div>
      </div>
    </div>
    <div class="ms-auto">
      <span class="text-muted small">Rola:</span>
      <span class="badge bg-secondary"><?= h($role_label) ?></span>
    </div>
  </div>
</div>

<?php if ($is_admin_role): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-1"></i>
  Administrator ma już <strong>pełny dostęp</strong> do wszystkich modułów — dodatkowe przypisania nie są potrzebne.
</div>
<?php endif; ?>

<?php if ($is_scoped): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-1"></i>
  Rola tego konta jest <strong>zawężona</strong> (tylko CRM / tylko EZD). Po przyznaniu dodatkowych modułów
  użytkownik po zalogowaniu zobaczy <strong>launcher</strong> z modułem podstawowym i przyznanymi dodatkowo
  (zamiast bezpośredniego przekierowania). Dostęp do tych modułów zostanie też dopuszczony w ścieżkach.
</div>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="user_id" value="<?= $uid ?>">

  <div class="card shadow-sm">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-list-check"></i> Moduły
      <span class="text-muted small fw-normal ms-1">— zaznacz, aby przyznać dostęp ponad rolę</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Moduł</th>
            <th class="text-center">Z roli</th>
            <th class="text-center" style="width:90px">Odczyt</th>
            <th class="text-center" style="width:90px">Zapis</th>
            <th class="text-center" style="width:90px">Usuwanie</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (PERMISSION_MODULES as $mod => $label):
            $rr = !empty($role_perms[$mod]['can_read']);
            $rw = !empty($role_perms[$mod]['can_write']);
            $rd = !empty($role_perms[$mod]['can_delete']);
            $ur = !empty($user_perms[$mod]['can_read']);
            $uw = !empty($user_perms[$mod]['can_write']);
            $ud = !empty($user_perms[$mod]['can_delete']);
            // Gdy rola daje pełny dostęp do modułu (admin), pokaż jako wynikający z roli.
            if ($is_admin_role) { $rr = $rw = $rd = true; }
          ?>
          <tr>
            <td class="fw-semibold"><?= h($label) ?>
              <div class="text-muted" style="font-size:.72rem"><?= h($mod) ?></div>
            </td>
            <td class="text-center">
              <?php if ($rr || $rw || $rd): ?>
                <?php if ($rr): ?><span class="badge bg-secondary bg-opacity-15 text-secondary border border-secondary">R</span><?php endif; ?>
                <?php if ($rw): ?><span class="badge bg-primary bg-opacity-15 text-primary border border-primary">W</span><?php endif; ?>
                <?php if ($rd): ?><span class="badge bg-danger bg-opacity-15 text-danger border border-danger">D</span><?php endif; ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <input type="checkbox" class="form-check-input" name="r[<?= h($mod) ?>]" value="1"
                     <?= $ur ? 'checked' : '' ?> <?= ($is_admin_role || $rr) ? 'disabled title="Wynika z roli"' : '' ?>>
            </td>
            <td class="text-center">
              <input type="checkbox" class="form-check-input" name="w[<?= h($mod) ?>]" value="1"
                     <?= $uw ? 'checked' : '' ?> <?= ($is_admin_role || $rw) ? 'disabled title="Wynika z roli"' : '' ?>>
            </td>
            <td class="text-center">
              <input type="checkbox" class="form-check-input" name="d[<?= h($mod) ?>]" value="1"
                     <?= $ud ? 'checked' : '' ?> <?= ($is_admin_role || $rd) ? 'disabled title="Wynika z roli"' : '' ?>>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (!$is_admin_role): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
      <div class="text-muted small">
        <i class="bi bi-info-circle me-1"></i>
        Zaznaczenie <strong>Zapis</strong> lub <strong>Usuwanie</strong> automatycznie nadaje też odczyt.
        Pola wynikające z roli są zablokowane (już przyznane).
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-save me-1"></i> Zapisz dodatkowe moduły
      </button>
    </div>
    <?php endif; ?>
  </div>
</form>

<script>
// Zapis/Usuwanie implikuje Odczyt — odzwierciedl to w UI.
document.querySelectorAll('input[name^="w["], input[name^="d["]').forEach(function(cb){
  cb.addEventListener('change', function(){
    if (!this.checked) return;
    var mod = this.name.slice(2, -1);
    var r = document.querySelector('input[name="r['+mod+']"]');
    if (r && !r.disabled) r.checked = true;
  });
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
