<?php
/**
 * panel/calendar.php — Kalendarz organizacji (ICS feed)
 *
 * Wyświetla listę nadchodzących wydarzeń z kalendarza organizacji
 * pobieranego z publicznego/prywatnego kanału ICS (np. Outlook 365).
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ics_parser.php';

require_login();
require_module_enabled('org_calendar_enabled', 'Kalendarz organizacji');

$PAGE_TITLE = 'Kalendarz organizacji';

// ── Konfiguracja ───────────────────────────────────────────────────────────────
$ics_url   = org_setting('org_calendar_ics_url');
$cache_ttl = max(300, (int)(org_setting('org_calendar_cache_ttl') ?: 3600));
$show_past_days  = (int)(org_setting('org_calendar_past_days')   ?: 7);
$show_future_days= (int)(org_setting('org_calendar_future_days') ?: 365);

// ── Obsługa AJAX odświeżenia ───────────────────────────────────────────────────
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
if ($is_ajax && ($_GET['_action'] ?? '') === 'refresh') {
    // Wymuś odświeżenie cache
    if ($ics_url) {
        IcsParser::invalidate_cache($ics_url);
    }
    // Odpowiedz JSON — odświeżenie przez JS poniżej
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// ── Pobierz i sparsuj eventy ───────────────────────────────────────────────────
$events      = [];
$fetch_error = null;
$last_updated = null;

if ($ics_url) {
    $ics_text = IcsParser::fetch($ics_url, $cache_ttl);
    if ($ics_text === null) {
        $fetch_error = 'Nie można pobrać kalendarza. Sprawdź ustawienia lub spróbuj za chwilę.';
    } else {
        $all_events = IcsParser::parse($ics_text);

        // Filtr zakresu dat
        $now     = new \DateTimeImmutable('today', new \DateTimeZone(org_setting('timezone') ?: 'Europe/Warsaw'));
        $from_dt = $now->modify("-{$show_past_days} days");
        $to_dt   = $now->modify("+{$show_future_days} days");

        foreach ($all_events as $ev) {
            $start = $ev['dtstart'];
            if (!$start instanceof \DateTimeInterface) continue;
            if ($start < $from_dt || $start > $to_dt) continue;
            $events[] = $ev;
        }

        // Info o czasie pobrania (z cache mtime)
        $cache_file = sys_get_temp_dir() . '/feer_org_cal_' . substr(md5($ics_url), 0, 12) . '.ics';
        if (is_file($cache_file)) {
            $last_updated = date('d.m.Y H:i', filemtime($cache_file));
        }
    }
}

// Grupuj po miesiącu
$by_month = [];
foreach ($events as $ev) {
    $key = $ev['dtstart']->format('Y-m');
    $by_month[$key][] = $ev;
}

$today_str = date('Y-m-d');

// ── Widok ──────────────────────────────────────────────────────────────────────
require dirname(__DIR__) . '/panel/includes/header_panel.php';
?>
<div class="container py-4" id="cal-root">

  <!-- Nagłówek -->
  <div class="d-flex align-items-center justify-content-between mb-3 gap-2 flex-wrap">
    <div>
      <h1 class="h4 mb-0 fw-bold">
        <i class="bi bi-calendar3 me-2" style="color:var(--vol-color)"></i>Kalendarz organizacji
      </h1>
      <?php if ($last_updated): ?>
        <div class="text-muted small mt-1">
          <i class="bi bi-clock me-1"></i>Aktualizacja: <?= h($last_updated) ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <?php if ($ics_url): ?>
        <button class="btn btn-sm btn-outline-secondary" id="btn-cal-refresh"
                aria-label="Odśwież kalendarz">
          <i class="bi bi-arrow-clockwise me-1"></i>Odśwież
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Błąd pobierania -->
  <?php if ($fetch_error): ?>
    <div class="alert alert-warning d-flex gap-2 align-items-center">
      <i class="bi bi-exclamation-triangle-fill fs-5"></i>
      <span><?= h($fetch_error) ?></span>
    </div>
  <?php endif; ?>

  <!-- Brak URL -->
  <?php if (!$ics_url && !$fetch_error): ?>
    <div class="alert alert-info d-flex gap-2 align-items-center">
      <i class="bi bi-calendar-x fs-4 flex-shrink-0"></i>
      <div>
        <strong>Kalendarz nie jest skonfigurowany.</strong><br>
        <span class="small">Administrator może ustawić adres ICS kalendarza w
          <a href="<?= APP_URL ?>/admin/org_calendar.php">ustawieniach kalendarza</a>.
        </span>
      </div>
    </div>
  <?php endif; ?>

  <!-- Lista wydarzeń -->
  <div id="cal-events">
    <?= render_calendar_events($by_month, $today_str) ?>
  </div>

</div>

<?php
// ── Helper renderowania ────────────────────────────────────────────────────────
function render_calendar_events(array $by_month, string $today_str): string
{
    if (empty($by_month)) {
        return '<div class="text-center text-muted py-5">
            <i class="bi bi-calendar-check display-4 d-block mb-3 opacity-50"></i>
            <p class="mb-0">Brak wydarzeń w wybranym zakresie.</p>
        </div>';
    }

    $html = '';
    $month_names = [
        '01'=>'Styczeń','02'=>'Luty','03'=>'Marzec','04'=>'Kwiecień',
        '05'=>'Maj','06'=>'Czerwiec','07'=>'Lipiec','08'=>'Sierpień',
        '09'=>'Wrzesień','10'=>'Październik','11'=>'Listopad','12'=>'Grudzień',
    ];

    foreach ($by_month as $month_key => $month_events) {
        [, $m] = explode('-', $month_key);
        $year  = substr($month_key, 0, 4);
        $label = ($month_names[$m] ?? $m) . ' ' . $year;

        $html .= '<div class="cal-month-group mb-4">';
        $html .= '<div class="cal-month-label d-flex align-items-center gap-2 mb-2">';
        $html .= '<i class="bi bi-calendar2-week text-muted"></i>';
        $html .= '<span class="fw-semibold text-muted small text-uppercase">' . h($label) . '</span>';
        $html .= '<hr class="flex-grow-1 my-0">';
        $html .= '</div>';

        $html .= '<div class="list-group shadow-sm">';
        foreach ($month_events as $ev) {
            $html .= render_event_item($ev, $today_str);
        }
        $html .= '</div>';
        $html .= '</div>';
    }
    return $html;
}

function render_event_item(array $ev, string $today_str): string
{
    $start   = $ev['dtstart'];
    $end     = $ev['dtend'] ?? null;
    $all_day = $ev['all_day'] ?? false;

    $date_str = $start instanceof \DateTimeInterface ? $start->format('Y-m-d') : '';
    $is_today = $date_str === $today_str;
    $is_past  = $date_str && $date_str < $today_str;

    // Format daty i czasu
    $day_num    = $start instanceof \DateTimeInterface ? $start->format('d') : '';
    $day_name   = $start instanceof \DateTimeInterface ? format_day_name($start) : '';
    $time_start = (!$all_day && $start instanceof \DateTimeInterface) ? $start->format('H:i') : '';
    $time_end   = (!$all_day && $end instanceof \DateTimeInterface) ? $end->format('H:i') : '';

    $time_html = '';
    if ($time_start) {
        $time_html = '<span class="small fw-semibold">' . h($time_start) . '</span>';
        if ($time_end) $time_html .= '<span class="text-muted small">–' . h($time_end) . '</span>';
    } else {
        $time_html = '<span class="badge bg-secondary-subtle text-secondary small">Cały dzień</span>';
    }

    $extra_class = $is_past ? ' opacity-60' : '';
    $today_badge = $is_today
        ? '<span class="badge ms-2" style="background:var(--vol-color);color:var(--vol-on)">Dziś</span>'
        : '';

    // Opis skrócony
    $desc = trim($ev['description'] ?? '');
    $desc_html = '';
    if ($desc) {
        $short = mb_strtrimwidth(preg_replace('/\s+/', ' ', $desc), 0, 160, '…');
        $desc_html = '<div class="small text-muted mt-1 cal-desc">' . h($short) . '</div>';
    }

    // Lokalizacja
    $loc = trim($ev['location'] ?? '');
    $loc_html = '';
    if ($loc) {
        $loc_html = '<span class="small text-muted ms-2"><i class="bi bi-geo-alt me-1"></i>' . h(mb_strtrimwidth($loc, 0, 60, '…')) . '</span>';
    }

    // Recurrence badge
    $rrule_html = !empty($ev['rrule'])
        ? '<span class="badge bg-light border text-muted small ms-2"><i class="bi bi-arrow-repeat me-1"></i>Cykliczne</span>'
        : '';

    // Kolor boczny paska — generowany z nazwy kalendarza lub kategorii
    $bar_color = $is_past ? '#9CA3AF' : 'var(--vol-color)';

    return '<div class="list-group-item list-group-item-action px-0 py-0 border-0 mb-2' . $extra_class . '"
                 style="border-radius:10px;overflow:hidden;">
      <div class="d-flex" style="border-radius:10px;border:1px solid #e5e7eb;overflow:hidden;">
        <!-- Data boczna -->
        <div class="cal-day-badge d-flex flex-column align-items-center justify-content-center px-3 text-white flex-shrink-0"
             style="min-width:56px;background:' . $bar_color . ';border-radius:0;">
          <span class="fw-bold" style="font-size:1.25rem;line-height:1.1">' . h($day_num) . '</span>
          <span style="font-size:.65rem;text-transform:uppercase;opacity:.85">' . h($day_name) . '</span>
        </div>
        <!-- Treść -->
        <div class="flex-grow-1 py-2 px-3">
          <div class="d-flex align-items-center flex-wrap gap-1">
            <span class="fw-semibold">' . h($ev['summary']) . '</span>'
              . $today_badge . $rrule_html . '
          </div>
          <div class="d-flex align-items-center flex-wrap mt-1 gap-1">
            ' . $time_html . $loc_html . '
          </div>'
          . $desc_html . '
        </div>
      </div>
    </div>';
}

function format_day_name(\DateTimeInterface $dt): string
{
    static $days = ['Niedz','Pon','Wt','Śr','Czw','Pt','Sob'];
    return $days[(int)$dt->format('w')];
}
?>

<style>
.cal-month-label hr { border-color: #e5e7eb; }
.opacity-60 { opacity: .6; }
</style>

<script>
(function () {
  'use strict';
  const btn = document.getElementById('btn-cal-refresh');
  if (!btn) return;

  btn.addEventListener('click', async function () {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Odświeżanie…';
    try {
      await fetch('<?= APP_URL ?>/panel/calendar.php?_action=refresh', {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      window.location.reload();
    } catch (e) {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Odśwież';
    }
  });
})();
</script>

<?php require dirname(__DIR__) . '/panel/includes/footer_panel.php'; ?>
