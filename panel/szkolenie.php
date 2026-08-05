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

<div class="pv-wrap">

  <div class="pv-page-header">
    <a href="<?= APP_URL ?>/panel/" class="pv-page-back">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Panel
    </a>
    <h1 class="pv-page-title">
      <i class="bi bi-calendar3-event" aria-hidden="true"></i> Umów się na szkolenie
    </h1>
    <p class="pv-page-sub">Wybierz szkolenie, dzień i wolny termin — rezerwacja w kilka kliknięć</p>
  </div>

  <?php include dirname(__DIR__) . '/includes/tidycal_book_ui.php'; ?>

</div>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
