<?php
/**
 * rozliczenia/_head.php — Layout modułu Rozliczenia (nowy styl panelu, wzorzec `tz-*`).
 *
 * Własny chrome (bez navbara SZO), tak jak podsystem Tożsamość: pasek górny z marką,
 * powrót do SZO i wylogowaniem + subnawigacja modułu.
 * Zmienne wejściowe: $PAGE_TITLE (opcjonalnie), $RZ_ACTIVE ('pulpit'|'grupy'|'uczestnicy'|'').
 */
if (!function_exists('current_user')) { http_response_code(500); die('layout: brak auth'); }
$__u    = current_user() ?? [];
$__org  = defined('ORG_NAME') ? ORG_NAME : '';
$__name = $__u['name'] ?? ($__u['email'] ?? 'Użytkownik');
$__init = mb_strtoupper(mb_substr($__name, 0, 1));
if (preg_match('/\s(\S)/u', $__name, $__m)) $__init .= mb_strtoupper($__m[1]);
$__title   = isset($PAGE_TITLE) ? ($PAGE_TITLE . ' — ') : '';
$RZ_ACTIVE = $RZ_ACTIVE ?? '';
$__q       = isset($rz_month, $rz_year) ? ('?m=' . sprintf('%04d-%02d', $rz_year, $rz_month)) : '';
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($__title) ?>Rozliczenia · <?= h($__org) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    :root{--tz:#2563eb;--tz-strong:#1d4ed8;--tz-50:#eff6ff;--tz-line:#e5e7eb;--tz-muted:#6b7280;--tz-canvas:#f9fafb;}
    body{background:var(--tz-canvas);color:#111827;min-height:100vh}
    .tz-skip{position:absolute;left:-999px}
    .tz-skip:focus{left:1rem;top:1rem;z-index:1080;background:var(--tz);color:#fff;padding:.5rem 1rem;border-radius:8px}
    .tzbar{background:#fff;border-bottom:1px solid var(--tz-line);position:sticky;top:0;z-index:1030}
    .tzbar .inner{max-width:1140px;margin:0 auto;padding:.55rem 1rem;display:flex;align-items:center;gap:1rem}
    .tzbar .brand{display:flex;align-items:center;gap:.55rem;text-decoration:none;color:inherit}
    .tzbar .brand .mark{width:32px;height:32px;border-radius:8px;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.95rem}
    .tzbar .brand b{color:#111827;font-weight:700;line-height:1.05;font-size:.95rem}
    .tzbar .brand small{display:block;font-size:.65rem;color:var(--tz-muted);font-weight:500}
    .tzbar .spacer{flex:1}
    .tzbar .lnk{color:var(--tz-muted);text-decoration:none;font-size:.83rem;padding:.35rem .6rem;border-radius:6px;display:inline-flex;align-items:center;gap:.35rem}
    .tzbar .lnk:hover{background:#f3f4f6;color:#374151}
    .tzbar .ava{width:30px;height:30px;border-radius:50%;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.72rem}
    .tz-wrap{max-width:1140px;margin:0 auto;padding:1.5rem 1rem 3rem}
    .tz-h{margin-bottom:1rem}
    .tz-h h1{font-size:1.35rem;font-weight:700;letter-spacing:-.01em;margin:0;color:#111827}
    .tz-h p{color:var(--tz-muted);margin:.1rem 0 0;font-size:.85rem}
    .tz-card{background:#fff;border:1px solid var(--tz-line);border-radius:12px;overflow:hidden;margin-bottom:1rem}
    .tz-card__hd{padding:.85rem 1.1rem;border-bottom:1px solid var(--tz-line);display:flex;align-items:center;gap:.6rem;font-weight:600;font-size:.92rem}
    .tz-card__hd i{color:var(--tz)}
    .tz-card__hd .sp{flex:1}
    .tz-card__bd{padding:1.1rem}
    .tz-card__ft{padding:.55rem 1.1rem;border-top:1px solid var(--tz-line);background:#f9fafb;font-size:.78rem;color:var(--tz-muted)}
    .tz-subnav{position:sticky;top:49px;z-index:5;background:var(--tz-canvas);padding:.5rem 0;margin-bottom:1rem}
    .tz-subnav .seg{display:inline-flex;gap:.15rem;padding:.2rem;background:#fff;border:1px solid var(--tz-line);border-radius:10px;flex-wrap:wrap}
    .tz-subnav a{font-size:.83rem;padding:.38rem .85rem;border-radius:7px;text-decoration:none;color:var(--tz-muted);display:inline-flex;align-items:center;gap:.35rem;font-weight:500}
    .tz-subnav a:hover,.tz-subnav a:focus-visible{background:#f3f4f6;color:#374151;outline:none}
    .tz-subnav a.on{background:var(--tz);color:#fff}.tz-subnav a.on i{color:#fff}
    .tz-btn{background:var(--tz-strong);color:#fff;border:none;border-radius:8px;padding:.5rem 1.05rem;font-weight:600;font-size:.85rem;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none}
    .tz-btn:hover{background:#1e40af;color:#fff}
    .tz-btn:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
    .tz-btn--ghost{background:#fff;color:#374151;border:1.5px solid var(--tz-line)}
    .tz-btn--ghost:hover{background:#f9fafb;border-color:#d1d5db;color:#111827}
    .tz-btn--sm{padding:.3rem .7rem;font-size:.78rem;border-radius:7px}
    .tz-badge{font-size:.7rem;font-weight:600;padding:.2rem .55rem;border-radius:999px;display:inline-flex;align-items:center;gap:.3rem}
    .tz-badge--ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
    .tz-badge--warn{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
    .tz-badge--bad{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
    .tz-badge--off{background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb}
    .tz-badge--info{background:var(--tz-50);color:var(--tz-strong);border:1px solid #bfdbfe}
    /* KPI */
    .rz-kpis{display:grid;gap:.85rem;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));margin-bottom:1rem}
    .rz-kpi{background:#fff;border:1px solid var(--tz-line);border-radius:12px;padding:.95rem 1.1rem}
    .rz-kpi dt{font-size:.68rem;color:var(--tz-muted);text-transform:uppercase;letter-spacing:.04em;font-weight:600;margin:0}
    .rz-kpi dd{margin:.25rem 0 0;font-size:1.45rem;font-weight:700;line-height:1.1;letter-spacing:-.01em}
    .rz-kpi small{display:block;font-size:.72rem;color:var(--tz-muted);font-weight:500;margin-top:.15rem}
    .rz-kpi--ok dd{color:#047857}.rz-kpi--bad dd{color:#b91c1c}.rz-kpi--info dd{color:var(--tz-strong)}
    /* Tabele */
    .rz-tbl{width:100%;border-collapse:collapse;font-size:.86rem}
    .rz-tbl caption{caption-side:top;text-align:left;padding:0 0 .5rem;color:var(--tz-muted);font-size:.78rem}
    .rz-tbl th,.rz-tbl td{padding:.55rem .8rem;border-top:1px solid var(--tz-line);vertical-align:middle}
    .rz-tbl thead th{border-top:0;border-bottom:1px solid var(--tz-line);background:#f9fafb;font-size:.72rem;
      text-transform:uppercase;letter-spacing:.03em;color:var(--tz-muted);font-weight:700;white-space:nowrap}
    .rz-tbl tbody tr:hover{background:#fcfdff}
    .rz-tbl .num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
    .rz-tbl a{color:var(--tz-strong);text-decoration:none;font-weight:600}
    .rz-tbl a:hover{text-decoration:underline}
    .rz-pos{color:#047857;font-weight:700}.rz-neg{color:#b91c1c;font-weight:700}.rz-zero{color:var(--tz-muted)}
    /* Pasek miesiąca */
    .rz-month{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem}
    .rz-month .lbl{font-weight:700;font-size:1rem;min-width:9.5rem;text-align:center}
    .rz-note{background:var(--tz-50);border:1px solid #bfdbfe;color:#1e3a8a;border-radius:10px;
      padding:.6rem .9rem;font-size:.82rem;display:flex;gap:.5rem;align-items:flex-start;margin-bottom:1rem}
    .rz-empty{padding:1.6rem 1.1rem;text-align:center;color:var(--tz-muted);font-size:.87rem}
    .tz .form-control:focus,.tz .form-select:focus{border-color:var(--tz);box-shadow:0 0 0 .2rem rgba(37,99,235,.15)}
  </style>
</head>
<body>
<a href="#rz-main" class="tz-skip">Przejdź do treści</a>
<header class="tzbar">
  <div class="inner">
    <a href="<?= APP_URL ?>/rozliczenia/index.php" class="brand">
      <span class="mark" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
      <span><b>Rozliczenia</b><small><?= h($__org) ?></small></span>
    </a>
    <span class="spacer"></span>
    <a class="lnk" href="<?= APP_URL ?>/karty30/ti/billing.php"><i class="bi bi-receipt" aria-hidden="true"></i><span class="d-none d-md-inline">Widok klasyczny</span></a>
    <a class="lnk" href="<?= APP_URL ?>/portal.php"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="d-none d-sm-inline">Wróć do SZO</span></a>
    <span class="ava" title="<?= h($__name) ?>" aria-hidden="true"><?= h($__init) ?></span>
    <a class="lnk" href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span class="d-none d-sm-inline">Wyloguj</span></a>
  </div>
</header>
<main id="rz-main" class="tz tz-wrap">
<nav class="tz-subnav" aria-label="Sekcje modułu Rozliczenia">
  <span class="seg">
    <a href="index.php<?= h($__q) ?>"      class="<?= $RZ_ACTIVE === 'pulpit'     ? 'on' : '' ?>"><i class="bi bi-speedometer2" aria-hidden="true"></i>Pulpit</a>
    <a href="grupy.php<?= h($__q) ?>"      class="<?= $RZ_ACTIVE === 'grupy'      ? 'on' : '' ?>"><i class="bi bi-collection" aria-hidden="true"></i>Grupy</a>
    <a href="uczestnicy.php<?= h($__q) ?>" class="<?= $RZ_ACTIVE === 'uczestnicy' ? 'on' : '' ?>"><i class="bi bi-people" aria-hidden="true"></i>Uczestnicy</a>
    <a href="faktury.php<?= h($__q) ?>"    class="<?= $RZ_ACTIVE === 'faktury'    ? 'on' : '' ?>"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>Faktury</a>
  </span>
</nav>
