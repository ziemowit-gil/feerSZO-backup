<?php
/**
 * tozsamosc/_head.php — Wspólny layout (chrome) podsystemu Tożsamość.
 *
 * ODRĘBNY od chrome SZO (brak navbara/menu SZO). Własny pasek górny: marka
 * „System Tożsamości", powrót do SZO, wylogowanie. Wymaga zalogowanego usera
 * ($user / current_user()). Zmienne wejściowe: $PAGE_TITLE (opcjonalnie),
 * $TZ_ACTIVE (klucz aktywnej pozycji: 'konto'|'mfa'|'').
 */
if (!function_exists('current_user')) { http_response_code(500); die('layout: brak auth'); }
$__u    = current_user() ?? [];
$__org  = defined('ORG_NAME') ? ORG_NAME : '';
$__name = $__u['name'] ?? ($__u['email'] ?? 'Użytkownik');
$__init = mb_strtoupper(mb_substr($__name, 0, 1));
if (preg_match('/\s(\S)/u', $__name, $__m)) $__init .= mb_strtoupper($__m[1]);
$__title = isset($PAGE_TITLE) ? ($PAGE_TITLE . ' — ') : '';
$TZ_ACTIVE = $TZ_ACTIVE ?? '';
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($__title) ?>System Tożsamości · <?= h($__org) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    :root{--tz:#1E6DFF;--tz-strong:#1656d6;--tz-50:#eef4ff;--tz-line:#E5E9F0;--tz-muted:#6B7280;--tz-canvas:#F4F6F9;}
    body{background:var(--tz-canvas);color:#111827;min-height:100vh}
    .tz .lbl-en{font-size:.72rem;color:var(--tz-muted);font-weight:500;display:block;margin-top:.1rem}
    .tz-skip{position:absolute;left:-999px}.tz-skip:focus{left:1rem;top:1rem;z-index:1080;background:var(--tz);color:#fff;padding:.5rem 1rem;border-radius:8px}
    /* Topbar podsystemu */
    .tzbar{background:#fff;border-bottom:1px solid var(--tz-line);position:sticky;top:0;z-index:1030}
    .tzbar .inner{max-width:960px;margin:0 auto;padding:.55rem 1rem;display:flex;align-items:center;gap:1rem}
    .tzbar .brand{display:flex;align-items:center;gap:.55rem;text-decoration:none;color:inherit}
    .tzbar .brand .mark{width:34px;height:34px;border-radius:10px;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.05rem}
    .tzbar .brand b{color:#1146ad;font-weight:700;line-height:1.05;font-size:.98rem}
    .tzbar .brand small{display:block;font-size:.66rem;letter-spacing:.06em;color:var(--tz-muted);text-transform:uppercase;font-weight:600}
    .tzbar .spacer{flex:1}
    .tzbar .lnk{color:var(--tz-muted);text-decoration:none;font-size:.85rem;padding:.4rem .6rem;border-radius:8px;display:inline-flex;align-items:center;gap:.35rem}
    .tzbar .lnk:hover{background:var(--tz-50);color:var(--tz-strong)}
    .tzbar .ava{width:34px;height:34px;border-radius:50%;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.78rem}
    .tz-wrap{max-width:960px;margin:0 auto;padding:1.5rem 1rem 3rem}
    .tz-h{margin-bottom:1.25rem}
    .tz-h h1{font-size:1.5rem;font-weight:700;letter-spacing:-.01em;margin:0}
    .tz-h p{color:var(--tz-muted);margin:.15rem 0 0;font-size:.9rem}
    /* Karty */
    .tz-card{background:#fff;border:1px solid var(--tz-line);border-radius:14px;box-shadow:0 1px 3px rgba(16,24,40,.08);overflow:hidden;margin-bottom:1.25rem}
    .tz-card__hd{padding:1rem 1.25rem;border-bottom:1px solid var(--tz-line);display:flex;align-items:center;gap:.65rem;font-weight:600}
    .tz-card__hd i{color:var(--tz)}
    .tz-card__bd{padding:1.25rem}
    .tz-id{background:linear-gradient(90deg,var(--tz),var(--tz-strong));color:#fff;padding:1.1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
    .tz-id .tz-ava{width:46px;height:46px;border-radius:12px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.1rem}
    .tz-uid{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:700;font-size:1.55rem;letter-spacing:.06em;line-height:1}
    .tz-dl{display:grid;grid-template-columns:repeat(1,1fr)}
    @media(min-width:576px){.tz-dl{grid-template-columns:repeat(2,1fr)}}
    @media(min-width:992px){.tz-dl{grid-template-columns:repeat(3,1fr)}}
    .tz-dl>div{padding:.8rem 1.1rem;border-top:1px solid var(--tz-line)}
    .tz-dl dt{font-size:.7rem;color:var(--tz-muted);margin:0;text-transform:uppercase;letter-spacing:.03em;font-weight:600}
    .tz-dl dd{font-weight:600;margin:.2rem 0 0;font-size:.94rem;word-break:break-word;color:#0f172a}
    .tz-note{background:var(--tz-canvas);border-top:1px solid var(--tz-line);padding:.6rem 1rem;font-size:.8rem;color:var(--tz-muted);display:flex;gap:.4rem;align-items:flex-start}
    .tz-btn{background:var(--tz-strong);color:#fff;border:none;border-radius:9px;padding:.6rem 1.3rem;font-weight:600;display:inline-flex;align-items:center;gap:.45rem;text-decoration:none}
    .tz-btn:hover{background:#0f3c9c;color:#fff}
    .tz-btn:focus-visible{outline:3px solid #FBBF24;outline-offset:2px}
    .tz-btn--ghost{background:#fff;color:var(--tz-strong);border:1px solid var(--tz-line)}
    .tz-btn--ghost:hover{background:var(--tz-50);color:var(--tz-strong)}
    .tz-badge{font-size:.72rem;font-weight:600;padding:.2rem .6rem;border-radius:999px;display:inline-flex;align-items:center;gap:.3rem}
    .tz-badge--ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
    .tz-badge--warn{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
    .tz-badge--off{background:#f3f4f6;color:#6B7280;border:1px solid #e5e7eb}
    .tz-otp{font-size:1.6rem;letter-spacing:.5rem;text-align:center;font-weight:700;font-family:ui-monospace,monospace}
    .tz-req{list-style:none;padding:0;margin:.5rem 0 0;display:grid;grid-template-columns:1fr;gap:.35rem;font-size:.85rem}
    @media(min-width:576px){.tz-req{grid-template-columns:1fr 1fr}}
    .tz-req li{color:var(--tz-muted);display:flex;align-items:center;gap:.4rem}
    .tz-req li.ok{color:#047857}
    .tz-req li .dot{display:inline-block;width:1.1em;text-align:center;font-weight:700}
    .tz-meter{height:7px;border-radius:999px;background:var(--tz-line);overflow:hidden;margin-top:.5rem}
    .tz-meter>span{display:block;height:100%;width:0;background:#f87171;transition:width .3s,background-color .3s}
    .tz-subnav{position:sticky;top:49px;z-index:5;background:var(--tz-canvas);padding:.6rem 0;margin-bottom:1.1rem}
    .tz-subnav .seg{display:inline-flex;gap:.2rem;padding:.25rem;background:#fff;border:1px solid var(--tz-line);border-radius:12px;flex-wrap:wrap;box-shadow:0 1px 2px rgba(16,24,40,.05)}
    .tz-subnav a{font-size:.85rem;padding:.4rem .9rem;border-radius:9px;text-decoration:none;color:var(--tz-muted);display:inline-flex;align-items:center;gap:.4rem;font-weight:500}
    .tz-subnav a:hover,.tz-subnav a:focus-visible{background:var(--tz-50);color:var(--tz-strong);outline:none}
    .tz-subnav a.on{background:var(--tz);color:#fff}.tz-subnav a.on i{color:#fff}
    .tz-panel{display:none}.tz-panel.active{display:block;animation:tzfade .2s ease}
    @keyframes tzfade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
    .tz-svc{display:flex;align-items:center;gap:.9rem;padding:.85rem 0;border-top:1px solid var(--tz-line)}
    .tz-svc:first-child{border-top:0}
    .tz-svc__ico{width:42px;height:42px;border-radius:11px;background:var(--tz-50);color:var(--tz-strong);display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
    .tz-tiles{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin:.25rem 0 .75rem}
    .tz-tile{display:block;text-align:left;background:#fff;border:1px solid var(--tz-line);border-radius:14px;padding:1.1rem;text-decoration:none;color:inherit;transition:transform .15s,border-color .15s,box-shadow .15s}
    .tz-tile:hover,.tz-tile:focus-visible{transform:translateY(-2px);border-color:var(--tz);box-shadow:0 8px 24px -6px rgba(30,109,255,.28);color:inherit;outline:none}
    .tz-tile__ico{width:42px;height:42px;border-radius:11px;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:.7rem}
    .tz .form-control:focus{border-color:var(--tz);box-shadow:0 0 0 .2rem rgba(30,109,255,.18)}
  </style>
</head>
<body>
<a href="#tz-main" class="tz-skip">Przejdź do treści</a>
<header class="tzbar">
  <div class="inner">
    <a href="<?= APP_URL ?>/tozsamosc/index.php" class="brand">
      <span class="mark" aria-hidden="true"><i class="bi bi-person-vcard-fill"></i></span>
      <span><b>System Tożsamości</b><small><?= h($__org) ?></small></span>
    </a>
    <span class="spacer"></span>
    <a class="lnk" href="<?= APP_URL ?>/portal.php"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="d-none d-sm-inline">Wróć do SZO</span></a>
    <span class="ava" title="<?= h($__name) ?>" aria-hidden="true"><?= h($__init) ?></span>
    <a class="lnk" href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span class="d-none d-sm-inline">Wyloguj</span></a>
  </div>
</header>
<main id="tz-main" class="tz tz-wrap">
