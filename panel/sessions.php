<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';

require_login();
$PAGE_TITLE = 'Sesje i historia logowań';
$user = current_user();
$uid  = (int)$user['id'];
$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);
$current_token = $_SESSION['_session_token'] ?? '';

// ── Akcje ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'revoke' && !empty($_POST['token'])) {
        $token = $_POST['token'];
        // Upewnij się że token należy do zalogowanego użytkownika
        $s = db_one("SELECT id FROM user_sessions WHERE token=? AND user_id=?", [$token, $uid]);
        if ($s) {
            session_destroy_token($token);
            flash_set('success', 'Sesja została zakończona.');
        }
    } elseif ($action === 'revoke_all') {
        session_destroy_all($uid, $current_token);
        flash_set('success', 'Wszystkie inne sesje zostały zakończone.');
    }
    header('Location: ' . APP_URL . '/panel/sessions.php'); exit;
}

// ── Odśwież znacznik aktywności bieżącej sesji ────────────────────────────────
if ($current_token) session_touch($current_token);

$sessions = sessions_for_user($uid);
$history  = authlog_user($uid, 40);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<?php if ($_is_volunteer_only): ?>

<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Bezpieczeństwo konta</h1>
  <p class="pv-page-sub">Aktywne sesje logowania</p>
</div>
<?php echo flash_html(); ?>

<div class="vol-detail-card mb-4">
  <div class="vol-detail-header d-flex align-items-center justify-content-between">
    <span><i class="bi bi-display me-2" aria-hidden="true"></i>Aktywne sesje</span>
    <?php if (count($sessions) > 1): ?>
    <form method="post" class="m-0" onsubmit="return confirm('Zakończyć wszystkie inne sesje?')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="revoke_all">
      <button class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.75rem">
        <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Zakończ pozostałe
      </button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!$sessions): ?>
  <div class="vol-detail-body text-center py-3 text-muted small">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak zarejestrowanych sesji
  </div>
  <?php endif; ?>
  <?php foreach ($sessions as $s):
    $is_current = $s['token'] === $current_token;
    $last = $s['last_active'] ?? $s['created_at'];
    $ua = strtolower($s['user_agent'] ?? '');
    $dev_icon = (str_contains($ua,'mobile') || str_contains($ua,'android') || str_contains($ua,'iphone')) ? 'bi-phone' : 'bi-laptop';
    // Mask IP - only first 2 octets
    $ip_parts = explode('.', $s['ip'] ?? '');
    $masked_ip = count($ip_parts) >= 2 ? $ip_parts[0].'.'.$ip_parts[1].'.x.x' : ($s['ip'] ?? '—');
  ?>
  <div class="vol-activity-row <?= $is_current ? 'fw-semibold' : '' ?>" style="<?= $is_current ? 'background:var(--vol-bg)' : '' ?>">
    <div class="vol-activity-icon bg-<?= $is_current ? 'success' : 'secondary' ?> bg-opacity-15 text-<?= $is_current ? 'success' : 'secondary' ?>">
      <i class="bi <?= $dev_icon ?>" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1" style="min-width:0">
      <div style="font-size:.83rem">
        <?= h(ua_label($s['user_agent'])) ?>
        <?php if ($is_current): ?>
        <span class="badge bg-success ms-1" style="font-size:.63rem">bieżąca</span>
        <?php endif; ?>
      </div>
      <div class="text-muted" style="font-size:.75rem">
        <i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= h($masked_ip) ?>
        · <?= h(date('d.m.Y H:i', strtotime($last))) ?>
      </div>
    </div>
    <?php if (!$is_current): ?>
    <form method="post" class="flex-shrink-0">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="revoke">
      <input type="hidden" name="token" value="<?= h($s['token']) ?>">
      <button class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.75rem"
              onclick="return confirm('Zakończyć tę sesję?')">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<div class="vol-detail-card">
  <div class="vol-detail-header">
    <i class="bi bi-clock-history me-2" aria-hidden="true"></i>Historia logowań
    <span class="text-muted fw-normal" style="font-size:.78rem;font-weight:400!important">(ostatnie 40)</span>
  </div>
  <?php if (!$history): ?>
  <div class="vol-detail-body text-center py-3 text-muted small">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak historii logowań
  </div>
  <?php endif; ?>
  <?php foreach ($history as $log):
    [$label, $variant, $icon] = authlog_action_label($log['action']);
  ?>
  <div class="vol-activity-row">
    <div class="vol-activity-icon bg-<?= $variant ?> bg-opacity-15 text-<?= $variant ?>">
      <i class="bi <?= $icon ?>" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1" style="min-width:0">
      <div style="font-size:.82rem;font-weight:500"><?= h($label) ?></div>
      <div class="text-muted" style="font-size:.72rem">
        <?php
        $lp = explode('.', $log['ip'] ?? '');
        $lip = count($lp) >= 2 ? $lp[0].'.'.$lp[1].'.x.x' : ($log['ip'] ?? '—');
        ?>
        <i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= h($lip) ?>
      </div>
    </div>
    <div class="text-muted text-nowrap" style="font-size:.72rem">
      <?= h(date('d.m H:i', strtotime($log['created_at']))) ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="alert alert-light border mt-3 d-flex align-items-start gap-2" style="font-size:.78rem">
  <i class="bi bi-info-circle text-muted flex-shrink-0 mt-1" aria-hidden="true"></i>
  <span>Historia logowań przechowywana jest przez <strong>90 dni</strong> wyłącznie do celów bezpieczeństwa (RODO art. 32).</span>
</div>

<?php else: /* !$_is_volunteer_only */

// Admin/editor layout
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/panel/index.php">Panel</a></li>
    <li class="breadcrumb-item active">Sesje i historia logowań</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;flex-shrink:0">
    <i class="bi bi-shield-lock text-primary fs-4"></i>
  </div>
  <div>
    <h4 class="mb-0">Sesje i historia logowań</h4>
    <div class="text-muted small">Zarządzaj aktywnymi sesjami i sprawdź historię dostępu</div>
  </div>
</div>

<?= flash_html() ?>

<div class="row g-4">

<div class="col-lg-6">
<div class="card shadow-sm h-100">
  <div class="card-header d-flex justify-content-between align-items-center fw-semibold">
    <span><i class="bi bi-display text-primary me-2"></i>Aktywne sesje</span>
    <?php if (count($sessions) > 1): ?>
    <form method="post" class="m-0" onsubmit="return confirm('Zakończyć wszystkie inne sesje?')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="revoke_all">
      <button class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.75rem">
        <i class="bi bi-x-circle me-1"></i>Zakończ pozostałe
      </button>
    </form>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <?php if (!$sessions): ?>
    <div class="text-muted text-center py-4" style="font-size:.85rem">
      <i class="bi bi-info-circle me-1"></i>Brak zarejestrowanych sesji
    </div>
    <?php endif; ?>
    <?php foreach ($sessions as $s):
      $is_current = $s['token'] === $current_token;
      $last = $s['last_active'] ?? $s['created_at'];
    ?>
    <div class="d-flex align-items-start gap-3 px-3 py-3 border-bottom">
      <div class="mt-1" style="font-size:1.4rem;opacity:.6">
        <?php $ua = strtolower($s['user_agent']);
          if (str_contains($ua,'mobile') || str_contains($ua,'android') || str_contains($ua,'iphone')) echo '📱';
          elseif (str_contains($ua,'tablet') || str_contains($ua,'ipad')) echo '📱';
          else echo '💻'; ?>
      </div>
      <div class="flex-grow-1" style="min-width:0">
        <div class="fw-semibold" style="font-size:.85rem">
          <?= h(ua_label($s['user_agent'])) ?>
          <?php if ($is_current): ?>
          <span class="badge bg-success ms-1" style="font-size:.65rem">bieżąca</span>
          <?php endif; ?>
        </div>
        <div class="text-muted" style="font-size:.75rem">
          <i class="bi bi-geo-alt me-1"></i><?= h($s['ip']) ?>
          <span class="mx-1">·</span>
          Ostatnia aktywność: <?= h(date('d.m.Y H:i', strtotime($last))) ?>
        </div>
        <div class="text-muted" style="font-size:.72rem;opacity:.6">
          Zalogowano: <?= h(date('d.m.Y H:i', strtotime($s['created_at']))) ?>
        </div>
      </div>
      <?php if (!$is_current): ?>
      <form method="post" class="flex-shrink-0">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="revoke">
        <input type="hidden" name="token" value="<?= h($s['token']) ?>">
        <button class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.75rem"
                onclick="return confirm('Zakończyć tę sesję?')">
          <i class="bi bi-x-lg"></i>
        </button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</div>

<div class="col-lg-6">
<div class="card shadow-sm h-100">
  <div class="card-header fw-semibold">
    <i class="bi bi-clock-history text-primary me-2"></i>Historia logowań
    <span class="text-muted fw-normal" style="font-size:.78rem">(ostatnie 40 zdarzeń)</span>
  </div>
  <div class="card-body p-0" style="max-height:520px;overflow-y:auto">
    <?php if (!$history): ?>
    <div class="text-muted text-center py-4" style="font-size:.85rem">
      <i class="bi bi-info-circle me-1"></i>Brak historii logowań
    </div>
    <?php endif; ?>
    <?php foreach ($history as $log):
      [$label, $variant, $icon] = authlog_action_label($log['action']);
    ?>
    <div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom">
      <div class="mt-1 flex-shrink-0">
        <span class="badge rounded-pill bg-<?= $variant ?> bg-opacity-15 text-<?= $variant ?>"
              style="font-size:.65rem;padding:.3rem .5rem">
          <i class="bi <?= $icon ?>"></i>
        </span>
      </div>
      <div class="flex-grow-1" style="min-width:0">
        <div style="font-size:.82rem;font-weight:500"><?= h($label) ?></div>
        <div class="text-muted" style="font-size:.72rem">
          <i class="bi bi-geo-alt me-1"></i><?= h($log['ip']) ?>
          <?php if ($log['user_agent']): ?>
          <span class="mx-1">·</span><?= h(ua_label($log['user_agent'])) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="text-muted text-nowrap" style="font-size:.72rem">
        <?= h(date('d.m H:i', strtotime($log['created_at']))) ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</div>

</div><!-- /row -->

<div class="alert alert-light border mt-4 d-flex align-items-start gap-2" style="font-size:.78rem">
  <i class="bi bi-info-circle text-muted flex-shrink-0 mt-1"></i>
  <span>
    Historia logowań przechowywana jest przez <strong>90 dni</strong> i służy wyłącznie do celów bezpieczeństwa i audytu (RODO, art. 32).
    Zakończenie sesji nie usuwa danych historycznych.
  </span>
</div>

<?php endif; /* $_is_volunteer_only */ ?>

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
