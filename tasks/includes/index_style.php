<style type="text/tailwindcss">
/* ── Tokeny (lokalne dla tej strony — nieudostępniane innym plikom) ───────── */
:root {
  --tk-focus:   #2563eb;
  --tk-border:  #e2e8f0;
  --tk-bg-soft: #f8fafc;
  --tk-text:    #0f172a;
  --tk-muted:   #64748b;
  --tk-radius:  .5rem;
  --tk-open:    #16a34a;
  --tk-taken:   #2563eb;
  --tk-done:    #64748b;
}

/* Skip link */
.skip-link{ @apply tw-absolute tw-left-4 tw-z-[9999] tw-text-white tw-py-[.4rem] tw-px-[.9rem]
  tw-rounded-b-md tw-text-[.85rem] tw-font-semibold tw-no-underline tw-transition-[top] tw-duration-150;
  top:-3rem; background: var(--tk-focus); }
.skip-link:focus{top:0}

/* SR announce */
#tk-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;
  overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}

/* Kropka koloru obszaru — używana w nagłówku bieżącego obszaru poniżej */
.ws-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}

/* ── Pasek filtrów ──────────────────────────────────────────────────────── */
.tk-toolbar{
  @apply tw-flex tw-flex-wrap tw-gap-2 tw-items-center tw-bg-white tw-border tw-rounded-lg tw-py-[.55rem] tw-px-3 tw-mb-[.85rem];
  border-color: var(--tk-border);
}
.tk-sep{width:1px;height:1.3rem;background:#e2e8f0;flex-shrink:0}

/* Status pills */
.tk-pill{
  @apply tw-inline-flex tw-items-center tw-gap-[.3rem] tw-py-[.22rem] tw-px-[.65rem] tw-rounded-full
         tw-text-[.77rem] tw-font-bold tw-border-[1.5px] tw-border-transparent tw-no-underline
         tw-transition-all tw-whitespace-nowrap tw-bg-[#f1f5f9];
  color: var(--tk-muted);
}
.tk-pill:hover{border-color:#94a3b8}
.tk-pill.active{background:var(--tk-text);color:#fff;border-color:var(--tk-text)}
.tk-pill[data-s="open"].active  {background:var(--tk-open);border-color:var(--tk-open)}
.tk-pill[data-s="taken"].active {background:var(--tk-taken);border-color:var(--tk-taken)}
.tk-pill[data-s="done"].active  {background:var(--tk-done);border-color:var(--tk-done)}
.tk-pill[data-s="mine"].active  {background:#7c3aed;border-color:#7c3aed}
.tk-pill:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px}
.pill-n{font-size:.67rem;opacity:.75}

.tk-select{
  @apply tw-text-[.8rem] tw-py-[.25rem] tw-px-[.55rem] tw-rounded-md tw-border-[1.5px] tw-border-[#e2e8f0] tw-bg-white tw-cursor-pointer;
  color: var(--tk-text);
}
.tk-select:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px}

/* Przycisk "Filtry" + panel (priorytet/kategoria/tag/obszar/jednostka) */
.tk-filters-btn{
  @apply tw-inline-flex tw-items-center tw-gap-[.35rem] tw-py-[.25rem] tw-px-[.7rem] tw-rounded-md
         tw-border-[1.5px] tw-border-[#e2e8f0] tw-bg-white tw-text-[.8rem] tw-font-bold tw-cursor-pointer
         tw-whitespace-nowrap tw-transition-colors;
  color: var(--tk-text);
}
.tk-filters-btn:hover{border-color:#94a3b8}
.tk-filters-btn.has-active{border-color:var(--tk-focus);color:var(--tk-focus)}
.tk-filters-badge{
  @apply tw-inline-flex tw-items-center tw-justify-center tw-min-w-[1.2rem] tw-h-[1.2rem] tw-px-[.3rem]
         tw-rounded-full tw-text-white tw-text-[.65rem] tw-font-bold;
  background: var(--tk-focus);
}
.tk-filters-panel{width:280px;padding:.85rem}
.tk-filters-field{margin-bottom:.65rem}
.tk-filters-field:last-of-type{margin-bottom:0}
.tk-filters-field label{
  @apply tw-block tw-text-[.7rem] tw-font-bold tw-uppercase tw-tracking-[.03em] tw-mb-1;
  color: var(--tk-muted);
}
.tk-filters-field .tk-select{width:100%}
.tk-filters-panel-footer{
  @apply tw-flex tw-items-center tw-justify-start tw-mt-3 tw-pt-[.65rem] tw-border-t tw-border-[#f1f5f9];
}

.tk-search{
  @apply tw-text-[.82rem] tw-py-[.28rem] tw-px-[.65rem] tw-rounded-md tw-border-[1.5px] tw-border-[#e2e8f0] tw-bg-white;
  width:180px;min-width:120px;
}
.tk-search:focus{outline:2px solid var(--tk-focus);outline-offset:2px;border-color:transparent}

/* ── Tabela zadań ───────────────────────────────────────────────────────── */
.tk-wrap{
  @apply tw-bg-white tw-border tw-rounded-lg tw-overflow-hidden;
  border-color: var(--tk-border);
}

/* ── Bulk action bar ─────────────────────────────────────────────────── */
.tk-bulk-bar {
  @apply tw-hidden tw-items-center tw-gap-2 tw-py-[.55rem] tw-px-[.85rem] tw-bg-blue-50 tw-border-b tw-border-blue-200 tw-flex-wrap;
}
.tk-bulk-bar.visible { display: flex; }
.tk-bulk-count {
  @apply tw-text-[.82rem] tw-font-bold tw-whitespace-nowrap;
  color: var(--tk-taken);
}
.tk-bulk-btn {
  @apply tw-inline-flex tw-items-center tw-gap-[.3rem] tw-py-[.25rem] tw-px-[.65rem] tw-rounded-[.35rem]
         tw-text-[.78rem] tw-font-bold tw-border-[1.5px] tw-border-blue-100 tw-bg-white tw-text-blue-700
         tw-cursor-pointer tw-whitespace-nowrap tw-transition-all;
}
.tk-bulk-btn:hover { background: #dbeafe; border-color: #93c5fd; }
.tk-bulk-btn:focus-visible { outline: 2px solid var(--tk-focus); }
.tk-bulk-btn.danger { color: #dc2626; border-color: #fecaca; }
.tk-bulk-btn.danger:hover { background: #fef2f2; border-color: #dc2626; }
.tk-bulk-sep { width: 1px; height: 1.2rem; background: #bfdbfe; flex-shrink: 0; }
.tk-bulk-close {
  @apply tw-ml-auto tw-bg-transparent tw-border-0 tw-text-slate-500 tw-cursor-pointer tw-text-[.85rem] tw-py-[.1rem] tw-px-[.3rem];
}
.tk-bulk-close:hover { color: #dc2626; }

/* Checkbox column */
.tk-table th.th-check, .tk-table td.td-check {
  width: 36px; text-align: center; padding: 0 .5rem;
}
.tk-row-check {
  width: 15px; height: 15px; cursor: pointer; accent-color: var(--tk-taken);
}
.tk-table tbody tr.selected { background: #eff6ff !important; }

/* Bulk select dropdown */
.tk-bulk-pri-sel, .tk-bulk-list-sel {
  font-size: .78rem; padding: .22rem .5rem; border-radius: .35rem;
  border: 1.5px solid #dbeafe; background: #fff; color: #1d4ed8; cursor: pointer;
}

.tk-table{
  width:100%;
  border-collapse:collapse;
  font-size:.84rem;
}

/* Nagłówek tabeli */
.tk-table thead th{
  @apply tw-font-bold tw-uppercase tw-tracking-[.06em] tw-py-[.55rem] tw-px-3 tw-whitespace-nowrap tw-text-left;
  background: var(--tk-bg-soft);
  font-size: .72rem;
  color: var(--tk-muted);
  border-bottom: 2px solid var(--tk-border);
}
.tk-table thead th.th-center{text-align:center}
.tk-table thead th:first-child{padding-left:1rem}

/* Wiersze */
.tk-table tbody tr{
  @apply tw-cursor-pointer tw-transition-colors;
  border-bottom: 1px solid #f1f5f9;
}
.tk-table tbody tr:last-child{border-bottom:none}
.tk-table tbody tr:hover{background:#f8fafc}
.tk-table tbody tr:focus-visible{
  outline:2px solid var(--tk-focus);outline-offset:-2px;
  background:#eff6ff;
}
.tk-table tbody tr.row-done{opacity:.65}

/* Komórki */
.tk-table td{
  padding:.6rem .75rem;
  vertical-align:middle;
  color: var(--tk-text);
}
.tk-table td:first-child{padding-left:1rem}

/* Pasek priorytetu (lewa krawędź) */
.tk-table tbody tr td:first-child{
  border-left:3px solid transparent;
}
.tk-table tbody tr[data-pri="4"] td:first-child{border-left-color:#dc2626}
.tk-table tbody tr[data-pri="3"] td:first-child{border-left-color:#f59e0b}
.tk-table tbody tr[data-pri="2"] td:first-child{border-left-color:#3b82f6}
.tk-table tbody tr[data-pri="1"] td:first-child{border-left-color:#94a3b8}

/* Tytuł */
.tk-title{
  @apply tw-font-semibold tw-leading-[1.4] tw-overflow-hidden;
  color: var(--tk-text);
  display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;
}
.tk-subtitle{font-size:.72rem;color:var(--tk-muted);margin-top:.1rem}

/* Status badge */
.tk-status{
  @apply tw-inline-flex tw-items-center tw-gap-1 tw-text-[.7rem] tw-font-bold tw-py-[.15rem] tw-px-2 tw-rounded-full tw-whitespace-nowrap;
}
.s-open  {background:#dcfce7;color:#15803d}
.s-taken {background:#dbeafe;color:#1d4ed8}
.s-done  {background:#f1f5f9;color:#64748b}

/* Badge weryfikacji wykonania (Z / ZP / O) */
.tk-confirm-badge{
  @apply tw-inline-flex tw-items-center tw-justify-center tw-min-w-[1.35rem] tw-h-[1.15rem] tw-px-[.3rem]
         tw-rounded-[.3rem] tw-text-[.62rem] tw-font-extrabold tw-tracking-[.02em] tw-align-middle;
}
.tk-confirm-badge.z  {background:#fef3c7;color:#b45309}
.tk-confirm-badge.zp {background:#ede9fe;color:#7c3aed}
.tk-confirm-badge.o  {background:#fee2e2;color:#dc2626}

/* Legenda pod tabelą */
.tk-legend{
  @apply tw-flex tw-flex-wrap tw-items-center tw-gap-x-5 tw-gap-y-[.4rem] tw-text-[.75rem] tw-text-slate-500 tw-pt-[.6rem] tw-px-1 tw-pb-[.15rem] tw-border-t;
  border-color: var(--tk-border, #e2e8f0);
}
.tk-legend .tk-confirm-badge{margin-right:.35rem}

/* Priorytet dot */
.pri-dot{
  display:inline-flex;align-items:center;gap:.3rem;
  font-size:.75rem;font-weight:600;white-space:nowrap;
}
.pri-dot i{font-size:.75rem}

/* Tagi */
.tk-tags{display:flex;flex-wrap:wrap;gap:.2rem}
.tk-tag{font-size:.64rem;padding:.08rem .38rem;border-radius:2rem;font-weight:700}

/* Postęp podzadań */
.tk-prog-wrap{display:flex;align-items:center;gap:.4rem}
.tk-prog-track{width:60px;height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;flex-shrink:0}
.tk-prog-fill{height:100%;border-radius:2px}
.tk-prog-label{font-size:.68rem;color:var(--tk-muted);white-space:nowrap}

/* Avatary */
.tk-av-stack{display:flex}
.tk-av{display:inline-flex;align-items:center;justify-content:center;
  width:24px;height:24px;border-radius:50%;
  font-size:.58rem;font-weight:700;color:#fff;
  border:2px solid #fff;flex-shrink:0}
.tk-av-stack .tk-av+.tk-av{margin-left:-6px}

/* Kolumna akcji */
.tk-actions{display:flex;align-items:center;gap:.35rem}
.btn-claim{
  @apply tw-text-[.73rem] tw-font-bold tw-py-[.2rem] tw-px-[.6rem] tw-rounded-full tw-border-[1.5px] tw-bg-white
         tw-whitespace-nowrap tw-transition-all;
  border-color: var(--tk-open); color: var(--tk-open);
}
.btn-claim:hover,.btn-claim:focus-visible{background:var(--tk-open);color:#fff}
.btn-claim:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px}
.btn-claim:disabled{opacity:.5;cursor:not-allowed}
.btn-unclaim{
  @apply tw-text-[.73rem] tw-font-bold tw-py-[.2rem] tw-px-[.6rem] tw-rounded-full tw-border-[1.5px] tw-border-[#e2e8f0] tw-bg-white
         tw-whitespace-nowrap tw-transition-all;
  color: var(--tk-muted);
}
.btn-unclaim:hover,.btn-unclaim:focus-visible{border-color:#dc2626;color:#dc2626}
.btn-unclaim:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px}
.btn-open{
  @apply tw-inline-flex tw-items-center tw-text-[.73rem] tw-font-bold tw-py-[.2rem] tw-px-[.6rem] tw-rounded-full
         tw-border-[1.5px] tw-border-green-600 tw-bg-green-600 tw-text-white
         tw-whitespace-nowrap tw-no-underline tw-transition-all tw-cursor-pointer;
}
.btn-open:hover,.btn-open:focus-visible{background:#15803d;border-color:#15803d;color:#fff}
.btn-open:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px}

/* Stan pusty */
.tk-empty{
  @apply tw-text-center tw-py-14 tw-px-4;
  color: var(--tk-muted);
}
.tk-empty i{font-size:2rem;display:block;margin-bottom:.6rem;opacity:.3}

/* Offcanvas */
#taskOffcanvas{width:600px;max-width:96vw}
#taskOffcanvas .offcanvas-header{border-bottom:1px solid #e2e8f0;padding:.85rem 1.1rem}
#taskOffcanvas .offcanvas-body{padding:0;overflow-y:auto}

/* Kolumna termin */
.td-due{white-space:nowrap;font-size:.78rem}
.td-due.overdue{color:#dc2626;font-weight:600}

/* Inline status / priority button */
.tk-status-btn{border:none;background:none;cursor:pointer;padding:.15rem .5rem;border-radius:2rem;font-size:.7rem;font-weight:700;display:inline-flex;align-items:center;gap:.25rem;white-space:nowrap;transition:opacity .12s;}
.tk-status-btn:hover{opacity:.75}

/* ── Kanban ── */
.tk-kanban{display:flex;gap:1rem;overflow-x:auto;align-items:flex-start;padding-bottom:1.5rem}
.tk-kanban-col{min-width:256px;max-width:288px;background:#f8fafc;border-radius:10px;flex-shrink:0}
.tk-kanban-hdr{display:flex;justify-content:space-between;align-items:center;padding:.55rem .65rem .55rem;font-size:.82rem;border-bottom:1px solid #e2e8f0}
.tk-kanban-body{padding:.5rem;min-height:60px}
.tk-col-add{background:none;border:none;color:#94a3b8;cursor:pointer;padding:.15rem .3rem;border-radius:.3rem;font-size:.9rem;line-height:1;transition:all .12s;flex-shrink:0}
.tk-col-add:hover{color:#2563eb;background:#eff6ff}
.tk-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:.4rem;cursor:pointer;transition:box-shadow .12s;overflow:hidden;display:flex}
.tk-card:hover{box-shadow:0 2px 10px rgba(0,0,0,.1);border-color:#cbd5e1}
.tk-card:focus-visible{outline:2px solid var(--tk-focus);outline-offset:2px;border-color:transparent}
.tk-card-done{opacity:.6}
.tk-card-pri-bar{width:3px;flex-shrink:0}
.tk-card-inner{flex:1;padding:.55rem .65rem;min-width:0}
.tk-card-title{font-size:.84rem;font-weight:500;color:#0f172a;line-height:1.4;margin-bottom:.25rem;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.tk-card-unit{font-size:.68rem;color:#6d28d9;margin-bottom:.25rem;
  display:flex;align-items:center;gap:.2rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tk-card-blocked{color:#b45309;font-weight:600}
.tk-card-tags{display:flex;flex-wrap:wrap;gap:.2rem;margin-bottom:.3rem}
.tk-card-footer{display:flex;justify-content:space-between;align-items:center;gap:.3rem}
.tk-card-due{font-size:.7rem;color:#64748b;display:flex;align-items:center;gap:.2rem;white-space:nowrap}
.tk-card-due.overdue{color:#dc2626;font-weight:600}
.tk-card-st{font-size:.7rem;color:#64748b;display:flex;align-items:center;gap:.2rem;white-space:nowrap}
.tk-card-avstack{display:flex}
.tk-card-asgn{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#dbeafe;color:#1d4ed8;font-size:.62rem;font-weight:700;border:2px solid #fff;flex-shrink:0}
.tk-card-avstack .tk-card-asgn+.tk-card-asgn{margin-left:-5px}
.tk-card-drop-hint{text-align:center;padding:.75rem .5rem;font-size:.75rem;color:#94a3b8;border:1.5px dashed #e2e8f0;border-radius:6px;margin:.25rem 0}
.tk-card-ghost{opacity:.4;background:#eff6ff!important;border-color:#93c5fd!important}
.tk-card-dragging{box-shadow:0 8px 24px rgba(0,0,0,.18);transform:rotate(1.5deg)}
/* Notify prefs modal */
.np-row{display:flex;align-items:center;gap:.75rem;padding:.6rem 0;border-bottom:1px solid #f1f5f9}
.np-row:last-child{border-bottom:none}
.np-icon{font-size:1.1rem;width:1.4rem;text-align:center;flex-shrink:0}
.np-label{flex:1;font-size:.87rem}
.np-label small{display:block;color:#94a3b8;font-size:.73rem;margin-top:.05rem}
</style>
