<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

// API mode: return JSON events
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $from        = $_GET['from']        ?? date('Y-m-01');
    $to          = $_GET['to']          ?? date('Y-m-t');
    $filter_cid  = (int)($_GET['client']     ?? 0);
    $filter_cons = (int)($_GET['consultant'] ?? 0);
    $filter_ser  = trim($_GET['series'] ?? '');

    $where = ['DATE(s.start_time) BETWEEN ? AND ?'];
    $params = [$from, $to];
    if ($filter_cid)  { $where[] = 's.client_id=?';   $params[] = $filter_cid; }
    if ($filter_cons) { $where[] = 's.assigned_to=?';  $params[] = $filter_cons; }
    if ($filter_ser)  { $where[] = 's.series_id=?';    $params[] = $filter_ser; }

    $rows = db_all(
        "SELECT s.id, s.start_time, s.duration_minutes, s.status,
                s.series_id, s.billing_type, s.time_from, s.time_to,
                c.name AS client_name,
                u.name AS consultant_name
         FROM k30_schedules s
         LEFT JOIN k30_clients c ON c.id=s.client_id
         LEFT JOIN users u ON u.id=s.assigned_to
         WHERE " . implode(' AND ', $where) . "
         ORDER BY s.start_time",
        $params
    );

    $colors = [
        'preliminary'        => '#F59E0B',
        'confirmed'          => '#2E844A',
        'attended'           => '#0176D3',
        'cancelled_by_feer'  => '#DC2626',
        'cancelled_by_client'=> '#D97706',
        'no_show'            => '#7C3AED',
        'cancelled'          => '#9CA3AF',
    ];

    $events = [];
    foreach ($rows as $r) {
        $start = new DateTime($r['start_time']);
        $end   = clone $start;
        $end->modify('+' . (int)$r['duration_minutes'] . ' minutes');
        $color = $colors[$r['status']] ?? '#6B7280';
        $title = $r['client_name'];
        if ($r['consultant_name']) $title .= ' / ' . $r['consultant_name'];
        $events[] = [
            'id'         => $r['id'],
            'title'      => $title,
            'start'      => $start->format('Y-m-d\TH:i:s'),
            'end'        => $end->format('Y-m-d\TH:i:s'),
            'color'      => $color,
            'url'        => APP_URL . '/karty30/schedules/view.php?id=' . $r['id'],
            'status'     => $r['status'],
            'is_series'  => !empty($r['series_id']),
            'billing'    => $r['billing_type'],
        ];
    }
    echo json_encode($events);
    exit;
}

$PAGE_TITLE = 'Kalendarz — Karty 30';
$can_write  = can_write('karty30') || is_admin();

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
    <li class="breadcrumb-item"><a href="index.php">Harmonogram</a></li>
    <li class="breadcrumb-item active">Kalendarz</li>
  </ol>
</nav>

<?php
$all_clients     = db_all("SELECT id, name FROM k30_clients ORDER BY name");
$all_consultants = k30_get_consultants();
$filter_cid  = (int)($_GET['client']     ?? 0);
$filter_cons = (int)($_GET['consultant'] ?? 0);
$filter_ser  = trim($_GET['series'] ?? '');
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-calendar-week text-info me-2"></i>Kalendarz wizyt</h4>
  <div class="d-flex gap-2">
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list me-1"></i>Lista</a>
    <?php if ($can_write): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="bi bi-calendar-plus me-1"></i>Nowy termin</a>
    <?php endif; ?>
  </div>
</div>

<!-- Filtry -->
<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-auto">
    <label class="form-label small fw-semibold mb-1">Beneficjent</label>
    <select name="client" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">Wszyscy</option>
      <?php foreach ($all_clients as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $filter_cid===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label small fw-semibold mb-1">Konsultant</label>
    <select name="consultant" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">Wszyscy</option>
      <?php foreach ($all_consultants as $u): ?>
      <option value="<?= (int)$u['id'] ?>" <?= $filter_cons===(int)$u['id']?'selected':'' ?>><?= h($u['display_name']??$u['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($filter_ser): ?>
  <div class="col-auto">
    <label class="form-label small fw-semibold mb-1">Seria</label>
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
        <i class="bi bi-arrow-repeat me-1"></i>filtr serii aktywny
      </span>
      <a href="?" class="btn btn-sm btn-outline-secondary py-0 px-2">✕</a>
      <input type="hidden" name="series" value="<?= h($filter_ser) ?>">
    </div>
  </div>
  <?php endif; ?>
  <?php if ($filter_cid || $filter_cons): ?>
  <div class="col-auto"><a href="?" class="btn btn-sm btn-link text-muted">Resetuj filtry</a></div>
  <?php endif; ?>
</form>

<!-- Legenda -->
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center" style="font-size:.72rem">
  <?php foreach (K30_SCHEDULE_STATUSES as $sk => $sv): ?>
  <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .55rem;border-radius:2rem;font-weight:600;background:<?= $sv['bg'] ?>;color:<?= $sv['color'] ?>">
    <i class="bi <?= $sv['icon'] ?>"></i><?= $sv['label'] ?>
  </span>
  <?php endforeach; ?>
  <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .55rem;border-radius:2rem;font-weight:600;background:#eff6ff;color:#2563eb">
    <i class="bi bi-arrow-repeat"></i>Cykl
  </span>
</div>

<div class="card shadow-sm">
  <div class="card-body p-2">
    <!-- Nawigacja -->
    <div class="d-flex align-items-center justify-content-between mb-2 px-2">
      <button id="cal-prev" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i></button>
      <div class="d-flex align-items-center gap-2">
        <button id="btn-today" class="btn btn-sm btn-outline-secondary">Dziś</button>
        <span id="cal-title" class="fw-bold fs-6"></span>
      </div>
      <button id="cal-next" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-right"></i></button>
    </div>
    <!-- Tryby widoku -->
    <div class="d-flex gap-1 mb-3 px-2">
      <button id="btn-month" class="btn btn-sm btn-primary">Miesiąc</button>
      <button id="btn-week"  class="btn btn-sm btn-outline-secondary">Tydzień</button>
      <button id="btn-day"   class="btn btn-sm btn-outline-secondary">Dzień</button>
    </div>
    <div id="calendar" style="min-height:500px"></div>
  </div>
</div>

<style>
.cal-grid { display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:#E5E7EB }
.cal-header { background:#F9FAFB;text-align:center;padding:.4rem;font-size:.75rem;font-weight:600;color:#6B7280 }
.cal-day { background:#fff;min-height:90px;padding:.3rem;cursor:pointer }
.cal-day:hover { background:#F0F9FF }
.cal-day.other-month { background:#FAFAFA }
.cal-day.other-month .cal-day-num { color:#D1D5DB }
.cal-day.today { background:#EEF4FF }
.cal-day-num { font-size:.78rem;font-weight:600;margin-bottom:.2rem }
.cal-event { display:block;padding:.12rem .4rem;border-radius:4px;font-size:.72rem;font-weight:500;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-decoration:none;color:#fff }
.cal-event.is-series::before { content:'↻ '; font-size:.7rem }
.cal-week-grid { display:grid;grid-template-columns:52px repeat(7,1fr);gap:0;border:1px solid #E5E7EB;border-radius:6px;overflow:hidden }
.cal-week-header { background:#F9FAFB;text-align:center;padding:.4rem .2rem;font-size:.73rem;font-weight:600;border-bottom:2px solid #E5E7EB }
.cal-week-header.today-col { background:#EEF4FF;color:#2563EB }
.cal-week-slot { border-bottom:1px solid #F3F4F6;border-left:1px solid #E5E7EB;min-height:28px;padding:1px;vertical-align:top }
.cal-week-slot.hour30 { border-bottom:1px dashed #F3F4F6 }
.cal-week-time { font-size:.68rem;color:#9CA3AF;padding:.1rem .3rem;border-bottom:1px solid #F3F4F6;text-align:right;background:#FAFAFA }
.cal-day-grid { display:grid;grid-template-columns:52px 1fr;gap:0;border:1px solid #E5E7EB;border-radius:6px;overflow:hidden }
.cal-day-slot { border-bottom:1px solid #F3F4F6;min-height:32px;padding:2px 4px }
.cal-day-slot.hour30 { border-bottom:1px dashed #F3F4F6 }
</style>

<script>
const API_URL = '<?= APP_URL ?>/karty30/schedules/calendar.php?api=1';
// Filtry z URL PHP
const FILTER_PARAMS = '<?= http_build_query(array_filter([
    'client'     => $filter_cid  ?: null,
    'consultant' => $filter_cons ?: null,
    'series'     => $filter_ser  ?: null,
])) ?>';

let currentDate = new Date();
let viewMode = 'month';
let events = [];

async function loadEvents(from, to) {
    const url = API_URL + '&from=' + from + '&to=' + to + (FILTER_PARAMS ? '&' + FILTER_PARAMS : '');
    const res = await fetch(url);
    events = await res.json();
}

function eventEl(e) {
    const cls = 'cal-event' + (e.is_series ? ' is-series' : '');
    return `<a href="${e.url}" class="${cls}" style="background:${e.color}" title="${e.title}">${e.title}</a>`;
}

function pad(n) { return String(n).padStart(2,'0'); }
function fmtDate(d) { return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`; }

function setViewBtns(active) {
    ['month','week','day'].forEach(v => {
        document.getElementById('btn-'+v).className =
            'btn btn-sm ' + (v===active ? 'btn-primary' : 'btn-outline-secondary');
    });
}

async function render() {
    if (viewMode === 'month')     await renderMonth();
    else if (viewMode === 'week') await renderWeek();
    else                          await renderDay();
}

async function renderMonth() {
    const y = currentDate.getFullYear();
    const m = currentDate.getMonth();
    const first = new Date(y, m, 1);
    const last  = new Date(y, m+1, 0);
    const from  = fmtDate(new Date(y, m, 1 - first.getDay()));
    const to    = fmtDate(new Date(y, m+1, 7 - last.getDay()));

    document.getElementById('cal-title').textContent =
        first.toLocaleDateString('pl-PL', {month:'long', year:'numeric'});

    await loadEvents(fmtDate(first), fmtDate(last));

    const days = ['Nd','Pn','Wt','Śr','Cz','Pt','Sb'];
    let html = '<div class="cal-grid">';
    days.forEach(d => html += `<div class="cal-header">${d}</div>`);

    const startDay = new Date(y, m, 1 - first.getDay());
    const today = fmtDate(new Date());
    for (let i=0; i<42; i++) {
        const d = new Date(startDay);
        d.setDate(startDay.getDate() + i);
        const ds = fmtDate(d);
        const isOther = d.getMonth() !== m;
        const isToday = ds === today;
        html += `<div class="cal-day ${isOther?'other-month':''} ${isToday?'today':''}" onclick="if(!event.target.closest('a')){window.location='?day=${ds}'}">`;
        html += `<div class="cal-day-num">${d.getDate()}</div>`;
        events.filter(e => e.start.startsWith(ds)).forEach(e => {
            html += eventEl(e);
        });
        html += '</div>';
    }
    html += '</div>';
    document.getElementById('calendar').innerHTML = html;
}

async function renderWeek() {
    const d = new Date(currentDate);
    d.setDate(d.getDate() - d.getDay());
    const weekStart = new Date(d);
    const weekEnd   = new Date(d);
    weekEnd.setDate(weekEnd.getDate() + 6);

    document.getElementById('cal-title').textContent =
        weekStart.toLocaleDateString('pl-PL', {day:'numeric',month:'long'}) + ' – ' +
        weekEnd.toLocaleDateString('pl-PL', {day:'numeric',month:'long',year:'numeric'});

    await loadEvents(fmtDate(weekStart), fmtDate(weekEnd));

    const today = fmtDate(new Date());
    let html = '<div class="cal-week-grid">';
    html += '<div class="cal-week-header"></div>';
    for (let i=0; i<7; i++) {
        const day = new Date(weekStart);
        day.setDate(day.getDate()+i);
        const ds = fmtDate(day);
        const isT = ds===today;
        html += `<div class="cal-week-header ${isT?'today-col':''}">${day.toLocaleDateString('pl-PL',{weekday:'short',day:'numeric',month:'numeric'})}</div>`;
    }
    for (let h=7; h<22; h++) {
        html += `<div class="cal-week-time">${pad(h)}:00</div>`;
        for (let i=0; i<7; i++) {
            const day = new Date(weekStart);
            day.setDate(day.getDate()+i);
            const ds = fmtDate(day);
            const hourStr = `${ds}T${pad(h)}:`;
            const slotEvents = events.filter(e => e.start.startsWith(hourStr));
            html += `<div class="cal-week-slot">`;
            slotEvents.forEach(e => { html += eventEl(e); });
            html += '</div>';
        }
        // :30 row
        html += `<div class="cal-week-time" style="color:#E5E7EB;font-size:.6rem">:30</div>`;
        for (let i=0; i<7; i++) {
            const day=new Date(weekStart); day.setDate(day.getDate()+i);
            const ds=fmtDate(day);
            const halfStr=`${ds}T${pad(h)}:3`;
            const half=events.filter(e=>e.start.startsWith(halfStr));
            html+=`<div class="cal-week-slot hour30">`;
            half.forEach(e=>{html+=eventEl(e);});
            html+=`</div>`;
        }
    }
    html += '</div>';
    document.getElementById('calendar').innerHTML = html;
}

async function renderDay() {
    const ds    = fmtDate(currentDate);
    document.getElementById('cal-title').textContent =
        currentDate.toLocaleDateString('pl-PL', {weekday:'long', day:'numeric', month:'long', year:'numeric'});
    await loadEvents(ds, ds);
    const today = fmtDate(new Date());
    let html = '<div class="cal-day-grid">';
    for (let h=7; h<22; h++) {
        for (let half=0; half<2; half++) {
            const tStr = `${ds}T${pad(h)}:${half?'3':'0'}`;
            const slotE = events.filter(e => e.start.startsWith(tStr));
            html += `<div class="cal-week-time" style="font-size:.7rem">${half?'':pad(h)+':00'}</div>`;
            html += `<div class="cal-day-slot${half?' hour30':''}">`;
            slotE.forEach(e => { html += eventEl(e); });
            html += '</div>';
        }
    }
    if (!events.length) {
        html += '<div class="cal-week-time"></div><div class="cal-day-slot text-muted p-2">Brak wizyt tego dnia.</div>';
    }
    html += '</div>';
    document.getElementById('calendar').innerHTML = html;
}

document.getElementById('cal-prev').addEventListener('click', () => {
    if      (viewMode === 'month') currentDate.setMonth(currentDate.getMonth()-1);
    else if (viewMode === 'week')  currentDate.setDate(currentDate.getDate()-7);
    else                           currentDate.setDate(currentDate.getDate()-1);
    render();
});
document.getElementById('cal-next').addEventListener('click', () => {
    if      (viewMode === 'month') currentDate.setMonth(currentDate.getMonth()+1);
    else if (viewMode === 'week')  currentDate.setDate(currentDate.getDate()+7);
    else                           currentDate.setDate(currentDate.getDate()+1);
    render();
});
document.getElementById('btn-today').addEventListener('click', () => {
    currentDate = new Date(); render();
});
document.getElementById('btn-month').addEventListener('click', () => {
    viewMode = 'month'; setViewBtns('month'); render();
});
document.getElementById('btn-week').addEventListener('click', () => {
    viewMode = 'week'; setViewBtns('week'); render();
});
document.getElementById('btn-day').addEventListener('click', () => {
    viewMode = 'day'; setViewBtns('day'); render();
});

// Otwarcie konkretnego dnia z GET ?day=YYYY-MM-DD
const urlDay = new URLSearchParams(window.location.search).get('day');
if (urlDay) { currentDate = new Date(urlDay + 'T12:00:00'); viewMode = 'day'; setViewBtns('day'); }

render();
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
