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

<div class="pv-wrap">

<div class="pv-page-header">
  <div class="pv-page-head-main">
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
    <h1 class="pv-page-title"><i class="bi bi-device-laptop" aria-hidden="true"></i>Aktywne sesje</h1>
    <p class="pv-page-sub">Urządzenia zalogowane do Twojego konta</p>
  </div>
</div>

<?= flash_html() ?>

<?php if ($_is_volunteer_only): ?>

<div class="tz-card mb-4">
  <div class="tz-card__hd d-flex align-items-center justify-content-between">
    <span><i class="bi bi-display me-2" aria-hidden="true"></i>Aktywne sesje</span>
    <?php if (count($sessions) > 1): ?>
    <form method="post" class="m-0" onsubmit="return confirm('Zakończyć wszystkie inne sesje?')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="revoke_all">
      <button type="submit" class="tz-btn tz-btn--ghost tz-btn--sm" aria-label="Zakończ wszystkie inne sesje">
        <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Zakończ pozostałe
      </button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!$sessions): ?>
  <div class="tz-card__bd text-center py-3 text-muted small">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak zarejestrowanych sesji
  </div>
  <?php endif; ?>
  <ul class="list-unstyled mb-0" role="list">
  <?php foreach ($sessions as $s):
    $is_current = $s['token'] === $current_token;
    $last = $s['last_active'] ?? $s['created_at'];
    $ua = strtolower($s['user_agent'] ?? '');
    $dev_icon = (str_contains($ua,'mobile') || str_contains($ua,'android') || str_contains($ua,'iphone')) ? 'bi-phone' : 'bi-laptop';
    // Mask IP - only first 2 octets
    $ip_parts = explode('.', $s['ip'] ?? '');
    $masked_ip = count($ip_parts) >= 2 ? $ip_parts[0].'.'.$ip_parts[1].'.x.x' : ($s['ip'] ?? '—');
    $dev_label = h(ua_label($s['user_agent']));
  ?>
  <li class="vol-activity-row <?= $is_current ? 'fw-semibold' : '' ?>">
    <div class="vol-activity-icon bg-<?= $is_current ? 'success' : 'secondary' ?> bg-opacity-15 text-<?= $is_current ? 'success' : 'secondary' ?>">
      <i class="bi <?= $dev_icon ?>" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1" style="min-width:0">
      <div style="font-size:.83rem">
        <?= $dev_label ?>
        <?php if ($is_current): ?>
        <span class="tz-badge tz-badge--ok ms-1">bieżąca</span>
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
      <button type="submit" class="tz-btn tz-btn--ghost tz-btn--sm"
              aria-label="Wyloguj sesję z <?= $dev_label ?>"
              onclick="return confirm('Zakończyć tę sesję?')">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </form>
    <?php endif; ?>
  </li>
  <?php endforeach; ?>
  </ul>
</div>

<div class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-clock-history me-2" aria-hidden="true"></i>Historia logowań
    <span class="text-muted fw-normal" style="font-size:.78rem;font-weight:400!important">(ostatnie 40)</span>
  </div>
  <?php if (!$history): ?>
  <div class="tz-card__bd text-center py-3 text-muted small">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak historii logowań
  </div>
  <?php endif; ?>
  <ul class="list-unstyled mb-0" role="list">
  <?php foreach ($history as $log):
    [$label, $variant, $icon] = authlog_action_label($log['action']);
    $lp = explode('.', $log['ip'] ?? '');
    $lip = count($lp) >= 2 ? $lp[0].'.'.$lp[1].'.x.x' : ($log['ip'] ?? '—');
  ?>
  <li class="vol-activity-row">
    <div class="vol-activity-icon bg-<?= $variant ?> bg-opacity-15 text-<?= $variant ?>">
      <i class="bi <?= $icon ?>" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1" style="min-width:0">
      <div style="font-size:.82rem;font-weight:500"><?= h($label) ?></div>
      <div class="text-muted" style="font-size:.72rem">
        <i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= h($lip) ?>
      </div>
    </div>
    <div class="text-muted text-nowrap" style="font-size:.72rem">
      <?= h(date('d.m H:i', strtotime($log['created_at']))) ?>
    </div>
  </li>
  <?php endforeach; ?>
  </ul>
</div>

<div class="tz-note mt-3 d-flex align-items-start gap-2" style="font-size:.78rem">
  <i class="bi bi-info-circle flex-shrink-0 mt-1" aria-hidden="true"></i>
  <span>Historia logowań przechowywana jest przez <strong>90 dni</strong> wyłącznie do celów bezpieczeństwa (RODO art. 32).</span>
</div>

<?php else: /* !$_is_volunteer_only */
// Admin/editor layout
?>

<div class="row g-4">

<div class="col-lg-6">
<div class="tz-card h-100">
  <div class="tz-card__hd d-flex justify-content-between align-items-center">
    <span><i class="bi bi-display text-primary me-2" aria-hidden="true"></i>Aktywne sesje</span>
    <?php if (count($sessions) > 1): ?>
    <form method="post" class="m-0" onsubmit="return confirm('Zakończyć wszystkie inne sesje?')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="revoke_all">
      <button type="submit" class="tz-btn tz-btn--ghost tz-btn--sm" aria-label="Zakończ wszystkie inne sesje">
        <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Zakończ pozostałe
      </button>
    </form>
    <?php endif; ?>
  </div>
  <div class="tz-card__bd p-0">
    <?php if (!$sessions): ?>
    <div class="text-muted text-center py-4" style="font-size:.85rem">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak zarejestrowanych sesji
    </div>
    <?php endif; ?>
    <ul class="list-unstyled mb-0" role="list">
    <?php foreach ($sessions as $s):
      $is_current = $s['token'] === $current_token;
      $last = $s['last_active'] ?? $s['created_at'];
      $ua = strtolower($s['user_agent'] ?? '');
      $dev_icon = (str_contains($ua,'mobile') || str_contains($ua,'android') || str_contains($ua,'iphone') || str_contains($ua,'tablet') || str_contains($ua,'ipad')) ? 'bi-phone' : 'bi-laptop';
      $dev_label = h(ua_label($s['user_agent']));
    ?>
    <li class="d-flex align-items-start gap-3 px-3 py-3 border-bottom">
      <div class="mt-1 flex-shrink-0" style="font-size:1.4rem;opacity:.6">
        <i class="bi <?= $dev_icon ?>" aria-hidden="true"></i>
      </div>
      <div class="flex-grow-1" style="min-width:0">
        <div class="fw-semibold" style="font-size:.85rem">
          <?= $dev_label ?>
          <?php if ($is_current): ?>
          <span class="tz-badge tz-badge--ok ms-1">bieżąca</span>
          <?php endif; ?>
        </div>
        <div class="text-muted" style="font-size:.75rem">
          <i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= h($s['ip']) ?>
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
        <button type="submit" class="tz-btn tz-btn--ghost tz-btn--sm"
                aria-label="Wyloguj sesję z <?= $dev_label ?>"
                onclick="return confirm('Zakończyć tę sesję?')">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </form>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
    </ul>
  </div>
</div>
</div>

<div class="col-lg-6">
<div class="tz-card h-100">
  <div class="tz-card__hd">
    <i class="bi bi-clock-history text-primary me-2" aria-hidden="true"></i>Historia logowań
    <span class="text-muted fw-normal" style="font-size:.78rem">(ostatnie 40 zdarzeń)</span>
  </div>
  <div class="tz-card__bd p-0" style="max-height:520px;overflow-y:auto">
    <?php if (!$history): ?>
    <div class="text-muted text-center py-4" style="font-size:.85rem">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak historii logowań
    </div>
    <?php endif; ?>
    <ul class="list-unstyled mb-0" role="list">
    <?php foreach ($history as $log):
      [$label, $variant, $icon] = authlog_action_label($log['action']);
    ?>
    <li class="d-flex align-items-start gap-2 px-3 py-2 border-bottom">
      <div class="mt-1 flex-shrink-0">
        <span class="tz-badge tz-badge--<?= $variant ?>">
          <i class="bi <?= $icon ?>" aria-hidden="true"></i>
        </span>
      </div>
      <div class="flex-grow-1" style="min-width:0">
        <div style="font-size:.82rem;font-weight:500"><?= h($label) ?></div>
        <div class="text-muted" style="font-size:.72rem">
          <i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= h($log['ip']) ?>
          <?php if ($log['user_agent']): ?>
          <span class="mx-1">·</span><?= h(ua_label($log['user_agent'])) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="text-muted text-nowrap" style="font-size:.72rem">
        <?= h(date('d.m H:i', strtotime($log['created_at']))) ?>
      </div>
    </li>
    <?php endforeach; ?>
    </ul>
  </div>
</div>
</div>

</div><!-- /row -->

<div class="tz-note mt-4 d-flex align-items-start gap-2" style="font-size:.78rem">
  <i class="bi bi-info-circle flex-shrink-0 mt-1" aria-hidden="true"></i>
  <span>
    Historia logowań przechowywana jest przez <strong>90 dni</strong> i służy wyłącznie do celów bezpieczeństwa i audytu (RODO, art. 32).
    Zakończenie sesji nie usuwa danych historycznych.
  </span>
</div>

<?php endif; /* $_is_volunteer_only */ ?>

</div><!-- /pv-wrap -->

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
?>
