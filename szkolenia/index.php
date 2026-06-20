<?php
/**
 * szkolenia/index.php — „Umów się na szkolenie" w systemie głównym / portalu.
 * Ten sam kreator co w panelu wolontariusza (wspólny widżet tidycal_book_ui.php).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';
require_once dirname(__DIR__) . '/includes/tidycal.php';

require_login();
if (!(can_read('szkolenia') || is_admin())) {
    http_response_code(403);
    die('Brak dostępu do modułu szkoleń.');
}

$u   = current_user();
$uid = (int)$u['id'];

$self = APP_URL . '/szkolenia/index.php';
$ctx  = [
    'user_id'       => $uid,
    'self_url'      => $self,
    'source'        => 'portal',
    'default_name'  => trim($u['name'] ?? '') ?: trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
    'default_email' => trim($u['email'] ?? ''),
];

tidycal_handle_slots_ajax();
tidycal_handle_booking_post($ctx);

$tc_self          = $self;
$tc_ctx           = $ctx;
$tc_user_bookings = tidycal_user_bookings($uid);
$tc_fallback_type = (int)($_GET['fallback'] ?? 0);

$PAGE_TITLE = 'Umów się na szkolenie';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div style="width:46px;height:46px;border-radius:12px;
              background:linear-gradient(135deg,#7C3AED,#C084FC);
              display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fff">
    <i class="bi bi-calendar2-check"></i>
  </div>
  <div>
    <h4 class="mb-0 fw-bold">Umów się na szkolenie</h4>
    <div class="text-muted small">Wybierz szkolenie, dzień i wolny termin — rezerwacja w kilka kliknięć</div>
  </div>
  <?php if (is_admin()): ?>
  <a href="<?= APP_URL ?>/admin/tidycal_settings.php" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-gear"></i> Konfiguracja
  </a>
  <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/tidycal_book_ui.php'; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
