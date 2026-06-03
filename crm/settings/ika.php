<?php
/**
 * crm/settings/ika.php — Zarządzanie wymaganiem IKA dla użytkowników CRM.
 *
 * Per-user flaga crm_ika_required:
 *   NULL  = domyślnie (wg roli: crm_user/admin/editor → IKA wymagane)
 *   0     = zwolniony z IKA w CRM
 *   1     = IKA wymagane nawet jeśli rola nie wymaga
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/cpc.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!is_admin()) {
    flash_set('danger', 'Tylko administrator może zarządzać ustawieniami IKA.');
    header('Location: ' . APP_URL . '/crm/dashboard.php');
    exit;
}
crm_migrate();
cpc_migrate();

$PAGE_TITLE = 'CRM — Wymaganie IKA';
$BASE_URL   = APP_URL . '/crm/settings/ika.php';

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = $_POST['_op'] ?? '';
    $uid = (int)($_POST['user_id'] ?? 0);

    if ($op === 'set_ika_flag' && $uid) {
        $flag_raw = $_POST['crm_ika_required'] ?? '';
        $flag = ($flag_raw === '0' || $flag_raw === '1') ? (int)$flag_raw : null;

        db()->prepare("UPDATE users SET crm_ika_required=? WHERE id=?")
            ->execute([$flag, $uid]);

        if ($flag === 0)      { $label = 'zwolniony z IKA'; }
        elseif ($flag === 1) { $label = 'IKA wymuszone'; }
        else                 { $label = 'przywrocono domyslne'; }

        $u_name = db_one("SELECT name FROM users WHERE id=?", [$uid])['name'] ?? "ID:$uid";
        log_user_action($uid, (int)current_user()['id'], 'crm_ika_flag',
            "crm_ika_required zmienione na: " . $label . " dla " . $u_name);
        flash_set('success', 'Ustawienie IKA dla "' . $u_name . '": ' . $label . '.');
        header('Location: ' . $BASE_URL);
        exit;
    }
}

// ── Pobierz użytkowników CRM ──────────────────────────────────────────────────
// Użytkownicy z dostępem CRM: crm_user, role z crm_only=1, portal_scope=crm_only
$crm_users = db_all(
    "SELECT u.id, u.name, u.first_name, u.last_name, u.email, u.role,
            u.cpc_code, u.cpc_fails, u.cpc_blocked_until, u.crm_ika_required,
            u.portal_scope,
            r.display_name AS role_label, r.crm_only AS role_crm_only
     FROM users u
     LEFT JOIN roles r ON r.name = u.role
     WHERE u.is_active = 1
       AND (u.role IN ('admin','editor','crm_user') OR r.crm_only = 1 OR u.portal_scope = 'crm_only')
     ORDER BY r.crm_only DESC, u.role, u.name"
);

// Rola naturalnie wymaga IKA?
function _ika_role_default(array $u): bool {
    return in_array($u['role'], ['admin', 'editor', 'crm_user'], true);
}

function _ika_status_label(array $u): array {
    $flag = isset($u['crm_ika_required']) && $u['crm_ika_required'] !== null
        ? (int)$u['crm_ika_required'] : null;

    if ($flag === 0) return ['Zwolniony', 'secondary', 'bi-shield-slash'];
    if ($flag === 1) return ['Wymuszone', 'warning',   'bi-shield-fill-check'];

    // NULL = domyślne wg roli
    return _ika_role_default($u)
        ? ['Wymagane (rola)',  'success', 'bi-shield-check']
        : ['Brak (rola)',      'light',   'bi-shield'];
}

function _ika_code_badge(array $u): string {
    if (empty($u['cpc_code'])) return '<span class="badge bg-danger">Brak kodu</span>';
    $blocked = !empty($u['cpc_blocked_until']) && $u['cpc_blocked_until'] > date('Y-m-d H:i:s');
    if ($blocked) return '<span class="badge bg-warning text-dark">Zablokowany</span>';
    return '<span class="badge bg-success">Kod aktywny</span>';
}

include __DIR__ . '/../includes/header_crm.php';
?>

<nav aria-label="Ścieżka nawigacji" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia</a></li>
    <li class="breadcrumb-item active">Wymaganie IKA</li>
  </ol>
</nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-shield-lock-fill"></i></div>
  <div>
    <h1 class="crm-object-title">Wymaganie IKA dla CRM</h1>
    <div class="crm-object-count">
      Kontroluj, którzy użytkownicy CRM muszą weryfikować kod IKA przy logowaniu.
    </div>
  </div>
  <div class="crm-object-actions">
    <a href="<?= APP_URL ?>/admin/manage_cpc.php" class="btn btn-sm btn-outline-secondary" target="_blank">
      <i class="bi bi-key me-1"></i>Zarządzaj kodami IKA
    </a>
  </div>
</div>

<div class="alert alert-info d-flex gap-2 mb-4" style="font-size:.84rem">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
  <div>
    <strong>Jak działa:</strong>
    Domyślnie IKA jest wymagane dla ról <code>admin</code>, <code>editor</code> i <code>crm_user</code>.
    Możesz to nadpisać per-użytkownik: <strong>zwolnić</strong> (np. wolontariusz CRM bez kodu)
    lub <strong>wymusić</strong> (np. viewer z dostępem CRM który powinien weryfikować tożsamość).
    Zmiana dotyczy wyłącznie modułu CRM — inne moduły (umowy, K30) nie są zmieniane.
  </div>
</div>

<?= flash_html() ?>

<div class="crm-list-card">
  <div class="p-3 border-bottom d-flex align-items-center justify-content-between gap-2">
    <span class="fw-semibold" style="font-size:.88rem">
      <i class="bi bi-people me-1 text-muted"></i>
      Użytkownicy z dostępem CRM
      <span class="badge bg-light text-dark border ms-1"><?= count($crm_users) ?></span>
    </span>
  </div>

  <?php if (!$crm_users): ?>
  <div class="crm-empty">
    <i class="crm-empty-icon bi bi-people"></i>
    <h5>Brak użytkowników CRM</h5>
    <p class="text-muted">Dodaj użytkowników z rolą <code>crm_user</code> lub utwórz wolontariat z dostępem CRM.</p>
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="crm-table">
      <thead>
        <tr>
          <th>Użytkownik</th>
          <th>Rola</th>
          <th>Kod IKA</th>
          <th>Ustawienie IKA</th>
          <th>Zmień</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($crm_users as $u):
          [$ika_label, $ika_color, $ika_icon] = _ika_status_label($u);
          $flag = isset($u['crm_ika_required']) && $u['crm_ika_required'] !== null
            ? (int)$u['crm_ika_required'] : null;
          $dname = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['name'] ?? '');
          $ini   = '';
          foreach (preg_split('/\s+/', trim($dname)) as $w) $ini .= mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8');
          $ini   = mb_substr($ini, 0, 2, 'UTF-8') ?: '?';
          $role_disp = $u['role_label'] ?: ucfirst($u['role']);
          if ($u['portal_scope'] === 'crm_only') $role_disp .= ' <span class="badge bg-light text-muted border" style="font-size:.65rem">portal: crm</span>';
        ?>
        <tr>
          <td>
            <div class="crm-name-cell">
              <div class="crm-avatar" style="background:var(--crm-primary)"><?= h($ini) ?></div>
              <div>
                <div class="fw-semibold" style="font-size:.87rem"><?= h($dname) ?></div>
                <div class="text-muted" style="font-size:.77rem"><?= h($u['email']) ?></div>
              </div>
            </div>
          </td>
          <td style="font-size:.83rem"><?= $role_disp ?></td>
          <td><?= _ika_code_badge($u) ?></td>
          <td>
            <span class="badge bg-<?= $ika_color ?> text-<?= $ika_color === 'light' ? 'dark' : 'white' ?>" style="font-size:.78rem">
              <i class="bi <?= $ika_icon ?> me-1"></i><?= $ika_label ?>
            </span>
          </td>
          <td>
            <form method="post" class="d-inline-flex gap-1 align-items-center">
              <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
              <input type="hidden" name="_op"      value="set_ika_flag">
              <input type="hidden" name="user_id"  value="<?= (int)$u['id'] ?>">
              <select name="crm_ika_required"
                      class="form-select form-select-sm"
                      style="width:auto;font-size:.8rem"
                      onchange="this.form.submit()"
                      aria-label="Zmień ustawienie IKA dla <?= h($dname) ?>">
                <option value=""  <?= $flag === null ? 'selected' : '' ?>>Domyślnie (wg roli)</option>
                <option value="1" <?= $flag === 1    ? 'selected' : '' ?>>Wymuś IKA</option>
                <option value="0" <?= $flag === 0    ? 'selected' : '' ?>>Zwolnij z IKA</option>
              </select>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="mt-4 p-3 rounded" style="background:#F9FAFB;border:1px solid #E5E7EB;font-size:.82rem">
  <strong>Legenda:</strong>
  <span class="badge bg-success ms-2">Wymagane (rola)</span> — IKA aktywne na podstawie roli (domyślnie)
  <span class="badge bg-warning text-dark ms-2">Wymuszone</span> — Admin wymagał IKA nawet dla roli bez IKA
  <span class="badge bg-secondary ms-2">Zwolniony</span> — Admin zwolnił z IKA (np. wolontariusz CRM bez kodu)
  <span class="badge bg-light text-dark border ms-2">Brak (rola)</span> — Rola nie wymaga IKA i nie ustawiono wymuszenia
</div>

<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
