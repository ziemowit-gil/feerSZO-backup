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
<div class="pv-wrap" id="cal-root">

  <div class="pv-page-header">
    <div class="pv-page-head-main">
      <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
      <h1 class="pv-page-title"><i class="bi bi-calendar3" aria-hidden="true"></i>Kalendarz</h1>
      <p class="pv-page-sub">Twój harmonogram i terminy</p>
      <?php if ($last_updated): ?>
        <p class="pv-page-sub"><i class="bi bi-clock" aria-hidden="true"></i> Aktualizacja: <?= h($last_updated) ?></p>
      <?php endif; ?>
    </div>
    <?php if ($ics_url): ?>
      <div class="pv-page-head-actions">
        <button class="tz-btn tz-btn--ghost" id="btn-cal-refresh" aria-label="Odśwież kalendarz">
          <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Odśwież
        </button>
      </div>
    <?php endif; ?>
  </div>

  <!-- Błąd pobierania -->
  <?php if ($fetch_error): ?>
    <div class="pv-alert pv-alert-warning" role="alert">
      <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
      <span><?= h($fetch_error) ?></span>
    </div>
  <?php endif; ?>

  <!-- Brak URL -->
  <?php if (!$ics_url && !$fetch_error): ?>
    <div class="pv-alert pv-alert-info" role="alert">
      <i class="bi bi-calendar-x" aria-hidden="true"></i>
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
        return '<div class="tz-empty">
            <i class="bi bi-calendar-check" aria-hidden="true"></i>
            <p>Brak wydarzeń w wybranym zakresie.</p>
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

        $html .= '<div class="cal-month-group">';
        $html .= '<div class="cal-month-label">';
        $html .= '<i class="bi bi-calendar2-week" aria-hidden="true"></i>';
        $html .= '<span>' . h($label) . '</span>';
        $html .= '<hr aria-hidden="true">';
        $html .= '</div>';

        foreach ($month_events as $ev) {
            $html .= render_event_item($ev, $today_str);
        }
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
        $time_html = '<span class="cal-time">' . h($time_start) . '</span>';
        if ($time_end) $time_html .= '<span class="cal-time-end">–' . h($time_end) . '</span>';
    } else {
        $time_html = '<span class="tz-badge">Cały dzień</span>';
    }

    $extra_class = $is_past ? ' cal-event--past' : '';
    $today_badge = $is_today
        ? '<span class="tz-badge tz-badge--today">Dziś</span>'
        : '';

    // Opis skrócony
    $desc = trim($ev['description'] ?? '');
    $desc_html = '';
    if ($desc) {
        $short = mb_strtrimwidth(preg_replace('/\s+/', ' ', $desc), 0, 160, '…');
        $desc_html = '<p class="cal-desc">' . h($short) . '</p>';
    }

    // Lokalizacja
    $loc = trim($ev['location'] ?? '');
    $loc_html = '';
    if ($loc) {
        $loc_html = '<span class="cal-loc"><i class="bi bi-geo-alt" aria-hidden="true"></i> ' . h(mb_strtrimwidth($loc, 0, 60, '…')) . '</span>';
    }

    // Recurrence badge
    $rrule_html = !empty($ev['rrule'])
        ? '<span class="tz-badge"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Cykliczne</span>'
        : '';

    $past_mod = $is_past ? ' cal-day-badge--past' : '';

    return '<article class="tz-card cal-event-item' . $extra_class . '" aria-label="' . h($ev['summary']) . '">
      <div class="tz-card__hd cal-day-badge' . $past_mod . '" aria-hidden="true">
        <span class="cal-day-num">' . h($day_num) . '</span>
        <span class="cal-day-name">' . h($day_name) . '</span>
      </div>
      <div class="tz-card__bd">
        <div class="cal-event-title">
          <span class="cal-summary">' . h($ev['summary']) . '</span>'
          . $today_badge . $rrule_html . '
        </div>
        <div class="cal-event-meta">'
          . $time_html . $loc_html . '
        </div>'
        . $desc_html . '
      </div>
    </article>';
}

function format_day_name(\DateTimeInterface $dt): string
{
    static $days = ['Niedz','Pon','Wt','Śr','Czw','Pt','Sob'];
    return $days[(int)$dt->format('w')];
}
?>

<script>
(function () {
  'use strict';
  const btn = document.getElementById('btn-cal-refresh');
  if (!btn) return;

  btn.addEventListener('click', async function () {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Odświeżanie…';
    try {
      await fetch('<?= APP_URL ?>/panel/calendar.php?_action=refresh', {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      window.location.reload();
    } catch (e) {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Odśwież';
    }
  });
})();
</script>

<?php require dirname(__DIR__) . '/panel/includes/footer_panel.php'; ?>
