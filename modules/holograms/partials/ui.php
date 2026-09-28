<?php
/**
 * modules/holograms/partials/ui.php — wspólna warstwa wizualna modułu Hologramy.
 * Język wizualny jak dashboard zadań (tasks/dashboard.php): karty .tz-card,
 * kafelki KPI z kolorową ikoną, nagłówki sekcji. Kolory statusów w jednym miejscu.
 * Kontrasty tekstu ≥ 4.5:1 (WCAG 2.1 AA).
 */
?>
<style type="text/tailwindcss">
.holo {
  --tz-line: #E5E9F0;
  --tz-muted: #5b6472;
  --tz-ink: #111827;
  --holo-accent: #1d4ed8;
  --st-available: #047857; --st-available-bg: #ecfdf5;
  --st-issued: #1d4ed8;    --st-issued-bg: #eff6ff;
  --st-returned: #b45309;  --st-returned-bg: #fffbeb;
  --st-damaged: #b91c1c;   --st-damaged-bg: #fef2f2;
  --st-lost: #6d28d9;      --st-lost-bg: #f5f3ff;
  @apply tw-max-w-[1200px];
}
/* Preflight Tailwinda jest wyłączony (Bootstrap), więc tw-border nie miałby stylu
   linii — zawężony odpowiednik resetu tylko dla modułu. */
.holo *, .holo *::before, .holo *::after { border-width: 0; border-style: solid; }

/* Nagłówek strony */
.holo .dash-h { @apply tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-3 tw-mb-5; }
.holo .dash-h h1 { @apply tw-text-2xl tw-font-extrabold tw-tracking-[-.01em] tw-m-0 tw-leading-[1.2] tw-flex tw-items-center tw-gap-2; color: var(--tz-ink); }
.holo .dash-h h1 .dash-h__ico { @apply tw-w-9 tw-h-9 tw-rounded-[10px] tw-inline-flex tw-items-center tw-justify-center tw-text-white tw-text-lg; background: var(--holo-accent); }
.holo .dash-h p { @apply tw-mt-1 tw-mb-0 tw-text-[.9rem]; color: var(--tz-muted); }

/* Przyciski */
.holo .h-btn { @apply tw-inline-flex tw-items-center tw-justify-center tw-gap-1.5 tw-rounded-[10px] tw-px-4 tw-py-2 tw-text-sm tw-font-semibold tw-no-underline tw-border tw-transition-colors tw-cursor-pointer; }
.holo .h-btn:focus-visible { @apply tw-outline tw-outline-2 tw-outline-offset-2; outline-color: var(--holo-accent); }
.holo .h-btn--primary { @apply tw-text-white; background: var(--holo-accent); border-color: var(--holo-accent); }
.holo .h-btn--primary:hover { background: #1e40af; color: #fff; }
.holo .h-btn--ghost { @apply tw-bg-white; border-color: #cbd5e1; color: var(--tz-ink); }
.holo .h-btn--ghost:hover, .holo .h-btn--ghost[aria-expanded="true"] { background: #f1f5f9; color: var(--tz-ink); }
.holo .h-btn--dark { @apply tw-text-white; background: #0f172a; border-color: #0f172a; }
.holo .h-btn--dark:hover { background: #1e293b; color: #fff; }
.holo .h-btn:disabled { @apply tw-opacity-50 tw-cursor-not-allowed; }

/* Karty */
.holo .tz-card { @apply tw-bg-white tw-border tw-rounded-2xl; border-color: var(--tz-line); box-shadow: 0 1px 3px rgba(16,24,40,.08); }
.holo .tz-card__hd { @apply tw-py-[.9rem] tw-px-[1.15rem] tw-border-b tw-flex tw-flex-wrap tw-items-center tw-gap-[.6rem] tw-font-bold tw-text-[.95rem]; border-color: var(--tz-line); color: var(--tz-ink); }
.holo .tz-card__hd > h2 { @apply tw-text-[.95rem] tw-font-bold tw-m-0 tw-flex tw-items-center tw-gap-2; }
.holo .tz-card__hd > h2 i { color: var(--holo-accent); }
.holo .tz-card__bd { @apply tw-p-[1.15rem]; }
.holo .tz-card__close { @apply tw-ml-auto tw-w-8 tw-h-8 tw-rounded-lg tw-inline-flex tw-items-center tw-justify-center tw-border-0 tw-bg-transparent tw-cursor-pointer; color: var(--tz-muted); }
.holo .tz-card__close:hover { background: #f1f5f9; color: var(--tz-ink); }
.holo .tz-section-h { @apply tw-text-[.78rem] tw-font-bold tw-uppercase tw-tracking-[.05em] tw-mt-6 tw-mb-2; color: var(--tz-muted); }

/* KPI */
.holo .kpi-grid { @apply tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 lg:tw-grid-cols-6 tw-gap-3; }
.holo .kpi-tile {
  @apply tw-relative tw-flex tw-flex-col tw-gap-[.15rem] tw-bg-white tw-border tw-rounded-2xl tw-py-4 tw-px-[1.05rem] tw-no-underline tw-min-h-[104px] tw-transition-all;
  border-color: var(--tz-line); color: inherit; --c: var(--holo-accent);
}
.holo .kpi-tile:hover, .holo .kpi-tile:focus-visible { transform: translateY(-2px); border-color: var(--c); box-shadow: 0 8px 24px -8px rgba(15,23,42,.25); color: inherit; }
.holo .kpi-tile[aria-current="true"] { border-color: var(--c); box-shadow: inset 0 0 0 1px var(--c); }
.holo .kpi-tile__ico { @apply tw-w-[38px] tw-h-[38px] tw-rounded-[10px] tw-text-white tw-flex tw-items-center tw-justify-center tw-text-[1.05rem] tw-mb-2; background: var(--c); }
.holo .kpi-tile__val { @apply tw-text-[1.7rem] tw-font-extrabold tw-leading-none; color: var(--tz-ink); }
.holo .kpi-tile__lbl { @apply tw-text-[.8rem] tw-font-medium; color: var(--tz-muted); }
.holo .kpi-tile.is-all       { --c: #334155; }
.holo .kpi-tile.is-available { --c: var(--st-available); }
.holo .kpi-tile.is-issued    { --c: var(--st-issued); }
.holo .kpi-tile.is-returned  { --c: var(--st-returned); }
.holo .kpi-tile.is-damaged   { --c: var(--st-damaged); }
.holo .kpi-tile.is-lost      { --c: var(--st-lost); }

/* Pasek struktury puli */
.holo .pool-bar { @apply tw-flex tw-h-2.5 tw-rounded-full tw-overflow-hidden tw-mt-3; background: var(--tz-line); }
.holo .pool-bar > span { @apply tw-h-full; }
.holo .pool-bar.is-mini { @apply tw-h-1.5 tw-mt-1 tw-min-w-[120px]; }
.holo .bg-available { background: var(--st-available); }
.holo .bg-issued    { background: var(--st-issued); }
.holo .bg-returned  { background: var(--st-returned); }
.holo .bg-damaged   { background: var(--st-damaged); }
.holo .bg-lost      { background: var(--st-lost); }

/* Znacznik statusu */
.holo-badge { @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-full tw-px-2.5 tw-py-0.5 tw-text-xs tw-font-semibold tw-whitespace-nowrap; }
.holo-badge__dot { @apply tw-w-1.5 tw-h-1.5 tw-rounded-full; background: currentColor; }
.holo-badge.is-available { color: var(--st-available); background: var(--st-available-bg); }
.holo-badge.is-issued    { color: var(--st-issued);    background: var(--st-issued-bg); }
.holo-badge.is-returned  { color: var(--st-returned);  background: var(--st-returned-bg); }
.holo-badge.is-damaged   { color: var(--st-damaged);   background: var(--st-damaged-bg); }
.holo-badge.is-lost      { color: var(--st-lost);      background: var(--st-lost-bg); }
.holo-badge.is-unknown   { color: #334155; background: #f1f5f9; }

/* Formularze */
.holo .f-label { @apply tw-block tw-text-[.82rem] tw-font-semibold tw-mb-1; color: var(--tz-ink); }
.holo .f-req { color: var(--st-damaged); }
.holo .f-help { @apply tw-text-xs tw-mt-1 tw-mb-0; color: var(--tz-muted); }
.holo .f-input { @apply tw-w-full tw-rounded-[10px] tw-border tw-bg-white tw-px-3 tw-py-2 tw-text-sm tw-outline-none tw-transition-shadow; border-color: #cbd5e1; color: var(--tz-ink); }
.holo .f-input:focus { border-color: var(--holo-accent); box-shadow: 0 0 0 3px rgba(29,78,216,.18); }
.holo .f-input.is-mono { @apply tw-font-mono tw-tracking-tight; }
.holo .f-seg { @apply tw-inline-flex tw-rounded-[10px] tw-border tw-p-0.5 tw-gap-0.5 tw-m-0; border-color: #cbd5e1; background: #f8fafc; }
.holo .f-seg label { @apply tw-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-px-3 tw-py-1.5 tw-text-sm tw-font-medium tw-cursor-pointer; color: var(--tz-muted); }
.holo .f-seg input { @apply tw-sr-only; }
.holo .f-seg label:has(input:checked) { @apply tw-bg-white tw-shadow-sm; color: var(--tz-ink); }
.holo .f-seg label:has(input:focus-visible) { @apply tw-outline tw-outline-2; outline-color: var(--holo-accent); }
.holo .alert-err { @apply tw-mb-4 tw-flex tw-gap-2 tw-rounded-xl tw-border tw-p-3 tw-text-sm; border-color: #fca5a5; background: var(--st-damaged-bg); color: #991b1b; }

/* Podgląd numerów serii */
.holo .preview { @apply tw-flex tw-flex-wrap tw-items-center tw-gap-2 tw-rounded-xl tw-border tw-border-dashed tw-p-3 tw-text-sm; border-color: #93c5fd; background: #f8fbff; color: var(--tz-ink); }
.holo .preview.is-bad { border-color: #fca5a5; background: var(--st-damaged-bg); color: #991b1b; }
.holo .chip { @apply tw-inline-flex tw-items-center tw-rounded-md tw-border tw-bg-white tw-px-2 tw-py-0.5 tw-font-mono tw-text-xs; border-color: #bfdbfe; }
.holo .count-pill { @apply tw-inline-flex tw-items-center tw-rounded-full tw-px-2.5 tw-py-0.5 tw-text-xs tw-font-bold tw-text-white; background: var(--holo-accent); }

/* Filtry-pigułki */
.holo .pill { @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-full tw-border tw-bg-white tw-px-3 tw-py-1 tw-text-[.82rem] tw-font-medium tw-no-underline; border-color: var(--tz-line); color: var(--tz-muted); }
.holo .pill:hover { border-color: #94a3b8; color: var(--tz-ink); }
.holo .pill[aria-current="true"] { @apply tw-text-white; background: #0f172a; border-color: #0f172a; }
.holo .pill__n { @apply tw-text-[.72rem] tw-opacity-80; }

/* Tabela */
.holo .h-table { @apply tw-w-full tw-text-sm tw-border-collapse; }
.holo .h-table thead th { @apply tw-text-left tw-text-[.72rem] tw-font-bold tw-uppercase tw-tracking-[.04em] tw-px-4 tw-py-2.5 tw-border-b; background: #f8fafc; color: var(--tz-muted); border-color: var(--tz-line); }
.holo .h-table td { @apply tw-px-4 tw-py-2.5 tw-border-b tw-align-middle; border-color: #f1f5f9; color: var(--tz-ink); }
.holo .h-table tbody tr:hover td { background: #f8fafc; }
.holo .h-table tbody tr.is-selected td { background: #eff6ff; }
.holo .h-table .num { @apply tw-font-mono tw-font-semibold tw-no-underline tw-whitespace-nowrap; color: var(--holo-accent); }
.holo .h-table .num:hover { @apply tw-underline; }
.holo .h-table .muted { color: #94a3b8; }
.holo .h-table .sub { @apply tw-block tw-text-xs; color: var(--tz-muted); }
.holo .h-check { @apply tw-h-4 tw-w-4 tw-rounded tw-cursor-pointer; accent-color: var(--holo-accent); }

/* Pasek akcji zbiorczych — przyklejony do dołu okna */
.holo .bulk-bar { @apply tw-sticky tw-bottom-4 tw-z-10 tw-mx-3 tw-mb-3 tw-flex tw-flex-wrap tw-items-end tw-gap-3 tw-rounded-2xl tw-p-3 tw-text-white; background: #0f172a; box-shadow: 0 12px 32px -8px rgba(15,23,42,.45); }
.holo .bulk-bar .f-label { @apply tw-text-white; }
.holo .bulk-bar .f-input { border-color: #334155; }
.holo .bulk-bar__count { @apply tw-flex tw-items-center tw-gap-2 tw-text-sm tw-mr-auto tw-self-center; }
.holo .bulk-bar__count strong { @apply tw-inline-flex tw-min-w-[1.75rem] tw-justify-center tw-rounded-full tw-bg-white tw-px-2 tw-py-0.5 tw-text-xs; color: #0f172a; }
.holo .bulk-bar .h-btn--primary { background: #fff; border-color: #fff; color: #0f172a; }
.holo .bulk-bar .h-btn--primary:hover { background: #e2e8f0; color: #0f172a; }
.holo .bulk-bar .link-btn { @apply tw-bg-transparent tw-border-0 tw-text-sm tw-underline tw-cursor-pointer tw-self-center; color: #cbd5e1; }

/* Pusty stan */
.holo .empty { @apply tw-text-center tw-py-12 tw-px-4; color: var(--tz-muted); }
.holo .empty i { @apply tw-text-4xl tw-block tw-mb-2; color: #cbd5e1; }

/* Oś czasu (karta hologramu) */
.holo .tl { @apply tw-list-none tw-m-0 tw-p-0; }
.holo .tl li { @apply tw-relative tw-pl-9 tw-pb-5; }
.holo .tl li:last-child { @apply tw-pb-0; }
.holo .tl li::before { content: ''; @apply tw-absolute tw-left-[13px] tw-top-7 tw-bottom-0 tw-w-px; background: var(--tz-line); }
.holo .tl li:last-child::before { display: none; }
.holo .tl__ico { @apply tw-absolute tw-left-0 tw-top-0 tw-w-7 tw-h-7 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-text-xs tw-text-white; background: #64748b; }
.holo .tl__ico.is-available, .holo .tl__ico.is-created { background: var(--st-available); }
.holo .tl__ico.is-issued   { background: var(--st-issued); }
.holo .tl__ico.is-returned { background: var(--st-returned); }
.holo .tl__ico.is-damaged  { background: var(--st-damaged); }
.holo .tl__ico.is-lost     { background: var(--st-lost); }
.holo .facts { @apply tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-x-6 tw-gap-y-3 tw-m-0; }
.holo .facts dt { @apply tw-text-[.72rem] tw-font-bold tw-uppercase tw-tracking-[.04em]; color: var(--tz-muted); }
.holo .facts dd { @apply tw-m-0 tw-text-sm; color: var(--tz-ink); }

@media (prefers-reduced-motion: reduce) {
  .holo .kpi-tile, .holo .kpi-tile:hover { transition: none; transform: none; }
}
</style>
