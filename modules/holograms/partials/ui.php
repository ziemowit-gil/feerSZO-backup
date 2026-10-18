<?php
/**
 * modules/holograms/partials/ui.php — wspólna warstwa wizualna modułu Hologramy, szata Metro UI
 * (jak reszta SZO: includes/szo_metro.php). Zero zaokrągleń, cieni i gradientów, kafle KPI
 * w pełnych kolorach statusów, płaskie szare powierzchnie, pola z ramką 2px, tabele z nagłówkiem
 * wersalikami. Akcent z grupy menu (Finanse → --sm-acc), kolory statusów w jednym miejscu.
 * Zwykły CSS (bez @apply) — nie czeka na Tailwind CDN; selektory od `html body .holo`.
 * Kontrasty tekstu ≥ 4.5:1 (WCAG 2.1 AA); nazwy klas bez zmian (używają ich index/view/logic).
 */
?>
<style>
html body .holo, html body .holo-badge {
  --tz-line: var(--sm-line, #e1e1e1);
  --tz-muted: var(--sm-muted, #5f5f5f);
  --tz-ink: var(--sm-dark, #1d1d1d);
  --tz-light: var(--sm-light, #f2f2f2);
  --tz-light-h: var(--sm-light-h, #e6e6e6);
  --tz-field: var(--sm-field, #8a8a8a);
  --holo-accent: var(--sm-acc, #007a00);
  --holo-accent-d: var(--sm-acc-d, #005700);
  --st-available: #007a00; --st-available-bg: #e5f2e5;
  --st-issued: #0050ef;    --st-issued-bg: #e6eefd;
  --st-returned: #c25100;  --st-returned-bg: #fbeee5;
  --st-damaged: #a20025;   --st-damaged-bg: #f6e5e9;
  --st-lost: #6a00ff;      --st-lost-bg: #f0e5ff;
}
html body .holo { max-width: 1200px; font-family: var(--sm-font, "Segoe UI", system-ui, sans-serif); }
html body .holo *, html body .holo *::before, html body .holo *::after { border-width: 0; border-style: solid; border-radius: 0 !important; box-shadow: none !important; }

/* Nagłówek strony */
html body .holo .dash-h { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .75rem 1rem; margin-bottom: 1.5rem; }
html body .holo .dash-h__eyebrow { font-size: .68rem; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; color: var(--holo-accent-d); margin-bottom: .25rem; }
html body .holo .dash-h h1 { display: flex; align-items: center; gap: .75rem; margin: 0; font-size: 2.25rem; font-weight: 300 !important; letter-spacing: -.02em; line-height: 1.1; color: var(--tz-ink); }
html body .holo .dash-h h1 .dash-h__ico { width: 48px; height: 48px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 1.4rem; color: #fff; background: var(--holo-accent); }
html body .holo .dash-h p { margin: .45rem 0 0; font-size: .88rem; color: var(--tz-muted); }

/* Przyciski — kwadratowe, płaskie */
html body .holo .h-btn { display: inline-flex; align-items: center; justify-content: center; gap: .45rem; height: 40px; padding: 0 1rem; font-size: .88rem; font-weight: 600;
  text-decoration: none; border: 0; cursor: pointer; transition: background-color .1s; white-space: nowrap; }
html body .holo .h-btn:focus-visible { outline: 3px solid #fbbf24; outline-offset: 2px; }
html body .holo .h-btn--primary { background: var(--holo-accent); color: #fff !important; }
html body .holo .h-btn--primary:hover { background: var(--tz-ink); color: #fff !important; }
html body .holo .h-btn--ghost { background: var(--tz-light); color: var(--tz-ink) !important; font-weight: 400; }
html body .holo .h-btn--ghost:hover, html body .holo .h-btn--ghost[aria-expanded="true"] { background: var(--tz-light-h); color: var(--tz-ink) !important; }
html body .holo .h-btn--ghost[aria-expanded="true"] { box-shadow: inset 0 -3px 0 var(--holo-accent) !important; }
html body .holo .h-btn--dark { background: var(--tz-ink); color: #fff !important; }
html body .holo .h-btn--dark:hover { background: #000; color: #fff !important; }
html body .holo .h-btn:disabled { opacity: .45; cursor: not-allowed; }

/* Karty → płaskie grupy z nagłówkiem-pasem */
html body .holo .tz-card { background: #fff; }
html body .holo section.tz-card[id^="panel-"] { background: var(--tz-light); padding: 0 1.25rem; }
html body .holo .tz-card__hd { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; padding: .9rem 0 .55rem; border-bottom: 2px solid var(--tz-ink); color: var(--tz-ink); font-weight: 300; font-size: 1.25rem; }
html body .holo .tz-card__hd > h2 { display: flex; align-items: center; gap: .6rem; margin: 0; font-size: 1.3rem; font-weight: 300; color: var(--tz-ink); }
html body .holo .tz-card__hd > h2 i { width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; font-size: .95rem; background: var(--holo-accent); color: #fff; }
html body .holo .tz-card__bd { padding: 1.1rem 0; }
html body .holo details.tz-card > summary.tz-card__hd { border-bottom-width: 0; }
html body .holo details.tz-card[open] > summary.tz-card__hd { border-bottom-width: 2px; }
html body .holo .tz-card__close { margin-left: auto; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; background: transparent; color: var(--tz-muted); cursor: pointer; }
html body .holo .tz-card__close:hover { background: var(--tz-ink); color: #fff; }
html body .holo .tz-section-h { margin: 1.5rem 0 .5rem; font-size: .66rem; font-weight: 700; text-transform: uppercase; letter-spacing: .14em; color: var(--tz-muted); }

/* KPI → kafle Start w kolorach statusów */
html body .holo .kpi-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .4rem; }
@media (min-width: 640px)  { html body .holo .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (min-width: 1024px) { html body .holo .kpi-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); } }
html body .holo .kpi-tile { --c: var(--holo-accent); position: relative; display: flex; flex-direction: column; min-height: 120px; padding: .8rem 1rem .85rem;
  background: var(--c); color: #fff !important; text-decoration: none; transition: filter .1s; }
html body .holo .kpi-tile:hover, html body .holo .kpi-tile:focus-visible { filter: brightness(.9); color: #fff !important; }
html body .holo .kpi-tile[aria-current="true"] { outline: 4px solid #fbbf24; outline-offset: -4px; }
html body .holo .kpi-tile__ico { order: 1; font-size: 1.15rem; line-height: 1; margin-bottom: auto; color: #fff; background: none; width: auto; height: auto; }
html body .holo .kpi-tile__val { order: 2; margin-top: .75rem; font-size: 2.4rem; font-weight: 300; line-height: 1; color: #fff; }
html body .holo .kpi-tile__lbl { order: 3; margin-top: .3rem; font-size: .7rem; font-weight: 600; letter-spacing: .04em; color: rgba(255,255,255,.88); }
html body .holo .kpi-tile.is-all       { --c: var(--tz-ink); }
html body .holo .kpi-tile.is-available { --c: var(--st-available); }
html body .holo .kpi-tile.is-issued    { --c: var(--st-issued); }
html body .holo .kpi-tile.is-returned  { --c: var(--st-returned); }
html body .holo .kpi-tile.is-damaged   { --c: var(--st-damaged); }
html body .holo .kpi-tile.is-lost      { --c: var(--st-lost); }

/* Pasek struktury puli */
html body .holo .pool-bar { display: flex; height: 6px; overflow: hidden; margin-top: .75rem; background: var(--tz-light-h); }
html body .holo .pool-bar > span { height: 100%; }
html body .holo .pool-bar.is-mini { height: 4px; margin-top: .25rem; min-width: 120px; }
html body .holo .bg-available { background: var(--st-available); }
html body .holo .bg-issued    { background: var(--st-issued); }
html body .holo .bg-returned  { background: var(--st-returned); }
html body .holo .bg-damaged   { background: var(--st-damaged); }
html body .holo .bg-lost      { background: var(--st-lost); }

/* Znacznik statusu — kwadratowy, pełny kolor */
html body .holo-badge { display: inline-flex; align-items: center; gap: .35rem; padding: .18rem .5rem; font-size: .7rem; font-weight: 700; letter-spacing: .03em; white-space: nowrap; color: #fff; border-radius: 0 !important; }
html body .holo-badge__dot { display: none; }
html body .holo-badge.is-available { background: var(--st-available); }
html body .holo-badge.is-issued    { background: var(--st-issued); }
html body .holo-badge.is-returned  { background: var(--st-returned); }
html body .holo-badge.is-damaged   { background: var(--st-damaged); }
html body .holo-badge.is-lost      { background: var(--st-lost); }
html body .holo-badge.is-unknown   { background: var(--tz-muted); }

/* Formularze */
html body .holo .f-label { display: block; margin-bottom: .25rem; font-size: .8rem; font-weight: 600; color: var(--tz-ink); }
html body .holo .f-req { color: var(--st-damaged); }
html body .holo .f-help { margin: .25rem 0 0; font-size: .74rem; color: var(--tz-muted); }
html body .holo .f-input { width: 100%; height: 40px; padding: 0 .75rem; font-size: .88rem; background: #fff; color: var(--tz-ink); border: 2px solid var(--tz-field); outline: none; }
html body .holo textarea.f-input { height: auto; padding: .5rem .75rem; }
html body .holo .f-input:focus { border-color: var(--holo-accent); }
html body .holo .f-input.is-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: -.01em; }
html body .holo .f-seg { display: inline-flex; gap: 2px; margin: 0; padding: 0; background: none; }
html body .holo .f-seg label { display: flex; align-items: center; gap: .4rem; height: 36px; padding: 0 .9rem; font-size: .85rem; cursor: pointer; background: #fff; color: var(--tz-ink); }
html body .holo .f-seg input { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; }
html body .holo .f-seg label:hover { background: var(--tz-light-h); }
html body .holo .f-seg label:has(input:checked) { background: var(--holo-accent); color: #fff; font-weight: 600; }
html body .holo .f-seg label:has(input:focus-visible) { outline: 3px solid #fbbf24; outline-offset: 1px; }
html body .holo .alert-err { display: flex; gap: .5rem; margin-bottom: 1rem; padding: .75rem 1rem; font-size: .86rem; background: #fff; color: var(--tz-ink); border-left: 6px solid var(--st-damaged); }
html body .holo .alert-err > i { color: var(--st-damaged); }

/* Podgląd numerów serii */
html body .holo .preview { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; padding: .7rem .9rem; font-size: .86rem; background: #fff; color: var(--tz-ink); border-left: 6px solid var(--st-issued); }
html body .holo .preview.is-bad { border-left-color: var(--st-damaged); }
html body .holo .chip { display: inline-flex; align-items: center; padding: .12rem .45rem; font-family: ui-monospace, Menlo, monospace; font-size: .75rem; background: var(--tz-light); color: var(--tz-ink); }
html body .holo .count-pill { display: inline-flex; align-items: center; padding: .15rem .55rem; font-size: .72rem; font-weight: 700; color: #fff; background: var(--holo-accent); }

/* Filtry → płaskie przełączniki */
html body .holo .pill { display: inline-flex; align-items: center; gap: .4rem; height: 32px; padding: 0 .8rem; font-size: .82rem; text-decoration: none; background: var(--tz-light); color: var(--tz-ink) !important; }
html body .holo .pill:hover { background: var(--tz-light-h); }
html body .holo .pill[aria-current="true"] { background: var(--tz-ink); color: #fff !important; font-weight: 600; }
html body .holo .pill__n { font-size: .72rem; opacity: .75; }

/* Tabela */
html body .holo .h-table { width: 100%; font-size: .86rem; border-collapse: collapse; }
html body .holo .h-table thead th { padding: .6rem 1rem; text-align: left; font-size: .66rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: var(--tz-muted); background: #fff; border-bottom: 2px solid var(--tz-ink); }
html body .holo .h-table td { padding: .6rem 1rem; vertical-align: middle; color: var(--tz-ink); border-bottom: 1px solid var(--tz-line); }
html body .holo .h-table tbody tr:hover td { background: var(--tz-light); }
html body .holo .h-table tbody tr.is-selected td { background: #fff7d6; }
html body .holo .h-table tbody tr.is-selected td:first-child { box-shadow: inset 4px 0 0 var(--holo-accent) !important; }
html body .holo .h-table .num { font-family: ui-monospace, Menlo, monospace; font-weight: 600; white-space: nowrap; text-decoration: none; color: var(--tz-ink) !important; }
html body .holo .h-table .num:hover { color: var(--holo-accent-d) !important; text-decoration: underline; }
html body .holo .h-table .muted { color: var(--tz-muted); }
html body .holo .h-table .sub { display: block; font-size: .74rem; color: var(--tz-muted); }
html body .holo .h-check { width: 18px; height: 18px; cursor: pointer; accent-color: var(--holo-accent); }

/* Pasek akcji zbiorczych — ciemny pas przyklejony do dołu okna */
html body .holo .bulk-bar { position: sticky; bottom: 0; z-index: 10; display: flex; flex-wrap: wrap; align-items: flex-end; gap: .75rem; margin: 0; padding: .75rem 1rem; color: #fff; background: var(--tz-ink); }
html body .holo .bulk-bar .f-label { color: rgba(255,255,255,.85); }
html body .holo .bulk-bar .f-input { border-color: #fff; }
html body .holo .bulk-bar__count { display: flex; align-items: center; gap: .5rem; margin-right: auto; align-self: center; font-size: .88rem; }
html body .holo .bulk-bar__count strong { display: inline-flex; min-width: 2rem; justify-content: center; padding: .1rem .5rem; font-size: 1.25rem; font-weight: 300; background: var(--holo-accent); color: #fff; }
html body .holo .bulk-bar .h-btn--primary { background: var(--holo-accent); color: #fff !important; }
html body .holo .bulk-bar .h-btn--primary:hover { background: #fff; color: var(--tz-ink) !important; }
html body .holo .bulk-bar .link-btn { align-self: center; background: transparent; font-size: .85rem; text-decoration: underline; color: rgba(255,255,255,.8); cursor: pointer; }

/* Pusty stan */
html body .holo .empty { padding: 3rem 1rem; text-align: center; color: var(--tz-muted); }
html body .holo .empty i { display: block; margin-bottom: .5rem; font-size: 2.5rem; color: var(--tz-field); }

/* Oś czasu (karta hologramu) — kwadratowe znaczniki */
html body .holo .tl { list-style: none; margin: 0; padding: 0; }
html body .holo .tl li { position: relative; padding: 0 0 1.25rem 2.5rem; }
html body .holo .tl li:last-child { padding-bottom: 0; }
html body .holo .tl li::before { content: ''; position: absolute; left: 14px; top: 30px; bottom: 0; width: 2px; background: var(--tz-line); }
html body .holo .tl li:last-child::before { display: none; }
html body .holo .tl__ico { position: absolute; left: 0; top: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-size: .78rem; color: #fff; background: var(--tz-muted); }
html body .holo .tl__ico.is-available, html body .holo .tl__ico.is-created { background: var(--st-available); }
html body .holo .tl__ico.is-issued   { background: var(--st-issued); }
html body .holo .tl__ico.is-returned { background: var(--st-returned); }
html body .holo .tl__ico.is-damaged  { background: var(--st-damaged); }
html body .holo .tl__ico.is-lost     { background: var(--st-lost); }
html body .holo .facts { display: grid; grid-template-columns: 1fr; gap: .9rem 1.5rem; margin: 0; }
@media (min-width: 640px) { html body .holo .facts { grid-template-columns: 1fr 1fr; } }
html body .holo .facts dt { font-size: .64rem; font-weight: 700; text-transform: uppercase; letter-spacing: .12em; color: var(--tz-muted); }
html body .holo .facts dd { margin: .15rem 0 0; font-size: .92rem; color: var(--tz-ink); }

@media (max-width: 640px) { html body .holo .dash-h h1 { font-size: 1.7rem; } }
@media (prefers-reduced-motion: reduce) { html body .holo .kpi-tile, html body .holo .h-btn { transition: none; } }
</style>
