<?php
/**
 * modules/smart_cards/partials/ui.php — warstwa wizualna modułu Karty dostępu.
 *
 * Karta w stylu płatniczym (.sc-card) jest w czystym CSS sterowanym zmiennymi
 * (--sc-from, --sc-to, --sc-angle, --sc-text, --sc-accent) i klasami wzoru/chipu,
 * więc ten sam wygląd daje render po stronie PHP (scard_render) i podgląd na żywo
 * w Alpine (scardVars) — bez klas Tailwinda dokładanych dynamicznie, których CDN
 * by nie wygenerował. Reszta: język wizualny jak moduł Hologramy.
 */

/** Atrybut style ze zmiennymi wyglądu karty. */
function scard_vars(array $d): string {
    return sprintf('--sc-from:%s;--sc-to:%s;--sc-angle:%ddeg;--sc-text:%s;--sc-accent:%s',
        $d['bg_from'], $d['bg_to'], (int)$d['angle'], $d['text'], $d['accent']);
}

function scard_status_badge(string $status): string {
    $label = SCARD_STATUSES[$status]['label'] ?? SCARD_APP_STATUSES[$status]['label'] ?? $status;
    return '<span class="sc-badge is-' . h($status) . '"><span class="sc-badge__dot" aria-hidden="true"></span>' . h($label) . '</span>';
}

/**
 * Karta: $info = [holder, uid, expires_at, status, template].
 * Stan inny niż aktywny — ukośna wstęga; opis dla czytników ekranu w aria-label.
 */
function scard_render(array $design, array $info, string $size = ''): string {
    $holder  = mb_strtoupper((string)($info['holder'] ?? ''));
    $uid     = (string)($info['uid'] ?? '');
    $exp     = !empty($info['expires_at']) ? date('m/y', strtotime($info['expires_at'])) : '--/--';
    $status  = (string)($info['status'] ?? 'active');
    $aria    = 'Karta ' . ($info['template'] ?? '') . ', posiadacz ' . ($info['holder'] ?? '—') . ', UID ' . ($uid ? scard_uid_display($uid) : 'brak')
             . ', ważna do ' . $exp . ', status: ' . (SCARD_STATUSES[$status]['label'] ?? $status);
    ob_start(); ?>
<div class="sc-card pat-<?= h($design['pattern']) ?> chip-<?= h($design['chip']) ?><?= $size ? ' is-' . h($size) : '' ?><?= $status !== 'active' ? ' is-inactive' : '' ?>"
     style="<?= h(scard_vars($design)) ?>" role="img" aria-label="<?= h($aria) ?>">
  <div class="sc-card__top">
    <span class="sc-card__label"><?= h($design['label']) ?></span>
    <svg class="sc-card__nfc" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 7.5a6.5 6.5 0 0 1 0 9M12 5a10 10 0 0 1 0 14M15.5 2.5a13.5 13.5 0 0 1 0 19M5 10a3 3 0 0 1 0 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
  </div>
  <div class="sc-card__chip" aria-hidden="true"><span></span></div>
  <div class="sc-card__uid"><?= h($uid ? scard_uid_display($uid) : '•••• •••• •••• ••') ?></div>
  <div class="sc-card__bottom">
    <div><span class="sc-card__cap">Posiadacz</span><span class="sc-card__holder"><?= h($holder ?: '—') ?></span></div>
    <div style="text-align:right"><span class="sc-card__cap">Ważna do</span><span class="sc-card__exp"><?= h($exp) ?></span></div>
  </div>
  <?php if ($status !== 'active'): ?><span class="sc-card__ribbon" aria-hidden="true"><?= h(mb_strtoupper(SCARD_STATUSES[$status]['label'] ?? $status)) ?></span><?php endif; ?>
</div>
<?php return (string)ob_get_clean();
}

/** Zakładki modułu. */
function scard_tabs(string $active, int $pending = 0): void {
    $tabs = [
        'cards'        => ['Karty',            'index.php',        'bi-credit-card-2-front'],
        'applications' => ['Wnioski',          'applications.php', 'bi-inbox'],
        'zones'        => ['Strefy dostępu',   'zones.php',        'bi-door-closed'],
        'templates'    => ['Wzory kart',       'templates.php',    'bi-palette'],
        'nfc'          => ['Programator NFC',  'nfc/index.php',    'bi-broadcast'],
    ];
    echo '<nav class="sc-tabs" aria-label="Sekcje modułu Karty dostępu">';
    foreach ($tabs as $k => [$lbl, $url, $ico]) {
        echo '<a href="' . h(APP_URL . '/modules/smart_cards/' . $url) . '"' . ($k === $active ? ' aria-current="page"' : '') . '>'
           . '<i class="bi ' . $ico . '" aria-hidden="true"></i> ' . h($lbl)
           . ($k === 'applications' && $pending ? ' <span class="sc-tabs__n" aria-label="oczekujących: ' . $pending . '">' . $pending . '</span>' : '')
           . '</a>';
    }
    echo '</nav>';
}
?>
<style type="text/tailwindcss">
.scm {
  --tz-line: #E5E9F0; --tz-muted: #5b6472; --tz-ink: #111827; --sc-brand: #1d4ed8;
  --st-active: #047857;  --st-active-bg: #ecfdf5;
  --st-blocked: #b45309; --st-blocked-bg: #fffbeb;
  --st-expired: #475569; --st-expired-bg: #f1f5f9;
  --st-lost: #b91c1c;    --st-lost-bg: #fef2f2;
  --st-pending: #1d4ed8; --st-pending-bg: #eff6ff;
  --st-approved: #047857; --st-approved-bg: #ecfdf5;
  --st-rejected: #b91c1c; --st-rejected-bg: #fef2f2;
  @apply tw-max-w-[1200px];
  color: var(--tz-ink);
}
.scm *, .scm *::before, .scm *::after { border-width: 0; border-style: solid; }
.scm [x-cloak] { display: none !important; }

.scm .dash-h { @apply tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-3 tw-mb-4; }
.scm .dash-h h1 { @apply tw-text-2xl tw-font-extrabold tw-m-0 tw-flex tw-items-center tw-gap-2 tw-leading-tight; }
.scm .dash-h__ico { @apply tw-w-9 tw-h-9 tw-rounded-[10px] tw-inline-flex tw-items-center tw-justify-center tw-text-white tw-text-lg; background: linear-gradient(135deg, #1e3a8a, #0f172a); }
.scm .dash-h p { @apply tw-mt-1 tw-mb-0 tw-text-[.9rem]; color: var(--tz-muted); }

.scm .sc-tabs { @apply tw-flex tw-flex-wrap tw-gap-1 tw-mb-5 tw-border-b; border-color: var(--tz-line); }
.scm .sc-tabs a { @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-px-3.5 tw-py-2.5 tw-text-sm tw-font-semibold tw-no-underline tw-border-b-2 -tw-mb-px; border-color: transparent; color: var(--tz-muted); }
.scm .sc-tabs a:hover { color: var(--tz-ink); }
.scm .sc-tabs a[aria-current="page"] { border-color: var(--sc-brand); color: var(--sc-brand); }
.scm .sc-tabs__n { @apply tw-inline-flex tw-min-w-[1.25rem] tw-justify-center tw-rounded-full tw-px-1.5 tw-text-[.7rem] tw-text-white; background: #b91c1c; }

.scm .h-btn { @apply tw-inline-flex tw-items-center tw-justify-center tw-gap-1.5 tw-rounded-[10px] tw-px-4 tw-py-2 tw-text-sm tw-font-semibold tw-no-underline tw-border tw-cursor-pointer tw-transition-colors; }
.scm .h-btn:focus-visible { @apply tw-outline tw-outline-2 tw-outline-offset-2; outline-color: var(--sc-brand); }
.scm .h-btn--primary { @apply tw-text-white; background: var(--sc-brand); border-color: var(--sc-brand); }
.scm .h-btn--primary:hover { background: #1e40af; color: #fff; }
.scm .h-btn--ghost { @apply tw-bg-white; border-color: #cbd5e1; color: var(--tz-ink); }
.scm .h-btn--ghost:hover { background: #f1f5f9; color: var(--tz-ink); }
.scm .h-btn--danger { @apply tw-text-white; background: #b91c1c; border-color: #b91c1c; }
.scm .h-btn--danger:hover { background: #991b1b; color: #fff; }
.scm .h-btn--warn { @apply tw-text-white; background: #b45309; border-color: #b45309; }
.scm .h-btn--sm { @apply tw-px-2.5 tw-py-1 tw-text-xs; }
.scm .h-btn:disabled { @apply tw-opacity-50 tw-cursor-not-allowed; }

.scm .tz-card { @apply tw-bg-white tw-border tw-rounded-2xl; border-color: var(--tz-line); box-shadow: 0 1px 3px rgba(16,24,40,.08); }
.scm .tz-card__hd { @apply tw-py-3.5 tw-px-5 tw-border-b tw-flex tw-flex-wrap tw-items-center tw-gap-2; border-color: var(--tz-line); }
.scm .tz-card__hd h2 { @apply tw-text-[.95rem] tw-font-bold tw-m-0 tw-flex tw-items-center tw-gap-2; }
.scm .tz-card__hd h2 i { color: var(--sc-brand); }
.scm .tz-card__bd { @apply tw-p-5; }

.scm .kpi-grid { @apply tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 lg:tw-grid-cols-6 tw-gap-3 tw-mb-5; }
.scm .kpi-tile { @apply tw-flex tw-flex-col tw-gap-0.5 tw-bg-white tw-border tw-rounded-2xl tw-py-4 tw-px-4 tw-no-underline tw-transition-all; border-color: var(--tz-line); color: inherit; --c: var(--sc-brand); }
.scm .kpi-tile:hover, .scm .kpi-tile:focus-visible { transform: translateY(-2px); border-color: var(--c); color: inherit; }
.scm .kpi-tile[aria-current="true"] { border-color: var(--c); box-shadow: inset 0 0 0 1px var(--c); }
.scm .kpi-tile__ico { @apply tw-w-9 tw-h-9 tw-rounded-[10px] tw-text-white tw-flex tw-items-center tw-justify-center tw-mb-2; background: var(--c); }
.scm .kpi-tile__val { @apply tw-text-[1.6rem] tw-font-extrabold tw-leading-none; }
.scm .kpi-tile__lbl { @apply tw-text-[.8rem] tw-font-medium; color: var(--tz-muted); }
.scm .kpi-tile.is-all { --c: #334155; } .scm .kpi-tile.is-active { --c: var(--st-active); } .scm .kpi-tile.is-blocked { --c: var(--st-blocked); }
.scm .kpi-tile.is-expired { --c: var(--st-expired); } .scm .kpi-tile.is-lost { --c: var(--st-lost); } .scm .kpi-tile.is-pending { --c: var(--st-pending); }

.sc-badge { @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-full tw-px-2.5 tw-py-0.5 tw-text-xs tw-font-semibold tw-whitespace-nowrap; color: #334155; background: #f1f5f9; }
.sc-badge__dot { @apply tw-w-1.5 tw-h-1.5 tw-rounded-full; background: currentColor; }
.sc-badge.is-active, .sc-badge.is-approved { color: var(--st-active); background: var(--st-active-bg); }
.sc-badge.is-blocked { color: var(--st-blocked); background: var(--st-blocked-bg); }
.sc-badge.is-expired { color: var(--st-expired); background: var(--st-expired-bg); }
.sc-badge.is-lost, .sc-badge.is-rejected { color: var(--st-lost); background: var(--st-lost-bg); }
.sc-badge.is-pending { color: var(--st-pending); background: var(--st-pending-bg); }
.sc-lvl { @apply tw-inline-flex tw-items-center tw-gap-1 tw-rounded-md tw-px-2 tw-py-0.5 tw-text-xs tw-font-bold; }
.sc-lvl.l1 { background: #ecfdf5; color: #047857; } .sc-lvl.l2 { background: #eff6ff; color: #1d4ed8; } .sc-lvl.l3 { background: #fffbeb; color: #b45309; }
.sc-lvl.l4 { background: #fff7ed; color: #c2410c; } .sc-lvl.l5 { background: #fef2f2; color: #b91c1c; }

.scm .f-label { @apply tw-block tw-text-[.82rem] tw-font-semibold tw-mb-1; }
.scm .f-help { @apply tw-text-xs tw-mt-1 tw-mb-0; color: var(--tz-muted); }
.scm .f-input { @apply tw-w-full tw-rounded-[10px] tw-border tw-bg-white tw-px-3 tw-py-2 tw-text-sm tw-outline-none; border-color: #cbd5e1; color: var(--tz-ink); }
.scm .f-input:focus { border-color: var(--sc-brand); box-shadow: 0 0 0 3px rgba(29,78,216,.18); }
.scm .f-input.is-mono { @apply tw-font-mono; }
.scm .f-check { @apply tw-flex tw-items-start tw-gap-2 tw-text-sm tw-cursor-pointer; }
.scm .f-check input { @apply tw-mt-0.5 tw-h-4 tw-w-4; accent-color: var(--sc-brand); }
.scm .zone-opt { @apply tw-rounded-lg tw-border tw-p-2; border-color: var(--tz-line); }
.scm .alert-err { @apply tw-mb-4 tw-flex tw-gap-2 tw-rounded-xl tw-border tw-p-3 tw-text-sm; border-color: #fca5a5; background: #fef2f2; color: #991b1b; }
.scm .alert-info { @apply tw-mb-4 tw-flex tw-gap-2 tw-rounded-xl tw-border tw-p-3 tw-text-sm; border-color: #bfdbfe; background: #eff6ff; color: #1e3a8a; }

.scm .h-table { @apply tw-w-full tw-text-sm tw-border-collapse; }
.scm .h-table th { @apply tw-text-left tw-text-[.72rem] tw-font-bold tw-uppercase tw-tracking-wide tw-px-4 tw-py-2.5 tw-border-b; background: #f8fafc; color: var(--tz-muted); border-color: var(--tz-line); }
.scm .h-table td { @apply tw-px-4 tw-py-3 tw-border-b tw-align-middle; border-color: #f1f5f9; }
.scm .h-table tbody tr:hover td { background: #f8fafc; }
.scm .sub { @apply tw-block tw-text-xs; color: var(--tz-muted); }
.scm .mono { @apply tw-font-mono tw-text-[.8rem]; }
.scm .empty { @apply tw-text-center tw-py-12 tw-px-4 tw-m-0; color: var(--tz-muted); }
.scm .empty i { @apply tw-text-4xl tw-block tw-mb-2; color: #cbd5e1; }

/* Modal (Alpine) */
.scm .sc-modal { @apply tw-fixed tw-inset-0 tw-z-[1060] tw-flex tw-items-start sm:tw-items-center tw-justify-center tw-p-4 tw-overflow-y-auto; background: rgba(15,23,42,.55); }
.scm .sc-modal__box { @apply tw-bg-white tw-rounded-2xl tw-w-full tw-max-w-[720px] tw-shadow-2xl tw-my-8; }
.scm .sc-modal__hd { @apply tw-flex tw-items-center tw-gap-2 tw-px-5 tw-py-4 tw-border-b; border-color: var(--tz-line); }
.scm .sc-modal__hd h2 { @apply tw-text-base tw-font-bold tw-m-0; }
.scm .sc-modal__x { @apply tw-ml-auto tw-w-8 tw-h-8 tw-rounded-lg tw-border-0 tw-bg-transparent tw-cursor-pointer; color: var(--tz-muted); }
.scm .sc-modal__x:hover { background: #f1f5f9; }
.scm .sc-modal__bd { @apply tw-p-5; }
.scm .sc-modal__ft { @apply tw-flex tw-justify-end tw-gap-2 tw-px-5 tw-py-4 tw-border-t; border-color: var(--tz-line); }

/* Oś czasu */
.scm .tl { @apply tw-list-none tw-m-0 tw-p-0; }
.scm .tl li { @apply tw-relative tw-pl-8 tw-pb-4; }
.scm .tl li::before { content: ''; @apply tw-absolute tw-left-[11px] tw-top-6 tw-bottom-0 tw-w-px; background: var(--tz-line); }
.scm .tl li:last-child::before { display: none; }
.scm .tl__dot { @apply tw-absolute tw-left-0 tw-top-0 tw-w-6 tw-h-6 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-text-[.7rem] tw-text-white; background: #64748b; }
.scm .facts { @apply tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-x-6 tw-gap-y-3 tw-m-0; }
.scm .facts dt { @apply tw-text-[.72rem] tw-font-bold tw-uppercase tw-tracking-wide; color: var(--tz-muted); }
.scm .facts dd { @apply tw-m-0 tw-text-sm; }

@media (prefers-reduced-motion: reduce) { .scm .kpi-tile, .scm .kpi-tile:hover { transition: none; transform: none; } }
</style>
<?php include __DIR__ . '/card_css.php'; ?>
