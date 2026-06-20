<?php
/**
 * panel/szkolenie.php — „Umów się na szkolenie" w panelu wolontariusza.
 * Kreator rezerwacji terminu szkolenia (TidyCal) bez dostępu do panelu admina.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tidycal.php';

require_login();

$u   = current_user();
$uid = (int)$u['id'];

$self = APP_URL . '/panel/szkolenie.php';
$ctx  = [
    'user_id'       => $uid,
    'self_url'      => $self,
    'source'        => 'panel',
    'default_name'  => trim($u['name'] ?? '') ?: trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
    'default_email' => trim($u['email'] ?? ''),
];

// Obsługa AJAX (wolne terminy) i POST (rezerwacja) — przed nagłówkiem.
tidycal_handle_slots_ajax();
tidycal_handle_booking_post($ctx);

// Zmienne dla wspólnego widżetu.
$tc_self          = $self;
$tc_ctx           = $ctx;
$tc_user_bookings = tidycal_user_bookings($uid);
$tc_fallback_type = (int)($_GET['fallback'] ?? 0);

$PAGE_TITLE = 'Umów się na szkolenie';
include __DIR__ . '/includes/header_panel.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div style="width:44px;height:44px;border-radius:12px;background:var(--vol-bg);
              display:flex;align-items:center;justify-content:center;
              font-size:1.3rem;color:var(--vol-color)">
    <i class="bi bi-calendar2-check" aria-hidden="true"></i>
  </div>
  <div>
    <h1 class="mb-0 fw-bold" style="font-size:1.2rem">Umów się na szkolenie</h1>
    <div class="text-muted small">Wybierz szkolenie, dzień i wolny termin — rezerwacja w kilka kliknięć</div>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/tidycal_book_ui.php'; ?>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
