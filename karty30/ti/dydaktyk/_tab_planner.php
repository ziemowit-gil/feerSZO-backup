<?php
/**
 * _tab_planner.php — Tab SZO Planner w panelu dydaktyka.
 *
 * Zmienne dostępne z index.php: $uid, $cur_course, dyd_token()
 */

ti_planner_migrate();
$pl_schedules = szo_schedules_list($uid);
$pl_blocks    = szo_blocks_list($uid);

$sel_sid      = (int)($_GET['sid'] ?? ($pl_schedules[0]['id'] ?? 0));
$sel_schedule = $sel_sid ? szo_schedule_get($sel_sid, $uid) : null;

// JSON dla JS
$js_blocks   = json_encode(array_values($pl_blocks), JSON_UNESCAPED_UNICODE);
$js_days     = json_encode($sel_schedule['days'] ?? [], JSON_UNESCAPED_UNICODE);
$js_sid      = $sel_sid;
$js_token    = h(dyd_token());
$ajax_url    = h(rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/planner_ajax.php');
?>
<style>
/* ── SZO Planner ─────────────────────────────────────── */
.szo-layout{display:grid;grid-template-columns:220px 1fr;gap:0;border:1px solid var(--bs-border-color);border-radius:8px;overflow:hidden;background:var(--bs-body-bg)}
.szo-lib{border-right:1px solid var(--bs-border-color);padding:10px;overflow-y:auto;max-height:560px;display:flex;flex-direction:column;gap:5px;background:var(--bs-tertiary-bg)}
.szo-lib-header{font-size:.72rem;font-weight:700;letter-spacing:.10em;text-transform:uppercase;color:var(--bs-secondary-color);margin-bottom:4px;padding:0 2px}
.szo-days{display:grid;grid-template-columns:repeat(var(--szo-ncols,3),1fr)}
.szo-day{border-right:1px solid var(--bs-border-color);display:flex;flex-direction:column;min-height:480px}
.szo-day:last-child{border-right:none}
.szo-day-header{padding:9px 12px 7px;border-bottom:1px solid var(--bs-border-color);background:var(--bs-tertiary-bg);position:sticky;top:0;z-index:5}
.szo-day-title{font-size:.8rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;margin-bottom:3px}
.szo-day-phase{font-size:.7rem;color:var(--bs-secondary-color);font-family:var(--bs-font-monospace);margin-bottom:5px}
.szo-ebar{height:4px;background:var(--bs-border-color);border-radius:2px;overflow:hidden;margin-bottom:5px}
.szo-ebar-fill{height:100%;width:0;border-radius:2px;transition:width .3s,background .3s;background:var(--bs-success)}
.szo-ebar-fill.warn{background:var(--bs-warning)}
.szo-ebar-fill.over{background:var(--bs-danger)}
.szo-day-stats{display:flex;justify-content:space-between;font-size:.7rem;color:var(--bs-secondary-color);font-family:var(--bs-font-monospace)}
.szo-blocks-list{flex:1;padding:6px;display:flex;flex-direction:column;gap:4px;overflow-y:auto}
/* Block card */
.szo-blk{border-radius:5px;padding:7px 9px;cursor:grab;position:relative;border:1px solid transparent;transition:opacity .15s,box-shadow .15s;user-select:none;background:var(--blk-bg,rgba(37,99,235,.12));border-color:var(--blk-border,rgba(37,99,235,.3))}
.szo-blk:active{cursor:grabbing}
.szo-blk.dragging{opacity:.3}
.szo-blk.drag-over{outline:2px solid #f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.2)}
.szo-blk-cat{font-size:.67rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--blk-label,#60a5fa);margin-bottom:2px}
.szo-blk-title{font-size:.8rem;font-weight:600;line-height:1.3;color:var(--bs-body-color)}
.szo-blk-meta{display:flex;justify-content:space-between;align-items:center;margin-top:4px;font-size:.68rem;color:var(--bs-secondary-color);font-family:var(--bs-font-monospace)}
.szo-blk-rm{position:absolute;top:5px;right:5px;background:rgba(220,53,69,.2);border:none;color:rgba(220,53,69,.8);width:15px;height:15px;border-radius:50%;cursor:pointer;font-size:8px;line-height:15px;text-align:center;display:none;padding:0}
.szo-blk:hover .szo-blk-rm{display:block}
.szo-blk-rm:hover{background:rgba(220,53,69,.5);color:#fff}
/* Drop zone */
.szo-dz{border:1.5px dashed var(--bs-border-color);border-radius:5px;padding:8px;text-align:center;font-size:.75rem;color:var(--bs-secondary-color);min-height:38px;display:flex;align-items:center;justify-content:center;transition:background .15s,border-color .15s}
.szo-dz.drag-over{background:rgba(245,158,11,.08);border-color:#f59e0b;color:#f59e0b}
/* Validation */
.szo-val{border-top:1px solid var(--bs-border-color);padding:7px 14px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;min-height:38px;background:var(--bs-tertiary-bg);font-size:.78rem}
.szo-val-ok{color:var(--bs-success);font-weight:600}
.szo-val-warn{color:var(--bs-warning);background:rgba(255,193,7,.1);padding:2px 8px;border-radius:4px}
.szo-val-err{color:var(--bs-danger);background:rgba(220,53,69,.1);padding:2px 8px;border-radius:4px}
/* Category colors */
[data-cat=theory]     {--blk-bg:rgba(37,99,235,.10);  --blk-border:rgba(37,99,235,.30);  --blk-label:#60a5fa}
[data-cat=workshop]   {--blk-bg:rgba(217,119,6,.10);   --blk-border:rgba(217,119,6,.30);   --blk-label:#fbbf24}
[data-cat=break]      {--blk-bg:rgba(5,150,105,.10);   --blk-border:rgba(5,150,105,.30);   --blk-label:#34d399}
[data-cat=buffer]     {--blk-bg:rgba(75,85,99,.12);    --blk-border:rgba(75,85,99,.30);    --blk-label:#9ca3af}
[data-cat=summary]    {--blk-bg:rgba(124,58,237,.10);  --blk-border:rgba(124,58,237,.30);  --blk-label:#a78bfa}
[data-cat=icebreaker] {--blk-bg:rgba(219,39,119,.10);  --blk-border:rgba(219,39,119,.30);  --blk-label:#f472b6}
[data-cat=qa]         {--blk-bg:rgba(6,182,212,.10);   --blk-border:rgba(6,182,212,.30);   --blk-label:#2dd4bf}
@media(max-width:768px){.szo-layout{grid-template-columns:1fr}.szo-lib{max-height:160px;flex-direction:row;flex-wrap:wrap}.szo-days{grid-template-columns:1fr}}
</style>

<div class="mt-3">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h2 class="h5 fw-bold mb-0"><i class="bi bi-calendar3-week text-primary me-2" aria-hidden="true"></i>SZO Planner — Harmonogramy zajęć</h2>
    <div class="ms-auto d-flex gap-2 flex-wrap">
      <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#szoPlannerNewScheduleModal">
        <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nowy harmonogram
      </button>
      <?php if ($sel_schedule): ?>
      <button class="btn btn-sm btn-warning" id="szoBtnAuto">
        <i class="bi bi-lightning-charge me-1" aria-hidden="true"></i>Generuj Auto-Plan
      </button>
      <button class="btn btn-sm btn-success" id="szoBtnSave">
        <i class="bi bi-floppy me-1" aria-hidden="true"></i>Zapisz
      </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($pl_schedules): ?>
  <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <span class="text-body-secondary small fw-semibold">Harmonogram:</span>
    <?php foreach ($pl_schedules as $sch): ?>
    <a href="index.php?tab=planner&sid=<?= (int)$sch['id'] ?>"
       class="btn btn-sm <?= $sch['id'] == $sel_sid ? 'btn-primary' : 'btn-outline-secondary' ?>">
      <?= h($sch['title']) ?>
      <span class="text-opacity-75 ms-1" style="font-size:.7rem"><?= (int)$sch['num_days'] ?>d</span>
    </a>
    <?php endforeach; ?>
    <?php if ($sel_schedule): ?>
    <button class="btn btn-sm btn-outline-danger ms-2" id="szoBtnDelSchedule"
            title="Usuń harmonogram <?= h($sel_schedule['title']) ?>">
      <i class="bi bi-trash" aria-hidden="true"></i>
    </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!$sel_schedule): ?>
  <div class="alert alert-secondary">
    <i class="bi bi-info-circle me-2"></i>
    Utwórz pierwszy harmonogram przyciskiem <strong>Nowy harmonogram</strong>, a następnie przeciągaj bloki z biblioteki do dni szkolenia.
  </div>
  <?php else: ?>

  <div class="szo-layout" id="szoPlannerApp" style="--szo-ncols:<?= (int)$sel_schedule['num_days'] ?>">
    <!-- BIBLIOTEKA BLOKÓW -->
    <div class="szo-lib" id="szoLibrary">
      <div class="szo-lib-header">Biblioteka bloków</div>
      <button class="btn btn-sm btn-outline-primary w-100 mb-1" style="font-size:.78rem"
              data-bs-toggle="modal" data-bs-target="#szoBlockModal" onclick="szoOpenBlockModal(null)">
        <i class="bi bi-plus me-1"></i>Dodaj blok
      </button>
      <div id="szoLibBlocks"></div>
    </div>

    <!-- KOLUMNY DNI -->
    <div class="szo-days" id="szoDayGrid">
      <?php foreach ($sel_schedule['days'] as $day): ?>
      <div class="szo-day" id="szoDay<?= (int)$day['day_number'] ?>col">
        <div class="szo-day-header">
          <div class="szo-day-title">Dzień <?= (int)$day['day_number'] ?></div>
          <div class="szo-day-phase" id="szoPhase<?= (int)$day['day_number'] ?>"><?= h(SZO_PHASES[$day['phase']] ?? $day['phase']) ?></div>
          <div class="szo-ebar"><div class="szo-ebar-fill" id="szoEbar<?= (int)$day['day_number'] ?>"></div></div>
          <div class="szo-day-stats">
            <span id="szoStat<?= (int)$day['day_number'] ?>">0 / 480 min</span>
            <span id="szoEnergy<?= (int)$day['day_number'] ?>">○ 0</span>
          </div>
        </div>
        <div class="szo-blocks-list" id="szoDayBlocks<?= (int)$day['day_number'] ?>" data-day="<?= (int)$day['day_number'] ?>"></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- WALIDACJA -->
    <div class="szo-val" id="szoValidation" style="grid-column:1/-1">
      <span class="szo-val-ok"><i class="bi bi-check-circle me-1"></i>Gotowy do planowania</span>
    </div>
  </div>

  <?php endif; /* sel_schedule */ ?>
</div>

<!-- ── MODAL: Nowy harmonogram ────────────────────── -->
<div class="modal fade" id="szoPlannerNewScheduleModal" tabindex="-1" aria-labelledby="szoNewSchedLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <form class="modal-content" id="szoNewSchedForm">
      <div class="modal-header">
        <h3 class="modal-title h6 fw-bold" id="szoNewSchedLabel">Nowy harmonogram</h3>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label small fw-semibold" for="szoSchedTitle">Nazwa</label>
          <input type="text" class="form-control form-control-sm" id="szoSchedTitle" name="title"
                 placeholder="np. Szkolenie przywódcze IX 2025" required maxlength="200">
        </div>
        <div class="mb-0">
          <label class="form-label small fw-semibold" for="szoSchedDays">Liczba dni</label>
          <select class="form-select form-select-sm" id="szoSchedDays" name="num_days">
            <option value="2">2 dni</option>
            <option value="3" selected>3 dni</option>
            <option value="4">4 dni</option>
            <option value="5">5 dni</option>
          </select>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-sm btn-primary">Utwórz</button>
      </div>
    </form>
  </div>
</div>

<!-- ── MODAL: Blok ────────────────────────────────── -->
<div class="modal fade" id="szoBlockModal" tabindex="-1" aria-labelledby="szoBlockModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="szoBlockForm">
      <input type="hidden" id="szoBlockId" name="block_id" value="">
      <div class="modal-header">
        <h3 class="modal-title h6 fw-bold" id="szoBlockModalLabel">Blok modułowy</h3>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body row g-2">
        <div class="col-12">
          <label class="form-label small fw-semibold" for="szoBlkTitle">Nazwa bloku <span class="text-danger">*</span></label>
          <input type="text" class="form-control form-control-sm" id="szoBlkTitle" name="title" required maxlength="200">
        </div>
        <div class="col-sm-6">
          <label class="form-label small fw-semibold" for="szoBlkCat">Kategoria</label>
          <select class="form-select form-select-sm" id="szoBlkCat" name="category">
            <?php foreach (SZO_CATEGORIES as $k => $v): ?>
            <option value="<?= h($k) ?>"><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-3">
          <label class="form-label small fw-semibold" for="szoBlkDur">Czas (min)</label>
          <input type="number" class="form-control form-control-sm" id="szoBlkDur" name="duration_min"
                 value="60" min="5" max="480" step="5">
        </div>
        <div class="col-sm-3">
          <label class="form-label small fw-semibold" for="szoBlkDiff">Trudność (1–5)</label>
          <input type="number" class="form-control form-control-sm" id="szoBlkDiff" name="difficulty"
                 value="2" min="1" max="5">
        </div>
        <div class="col-sm-4">
          <label class="form-label small fw-semibold" for="szoBlkEnergy">Wpływ energii (−2..+2)</label>
          <input type="number" class="form-control form-control-sm" id="szoBlkEnergy" name="energy_impact"
                 value="0" min="-2" max="2">
        </div>
        <div class="col-sm-4">
          <label class="form-label small fw-semibold" for="szoBlkBreak">Przerwa po (min)</label>
          <input type="number" class="form-control form-control-sm" id="szoBlkBreak" name="min_break_after"
                 value="0" min="0" max="60" step="5">
        </div>
        <div class="col-sm-4 d-flex align-items-end">
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="szoBlkLocked" name="locked" value="1">
            <label class="form-check-label small" for="szoBlkLocked">Zablokowany</label>
          </div>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold" for="szoBlkTags">Tagi (przecinki)</label>
          <input type="text" class="form-control form-control-sm" id="szoBlkTags" name="tags"
                 placeholder="np. leadership, komunikacja">
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold" for="szoBlkNotes">Notatki</label>
          <textarea class="form-control form-control-sm" id="szoBlkNotes" name="notes" rows="2" maxlength="500"></textarea>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-sm btn-outline-danger d-none" id="szoBlkDeleteBtn">Usuń blok</button>
        <button type="submit" class="btn btn-sm btn-primary">Zapisz blok</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
'use strict';

/* ── Stałe i dane startowe ──────────────────────────── */
const AJAX   = <?= json_encode($ajax_url) ?>;
const TOKEN  = <?= json_encode(dyd_token()) ?>;
const SID    = <?= (int)$js_sid ?>;
const CAT_LABELS = {
  theory:'Teoria', workshop:'Warsztat', break:'Przerwa',
  buffer:'Bufor', summary:'Synteza', icebreaker:'Icebreaker', qa:'Q&A'
};
const PHASE_LABELS = {
  foundation:'Fundamenty', intensive:'Intensywna',
  synthesis:'Synteza', continuation:'Kontynuacja'
};
const PRIORITY = {
  foundation: {icebreaker:10, theory:8, theory_hard:4, workshop:4, workshop_hard:2, summary:1, qa:3, break:6, buffer:4},
  intensive:  {icebreaker:3,  theory:5, theory_hard:7, workshop:8, workshop_hard:10, summary:3, qa:4, break:6, buffer:5},
  synthesis:  {icebreaker:2,  theory:3, theory_hard:1, workshop:7, workshop_hard:4, summary:10, qa:9, break:5, buffer:5},
  continuation:{icebreaker:3, theory:5, theory_hard:5, workshop:7, workshop_hard:7, summary:6, qa:6, break:6, buffer:5},
};

/* ── Stan aplikacji ─────────────────────────────────── */
let ALL_BLOCKS = {};       // id => block object
let state = {
  library: [],             // [block_id, ...]
  days: {},                // day_number => [block_id, ...]
  phases: {},              // day_number => phase string
};
let dragging = null;       // {blockId, source:'library'|'day', dayNum?}
let saveTimer = null;

/* ── Init ───────────────────────────────────────────── */
const SERVER_BLOCKS = <?= $js_blocks ?>;
const SERVER_DAYS   = <?= $js_days ?>;

function init() {
  // Zaindeksuj bloki
  SERVER_BLOCKS.forEach(b => { ALL_BLOCKS[b.id] = b; });

  // Stan dni z serwera
  SERVER_DAYS.forEach(d => {
    state.days[d.day_number]   = (d.block_order || []).map(Number);
    state.phases[d.day_number] = d.phase || 'foundation';
  });

  // Bloki w bibliotece = wszystkie bloki minus umieszczone w dniach
  const placed = new Set(Object.values(state.days).flat());
  state.library = SERVER_BLOCKS.map(b => b.id).filter(id => !placed.has(id));

  renderAll();
  attachGlobalDnD();
}

/* ── Render ─────────────────────────────────────────── */
function renderAll() {
  renderLibrary();
  Object.keys(state.days).map(Number).forEach(dn => renderDay(dn));
  renderValidation();
}

function blockCard(blockId, source, dayNum) {
  const b = ALL_BLOCKS[blockId];
  if (!b) return '';
  const dots = '●'.repeat(b.difficulty) + '○'.repeat(5 - b.difficulty);
  const rmBtn = source === 'day'
    ? `<button class="szo-blk-rm" data-rm="1" data-day="${dayNum}" data-bid="${b.id}" tabindex="-1" aria-label="Usuń z dnia">✕</button>`
    : `<button class="szo-blk-rm" data-edit="1" data-bid="${b.id}" tabindex="-1" aria-label="Edytuj blok" style="color:#60a5fa;background:rgba(37,99,235,.2)">✎</button>`;
  return `<div class="szo-blk" draggable="true"
    data-bid="${b.id}" data-src="${source}" ${source==='day' ? `data-day="${dayNum}"` : ''}
    data-cat="${b.category}" role="listitem" tabindex="0"
    aria-label="${b.title}, ${b.duration_min} minut">
    <div class="szo-blk-cat">${CAT_LABELS[b.category]||b.category}</div>
    <div class="szo-blk-title">${esc(b.title)}</div>
    <div class="szo-blk-meta">
      <span>${b.duration_min} min</span>
      <span title="Trudność">${dots}</span>
    </div>
    ${rmBtn}
  </div>`;
}

function dropZone(dayNum, slotIdx) {
  return `<div class="szo-dz" data-dz="1" data-day="${dayNum}" data-slot="${slotIdx}">+ upuść tutaj</div>`;
}

function renderLibrary() {
  const el = document.getElementById('szoLibBlocks');
  if (!el) return;
  if (!state.library.length) {
    el.innerHTML = '<p class="small text-body-secondary text-center my-2">Brak bloków w bibliotece</p>';
    return;
  }
  el.innerHTML = state.library.map(id => blockCard(id, 'library', null)).join('');
}

function renderDay(dayNum) {
  const blocksEl = document.getElementById('szoDayBlocks' + dayNum);
  if (!blocksEl) return;
  const ids = state.days[dayNum] || [];
  blocksEl.innerHTML = ids.map((id, si) => blockCard(id, 'day', dayNum)).join('') + dropZone(dayNum, ids.length);

  // Stats
  const total = ids.reduce((s, id) => s + (ALL_BLOCKS[id]?.duration_min || 0), 0);
  const energy = ids.reduce((s, id) => s + (ALL_BLOCKS[id]?.energy_impact || 0), 0);
  const pct = Math.min(total / 480 * 100, 100);

  const ef = document.getElementById('szoEbar' + dayNum);
  if (ef) {
    ef.style.width = pct + '%';
    ef.className = 'szo-ebar-fill' + (total > 480 ? ' over' : total > 390 ? ' warn' : '');
  }
  const st = document.getElementById('szoStat' + dayNum);
  if (st) st.textContent = total + ' / 480 min';
  const en = document.getElementById('szoEnergy' + dayNum);
  if (en) en.textContent = energy > 0 ? '⚡ +' + energy : energy < 0 ? '🔋 ' + energy : '○ 0';
  const ph = document.getElementById('szoPhase' + dayNum);
  if (ph) ph.textContent = PHASE_LABELS[state.phases[dayNum]] || state.phases[dayNum] || '';
}

function renderValidation() {
  const panel = document.getElementById('szoValidation');
  if (!panel) return;
  const issues = [];

  Object.keys(state.days).map(Number).forEach(dn => {
    const ids = state.days[dn] || [];
    const total = ids.reduce((s, id) => s + (ALL_BLOCKS[id]?.duration_min || 0), 0);

    if (total > 480) issues.push({t:'err', m:`Dzień ${dn}: przekroczono limit 8h (${total} min)`});
    else if (total > 420) issues.push({t:'warn', m:`Dzień ${dn}: plan przekracza 7h`});

    let acc = 0, warned = false;
    ids.forEach(id => {
      const b = ALL_BLOCKS[id];
      if (!b) return;
      if (b.category === 'break' || b.category === 'buffer') { acc = 0; warned = false; }
      else {
        acc += b.duration_min;
        if (acc > 90 && !warned) {
          issues.push({t:'warn', m:`Dzień ${dn}: ponad 90 min bez przerwy`});
          warned = true; acc = 0;
        }
      }
    });

    if (dn === 3 || state.phases[dn] === 'synthesis') {
      ids.forEach(id => {
        const b = ALL_BLOCKS[id];
        if (b && b.category === 'theory' && b.difficulty >= 4)
          issues.push({t:'warn', m:`Dzień ${dn}: ciężka teoria (poz. ${b.difficulty}) w fazie syntezy`});
      });
    }
  });

  if (!issues.length) {
    panel.innerHTML = '<span class="szo-val-ok"><i class="bi bi-check-circle me-1"></i>Plan spójny — brak naruszeń reguł</span>';
  } else {
    panel.innerHTML = issues.map(i =>
      `<span class="szo-val-${i.t==='err'?'err':'warn'}">${i.t==='err'?'✕':'△'} ${esc(i.m)}</span>`
    ).join('');
  }
}

/* ── Drag & Drop ─────────────────────────────────────── */
function attachGlobalDnD() {
  const app = document.getElementById('szoPlannerApp');
  if (!app) return;

  app.addEventListener('dragstart', e => {
    const card = e.target.closest('.szo-blk');
    if (!card) return;
    dragging = {
      blockId: +card.dataset.bid,
      source:  card.dataset.src,
      dayNum:  card.dataset.day ? +card.dataset.day : null,
    };
    requestAnimationFrame(() => card.classList.add('dragging'));
    e.dataTransfer.effectAllowed = 'move';
  });

  app.addEventListener('dragend', () => {
    app.querySelectorAll('.dragging').forEach(el => el.classList.remove('dragging'));
    dragging = null;
  });

  app.addEventListener('dragover', e => {
    const tgt = e.target.closest('.szo-dz,.szo-blk[data-src="day"]');
    if (!tgt || !dragging) return;
    e.preventDefault();
    tgt.classList.add('drag-over');
  });

  app.addEventListener('dragleave', e => {
    const tgt = e.target.closest('.szo-dz,.szo-blk');
    if (tgt && !tgt.contains(e.relatedTarget)) tgt.classList.remove('drag-over');
  });

  app.addEventListener('drop', e => {
    e.preventDefault();
    const tgt = e.target.closest('.szo-dz,.szo-blk[data-src="day"]');
    if (!tgt || !dragging) return;
    tgt.classList.remove('drag-over');

    const targetDay  = +tgt.dataset.day;
    const targetSlot = +(tgt.dataset.slot ?? tgt.dataset.day && state.days[targetDay].indexOf(+tgt.dataset.bid));
    const { blockId, source, dayNum: srcDay } = dragging;

    // Remove from source
    if (source === 'library') {
      state.library = state.library.filter(id => id !== blockId);
    } else {
      const idx = state.days[srcDay].indexOf(blockId);
      if (idx !== -1) state.days[srcDay].splice(idx, 1);
    }

    // Insert at target slot
    const slot = +tgt.dataset.slot;
    if (!isNaN(slot)) {
      state.days[targetDay].splice(slot, 0, blockId);
    } else {
      // dropped on existing block — insert before it
      const existBid = +tgt.dataset.bid;
      const pos = state.days[targetDay].indexOf(existBid);
      state.days[targetDay].splice(pos >= 0 ? pos : state.days[targetDay].length, 0, blockId);
    }

    renderAll();
    scheduleSave();
  });

  // Click remove / edit buttons (event delegation)
  app.addEventListener('click', e => {
    const rmBtn = e.target.closest('[data-rm]');
    if (rmBtn) {
      const dayNum = +rmBtn.dataset.day;
      const bid    = +rmBtn.dataset.bid;
      state.days[dayNum] = (state.days[dayNum] || []).filter(id => id !== bid);
      state.library.push(bid);
      renderAll();
      scheduleSave();
      return;
    }
    const editBtn = e.target.closest('[data-edit]');
    if (editBtn) {
      szoOpenBlockModal(+editBtn.dataset.bid);
    }
  });
}

/* ── Auto-Schedule ──────────────────────────────────── */
window.szoAutoSchedule = function() {
  if (!confirm('Generuj Auto-Plan? Istniejące rozmieszczenie bloków zostanie zastąpione.')) return;

  const all = [...state.library, ...Object.values(state.days).flat()];
  const uniqueAll = [...new Set(all)];
  Object.keys(state.days).forEach(dn => { state.days[dn] = []; });
  state.library = [];

  const placed = new Set();
  const dayNums = Object.keys(state.days).map(Number).sort((a,b)=>a-b);

  function blockScore(id, phase) {
    const b = ALL_BLOCKS[id];
    if (!b) return 0;
    const key = (b.category === 'theory' && b.difficulty >= 3) ? 'theory_hard'
      : b.category === 'theory' ? 'theory'
      : (b.category === 'workshop' && b.difficulty >= 3) ? 'workshop_hard'
      : b.category === 'workshop' ? 'workshop'
      : b.category;
    return (PRIORITY[phase] || PRIORITY.continuation)[key] || 1;
  }

  dayNums.forEach((dn, i) => {
    const phase = state.phases[dn] || 'foundation';
    const sorted = uniqueAll
      .filter(id => !placed.has(id))
      .map(id => ({id, score: blockScore(id, phase)}))
      .sort((a,b) => b.score - a.score);

    let dayMin = 0, acc = 0;
    sorted.forEach(({id, score}) => {
      if (score < 3) return;
      const b = ALL_BLOCKS[id];
      if (!b || dayMin + b.duration_min > 420) return;
      if (acc >= 90 && b.category !== 'break' && b.category !== 'buffer') {
        // Szukaj przerwy do wstrzyknięcia
        const brk = sorted.find(x => !placed.has(x.id) && (ALL_BLOCKS[x.id]?.category === 'break' || ALL_BLOCKS[x.id]?.category === 'buffer') && x.id !== id);
        if (brk) { state.days[dn].push(brk.id); placed.add(brk.id); dayMin += ALL_BLOCKS[brk.id].duration_min; acc = 0; }
      }
      if (placed.has(id)) return;
      state.days[dn].push(id);
      placed.add(id);
      dayMin += b.duration_min;
      acc = (b.category === 'break' || b.category === 'buffer') ? 0 : acc + b.duration_min;
    });
  });

  // Reszta wraca do biblioteki
  state.library = uniqueAll.filter(id => !placed.has(id));
  renderAll();
  scheduleSave();
};

/* ── Zapis (debounced) ──────────────────────────────── */
function scheduleSave() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(doSave, 1500);
}

async function doSave() {
  if (!SID) return;
  const days = Object.keys(state.days).map(Number).map(dn => ({
    day_number: dn,
    phase: state.phases[dn] || 'foundation',
    block_order: state.days[dn] || [],
  }));
  try {
    const fd = new FormData();
    fd.append('action', 'schedule_save');
    fd.append('schedule_id', SID);
    fd.append('days_json', JSON.stringify(days));
    fd.append('_token', TOKEN);
    const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
    const j = await r.json();
    const btn = document.getElementById('szoBtnSave');
    if (btn) {
      btn.classList.toggle('btn-success', j.ok);
      btn.classList.toggle('btn-danger', !j.ok);
      setTimeout(() => { btn.classList.add('btn-success'); btn.classList.remove('btn-danger'); }, 2000);
    }
  } catch(e) { console.error('szo save error', e); }
}

/* ── Przycisk Zapisz (ręczny) ───────────────────────── */
const saveBtn = document.getElementById('szoBtnSave');
if (saveBtn) saveBtn.addEventListener('click', () => { clearTimeout(saveTimer); doSave(); });

/* ── Przycisk Auto-Plan ─────────────────────────────── */
const autoBtn = document.getElementById('szoBtnAuto');
if (autoBtn) autoBtn.addEventListener('click', window.szoAutoSchedule);

/* ── Usuń harmonogram ───────────────────────────────── */
const delBtn = document.getElementById('szoBtnDelSchedule');
if (delBtn) delBtn.addEventListener('click', async () => {
  if (!confirm('Usunąć harmonogram? Tej akcji nie można cofnąć.')) return;
  const fd = new FormData();
  fd.append('action', 'schedule_delete');
  fd.append('schedule_id', SID);
  fd.append('_token', TOKEN);
  const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
  const j = await r.json();
  if (j.ok) window.location.href = 'index.php?tab=planner';
  else alert('Błąd: ' + j.msg);
});

/* ── Nowy harmonogram (modal form) ──────────────────── */
const newSchedForm = document.getElementById('szoNewSchedForm');
if (newSchedForm) newSchedForm.addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(newSchedForm);
  fd.append('action', 'schedule_create');
  fd.append('_token', TOKEN);
  const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
  const j = await r.json();
  if (j.ok && j.schedule) {
    window.location.href = 'index.php?tab=planner&sid=' + j.schedule.id;
  } else {
    alert('Błąd: ' + (j.msg || 'Nieznany błąd'));
  }
});

/* ── Modal bloku ────────────────────────────────────── */
window.szoOpenBlockModal = function(blockId) {
  const form   = document.getElementById('szoBlockForm');
  const delBtn = document.getElementById('szoBlkDeleteBtn');
  if (!form) return;
  form.reset();
  const b = blockId ? ALL_BLOCKS[blockId] : null;
  document.getElementById('szoBlockId').value   = b ? b.id : '';
  document.getElementById('szoBlkTitle').value  = b ? b.title : '';
  document.getElementById('szoBlkCat').value    = b ? b.category : 'workshop';
  document.getElementById('szoBlkDur').value    = b ? b.duration_min : 60;
  document.getElementById('szoBlkDiff').value   = b ? b.difficulty : 2;
  document.getElementById('szoBlkEnergy').value = b ? b.energy_impact : 0;
  document.getElementById('szoBlkBreak').value  = b ? b.min_break_after : 0;
  document.getElementById('szoBlkNotes').value  = b ? (b.notes || '') : '';
  document.getElementById('szoBlkTags').value   = b ? (JSON.parse(b.tags||'[]').join(', ')) : '';
  document.getElementById('szoBlkLocked').checked = b ? !!b.locked : false;
  document.getElementById('szoBlockModalLabel').textContent = b ? 'Edytuj blok' : 'Nowy blok';
  if (delBtn) delBtn.classList.toggle('d-none', !b);
  bootstrap.Modal.getOrCreateInstance(document.getElementById('szoBlockModal')).show();
};

const blockForm = document.getElementById('szoBlockForm');
if (blockForm) {
  blockForm.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(blockForm);
    fd.append('action', 'block_save');
    fd.append('_token', TOKEN);
    const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
    const j = await r.json();
    if (j.ok && j.block) {
      ALL_BLOCKS[j.block.id] = j.block;
      if (!state.library.includes(j.block.id) && !Object.values(state.days).flat().includes(j.block.id)) {
        state.library.push(j.block.id);
      }
      bootstrap.Modal.getInstance(document.getElementById('szoBlockModal')).hide();
      renderAll();
    } else alert('Błąd: ' + (j.msg || 'Nieznany błąd'));
  });

  document.getElementById('szoBlkDeleteBtn')?.addEventListener('click', async () => {
    const bid = +document.getElementById('szoBlockId').value;
    if (!bid || !confirm('Usunąć blok z biblioteki?')) return;
    const fd = new FormData();
    fd.append('action', 'block_delete');
    fd.append('block_id', bid);
    fd.append('_token', TOKEN);
    const r = await fetch(AJAX, {method:'POST', body:fd, headers:{'X-CSRF-Token':TOKEN}});
    const j = await r.json();
    if (j.ok) {
      delete ALL_BLOCKS[bid];
      state.library = state.library.filter(id => id !== bid);
      Object.keys(state.days).forEach(dn => {
        state.days[dn] = (state.days[dn]||[]).filter(id => id !== bid);
      });
      bootstrap.Modal.getInstance(document.getElementById('szoBlockModal')).hide();
      renderAll();
      scheduleSave();
    } else alert('Błąd: ' + j.msg);
  });
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

init();

})();
</script>
