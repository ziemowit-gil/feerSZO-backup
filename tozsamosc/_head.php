<?php
/**
 * tozsamosc/_head.php — Wspólny layout (chrome) podsystemu Tożsamość.
 *
 * ODRĘBNY od chrome SZO (brak navbara/menu SZO). Własny granatowy pasek górny:
 * marka „System Tożsamości", nawigacja podsystemu (Moja tożsamość, Metody
 * weryfikacji, dla admina rozwijane „Administracja"), powrót do SZO, menu
 * użytkownika z wylogowaniem. Wymaga zalogowanego usera ($user / current_user()).
 *
 * Zmienne wejściowe:
 *   $PAGE_TITLE   (opcjonalnie) — tytuł karty przeglądarki.
 *   $TZ_ACTIVE    klucz aktywnej pozycji: 'konto'|'mfa'|'users'|'uzytkownicy'|'administracja'|''.
 *   $TZ_HERO_HTML (opcjonalnie) — HTML „hero" renderowany na ciemnym bandzie pod paskiem;
 *                 treść strony (<main>) nachodzi wtedy na band (klasa tz-wrap--overlap).
 *
 * Warstwa wizualna (2026-10-05, v2): Tailwind (CDN, prefiks `tw-`, bez preflightu —
 * nie koliduje z Bootstrapem, który zostaje silnikiem formularzy/offcanvasów na
 * starszych stronach podsystemu: mfa.php, access.php, ldap.php, admin/users.php pod
 * TZ_USERS_CHROME) + Alpine.js (menu, zakładki, filtry). Paleta marki `brand`
 * (#1E6DFF) i `ink` (granat) zdefiniowane w tailwind.config poniżej.
 * Klasy `.tz-*` są WSPÓLNYM kontraktem starszych stron — nie usuwać ani nie zmieniać nazw.
 * UWAGA (Tailwind bez preflightu): każde `tw-border*` wymaga `tw-border-solid`.
 */
if (!function_exists('current_user')) { http_response_code(500); die('layout: brak auth'); }
$__u    = current_user() ?? [];
$__org  = defined('ORG_NAME') ? ORG_NAME : '';
$__name = $__u['name'] ?? ($__u['email'] ?? 'Użytkownik');
$__init = mb_strtoupper(mb_substr($__name, 0, 1));
if (preg_match('/\s(\S)/u', $__name, $__m)) $__init .= mb_strtoupper($__m[1]);
$__title = isset($PAGE_TITLE) ? ($PAGE_TITLE . ' — ') : '';
$TZ_ACTIVE = $TZ_ACTIVE ?? '';
$TZ_HERO_HTML = $TZ_HERO_HTML ?? '';
$__is_admin = function_exists('is_admin') && is_admin();
$__script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

// Pozycje administracyjne podsystemu (plik => [etykieta, ikona]); aktywność po nazwie skryptu lub $TZ_ACTIVE.
$__admin_items = [
    'uzytkownicy.php' => ['Użytkownicy',          'bi-people'],
    'role.php'        => ['Role i uprawnienia',   'bi-diagram-3'],
    'moduly.php'      => ['Uprawnienia modułów',  'bi-grid-3x3-gap'],
    'audit.php'       => ['Dziennik zdarzeń',     'bi-journal-text'],
    'ustawienia.php'  => ['Ustawienia logowania', 'bi-sliders'],
    'saml.php'        => ['SAML / SSO',           'bi-box-arrow-in-right'],
    'ldap.php'        => ['Katalog LDAP',         'bi-diagram-2'],
    'sync.php'        => ['Synchronizacja kont',  'bi-arrow-repeat'],
    'ika_admin.php'   => ['Kody IKA',             'bi-123'],
    'ezd_macierz.php' => ['Macierz dostępu EZD',  'bi-table'],
    'api_keys.php'    => ['Klucze API',           'bi-key'],
];
$__admin_active = in_array($TZ_ACTIVE, ['users', 'uzytkownicy', 'administracja'], true)
    || isset($__admin_items[$__script]) || $__script === 'users.php' || $__script === 'access.php';
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0b1a3a">
  <title><?= h($__title) ?>System Tożsamości · <?= h($__org) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <!-- Alpine.js — wtyczka focus MUSI ładować się przed rdzeniem (rejestruje się na obiekcie Alpine przed jego startem). -->
  <script src="https://cdn.jsdelivr.net/npm/@alpinejs/focus@3/dist/cdn.min.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js" defer></script>
  <!-- Tailwind — prefiks tw-, bez preflightu (jak w includes/header.php): zero kolizji z Bootstrapem. -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      prefix: 'tw-',
      corePlugins: { preflight: false },
      theme: { extend: { colors: {
        brand: { 50:'#eef4ff', 100:'#dbe7ff', 200:'#bdd2ff', 300:'#8fb3ff', 400:'#5a8cff', 500:'#1E6DFF', 600:'#1656d6', 700:'#1245ab', 800:'#133b88', 900:'#13326b' },
        ink:   { 50:'#f3f5f9', 100:'#e6e9f0', 200:'#cfd6e3', 300:'#a9b4c9', 400:'#7d8aa6', 500:'#56637f', 600:'#3b4660', 700:'#273352', 800:'#152245', 900:'#0b1a3a' }
      } } }
    };
  </script>
  <style>
    :root{--tz:#1E6DFF;--tz-strong:#1656d6;--tz-50:#eef4ff;--tz-ink:#0b1a3a;--tz-line:#e6e9f0;--tz-muted:#64748b;--tz-canvas:#f3f5f9;--tz-bar:60px}
    [x-cloak]{display:none!important}
    html{scroll-behavior:smooth}
    body{background:var(--tz-canvas);color:#0f172a;min-height:100vh;-webkit-font-smoothing:antialiased;font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
    .tz .lbl-en{font-size:.72rem;color:var(--tz-muted);font-weight:500;display:block;margin-top:.1rem}
    .tz-skip{position:absolute;left:-999px}.tz-skip:focus{left:1rem;top:1rem;z-index:1080;background:#fff;color:var(--tz-ink);padding:.5rem 1rem;border-radius:8px;font-weight:600}
    /* ── Pasek górny (granat) ── */
    .tzbar{background:linear-gradient(90deg,#0b1a3a 0%,#11275e 60%,#143070 100%);color:#fff;position:sticky;top:0;z-index:1030;box-shadow:0 1px 0 rgba(255,255,255,.06) inset,0 6px 18px -10px rgba(2,6,23,.6)}
    .tzbar .inner{max-width:1160px;margin:0 auto;padding:0 1rem;height:var(--tz-bar);display:flex;align-items:center;gap:.5rem}
    .tzbar .brand{display:flex;align-items:center;gap:.6rem;text-decoration:none;color:#fff;margin-right:.5rem;flex-shrink:0}
    .tzbar .brand .mark{width:36px;height:36px;border-radius:11px;background:linear-gradient(135deg,#1E6DFF,#7c5cff);display:flex;align-items:center;justify-content:center;font-size:1.05rem;box-shadow:0 6px 16px -4px rgba(30,109,255,.7)}
    .tzbar .brand b{display:block;font-weight:700;line-height:1.05;font-size:.95rem;letter-spacing:-.01em}
    .tzbar .brand small{display:block;font-size:.64rem;color:rgba(255,255,255,.62);font-weight:500;letter-spacing:.02em}
    .tzbar .spacer{flex:1}
    .tzbar .lnk{color:rgba(255,255,255,.8);text-decoration:none;font-size:.83rem;font-weight:500;padding:.45rem .7rem;border-radius:9px;display:inline-flex;align-items:center;gap:.4rem;background:transparent;border:0;cursor:pointer;white-space:nowrap;transition:background .12s,color .12s;font-family:inherit;line-height:1.2}
    .tzbar .lnk:hover{background:rgba(255,255,255,.09);color:#fff}
    .tzbar .lnk:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
    .tzbar .lnk.on{background:rgba(255,255,255,.15);color:#fff;box-shadow:0 0 0 1px rgba(255,255,255,.08) inset}
    .tzbar .lnk i{opacity:.9}
    .tzbar .ava{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#1E6DFF,#7c5cff);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.74rem;letter-spacing:.02em;box-shadow:0 0 0 2px rgba(255,255,255,.18)}
    .tzbar .who{display:none;flex-direction:column;line-height:1.1;text-align:left}
    .tzbar .who b{font-size:.78rem;color:#fff;font-weight:600;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .tzbar .who small{font-size:.64rem;color:rgba(255,255,255,.62);max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    @media(min-width:900px){.tzbar .who{display:flex}}
    .tzbar .usr{display:inline-flex;align-items:center;gap:.55rem;padding:.25rem .45rem .25rem .25rem;border-radius:999px;background:transparent;border:0;cursor:pointer;color:#fff}
    .tzbar .usr:hover{background:rgba(255,255,255,.09)}
    .tzbar .usr:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
    .tznav{display:none;align-items:center;gap:.15rem}
    @media(min-width:768px){.tznav{display:flex}}
    .tzmenu{position:absolute;top:calc(100% + .35rem);right:0;min-width:230px;background:#fff;color:#0f172a;border:1px solid var(--tz-line);border-radius:14px;box-shadow:0 18px 40px -12px rgba(2,6,23,.35);padding:.4rem;z-index:1040}
    .tzmenu a,.tzmenu button{display:flex;align-items:center;gap:.6rem;width:100%;padding:.5rem .65rem;border-radius:9px;color:#1e293b;text-decoration:none;font-size:.84rem;font-weight:500;background:transparent;border:0;cursor:pointer;text-align:left;font-family:inherit}
    .tzmenu a:hover,.tzmenu button:hover,.tzmenu a:focus-visible{background:#f1f5f9;color:#0f172a;outline:none}
    .tzmenu a.on{background:var(--tz-50);color:var(--tz-strong)}
    .tzmenu a i{color:var(--tz-muted);width:1.1rem;text-align:center}
    .tzmenu a.on i{color:var(--tz-strong)}
    .tzmenu .hd{padding:.55rem .65rem .4rem;border-bottom:1px solid var(--tz-line);margin-bottom:.3rem}
    .tzmenu .hd b{display:block;font-size:.84rem;font-weight:700;color:#0f172a}
    .tzmenu .hd small{display:block;font-size:.7rem;color:var(--tz-muted);word-break:break-all}
    .tzmenu .sep{height:1px;background:var(--tz-line);margin:.3rem .2rem}
    .tzmenu .danger{color:#b91c1c}.tzmenu .danger i{color:#b91c1c}
    .tzmenu .lab{font-size:.64rem;text-transform:uppercase;letter-spacing:.08em;color:var(--tz-muted);font-weight:700;padding:.45rem .65rem .2rem}
    .tzmob{background:#0b1a3a;border-top:1px solid rgba(255,255,255,.08);padding:.5rem 1rem .8rem}
    .tzmob a{display:flex;align-items:center;gap:.6rem;padding:.6rem .7rem;border-radius:10px;color:rgba(255,255,255,.85);text-decoration:none;font-size:.9rem;font-weight:500}
    .tzmob a.on,.tzmob a:hover{background:rgba(255,255,255,.12);color:#fff}
    .tzmob .lab{font-size:.64rem;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.5);font-weight:700;padding:.6rem .7rem .2rem}
    @media(min-width:768px){.tzmob,.tzbar .burger{display:none!important}}
    /* ── Band „hero" pod paskiem (gdy strona poda $TZ_HERO_HTML) ── */
    .tz-band{background:radial-gradient(1200px 420px at 85% -20%,rgba(30,109,255,.45),transparent 60%),radial-gradient(800px 300px at 0% 120%,rgba(124,92,255,.35),transparent 60%),linear-gradient(135deg,#0b1a3a 0%,#11275e 55%,#143070 100%);color:#fff;position:relative;overflow:hidden}
    .tz-band::before{content:'';position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.05) 1px,transparent 1px);background-size:36px 36px;mask-image:linear-gradient(180deg,rgba(0,0,0,.7),transparent 85%);-webkit-mask-image:linear-gradient(180deg,rgba(0,0,0,.7),transparent 85%);pointer-events:none}
    .tz-band .inner{max-width:1160px;margin:0 auto;padding:1.75rem 1rem 4.75rem;position:relative}
    .tz-wrap{max-width:1160px;margin:0 auto;padding:1.5rem 1rem 3rem}
    .tz-wrap--overlap{margin-top:-3.5rem;position:relative;z-index:1;padding-top:0}
    .tz-h{margin-bottom:1.1rem}
    .tz-h h1{font-size:1.45rem;font-weight:800;letter-spacing:-.02em;margin:0;color:#0f172a}
    .tz-h p{color:var(--tz-muted);margin:.15rem 0 0;font-size:.86rem}
    /* ── Karty (kontrakt starszych stron podsystemu) ── */
    .tz-card{background:#fff;border:1px solid var(--tz-line);border-radius:16px;overflow:hidden;margin-bottom:1rem;box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 24px -18px rgba(16,24,40,.18)}
    .tz-card__hd{padding:.85rem 1.15rem;border-bottom:1px solid var(--tz-line);display:flex;align-items:center;gap:.6rem;font-weight:600;font-size:.92rem;background:linear-gradient(180deg,#fff,#fafbfd)}
    .tz-card__hd i{color:var(--tz)}
    .tz-card__bd{padding:1.15rem}
    .tz-id{background:#fff;padding:1.1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;border-bottom:1px solid var(--tz-line)}
    .tz-id .tz-ava{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#1E6DFF,#7c5cff);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;flex-shrink:0}
    .tz-uid{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:700;font-size:1.4rem;letter-spacing:.04em;line-height:1;color:var(--tz-strong)}
    .tz-dl{display:grid;grid-template-columns:repeat(1,1fr)}
    @media(min-width:576px){.tz-dl{grid-template-columns:repeat(2,1fr)}}
    @media(min-width:992px){.tz-dl{grid-template-columns:repeat(3,1fr)}}
    .tz-dl>div{padding:.75rem 1.1rem;border-top:1px solid var(--tz-line)}
    .tz-dl dt{font-size:.66rem;color:var(--tz-muted);margin:0;text-transform:uppercase;letter-spacing:.06em;font-weight:700}
    .tz-dl dd{font-weight:600;margin:.15rem 0 0;font-size:.9rem;word-break:break-word;color:#0f172a}
    .tz-note{background:#f8fafc;border-top:1px solid var(--tz-line);padding:.6rem 1.1rem;font-size:.78rem;color:var(--tz-muted);display:flex;gap:.45rem;align-items:flex-start}
    .tz-btn{background:var(--tz);color:#fff;border:none;border-radius:10px;padding:.55rem 1.15rem;font-weight:600;font-size:.87rem;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:background .12s,box-shadow .12s,transform .12s;box-shadow:0 6px 14px -8px rgba(30,109,255,.8)}
    .tz-btn:hover{background:var(--tz-strong);color:#fff;transform:translateY(-1px)}
    .tz-btn:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
    .tz-btn--ghost{background:#fff;color:#334155;border:1.5px solid var(--tz-line);box-shadow:none}
    .tz-btn--ghost:hover{background:#f8fafc;border-color:#cbd5e1;color:#0f172a}
    .tz-badge{font-size:.7rem;font-weight:600;padding:.2rem .55rem;border-radius:999px;display:inline-flex;align-items:center;gap:.3rem}
    .tz-badge--ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
    .tz-badge--warn{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
    .tz-badge--off{background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0}
    .tz-otp{font-size:1.6rem;letter-spacing:.5rem;text-align:center;font-weight:700;font-family:ui-monospace,monospace}
    .tz-req{list-style:none;padding:0;margin:.5rem 0 0;display:grid;grid-template-columns:1fr;gap:.35rem;font-size:.85rem}
    @media(min-width:576px){.tz-req{grid-template-columns:1fr 1fr}}
    .tz-req li{color:var(--tz-muted);display:flex;align-items:center;gap:.4rem}
    .tz-req li.ok{color:#047857}
    .tz-req li .dot{display:inline-block;width:1.1em;text-align:center;font-weight:700}
    .tz-meter{height:6px;border-radius:999px;background:var(--tz-line);overflow:hidden;margin-top:.5rem}
    .tz-meter>span{display:block;height:100%;width:0;background:#f87171;transition:width .3s,background-color .3s}
    .tz-subnav{position:sticky;top:var(--tz-bar);z-index:5;background:var(--tz-canvas);padding:.5rem 0;margin-bottom:1rem}
    .tz-subnav .seg{display:inline-flex;gap:.15rem;padding:.2rem;background:#fff;border:1px solid var(--tz-line);border-radius:12px;flex-wrap:wrap}
    .tz-subnav a{font-size:.83rem;padding:.38rem .85rem;border-radius:9px;text-decoration:none;color:var(--tz-muted);display:inline-flex;align-items:center;gap:.35rem;font-weight:500}
    .tz-subnav a:hover,.tz-subnav a:focus-visible{background:#f1f5f9;color:#334155;outline:none}
    .tz-subnav a.on{background:var(--tz);color:#fff}.tz-subnav a.on i{color:#fff}
    .tz-panel{display:none}.tz-panel.active{display:block;animation:tzfade .15s ease}
    @keyframes tzfade{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
    .tz-svc{display:flex;align-items:center;gap:.85rem;padding:.8rem 0;border-top:1px solid var(--tz-line)}
    .tz-svc:first-child{border-top:0}
    .tz-svc__ico{width:38px;height:38px;border-radius:10px;background:var(--tz-50);color:var(--tz-strong);display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0}
    .tz-tiles{display:grid;gap:.85rem;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin:.2rem 0 .65rem}
    .tz-tile{display:block;text-align:left;background:#fff;border:1.5px solid var(--tz-line);border-radius:14px;padding:1rem;text-decoration:none;color:inherit;transition:border-color .13s,box-shadow .13s,transform .13s}
    .tz-tile:hover,.tz-tile:focus-visible{border-color:var(--tz);box-shadow:0 0 0 3px rgba(30,109,255,.12);color:inherit;outline:none;transform:translateY(-1px)}
    .tz-tile__ico{width:38px;height:38px;border-radius:10px;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:.6rem}
    .tz .form-control:focus,.tz .form-select:focus{border-color:var(--tz);box-shadow:0 0 0 .2rem rgba(30,109,255,.15)}
    .tz .form-control,.tz .form-select{border-radius:10px;border-color:#cbd5e1}
    .tz .btn-primary{background:var(--tz);border-color:var(--tz)}.tz .btn-primary:hover{background:var(--tz-strong);border-color:var(--tz-strong)}
    .tz .btn-outline-primary{color:var(--tz-strong);border-color:var(--tz)}.tz .btn-outline-primary:hover{background:var(--tz);border-color:var(--tz);color:#fff}
    /* ── Kafel „bonto" (launcher zewnętrznych narzędzi) ── */
    .tz-app-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:.85rem;margin:.5rem 0}
    .tz-app{position:relative;border-radius:16px;overflow:hidden;text-decoration:none;color:#fff;display:flex;flex-direction:column;min-height:148px;padding:1rem 1.1rem .9rem;transition:transform .14s,box-shadow .14s}
    .tz-app::after{content:'';position:absolute;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.08);right:-35px;bottom:-55px;pointer-events:none}
    .tz-app:hover{transform:translateY(-3px);box-shadow:0 12px 28px rgba(2,6,23,.18);color:#fff}
    .tz-app-ic{width:40px;height:40px;border-radius:11px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
    .tz-app-title{font-size:.98rem;font-weight:800;line-height:1.2;margin-top:.6rem;position:relative;z-index:1}
    .tz-app-desc{font-size:.73rem;color:rgba(255,255,255,.8);margin-top:.2rem;flex:1;line-height:1.35;position:relative;z-index:1}
    .tz-app-foot{display:flex;align-items:center;gap:.4rem;margin-top:.7rem;position:relative;z-index:1}
    .tz-app-cta{font-size:.72rem;font-weight:700;color:rgba(255,255,255,.9);display:flex;align-items:center;gap:.25rem;text-decoration:none}
    .tz-app-cta:hover{color:#fff;text-decoration:underline}
  </style>
</head>
<body>
<a href="#tz-main" class="tz-skip">Przejdź do treści</a>
<header class="tzbar" x-data="{ mob: false, usr: false, adm: false }" @keydown.escape.window="usr = false; adm = false; mob = false">
  <div class="inner">
    <button type="button" class="lnk burger" @click="mob = !mob" :aria-expanded="mob ? 'true' : 'false'" aria-controls="tz-mobnav" aria-label="Menu">
      <i class="bi" :class="mob ? 'bi-x-lg' : 'bi-list'" aria-hidden="true" style="font-size:1.2rem"></i>
    </button>
    <a href="<?= APP_URL ?>/tozsamosc/index.php" class="brand">
      <span class="mark" aria-hidden="true"><i class="bi bi-person-vcard-fill"></i></span>
      <span><b>System Tożsamości</b><small><?= h($__org) ?></small></span>
    </a>
    <nav class="tznav" aria-label="Sekcje systemu tożsamości">
      <a class="lnk<?= $TZ_ACTIVE === 'konto' ? ' on' : '' ?>" href="<?= APP_URL ?>/tozsamosc/index.php"<?= $TZ_ACTIVE === 'konto' ? ' aria-current="page"' : '' ?>><i class="bi bi-person-badge" aria-hidden="true"></i>Moja tożsamość</a>
      <a class="lnk<?= $TZ_ACTIVE === 'mfa' ? ' on' : '' ?>" href="<?= APP_URL ?>/tozsamosc/mfa.php"<?= $TZ_ACTIVE === 'mfa' ? ' aria-current="page"' : '' ?>><i class="bi bi-shield-check" aria-hidden="true"></i>Metody weryfikacji</a>
      <?php if ($__is_admin): ?>
      <span class="tw-relative" @click.outside="adm = false">
        <button type="button" class="lnk<?= $__admin_active ? ' on' : '' ?>" @click="adm = !adm" :aria-expanded="adm ? 'true' : 'false'" aria-haspopup="menu">
          <i class="bi bi-shield-fill-gear" aria-hidden="true"></i>Administracja<i class="bi bi-chevron-down" aria-hidden="true" style="font-size:.65rem;margin-left:-.1rem"></i>
        </button>
        <div class="tzmenu" style="right:auto;left:0;min-width:260px" x-show="adm" x-cloak x-transition.opacity.duration.120ms role="menu" aria-label="Administracja tożsamością">
          <div class="lab">Administracja tożsamością</div>
          <?php foreach ($__admin_items as $__f => [$__l, $__i]): $__on = ($__script === $__f) || ($__f === 'uzytkownicy.php' && ($__script === 'users.php' || in_array($TZ_ACTIVE, ['users','uzytkownicy'], true))); ?>
          <a href="<?= APP_URL ?>/tozsamosc/<?= $__f ?>" class="<?= $__on ? 'on' : '' ?>" role="menuitem"<?= $__on ? ' aria-current="page"' : '' ?>><i class="bi <?= $__i ?>" aria-hidden="true"></i><?= h($__l) ?></a>
          <?php endforeach; ?>
          <div class="sep"></div>
          <a href="<?= APP_URL ?>/admin/index.php" role="menuitem"><i class="bi bi-gear" aria-hidden="true"></i>Panel administracyjny SZO<i class="bi bi-box-arrow-up-right tw-ml-auto" aria-hidden="true" style="font-size:.7rem"></i></a>
        </div>
      </span>
      <?php endif; ?>
    </nav>
    <span class="spacer"></span>
    <a class="lnk" href="<?= APP_URL ?>/portal.php"><i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i><span class="d-none d-sm-inline">Wróć do SZO</span></a>
    <span class="tw-relative" @click.outside="usr = false">
      <button type="button" class="usr" @click="usr = !usr" :aria-expanded="usr ? 'true' : 'false'" aria-haspopup="menu" aria-label="Menu użytkownika: <?= h($__name) ?>">
        <span class="ava" aria-hidden="true"><?= h($__init) ?></span>
        <span class="who" aria-hidden="true"><b><?= h($__name) ?></b><small><?= h($__u['email'] ?? '') ?></small></span>
        <i class="bi bi-chevron-down" aria-hidden="true" style="font-size:.65rem;opacity:.7"></i>
      </button>
      <div class="tzmenu" x-show="usr" x-cloak x-transition.opacity.duration.120ms role="menu" aria-label="Konto">
        <div class="hd"><b><?= h($__name) ?></b><small><?= h($__u['email'] ?? '') ?></small></div>
        <a href="<?= APP_URL ?>/tozsamosc/index.php" role="menuitem" class="<?= $TZ_ACTIVE === 'konto' ? 'on' : '' ?>"><i class="bi bi-person-badge" aria-hidden="true"></i>Moja tożsamość</a>
        <a href="<?= APP_URL ?>/tozsamosc/index.php#bezpieczenstwo" role="menuitem"><i class="bi bi-shield-lock" aria-hidden="true"></i>Bezpieczeństwo konta</a>
        <a href="<?= APP_URL ?>/tozsamosc/mfa.php" role="menuitem" class="<?= $TZ_ACTIVE === 'mfa' ? 'on' : '' ?>"><i class="bi bi-shield-check" aria-hidden="true"></i>Metody weryfikacji</a>
        <a href="<?= APP_URL ?>/tozsamosc/rodo_export.php" target="_blank" rel="noopener" role="menuitem"><i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>Eksport moich danych (PDF)</a>
        <div class="sep"></div>
        <a href="<?= APP_URL ?>/portal.php" role="menuitem"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>Wróć do SZO</a>
        <a href="<?= APP_URL ?>/auth/logout.php" role="menuitem" class="danger"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Wyloguj</a>
      </div>
    </span>
  </div>
  <nav id="tz-mobnav" class="tzmob" x-show="mob" x-cloak x-transition.opacity.duration.120ms aria-label="Sekcje systemu tożsamości (mobile)">
    <a href="<?= APP_URL ?>/tozsamosc/index.php" class="<?= $TZ_ACTIVE === 'konto' ? 'on' : '' ?>"><i class="bi bi-person-badge" aria-hidden="true"></i>Moja tożsamość</a>
    <a href="<?= APP_URL ?>/tozsamosc/index.php#bezpieczenstwo"><i class="bi bi-shield-lock" aria-hidden="true"></i>Bezpieczeństwo konta</a>
    <a href="<?= APP_URL ?>/tozsamosc/mfa.php" class="<?= $TZ_ACTIVE === 'mfa' ? 'on' : '' ?>"><i class="bi bi-shield-check" aria-hidden="true"></i>Metody weryfikacji</a>
    <?php if ($__is_admin): ?>
    <div class="lab">Administracja</div>
    <?php foreach ($__admin_items as $__f => [$__l, $__i]): ?>
    <a href="<?= APP_URL ?>/tozsamosc/<?= $__f ?>" class="<?= $__script === $__f ? 'on' : '' ?>"><i class="bi <?= $__i ?>" aria-hidden="true"></i><?= h($__l) ?></a>
    <?php endforeach; ?>
    <?php endif; ?>
  </nav>
</header>
<?php if ($TZ_HERO_HTML !== ''): ?>
<div class="tz-band tz"><div class="inner"><?= $TZ_HERO_HTML ?></div></div>
<?php endif; ?>
<main id="tz-main" class="tz tz-wrap<?= $TZ_HERO_HTML !== '' ? ' tz-wrap--overlap' : '' ?>">
