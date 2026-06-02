<?php
/**
 * crm/calendar.php — Kalendarz CRM z widokiem miesięcznym i listą.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$PAGE_TITLE  = 'Kalendarz CRM';
$can_write   = can_write('crm') || is_admin();

// Kontakty do autouzupełniania w modalu
$contacts = db_all(
    "SELECT id, imie_nazwisko, type FROM crm_contacts WHERE crm_active=1 ORDER BY imie_nazwisko LIMIT 300"
);

include __DIR__ . '/includes/header_crm.php';
?>

<style>
/* ═══════════════════════════════════════════════════════════════════
   Kalendarz CRM — samodzielny system bez zewnętrznych bibliotek JS
   ═══════════════════════════════════════════════════════════════════ */

/* Layout */
.cal-shell { display:flex; gap:1.25rem; align-items:flex-start; }
.cal-main  { flex:1; min-width:0; }
.cal-side  { width:260px; flex-shrink:0; }

@media(max-width:900px) {
  .cal-shell { flex-direction:column; }
  .cal-side  { width:100%; }
}

/* Topbar kalendarza */
.cal-topbar {
  display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem;
  background:#fff; border:1px solid #E5E7EB; border-radius:10px;
  padding:.7rem 1rem; margin-bottom:.85rem;
  box-shadow:0 1px 3px rgba(0,0,0,.04);
}
.cal-title { font-size:1.1rem; font-weight:700; color:#111827; letter-spacing:.01em; }
.cal-nav-btn {
  background:#F3F4F6; border:none; border-radius:7px;
  width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center;
  cursor:pointer; font-size:1rem; color:#374151; transition:background .12s;
}
.cal-nav-btn:hover { background:#E5E7EB; }
.cal-view-btn {
  padding:.3rem .75rem; border-radius:7px; border:1.5px solid #E5E7EB;
  background:#fff; font-size:.78rem; font-weight:500; color:#374151;
  cursor:pointer; transition:all .12s;
}
.cal-view-btn.active { background:#EFF7ED; border-color:#2E844A; color:#2E844A; font-weight:600; }

/* Grid miesiąca */
.cal-grid { background:#fff; border:1px solid #E5E7EB; border-radius:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.cal-weekdays {
  display:grid; grid-template-columns:repeat(7,1fr);
  background:#F9FAFB; border-bottom:2px solid #E5E7EB;
}
.cal-weekday {
  text-align:center; padding:.45rem .25rem;
  font-size:.69rem; font-weight:700; letter-spacing:.06em;
  text-transform:uppercase; color:#9CA3AF;
}
.cal-days { display:grid; grid-template-columns:repeat(7,1fr); }
.cal-day {
  min-height:90px; border-right:1px solid #F3F4F6; border-bottom:1px solid #F3F4F6;
  padding:.35rem .3rem; position:relative; cursor:pointer; transition:background .1s;
}
.cal-day:nth-child(7n) { border-right:none; }
.cal-day:hover { background:#FAFAFA; }
.cal-day.other-month { background:#FAFAFA; }
.cal-day.other-month .cal-day-num { color:#D1D5DB; }
.cal-day.today { background:#EFF7ED; }
.cal-day.today .cal-day-num {
  background:#2E844A; color:#fff; border-radius:50%;
  width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center;
}
.cal-day.selected { outline:2px solid #2E844A; outline-offset:-2px; }
.cal-day-num { font-size:.8rem; font-weight:600; color:#374151; margin-bottom:.2rem; display:block; }
.cal-day-num.weekend { color:#DC2626; }

/* Zdarzenia w komórkach */
.cal-event {
  display:block; font-size:.68rem; font-weight:500; line-height:1.2;
  padding:.1rem .35rem; border-radius:4px; margin-bottom:2px;
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  cursor:pointer; transition:filter .1s;
  text-decoration:none;
}
.cal-event:hover { filter:brightness(.92); }
.cal-more { font-size:.65rem; color:#9CA3AF; cursor:pointer; padding:.05rem .3rem; }
.cal-more:hover { color:#374151; }

/* Widok listy */
.cal-list-date {
  display:flex; align-items:center; gap:.75rem;
  padding:.65rem 0; border-bottom:2px solid #E5E7EB; margin-bottom:.5rem; margin-top:1rem;
}
.cal-list-date:first-of-type { margin-top:0; }
.cal-list-date-box {
  width:40px; text-align:center; flex-shrink:0;
}
.cal-list-date-num { font-size:1.4rem; font-weight:800; color:#111827; line-height:1; }
.cal-list-date-day { font-size:.67rem; text-transform:uppercase; letter-spacing:.05em; color:#9CA3AF; font-weight:600; }
.cal-list-event {
  display:flex; align-items:center; gap:.65rem;
  padding:.5rem .75rem; border-radius:8px; margin-bottom:.3rem;
  background:#fff; border:1px solid #F3F4F6; cursor:pointer;
  transition:box-shadow .1s, border-color .1s;
}
.cal-list-event:hover { box-shadow:0 2px 8px rgba(0,0,0,.08); border-color:#E5E7EB; }
.cal-list-event.done { opacity:.55; }
.cal-event-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.cal-event-title { font-size:.84rem; font-weight:600; color:#111827; }
.cal-event-title.done { text-decoration:line-through; }
.cal-event-sub { font-size:.73rem; color:#9CA3AF; margin-top:1px; }

/* Sidebar */
.cal-side-card { background:#fff; border:1px solid #E5E7EB; border-radius:10px; padding:1rem; box-shadow:0 1px 3px rgba(0,0,0,.04); margin-bottom:.85rem; }
.cal-side-title { font-size:.7rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:#9CA3AF; margin-bottom:.65rem; }
.cal-mini { width:100%; border-collapse:collapse; }
.cal-mini th { font-size:.64rem; font-weight:700; text-align:center; color:#9CA3AF; padding:.15rem 0; }
.cal-mini td { font-size:.75rem; text-align:center; padding:.2rem .1rem; cursor:pointer; border-radius:4px; transition:background .1s; }
.cal-mini td:hover { background:#F3F4F6; }
.cal-mini td.today { background:#2E844A; color:#fff; border-radius:50%; font-weight:700; }
.cal-mini td.other-month { color:#D1D5DB; }
.cal-mini td.has-events { font-weight:700; color:#0176D3; }
.cal-mini td.selected { outline:2px solid #2E844A; border-radius:50%; }

.legend-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:.4rem; flex-shrink:0; }
.upcoming-item { display:flex; align-items:flex-start; gap:.5rem; padding:.45rem 0; border-bottom:1px solid #F9FAFB; font-size:.8rem; }
.upcoming-item:last-child { border-bottom:none; }
.upcoming-date { font-size:.7rem; color:#9CA3AF; white-space:nowrap; padding-top:.1rem; }

/* Modal */
.cal-modal-overlay {
  position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:2000;
  display:flex; align-items:center; justify-content:center;
  opacity:0; visibility:hidden; transition:all .18s;
}
.cal-modal-overlay.open { opacity:1; visibility:visible; }
.cal-modal {
  background:#fff; border-radius:14px; width:100%; max-width:480px;
  max-height:90vh; overflow-y:auto;
  box-shadow:0 20px 60px rgba(0,0,0,.2);
  transform:translateY(10px) scale(.98);
  transition:transform .18s;
}
.cal-modal-overlay.open .cal-modal { transform:none; }
.cal-modal-header {
  display:flex; align-items:center; justify-content:space-between;
  padding:1rem 1.25rem .75rem; border-bottom:1px solid #F3F4F6;
}
.cal-modal-title { font-size:1rem; font-weight:700; color:#111827; }
.cal-modal-body  { padding:1rem 1.25rem; }
.cal-modal-foot  { padding:.75rem 1.25rem; border-top:1px solid #F3F4F6; display:flex; gap:.5rem; justify-content:flex-end; }

/* Type pills */
.type-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:.35rem; }
.type-pill {
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:.2rem; padding:.4rem .2rem; border-radius:8px;
  border:1.5px solid #E5E7EB; cursor:pointer; transition:all .12s;
  font-size:.68rem; font-weight:600; color:#6B7280;
}
.type-pill:hover  { border-color:#9CA3AF; color:#374151; }
.type-pill.active { border-color:var(--t-color); background:var(--t-bg); color:var(--t-color); }
.type-pill i      { font-size:1.1rem; }
</style>

<!-- Page header -->
<div class="crm-page-header mb-3">
  <div>
    <div class="crm-page-title"><i class="bi bi-calendar3-fill" style="color:#0176D3"></i> Kalendarz</div>
    <div class="crm-page-subtitle">Zdarzenia, zadania i historia kontaktów CRM</div>
  </div>
  <?php if ($can_write): ?>
  <div class="crm-page-actions">
    <button class="btn btn-crm-primary btn-sm" onclick="Cal.openNewModal()">
      <i class="bi bi-plus-lg me-1"></i>Nowe zdarzenie
    </button>
  </div>
  <?php endif; ?>
</div>

<div class="cal-shell">

  <!-- ══ MAIN: topbar + siatka/lista ═══════════════════════════════════════ -->
  <div class="cal-main">

    <!-- Topbar nawigacji -->
    <div class="cal-topbar">
      <div class="d-flex align-items-center gap-2">
        <button class="cal-nav-btn" onclick="Cal.prev()" title="Poprzedni miesiąc/tydzień">
          <i class="bi bi-chevron-left"></i>
        </button>
        <button class="cal-nav-btn" onclick="Cal.today()" title="Dziś" style="width:auto;padding:0 .6rem;font-size:.78rem;font-weight:600">
          Dziś
        </button>
        <button class="cal-nav-btn" onclick="Cal.next()" title="Następny miesiąc/tydzień">
          <i class="bi bi-chevron-right"></i>
        </button>
        <span class="cal-title" id="calTitle"></span>
      </div>
      <div class="d-flex align-items-center gap-1">
        <button class="cal-view-btn active" id="btnMonth" onclick="Cal.setView('month')">Miesiąc</button>
        <button class="cal-view-btn"        id="btnWeek"  onclick="Cal.setView('week')">Tydzień</button>
        <button class="cal-view-btn"        id="btnList"  onclick="Cal.setView('list')">Lista</button>
      </div>
    </div>

    <!-- Siatka / lista — wypełniana przez JS -->
    <div id="calGrid"></div>

  </div><!-- /main -->

  <!-- ══ SIDEBAR ══════════════════════════════════════════════════════════ -->
  <div class="cal-side">

    <!-- Mini kalendarz -->
    <div class="cal-side-card">
      <div id="miniCalHeader" class="d-flex align-items-center justify-content-between mb-2">
        <button class="cal-nav-btn" onclick="Cal.prev()" style="width:24px;height:24px;font-size:.75rem"><i class="bi bi-chevron-left"></i></button>
        <span id="miniCalTitle" style="font-size:.82rem;font-weight:700;color:#374151"></span>
        <button class="cal-nav-btn" onclick="Cal.next()" style="width:24px;height:24px;font-size:.75rem"><i class="bi bi-chevron-right"></i></button>
      </div>
      <table class="cal-mini"><thead id="miniCalHead"></thead><tbody id="miniCalBody"></tbody></table>
    </div>

    <!-- Legenda -->
    <div class="cal-side-card">
      <div class="cal-side-title">Typy zdarzeń</div>
      <?php
      $legend = [
        ['task',     '#2E844A', 'bi-check-circle-fill', 'Zadanie'],
        ['meeting',  '#0176D3', 'bi-people-fill',        'Spotkanie'],
        ['deadline', '#DC2626', 'bi-flag-fill',           'Termin'],
        ['reminder', '#D97706', 'bi-bell-fill',           'Przypomnienie'],
        ['call',     '#7C3AED', 'bi-telephone-fill',      'Rozmowa'],
        ['case',     '#6B7280', 'bi-briefcase-fill',      'Sprawa (auto)'],
        ['comm',     '#9CA3AF', 'bi-chat-dots-fill',      'Komunikacja (auto)'],
      ];
      foreach ($legend as [$type,$color,$icon,$label]):
      ?>
      <div class="d-flex align-items-center gap-2 mb-1" style="font-size:.78rem">
        <span class="legend-dot" style="background:<?= $color ?>"></span>
        <i class="bi <?= $icon ?>" style="color:<?= $color ?>;font-size:.75rem;width:14px;text-align:center"></i>
        <span style="color:#374151"><?= $label ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Nadchodzące zdarzenia (7 dni) -->
    <div class="cal-side-card">
      <div class="cal-side-title">Nadchodzące (7 dni)</div>
      <div id="upcomingList" style="min-height:40px">
        <div class="text-muted" style="font-size:.8rem">Ładowanie…</div>
      </div>
    </div>

  </div><!-- /sidebar -->

</div><!-- /shell -->

<!-- ══ MODAL: nowe/edytuj zdarzenie ════════════════════════════════════════ -->
<div class="cal-modal-overlay" id="calModal" onclick="if(event.target===this)Cal.closeModal()">
  <div class="cal-modal" role="dialog" aria-modal="true" aria-labelledby="calModalTitle">
    <div class="cal-modal-header">
      <span class="cal-modal-title" id="calModalTitle">Nowe zdarzenie</span>
      <button type="button" onclick="Cal.closeModal()" class="btn-close" aria-label="Zamknij"></button>
    </div>
    <div class="cal-modal-body">

      <!-- Typ zdarzenia -->
      <div class="mb-3">
        <label class="form-label fw-semibold small mb-2">Typ zdarzenia</label>
        <div class="type-grid" id="typePills">
          <?php
          $types = [
            ['task',     '#2E844A','#EFF7ED', 'bi-check-circle', 'Zadanie'],
            ['meeting',  '#0176D3','#EEF4FF', 'bi-people',       'Spotkanie'],
            ['deadline', '#DC2626','#FEF2F2', 'bi-flag',         'Termin'],
            ['reminder', '#D97706','#FEF3E2', 'bi-bell',         'Przyp.'],
            ['call',     '#7C3AED','#F5F3FF', 'bi-telephone',    'Rozmowa'],
          ];
          foreach ($types as [$tv,$tc,$tbg,$ti,$tl]):
          ?>
          <button type="button" class="type-pill <?= $tv==='task'?'active':'' ?>"
                  data-type="<?= $tv ?>" data-color="<?= $tc ?>" data-bg="<?= $tbg ?>"
                  style="--t-color:<?= $tc ?>;--t-bg:<?= $tbg ?>"
                  onclick="Cal.selectType(this)">
            <i class="bi <?= $ti ?>"></i><?= $tl ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Tytuł -->
      <div class="mb-3">
        <label class="form-label fw-semibold small">Tytuł <span class="text-danger">*</span></label>
        <input id="evTitle" type="text" class="form-control form-control-sm"
               placeholder="Co trzeba zrobić?" autocomplete="off">
      </div>

      <!-- Data i czas -->
      <div class="row g-2 mb-3">
        <div class="col-6">
          <label class="form-label fw-semibold small">Data <span class="text-danger">*</span></label>
          <input id="evDate" type="date" class="form-control form-control-sm">
        </div>
        <div class="col-6">
          <label class="form-label fw-semibold small">Data końca</label>
          <input id="evEndDate" type="date" class="form-control form-control-sm">
        </div>
        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="evAllDay" checked
                   onchange="Cal.toggleTime()">
            <label class="form-check-label small" for="evAllDay">Cały dzień</label>
          </div>
        </div>
        <div id="evTimeRow" class="col-12" style="display:none">
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label fw-semibold small">Godzina start</label>
              <input id="evTime" type="time" class="form-control form-control-sm">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold small">Godzina koniec</label>
              <input id="evEndTime" type="time" class="form-control form-control-sm">
            </div>
          </div>
        </div>
      </div>

      <!-- Kontakt -->
      <div class="mb-3">
        <label class="form-label fw-semibold small">Kontakt <span class="text-muted fw-normal">(opcjonalny)</span></label>
        <select id="evContact" class="form-select form-select-sm">
          <option value="">— brak powiązania —</option>
          <?php foreach ($contacts as $c): ?>
          <option value="<?= (int)$c['id'] ?>">
            <?= h($c['imie_nazwisko']) ?><?= $c['type']==='organizacja' ? ' [org]' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Opis -->
      <div class="mb-1">
        <label class="form-label fw-semibold small">Opis <span class="text-muted fw-normal">(opcjonalny)</span></label>
        <textarea id="evDesc" class="form-control form-control-sm" rows="2"
                  placeholder="Szczegóły zdarzenia…"></textarea>
      </div>

      <input type="hidden" id="evId" value="">
      <input type="hidden" id="evColor" value="#2E844A">
      <div id="evError" class="alert alert-danger py-2 mt-2" style="display:none;font-size:.82rem"></div>
    </div>
    <div class="cal-modal-foot">
      <button id="evDeleteBtn" type="button" class="btn btn-outline-danger btn-sm me-auto"
              onclick="Cal.deleteEvent()" style="display:none">
        <i class="bi bi-trash me-1"></i>Usuń
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="Cal.closeModal()">Anuluj</button>
      <button id="evSaveBtn" type="button" class="btn btn-success btn-sm" onclick="Cal.saveEvent()">
        <i class="bi bi-check-lg me-1"></i>Zapisz
      </button>
    </div>
  </div>
</div>

<!-- ══ POPUP: szczegóły zdarzenia ══════════════════════════════════════════ -->
<div id="evPopup" style="
  position:fixed; z-index:1500; background:#fff; border-radius:10px;
  box-shadow:0 8px 32px rgba(0,0,0,.18); border:1px solid #E5E7EB;
  padding:0; min-width:240px; max-width:300px; display:none;
">
  <div id="evPopupBar" style="height:4px;border-radius:10px 10px 0 0;background:#2E844A"></div>
  <div style="padding:.75rem 1rem">
    <div id="evPopupTitle" style="font-weight:700;font-size:.9rem;color:#111827;margin-bottom:.25rem"></div>
    <div id="evPopupContact" style="font-size:.75rem;color:#9CA3AF;margin-bottom:.2rem"></div>
    <div id="evPopupTime" style="font-size:.75rem;color:#6B7280;margin-bottom:.5rem"></div>
    <div id="evPopupDesc" style="font-size:.8rem;color:#374151;margin-bottom:.5rem;white-space:pre-wrap"></div>
    <div class="d-flex gap-1">
      <button id="evPopupDone" class="btn btn-xs btn-outline-success btn-sm py-0 px-2" style="font-size:.72rem"
              onclick="Cal.toggleDone()"><i class="bi bi-check me-1"></i>Wykonane</button>
      <button class="btn btn-xs btn-outline-primary btn-sm py-0 px-2" style="font-size:.72rem"
              onclick="Cal.editFromPopup()"><i class="bi bi-pencil me-1"></i>Edytuj</button>
      <button class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2 ms-auto" style="font-size:.72rem"
              onclick="document.getElementById('evPopup').style.display='none'"><i class="bi bi-x"></i></button>
    </div>
  </div>
</div>

<script>
/* ═══════════════════════════════════════════════════════════════════════════
   Cal — silnik kalendarza CRM
   ═══════════════════════════════════════════════════════════════════════════ */
const Cal = (function() {
  'use strict';

  const API      = '<?= APP_URL ?>/crm/api/calendar_events.php';
  const CAN_EDIT = <?= $can_write ? 'true' : 'false' ?>;

  const PL_MONTHS  = ['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
  const PL_DAYS    = ['Pon','Wt','Śr','Czw','Pt','Sob','Nd'];
  const PL_DAYS_F  = ['Poniedziałek','Wtorek','Środa','Czwartek','Piątek','Sobota','Niedziela'];

  // Typy i style
  const TYPE_CFG = {
    task:     { label:'Zadanie',       color:'#2E844A', bg:'#EFF7ED', icon:'bi-check-circle-fill' },
    meeting:  { label:'Spotkanie',     color:'#0176D3', bg:'#EEF4FF', icon:'bi-people-fill' },
    deadline: { label:'Termin',        color:'#DC2626', bg:'#FEF2F2', icon:'bi-flag-fill' },
    reminder: { label:'Przypomnienie', color:'#D97706', bg:'#FEF3E2', icon:'bi-bell-fill' },
    call:     { label:'Rozmowa',       color:'#7C3AED', bg:'#F5F3FF', icon:'bi-telephone-fill' },
    case:     { label:'Sprawa',        color:'#6B7280', bg:'#F3F4F6', icon:'bi-briefcase-fill' },
    comm:     { label:'Komunikacja',   color:'#9CA3AF', bg:'#F9FAFB', icon:'bi-chat-dots-fill' },
  };

  // State
  let _view    = 'month';
  let _date    = new Date();      // aktualnie wyświetlany miesiąc/tydzień
  let _today   = new Date();
  let _events  = [];              // cache zdarzeń
  let _popup_event = null;        // zdarzenie w popupie
  let _selected_date = null;

  // Normalizuj datę do początku dnia
  function stripTime(d) {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
  }
  function toISO(d) {
    return d.getFullYear() + '-'
      + String(d.getMonth()+1).padStart(2,'0') + '-'
      + String(d.getDate()).padStart(2,'0');
  }
  function parseDate(s) {
    if (!s) return null;
    const [y,m,d] = s.split('-').map(Number);
    return new Date(y, m-1, d);
  }
  function formatDate(d, full) {
    if (!d) return '';
    const names = full ? ['Poniedziałek','Wtorek','Środa','Czwartek','Piątek','Sobota','Niedziela'] : ['Pon','Wt','Śr','Czw','Pt','Sob','Nd'];
    const dow = (d.getDay()+6)%7;
    return (full ? (names[dow]+', ') : '') + d.getDate() + ' ' + PL_MONTHS[d.getMonth()].substring(0,3) + ' ' + d.getFullYear();
  }
  function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Ładowanie zdarzeń ─────────────────────────────────────────────────────
  function getRangeDates() {
    let from, to;
    if (_view === 'month') {
      const first = new Date(_date.getFullYear(), _date.getMonth(), 1);
      const startDow = (first.getDay()+6)%7;
      from = new Date(first); from.setDate(from.getDate() - startDow);
      to   = new Date(from);  to.setDate(to.getDate() + 41);
    } else if (_view === 'week') {
      const dow = (_date.getDay()+6)%7;
      from = new Date(_date); from.setDate(from.getDate()-dow);
      to   = new Date(from);  to.setDate(to.getDate()+6);
    } else {
      from = new Date(_date.getFullYear(), _date.getMonth(), 1);
      to   = new Date(_date.getFullYear(), _date.getMonth()+1, 0);
    }
    return { from: toISO(from), to: toISO(to) };
  }

  function load() {
    const { from, to } = getRangeDates();
    fetch(API + '?action=list&from=' + from + '&to=' + to)
      .then(r => r.json())
      .then(res => {
        if (res.ok) {
          _events = res.data || [];
          render();
          renderUpcoming();
        }
      })
      .catch(() => {});
  }

  // Zdarzenia dla danej daty (ISO)
  function eventsFor(isoDate) {
    return _events.filter(e => e.date === isoDate ||
      (e.end_date && isoDate >= e.date && isoDate <= e.end_date));
  }

  // ── RENDER: miesiąc ───────────────────────────────────────────────────────
  function renderMonth() {
    const yr   = _date.getFullYear();
    const mo   = _date.getMonth();
    const first = new Date(yr, mo, 1);
    const last  = new Date(yr, mo+1, 0);
    const startDow = (first.getDay()+6)%7;

    document.getElementById('calTitle').textContent = PL_MONTHS[mo] + ' ' + yr;
    document.getElementById('miniCalTitle').textContent = PL_MONTHS[mo].substring(0,3) + ' ' + yr;

    // Minikal
    renderMiniCal(yr, mo);

    // Grid
    let html = `<div class="cal-grid">
      <div class="cal-weekdays">
        ${PL_DAYS.map((d,i)=>`<div class="cal-weekday" style="${i>=5?'color:#DC2626':''}">${d}</div>`).join('')}
      </div>
      <div class="cal-days">`;

    const cursor = new Date(first);
    cursor.setDate(cursor.getDate() - startDow);

    for (let w = 0; w < 6; w++) {
      for (let d = 0; d < 7; d++) {
        const iso     = toISO(cursor);
        const inMonth = cursor.getMonth() === mo;
        const isToday = iso === toISO(_today);
        const isSel   = _selected_date === iso;
        const isWE    = d >= 5;
        const dayEvs  = eventsFor(iso);

        let cls = 'cal-day';
        if (!inMonth) cls += ' other-month';
        if (isToday)  cls += ' today';
        if (isSel)    cls += ' selected';

        let numCls = 'cal-day-num' + (isWE?' weekend':'');
        let numHtml = isToday
          ? `<span class="${numCls}"><span class="today-num">${cursor.getDate()}</span></span>`
          : `<span class="${numCls}">${cursor.getDate()}</span>`;

        const MAX_SHOW = 3;
        let evsHtml = dayEvs.slice(0, MAX_SHOW).map(e => {
          const c = e.color || '#9CA3AF';
          const done = e.status==='done' ? 'opacity:.5;text-decoration:line-through;' : '';
          if (e.type !== 'event') {
            return `<a href="${e.link||'#'}" class="cal-event"
                      style="background:${c}18;color:${c};${done}"
                      onclick="event.stopPropagation()" title="${esc(e.title)}">
                      <i class="bi ${TYPE_CFG[e.event_type]?.icon||'bi-circle'}" style="font-size:.65rem"></i>
                      ${esc(e.title)}
                    </a>`;
          }
          return `<span class="cal-event" style="background:${c}18;color:${c};${done}"
                       data-id="${e.id}" onclick="event.stopPropagation();Cal.showPopup(event,'${e.id}')"
                       title="${esc(e.title)}">
                    <i class="bi ${TYPE_CFG[e.event_type]?.icon||'bi-circle'}" style="font-size:.65rem"></i>
                    ${esc(e.title)}
                  </span>`;
        }).join('');

        if (dayEvs.length > MAX_SHOW) {
          evsHtml += `<span class="cal-more">+${dayEvs.length - MAX_SHOW} więcej</span>`;
        }

        html += `<div class="${cls}" data-date="${iso}" onclick="Cal.dayClick('${iso}')">
          ${numHtml}${evsHtml}
        </div>`;
        cursor.setDate(cursor.getDate()+1);
      }
    }
    html += '</div></div>';
    document.getElementById('calGrid').innerHTML = html;
  }

  // ── RENDER: tydzień (widok listy 7-dniowej) ───────────────────────────────
  function renderWeek() {
    const dow   = (_date.getDay()+6)%7;
    const start = new Date(_date); start.setDate(start.getDate()-dow);
    const end   = new Date(start); end.setDate(end.getDate()+6);

    document.getElementById('calTitle').textContent =
      formatDate(start) + ' — ' + formatDate(end);
    document.getElementById('miniCalTitle').textContent =
      PL_MONTHS[_date.getMonth()].substring(0,3) + ' ' + _date.getFullYear();
    renderMiniCal(_date.getFullYear(), _date.getMonth());

    let html = '<div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden">';
    const cursor = new Date(start);
    for (let i = 0; i < 7; i++) {
      const iso    = toISO(cursor);
      const isToday= iso === toISO(_today);
      const isWE   = i >= 5;
      const dayEvs = eventsFor(iso);
      const dowLabel = PL_DAYS_F[(cursor.getDay()+6)%7];

      html += `<div style="border-bottom:1px solid #F3F4F6;${isToday?'background:#EFF7ED':''}">
        <div style="display:flex;align-items:flex-start;gap:.75rem;padding:.75rem 1rem">
          <div style="flex-shrink:0;width:44px;text-align:center">
            <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;color:${isWE?'#DC2626':'#9CA3AF'};font-weight:600">${dowLabel.substring(0,3).toUpperCase()}</div>
            <div style="font-size:1.4rem;font-weight:800;color:${isToday?'#2E844A':(isWE?'#DC2626':'#111827')};line-height:1">${cursor.getDate()}</div>
          </div>
          <div style="flex:1;padding-top:.1rem">`;

      if (dayEvs.length === 0) {
        html += `<div style="font-size:.78rem;color:#D1D5DB;padding:.2rem 0">Brak zdarzeń`;
        if (CAN_EDIT) html += ` — <button class="btn btn-link btn-sm p-0" style="font-size:.75rem" onclick="Cal.openNewModal('${iso}')">Dodaj</button>`;
        html += `</div>`;
      } else {
        dayEvs.forEach(e => {
          const c = e.color || '#9CA3AF';
          const done = e.status==='done';
          if (e.type !== 'event') {
            html += `<div class="cal-list-event${done?' done':''}">
              <div class="cal-event-dot" style="background:${c}"></div>
              <div>
                <div class="cal-event-title${done?' done':''}">${esc(e.title)}</div>
                <div class="cal-event-sub">${esc(e.contact_name||'')} ${TYPE_CFG[e.event_type]?.label||''}</div>
              </div>
            </div>`;
          } else {
            html += `<div class="cal-list-event${done?' done':''}" onclick="Cal.showPopup(event,'${e.id}')">
              <div class="cal-event-dot" style="background:${c}"></div>
              <div style="flex:1;min-width:0">
                <div class="cal-event-title${done?' done':''}">${esc(e.title)}</div>
                <div class="cal-event-sub">${esc(e.contact_name||'')} ${e.event_time?'· '+e.event_time:''}</div>
              </div>
              ${done?'<i class="bi bi-check-circle-fill text-success" style="font-size:.9rem"></i>':''}
            </div>`;
          }
        });
      }

      html += `</div></div></div>`;
      cursor.setDate(cursor.getDate()+1);
    }
    html += '</div>';
    document.getElementById('calGrid').innerHTML = html;
  }

  // ── RENDER: lista (cały miesiąc) ──────────────────────────────────────────
  function renderList() {
    const yr  = _date.getFullYear();
    const mo  = _date.getMonth();
    const days = new Date(yr, mo+1, 0).getDate();

    document.getElementById('calTitle').textContent = PL_MONTHS[mo] + ' ' + yr + ' — Lista';
    document.getElementById('miniCalTitle').textContent = PL_MONTHS[mo].substring(0,3) + ' ' + yr;
    renderMiniCal(yr, mo);

    let html = '<div class="card border-0 shadow-sm" style="border-radius:10px;padding:1rem">';
    let hasAny = false;

    for (let d = 1; d <= days; d++) {
      const cur    = new Date(yr, mo, d);
      const iso    = toISO(cur);
      const dayEvs = eventsFor(iso);
      if (!dayEvs.length) continue;

      hasAny = true;
      const isToday = iso === toISO(_today);
      const dow = PL_DAYS[(cur.getDay()+6)%7];

      html += `<div class="cal-list-date">
        <div class="cal-list-date-box">
          <div class="cal-list-date-num" style="${isToday?'color:#2E844A':''}">${d}</div>
          <div class="cal-list-date-day">${dow}</div>
        </div>
        <div style="flex:1;min-width:0">`;

      dayEvs.forEach(e => {
        const c    = e.color || '#9CA3AF';
        const done = e.status==='done';
        if (e.type !== 'event') {
          html += `<div class="cal-list-event${done?' done':''}">
            <div class="cal-event-dot" style="background:${c}"></div>
            <div>
              <div class="cal-event-title">${esc(e.title)}</div>
              <div class="cal-event-sub">${esc(e.contact_name||'')} · ${TYPE_CFG[e.event_type]?.label||''}</div>
            </div>
          </div>`;
        } else {
          html += `<div class="cal-list-event${done?' done':''}" onclick="Cal.showPopup(event,'${e.id}')">
            <div class="cal-event-dot" style="background:${c}"></div>
            <div style="flex:1;min-width:0">
              <div class="cal-event-title${done?' done':''}">${esc(e.title)}</div>
              <div class="cal-event-sub">${esc(e.contact_name||'')} ${e.event_time?'· '+e.event_time:''}</div>
            </div>
            ${done?'<i class="bi bi-check-circle-fill text-success" style="font-size:.9rem"></i>':''}
          </div>`;
        }
      });

      html += `</div></div>`;
    }

    if (!hasAny) {
      html += `<div class="text-center py-4">
        <i class="bi bi-calendar3 d-block mb-2 opacity-25" style="font-size:2rem;color:#9CA3AF"></i>
        <div style="font-size:.85rem;color:#9CA3AF">Brak zdarzeń w tym miesiącu</div>
      </div>`;
    }

    html += '</div>';
    document.getElementById('calGrid').innerHTML = html;
  }

  // ── Mini kalendarz ────────────────────────────────────────────────────────
  function renderMiniCal(yr, mo) {
    const first   = new Date(yr, mo, 1);
    const startDow= (first.getDay()+6)%7;
    const todayISO= toISO(_today);

    // Dni z eventami w tym miesiącu
    const datesWithEvents = new Set(_events.map(e=>e.date));

    let head = '<tr>' + PL_DAYS.map(d=>`<th>${d[0]}</th>`).join('') + '</tr>';
    let body = '';
    const cursor = new Date(first);
    cursor.setDate(cursor.getDate()-startDow);

    for (let w = 0; w < 6; w++) {
      let row = '<tr>';
      for (let d = 0; d < 7; d++) {
        const iso     = toISO(cursor);
        const inMonth = cursor.getMonth() === mo;
        const isToday = iso === todayISO;
        const hasSel  = _selected_date === iso;
        const hasEvs  = datesWithEvents.has(iso);
        let cls = '';
        if (!inMonth) cls += ' other-month';
        if (isToday)  cls += ' today';
        if (hasSel)   cls += ' selected';
        if (hasEvs && inMonth) cls += ' has-events';
        row += `<td class="${cls.trim()}" onclick="Cal.miniDayClick('${iso}')">${cursor.getDate()}</td>`;
        cursor.setDate(cursor.getDate()+1);
      }
      row += '</tr>';
      body += row;
    }

    document.getElementById('miniCalHead').innerHTML = head;
    document.getElementById('miniCalBody').innerHTML = body;
  }

  // ── Nadchodzące zdarzenia (sidebar) ───────────────────────────────────────
  function renderUpcoming() {
    const todayISO = toISO(_today);
    const endDate  = new Date(_today); endDate.setDate(endDate.getDate()+7);
    const endISO   = toISO(endDate);

    fetch(API + '?action=list&from=' + todayISO + '&to=' + endISO)
      .then(r=>r.json())
      .then(res=>{
        const el  = document.getElementById('upcomingList');
        const evs = (res.data||[])
          .filter(e=>e.date>=todayISO && e.status!=='done')
          .sort((a,b)=>a.date.localeCompare(b.date))
          .slice(0,8);

        if (!evs.length) {
          el.innerHTML = '<div style="font-size:.78rem;color:#9CA3AF">Brak nadchodzących zdarzeń</div>';
          return;
        }
        el.innerHTML = evs.map(e=>{
          const c = e.color||'#9CA3AF';
          const d = parseDate(e.date);
          const isToday = e.date === todayISO;
          return `<div class="upcoming-item">
            <span class="legend-dot" style="background:${c};margin-top:.2rem"></span>
            <div style="flex:1;min-width:0">
              <div style="font-size:.8rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#111827">${esc(e.title)}</div>
              ${e.contact_name?`<div style="font-size:.72rem;color:#9CA3AF">${esc(e.contact_name)}</div>`:''}
            </div>
            <div class="upcoming-date">${isToday?'<span style="color:#2E844A;font-weight:600">Dziś</span>':d.getDate()+' '+PL_MONTHS[d.getMonth()].substring(0,3)}</div>
          </div>`;
        }).join('');
      })
      .catch(()=>{});
  }

  // ── Render dispatcher ─────────────────────────────────────────────────────
  function render() {
    ['btnMonth','btnWeek','btnList'].forEach(id=>{
      document.getElementById(id)?.classList.remove('active');
    });
    const map = {month:'btnMonth',week:'btnWeek',list:'btnList'};
    document.getElementById(map[_view])?.classList.add('active');

    if (_view==='month')      renderMonth();
    else if (_view==='week')  renderWeek();
    else                      renderList();
  }

  // ── Nawigacja ─────────────────────────────────────────────────────────────
  function prev() {
    if (_view==='month')     _date = new Date(_date.getFullYear(), _date.getMonth()-1, 1);
    else if (_view==='week') { _date = new Date(_date); _date.setDate(_date.getDate()-7); }
    else                     _date = new Date(_date.getFullYear(), _date.getMonth()-1, 1);
    load();
  }
  function next() {
    if (_view==='month')     _date = new Date(_date.getFullYear(), _date.getMonth()+1, 1);
    else if (_view==='week') { _date = new Date(_date); _date.setDate(_date.getDate()+7); }
    else                     _date = new Date(_date.getFullYear(), _date.getMonth()+1, 1);
    load();
  }
  function today() { _date = new Date(_today); load(); }
  function setView(v) { _view = v; load(); }

  // ── Klik w dzień ─────────────────────────────────────────────────────────
  function dayClick(iso) {
    _selected_date = iso;
    if (CAN_EDIT) openNewModal(iso);
    else render();
  }
  function miniDayClick(iso) {
    _selected_date = iso;
    _date = parseDate(iso);
    _view = 'month';
    load();
  }

  // ── Popup szczegółów ──────────────────────────────────────────────────────
  function showPopup(evt, eid) {
    evt.stopPropagation();
    const e = _events.find(x=>x.id===eid);
    if (!e) return;
    _popup_event = e;
    const c = e.color||'#9CA3AF';

    document.getElementById('evPopupBar').style.background = c;
    document.getElementById('evPopupTitle').textContent = e.title;
    document.getElementById('evPopupContact').textContent = e.contact_name||'';
    document.getElementById('evPopupContact').style.display = e.contact_name ? '' : 'none';

    let timeStr = formatDate(parseDate(e.date));
    if (!e.all_day && e.time) timeStr += ', ' + e.time;
    document.getElementById('evPopupTime').textContent = timeStr;
    document.getElementById('evPopupDesc').textContent = e.description || '';
    document.getElementById('evPopupDesc').style.display = e.description ? '' : 'none';

    const doneBtn = document.getElementById('evPopupDone');
    doneBtn.innerHTML = e.status==='done'
      ? '<i class="bi bi-x-circle me-1"></i>Cofnij'
      : '<i class="bi bi-check me-1"></i>Wykonane';

    const popup = document.getElementById('evPopup');
    popup.style.display = 'block';
    // Pozycjonowanie
    const x = Math.min(evt.clientX, window.innerWidth - 310);
    const y = Math.min(evt.clientY + 8, window.innerHeight - 200);
    popup.style.left  = x + 'px';
    popup.style.top   = (y + window.scrollY) + 'px';

    // Ukryj popup po kliknięciu poza nim
    setTimeout(()=>{
      document.addEventListener('click', hidePopup, { once: true });
    }, 50);
  }
  function hidePopup(e) {
    const p = document.getElementById('evPopup');
    if (!p.contains(e.target)) p.style.display = 'none';
  }

  function toggleDone() {
    if (!_popup_event) return;
    fetch(API, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ action:'done', id: _popup_event.raw_id })
    })
    .then(r=>r.json())
    .then(res=>{
      if (res.ok) {
        const idx = _events.findIndex(x=>x.id===_popup_event.id);
        if (idx>=0) { _events[idx].status = res.data.status; _popup_event = _events[idx]; }
        document.getElementById('evPopup').style.display='none';
        render();
        renderUpcoming();
      }
    });
  }

  function editFromPopup() {
    document.getElementById('evPopup').style.display='none';
    if (_popup_event) openEditModal(_popup_event);
  }

  // ── Modal: nowe zdarzenie ─────────────────────────────────────────────────
  function openNewModal(iso) {
    clearModal();
    document.getElementById('calModalTitle').textContent = 'Nowe zdarzenie';
    document.getElementById('evDeleteBtn').style.display = 'none';
    if (iso) document.getElementById('evDate').value = iso;
    else document.getElementById('evDate').value = toISO(_today);
    document.getElementById('calModal').classList.add('open');
    setTimeout(()=>document.getElementById('evTitle').focus(), 80);
  }
  function openEditModal(e) {
    clearModal();
    document.getElementById('calModalTitle').textContent = 'Edytuj zdarzenie';
    document.getElementById('evDeleteBtn').style.display = '';
    document.getElementById('evId').value    = e.raw_id;
    document.getElementById('evTitle').value = e.title;
    document.getElementById('evDate').value  = e.date;
    document.getElementById('evEndDate').value = e.end_date||'';
    document.getElementById('evAllDay').checked = !!e.all_day;
    document.getElementById('evDesc').value  = e.description||'';
    document.getElementById('evContact').value = e.contact_id||'';
    document.getElementById('evColor').value = e.color||'#2E844A';
    if (!e.all_day) {
      document.getElementById('evTimeRow').style.display='';
      document.getElementById('evTime').value = e.time||'';
      document.getElementById('evEndTime').value = e.end_time||'';
    }
    // Typ
    selectTypeByVal(e.event_type);
    document.getElementById('calModal').classList.add('open');
  }
  function closeModal() { document.getElementById('calModal').classList.remove('open'); }

  function clearModal() {
    ['evId','evTitle','evDesc','evEndDate'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
    document.getElementById('evDate').value    = toISO(_today);
    document.getElementById('evAllDay').checked= true;
    document.getElementById('evTimeRow').style.display='none';
    document.getElementById('evTime').value    = '';
    document.getElementById('evEndTime').value = '';
    document.getElementById('evContact').value = '';
    document.getElementById('evColor').value   = '#2E844A';
    document.getElementById('evError').style.display='none';
    selectTypeByVal('task');
  }

  function selectType(btn) {
    document.querySelectorAll('.type-pill').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('evColor').value = btn.dataset.color;
  }
  function selectTypeByVal(v) {
    const btn = document.querySelector(`.type-pill[data-type="${v}"]`);
    if (btn) selectType(btn);
  }

  function toggleTime() {
    const allDay = document.getElementById('evAllDay').checked;
    document.getElementById('evTimeRow').style.display = allDay ? 'none' : '';
  }

  function saveEvent() {
    const id    = document.getElementById('evId').value;
    const title = document.getElementById('evTitle').value.trim();
    const date  = document.getElementById('evDate').value;
    const allDay= document.getElementById('evAllDay').checked;
    const etype = document.querySelector('.type-pill.active')?.dataset.type || 'task';

    if (!title || !date) {
      const er = document.getElementById('evError');
      er.textContent = 'Wypełnij tytuł i datę.';
      er.style.display='';
      return;
    }
    document.getElementById('evError').style.display='none';

    const payload = {
      action:      id ? 'update' : 'create',
      id:          id ? parseInt(id) : undefined,
      title, date, event_type: etype, all_day: allDay,
      description: document.getElementById('evDesc').value.trim()||null,
      event_end_date: document.getElementById('evEndDate').value||null,
      event_time:  !allDay ? (document.getElementById('evTime').value||null) : null,
      event_end_time: !allDay ? (document.getElementById('evEndTime').value||null) : null,
      contact_id:  document.getElementById('evContact').value ? parseInt(document.getElementById('evContact').value) : null,
      color:       document.getElementById('evColor').value,
    };

    const btn = document.getElementById('evSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    fetch(API, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    })
    .then(r=>r.json())
    .then(res=>{
      btn.disabled=false;
      btn.innerHTML='<i class="bi bi-check-lg me-1"></i>Zapisz';
      if (res.ok) { closeModal(); load(); renderUpcoming(); }
      else {
        const er = document.getElementById('evError');
        er.textContent = res.error||'Błąd zapisu.';
        er.style.display='';
      }
    })
    .catch(()=>{
      btn.disabled=false;
      btn.innerHTML='<i class="bi bi-check-lg me-1"></i>Zapisz';
    });
  }

  function deleteEvent() {
    const id = document.getElementById('evId').value;
    if (!id || !confirm('Usunąć to zdarzenie?')) return;
    fetch(API, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ action:'delete', id: parseInt(id) })
    })
    .then(r=>r.json())
    .then(res=>{ if(res.ok){ closeModal(); load(); renderUpcoming(); } });
  }

  // ── Keyboard shortcuts ────────────────────────────────────────────────────
  document.addEventListener('keydown', e=>{
    if (e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA'||e.target.tagName==='SELECT') return;
    if (e.key==='ArrowLeft')  { prev(); return; }
    if (e.key==='ArrowRight') { next(); return; }
    if (e.key==='t'||e.key==='T') { today(); return; }
    if (e.key==='m'||e.key==='M') { setView('month'); return; }
    if (e.key==='w'||e.key==='W') { setView('week'); return; }
    if (e.key==='l'||e.key==='L') { setView('list'); return; }
    if (e.key==='Escape') {
      document.getElementById('calModal').classList.remove('open');
      document.getElementById('evPopup').style.display='none';
    }
    if ((e.key==='n'||e.key==='N') && CAN_EDIT) { openNewModal(); return; }
  });

  // Inicjalizacja
  load();
  renderUpcoming();

  // Publiczne API
  return { prev, next, today, setView, dayClick, miniDayClick, showPopup,
           toggleDone, editFromPopup, openNewModal, closeModal,
           saveEvent, deleteEvent, selectType, toggleTime };

})();
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
