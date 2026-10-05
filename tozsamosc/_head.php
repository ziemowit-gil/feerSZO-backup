<?php
/**
 * tozsamosc/_head.php — Wspólny layout (chrome) podsystemu Tożsamość.
 *
 * ODRĘBNY od chrome SZO (brak navbara/menu SZO). Własny pasek górny: marka
 * „System Tożsamości", powrót do SZO, wylogowanie. Wymaga zalogowanego usera
 * ($user / current_user()). Zmienne wejściowe: $PAGE_TITLE (opcjonalnie),
 * $TZ_ACTIVE (klucz aktywnej pozycji: 'konto'|'mfa'|'').
 *
 * Warstwa wizualna (2026-10-05): Tailwind (CDN, prefiks `tw-`, bez preflightu —
 * nie koliduje z Bootstrapem, który zostaje silnikiem formularzy/offcanvasów
 * na starszych stronach podsystemu: mfa.php, access.php, ldap.php, admin/users.php
 * pod TZ_USERS_CHROME) + Alpine.js (zakładki, filtry, menu). Klasy `.tz-*`
 * poniżej są WSPÓLNYM kontraktem tych stron — nie usuwać ani nie zmieniać nazw.
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
$__is_admin = function_exists('is_admin') && is_admin();
$__script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
// Strony administracyjne podsystemu (plik => [etykieta, ikona]) — rozwijane „Administracja" w pasku.
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
    || isset($__admin_items[$__script]) || in_array($__script, ['users.php', 'access.php'], true);
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
  <!-- Alpine.js — wtyczka focus MUSI ładować się przed rdzeniem (rejestruje się na obiekcie Alpine przed jego startem). -->
  <script src="https://cdn.jsdelivr.net/npm/@alpinejs/focus@3/dist/cdn.min.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js" defer></script>
  <!-- Tailwind — prefiks tw-, bez preflightu (jak w includes/header.php): zero kolizji z Bootstrapem. -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      prefix: 'tw-',
      corePlugins: { preflight: false },
    };
  </script>
  <style>
    :root{--tz:#2563eb;--tz-strong:#1d4ed8;--tz-50:#eff6ff;--tz-line:#e5e7eb;--tz-muted:#6b7280;--tz-canvas:#f6f7f9;}
    [x-cloak]{display:none!important}
    html{scroll-behavior:smooth}
    body{background:var(--tz-canvas);color:#111827;min-height:100vh;-webkit-font-smoothing:antialiased}
    .tz .lbl-en{font-size:.72rem;color:var(--tz-muted);font-weight:500;display:block;margin-top:.1rem}
    .tz-skip{position:absolute;left:-999px}.tz-skip:focus{left:1rem;top:1rem;z-index:1080;background:var(--tz);color:#fff;padding:.5rem 1rem;border-radius:8px}
    /* Topbar */
    .tzbar{background:rgba(255,255,255,.88);backdrop-filter:saturate(1.4) blur(10px);-webkit-backdrop-filter:saturate(1.4) blur(10px);border-bottom:1px solid var(--tz-line);position:sticky;top:0;z-index:1030}
    .tzbar .inner{max-width:1120px;margin:0 auto;padding:.5rem 1rem;display:flex;align-items:center;gap:.75rem}
    .tzbar .brand{display:flex;align-items:center;gap:.55rem;text-decoration:none;color:inherit}
    .tzbar .brand .mark{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#2563eb,#4f46e5);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1rem;box-shadow:0 4px 10px rgba(37,99,235,.25)}
    .tzbar .brand b{color:#111827;font-weight:700;line-height:1.05;font-size:.95rem;letter-spacing:-.01em}
    .tzbar .brand small{display:block;font-size:.65rem;color:var(--tz-muted);font-weight:500}
    .tzbar .spacer{flex:1}
    .tzbar .lnk{color:#374151;text-decoration:none;font-size:.82rem;font-weight:500;padding:.38rem .65rem;border-radius:8px;display:inline-flex;align-items:center;gap:.4rem;transition:background .12s,color .12s}
    .tzbar .lnk:hover{background:#f3f4f6;color:#111827}
    .tzbar .lnk:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
    .tzbar .lnk.on{background:var(--tz-50);color:var(--tz-strong)}
    .tzbar .ava{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#4f46e5);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.72rem;letter-spacing:.02em}
    .tzbar .who{display:none;flex-direction:column;line-height:1.1;margin-left:.1rem}
    .tzbar .who b{font-size:.78rem;color:#111827;font-weight:600;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .tzbar .who small{font-size:.64rem;color:var(--tz-muted)}
    @media(min-width:768px){.tzbar .who{display:flex}}
    .tzbar .lnk{font-family:inherit;border:0;cursor:pointer;background:transparent}
    .tzmenu{position:absolute;top:calc(100% + .35rem);left:0;min-width:250px;background:#fff;color:#111827;border:1px solid var(--tz-line);border-radius:12px;box-shadow:0 12px 32px -12px rgba(16,24,40,.25);padding:.35rem;z-index:1040}
    .tzmenu a{display:flex;align-items:center;gap:.6rem;padding:.48rem .65rem;border-radius:8px;color:#1f2937;text-decoration:none;font-size:.83rem;font-weight:500}
    .tzmenu a:hover,.tzmenu a:focus-visible{background:#f3f4f6;color:#111827;outline:none}
    .tzmenu a.on{background:var(--tz-50);color:var(--tz-strong)}
    .tzmenu a i{color:var(--tz-muted);width:1.1rem;text-align:center}.tzmenu a.on i{color:var(--tz-strong)}
    .tzmenu .lab{font-size:.64rem;text-transform:uppercase;letter-spacing:.08em;color:var(--tz-muted);font-weight:700;padding:.4rem .65rem .2rem}
    .tzmenu .sep{height:1px;background:var(--tz-line);margin:.3rem .2rem}
    .tz-wrap{max-width:1120px;margin:0 auto;padding:1.5rem 1rem 3rem}
    .tz-h{margin-bottom:1rem}
    .tz-h h1{font-size:1.35rem;font-weight:700;letter-spacing:-.01em;margin:0;color:#111827}
    .tz-h p{color:var(--tz-muted);margin:.1rem 0 0;font-size:.85rem}
    /* Karty (kontrakt starszych stron podsystemu) */
    .tz-card{background:#fff;border:1px solid var(--tz-line);border-radius:14px;overflow:hidden;margin-bottom:1rem;box-shadow:0 1px 2px rgba(16,24,40,.04)}
    .tz-card__hd{padding:.85rem 1.1rem;border-bottom:1px solid var(--tz-line);display:flex;align-items:center;gap:.6rem;font-weight:600;font-size:.92rem}
    .tz-card__hd i{color:var(--tz)}
    .tz-card__bd{padding:1.1rem}
    .tz-id{background:#fff;padding:1.1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;border-bottom:1px solid var(--tz-line)}
    .tz-id .tz-ava{width:44px;height:44px;border-radius:10px;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;flex-shrink:0}
    .tz-uid{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:700;font-size:1.4rem;letter-spacing:.04em;line-height:1;color:var(--tz-strong)}
    .tz-dl{display:grid;grid-template-columns:repeat(1,1fr)}
    @media(min-width:576px){.tz-dl{grid-template-columns:repeat(2,1fr)}}
    @media(min-width:992px){.tz-dl{grid-template-columns:repeat(3,1fr)}}
    .tz-dl>div{padding:.75rem 1.1rem;border-top:1px solid var(--tz-line)}
    .tz-dl dt{font-size:.68rem;color:var(--tz-muted);margin:0;text-transform:uppercase;letter-spacing:.04em;font-weight:600}
    .tz-dl dd{font-weight:600;margin:.15rem 0 0;font-size:.9rem;word-break:break-word;color:#111827}
    .tz-note{background:#f9fafb;border-top:1px solid var(--tz-line);padding:.55rem 1rem;font-size:.78rem;color:var(--tz-muted);display:flex;gap:.4rem;align-items:flex-start}
    .tz-btn{background:var(--tz-strong);color:#fff;border:none;border-radius:9px;padding:.55rem 1.2rem;font-weight:600;font-size:.87rem;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:background .12s,box-shadow .12s}
    .tz-btn:hover{background:#1e40af;color:#fff}
    .tz-btn:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
    .tz-btn--ghost{background:#fff;color:#374151;border:1.5px solid var(--tz-line)}
    .tz-btn--ghost:hover{background:#f9fafb;border-color:#d1d5db;color:#111827}
    .tz-badge{font-size:.7rem;font-weight:600;padding:.2rem .55rem;border-radius:999px;display:inline-flex;align-items:center;gap:.3rem}
    .tz-badge--ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
    .tz-badge--warn{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
    .tz-badge--off{background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb}
    .tz-otp{font-size:1.6rem;letter-spacing:.5rem;text-align:center;font-weight:700;font-family:ui-monospace,monospace}
    .tz-req{list-style:none;padding:0;margin:.5rem 0 0;display:grid;grid-template-columns:1fr;gap:.35rem;font-size:.85rem}
    @media(min-width:576px){.tz-req{grid-template-columns:1fr 1fr}}
    .tz-req li{color:var(--tz-muted);display:flex;align-items:center;gap:.4rem}
    .tz-req li.ok{color:#047857}
    .tz-req li .dot{display:inline-block;width:1.1em;text-align:center;font-weight:700}
    .tz-meter{height:6px;border-radius:999px;background:var(--tz-line);overflow:hidden;margin-top:.5rem}
    .tz-meter>span{display:block;height:100%;width:0;background:#f87171;transition:width .3s,background-color .3s}
    .tz-subnav{position:sticky;top:51px;z-index:5;background:var(--tz-canvas);padding:.5rem 0;margin-bottom:1rem}
    .tz-subnav .seg{display:inline-flex;gap:.15rem;padding:.2rem;background:#fff;border:1px solid var(--tz-line);border-radius:10px;flex-wrap:wrap}
    .tz-subnav a{font-size:.83rem;padding:.38rem .85rem;border-radius:7px;text-decoration:none;color:var(--tz-muted);display:inline-flex;align-items:center;gap:.35rem;font-weight:500}
    .tz-subnav a:hover,.tz-subnav a:focus-visible{background:#f3f4f6;color:#374151;outline:none}
    .tz-subnav a.on{background:var(--tz);color:#fff}.tz-subnav a.on i{color:#fff}
    .tz-panel{display:none}.tz-panel.active{display:block;animation:tzfade .15s ease}
    @keyframes tzfade{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
    .tz-svc{display:flex;align-items:center;gap:.85rem;padding:.8rem 0;border-top:1px solid var(--tz-line)}
    .tz-svc:first-child{border-top:0}
    .tz-svc__ico{width:38px;height:38px;border-radius:9px;background:var(--tz-50);color:var(--tz-strong);display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0}
    .tz-tiles{display:grid;gap:.85rem;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin:.2rem 0 .65rem}
    .tz-tile{display:block;text-align:left;background:#fff;border:1.5px solid var(--tz-line);border-radius:12px;padding:1rem;text-decoration:none;color:inherit;transition:border-color .13s,box-shadow .13s,transform .13s}
    .tz-tile:hover,.tz-tile:focus-visible{border-color:var(--tz);box-shadow:0 0 0 3px rgba(37,99,235,.12);color:inherit;outline:none;transform:translateY(-1px)}
    .tz-tile__ico{width:38px;height:38px;border-radius:9px;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:.6rem}
    .tz .form-control:focus,.tz .form-select:focus{border-color:var(--tz);box-shadow:0 0 0 .2rem rgba(37,99,235,.15)}
    .tz .form-control,.tz .form-select{border-radius:9px;border-color:#d1d5db}
    /* ── Kafel "bonto" (launcher zewnętrznych narzędzi) ── */
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
<header class="tzbar" x-data="{ adm: false }" @keydown.escape.window="adm = false">
  <div class="inner">
    <a href="<?= APP_URL ?>/tozsamosc/index.php" class="brand">
      <span class="mark" aria-hidden="true"><i class="bi bi-person-vcard-fill"></i></span>
      <span><b>System Tożsamości</b><small><?= h($__org) ?></small></span>
    </a>
    <nav class="d-none d-md-flex align-items-center gap-1 ms-2" aria-label="Sekcje systemu tożsamości">
      <a class="lnk<?= $TZ_ACTIVE === 'konto' ? ' on' : '' ?>" href="<?= APP_URL ?>/tozsamosc/index.php"<?= $TZ_ACTIVE === 'konto' ? ' aria-current="page"' : '' ?>><i class="bi bi-person-badge" aria-hidden="true"></i>Moja tożsamość</a>
      <a class="lnk<?= $TZ_ACTIVE === 'mfa' ? ' on' : '' ?>" href="<?= APP_URL ?>/tozsamosc/mfa.php"<?= $TZ_ACTIVE === 'mfa' ? ' aria-current="page"' : '' ?>><i class="bi bi-shield-check" aria-hidden="true"></i>Metody weryfikacji</a>
      <?php if ($__is_admin): ?>
      <span class="position-relative" @click.outside="adm = false">
        <button type="button" class="lnk<?= $__admin_active ? ' on' : '' ?>" @click="adm = !adm" :aria-expanded="adm ? 'true' : 'false'" aria-haspopup="menu">
          <i class="bi bi-shield-fill-gear" aria-hidden="true"></i>Administracja<i class="bi bi-chevron-down" aria-hidden="true" style="font-size:.6rem"></i>
        </button>
        <div class="tzmenu" x-show="adm" x-cloak x-transition.opacity.duration.120ms role="menu" aria-label="Administracja tożsamością">
          <div class="lab">Administracja tożsamością</div>
          <?php foreach ($__admin_items as $__f => [$__l, $__i]): $__on = ($__script === $__f) || ($__f === 'uzytkownicy.php' && ($__script === 'users.php' || in_array($TZ_ACTIVE, ['users', 'uzytkownicy'], true))); ?>
          <a href="<?= APP_URL ?>/tozsamosc/<?= $__f ?>" class="<?= $__on ? 'on' : '' ?>" role="menuitem"<?= $__on ? ' aria-current="page"' : '' ?>><i class="bi <?= $__i ?>" aria-hidden="true"></i><?= h($__l) ?></a>
          <?php endforeach; ?>
          <div class="sep"></div>
          <a href="<?= APP_URL ?>/admin/index.php" role="menuitem"><i class="bi bi-gear" aria-hidden="true"></i>Panel administracyjny SZO</a>
        </div>
      </span>
      <?php endif; ?>
    </nav>
    <span class="spacer"></span>
    <a class="lnk" href="<?= APP_URL ?>/portal.php"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="d-none d-sm-inline">Wróć do SZO</span></a>
    <span class="ava" title="<?= h($__name) ?>" aria-hidden="true"><?= h($__init) ?></span>
    <span class="who" aria-hidden="true"><b><?= h($__name) ?></b><small><?= h($__u['email'] ?? '') ?></small></span>
    <a class="lnk" href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span class="d-none d-sm-inline">Wyloguj</span></a>
  </div>
</header>
<main id="tz-main" class="tz tz-wrap">
