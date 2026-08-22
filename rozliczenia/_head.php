<?php
/**
 * rozliczenia/_head.php — Layout modułu Rozliczenia.
 *
 * Wygląd i układ jak w panelu dydaktyka (karty30/ti/dydaktyk): wspólny layout TI
 * (kursant/_layout_head.php, Bootstrap 5.3 + WCAG), granatowy pasek górny,
 * stały panel boczny 220 px z sekcjami i treść w kontenerze `.rz-wrap`.
 *
 * Zmienne wejściowe: $PAGE_TITLE, $RZ_ACTIVE ('pulpit'|'grupy'|'uczestnicy'|'faktury'),
 * $rz_year/$rz_month (pasek okresu w sidebarze).
 */
if (!function_exists('current_user')) { http_response_code(500); die('layout: brak auth'); }
$__u   = current_user() ?? [];
$__ym  = isset($rz_year, $rz_month) ? sprintf('%04d-%02d', $rz_year, $rz_month) : date('Y-m');
$__lbl = isset($rz_month_label) ? $rz_month_label : '';
$RZ_ACTIVE = $RZ_ACTIVE ?? '';

$KP_TITLE   = ($PAGE_TITLE ?? 'Rozliczenia') . ' — Rozliczenia';
$KP_TOPBAR  = [
    'brand'  => 'Rozliczenia zajęć',
    'icon'   => 'cash-coin',
    'user'   => $__u['name'] ?? '',
    'logout' => APP_URL . '/auth/logout.php',
];
$KP_SKIP_TAB_MEMORY = true;   // moduł nie używa ?tab= — bez pamięci zakładek
include dirname(__DIR__) . '/karty30/ti/kursant/_layout_head.php';

/** Pozycja menu bocznego. */
$__sb = function (string $key, string $href, string $icon, string $label) use ($RZ_ACTIVE) {
    $on = $RZ_ACTIVE === $key;
    echo '<a class="rz-sb-link' . ($on ? ' active' : '') . '" href="' . h($href) . '"'
       . ($on ? ' aria-current="page"' : '') . '>'
       . '<i class="bi bi-' . h($icon) . '" aria-hidden="true"></i>' . h($label) . '</a>';
};
?>
<style>
  /* ── Pasek górny w kolorystyce panelu dydaktyka ─────────────────────────── */
  header .navbar { background:#1b2e45 !important; border-bottom:none !important; }
  header .navbar .navbar-brand, header .navbar .navbar-brand i { color:#fff !important; }
  header .navbar .btn-outline-secondary { color:rgba(255,255,255,.8) !important; border-color:rgba(255,255,255,.3) !important; }
  header .navbar .btn-outline-secondary:hover { background:rgba(255,255,255,.1) !important; color:#fff !important; }
  header .navbar .text-body-secondary { color:rgba(255,255,255,.75) !important; }

  /* ── Panel boczny ───────────────────────────────────────────────────────── */
  .rz-sidebar {
    position:fixed; left:0; top:56px; bottom:0; width:220px;
    background:#1b2e45; overflow-y:auto; overflow-x:hidden; z-index:100;
    display:flex; flex-direction:column; padding:.5rem 0 1rem;
    border-right:1px solid rgba(255,255,255,.08);
  }
  .rz-sb-link {
    display:flex; align-items:center; gap:.55rem;
    padding:.55rem 1rem; font-size:.85rem; font-weight:600;
    color:rgba(255,255,255,.8); text-decoration:none;
    border-left:3px solid transparent; white-space:nowrap; overflow:hidden;
    transition:background .12s, color .12s, border-color .12s;
  }
  .rz-sb-link:hover { background:rgba(255,255,255,.1); color:#fff; border-left-color:rgba(255,255,255,.2); }
  .rz-sb-link.active { background:rgba(255,255,255,.12); color:#fff; font-weight:700; border-left-color:#5bbcff; }
  .rz-sb-link:focus-visible { outline:3px solid #ffdd00; outline-offset:-3px; }
  .rz-sb-section {
    padding:.6rem 1rem .2rem; font-size:.67rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.08em; color:rgba(255,255,255,.35);
  }
  .rz-sb-sep { border-top:1px solid rgba(255,255,255,.1); margin:.35rem 0; }
  .rz-sb-period { padding:.65rem 1rem .55rem; border-bottom:1px solid rgba(255,255,255,.12); margin-bottom:.35rem; }
  .rz-sb-period .name { font-size:.82rem; font-weight:700; color:#fff; }
  .rz-sb-period .nav { display:flex; gap:.3rem; margin-top:.4rem; }
  .rz-sb-period .nav a {
    flex:1; text-align:center; font-size:.75rem; padding:.15rem .3rem; border-radius:5px;
    color:rgba(255,255,255,.85); text-decoration:none; border:1px solid rgba(255,255,255,.18);
    background:rgba(255,255,255,.08);
  }
  .rz-sb-period .nav a:hover { background:rgba(255,255,255,.18); color:#fff; }

  /* ── Treść ──────────────────────────────────────────────────────────────── */
  .rz-wrap {
    padding-left:  max(1rem, calc((100% - 1100px) / 2));
    padding-right: max(1rem, calc((100% - 1100px) / 2));
  }
  @media (min-width:768px) {
    .rz-content { margin-left:220px; }
  }
  @media (max-width:767px) {
    .rz-sidebar { transform:translateX(-220px); transition:transform .22s ease; }
    .rz-sidebar.open { transform:translateX(0); box-shadow:4px 0 24px rgba(0,0,0,.35); }
    .rz-sb-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:99; }
    .rz-sb-overlay.show { display:block; }
  }
  #rzSbToggle {
    position:fixed; top:64px; left:8px; z-index:101;
    width:32px; height:32px; padding:0; line-height:1;
    background:#1b2e45; border:1px solid rgba(255,255,255,.22); border-radius:6px;
    color:rgba(255,255,255,.85); display:flex; align-items:center; justify-content:center;
    cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,.35);
  }
  #rzSbToggle:hover { background:#243d5c; color:#fff; }
  @media (min-width:768px) { #rzSbToggle { display:none; } }

  /* Tryb „ukryj menu" z panelu dostępności wspólnego layoutu TI */
  html.kp-hidemenu .rz-sidebar { display:none !important; }
  html.kp-hidemenu .rz-content { margin-left:0 !important; }
  /* Tryb wysokiego kontrastu — panel boczny czytelny na czarnym tle */
  html.kp-contrast .rz-sidebar { background:#000 !important; border-right:1px solid #fff; }
  html.kp-contrast .rz-sb-link { color:#fff; }
  html.kp-contrast .rz-sb-link.active { background:#ffdd00; color:#000; }

  /* ── Karty i tabele (MD3, jak w panelu dydaktyka) ───────────────────────── */
  .rz-wrap .card { border-radius:12px !important; border-color:var(--bs-border-color) !important;
    box-shadow:0 1px 2px rgba(0,0,0,.06), 0 2px 8px rgba(0,0,0,.04) !important; }
  .rz-wrap .card-header { background:transparent !important; border-radius:12px 12px 0 0 !important; padding:.7rem 1rem; }
  .rz-kpis { display:grid; gap:.85rem; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); margin-bottom:1rem; }
  .rz-kpi { background:var(--bs-body-bg); border:1px solid var(--bs-border-color); border-radius:12px; padding:.95rem 1.1rem;
    box-shadow:0 1px 2px rgba(0,0,0,.06); }
  .rz-kpi dt { font-size:.68rem; color:var(--bs-secondary-color); text-transform:uppercase; letter-spacing:.04em; font-weight:700; margin:0; }
  .rz-kpi dd { margin:.25rem 0 0; font-size:1.45rem; font-weight:700; line-height:1.1; }
  .rz-kpi small { display:block; font-size:.72rem; color:var(--bs-secondary-color); margin-top:.15rem; }
  .rz-kpi--ok dd { color:#15803d; } .rz-kpi--bad dd { color:#b91c1c; } .rz-kpi--info dd { color:#1d4ed8; }
  .rz-num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
  .rz-pos { color:#15803d; font-weight:700; } .rz-neg { color:#b91c1c; font-weight:700; }
  .rz-zero { color:var(--bs-secondary-color); }
  .rz-empty { padding:1.6rem 1.1rem; text-align:center; color:var(--bs-secondary-color); font-size:.87rem; }
  .rz-month { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; }
  .rz-month .lbl { font-weight:700; font-size:1rem; min-width:9.5rem; text-align:center; }
</style>

<button id="rzSbToggle" type="button" aria-label="Pokaż menu modułu" aria-controls="rzSidebar" aria-expanded="false">
  <i class="bi bi-list" aria-hidden="true"></i>
</button>
<div class="rz-sb-overlay" id="rzSbOverlay" hidden></div>

<nav class="rz-sidebar" id="rzSidebar" aria-label="Menu modułu Rozliczenia">
  <div class="rz-sb-period">
    <div class="name"><i class="bi bi-calendar3 me-1" aria-hidden="true"></i><?= h($__lbl !== '' ? $__lbl : $__ym) ?></div>
    <div class="nav">
      <a href="?m=<?= h(date('Y-m', strtotime($__ym . '-01 -1 month'))) ?>" aria-label="Poprzedni miesiąc"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
      <a href="?m=<?= h(date('Y-m')) ?>">dziś</a>
      <a href="?m=<?= h(date('Y-m', strtotime($__ym . '-01 +1 month'))) ?>" aria-label="Następny miesiąc"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </div>
  </div>

  <?php $__sb('pulpit', 'index.php?m=' . $__ym, 'house', 'Pulpit'); ?>

  <div class="rz-sb-sep"></div>
  <div class="rz-sb-section">Rozliczenia</div>
  <?php
    $__sb('grupy',      'grupy.php?m=' . $__ym,      'collection',         'Grupy');
    $__sb('uczestnicy', 'uczestnicy.php?m=' . $__ym, 'people',             'Uczestnicy');
    $__sb('faktury',    'faktury.php?m=' . $__ym,    'file-earmark-text',  'Faktury');
  ?>

  <div class="rz-sb-sep"></div>
  <div class="rz-sb-section">Powiązane</div>
  <a class="rz-sb-link" href="<?= APP_URL ?>/karty30/ti/billing.php"><i class="bi bi-receipt" aria-hidden="true"></i>Widok klasyczny</a>
  <a class="rz-sb-link" href="<?= APP_URL ?>/karty30/ti/index.php"><i class="bi bi-card-checklist" aria-hidden="true"></i>Zajęcia TI</a>
  <a class="rz-sb-link" href="<?= APP_URL ?>/portal.php"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Powrót do SZO</a>
</nav>

<main id="main" class="container-fluid rz-content rz-wrap py-4">
