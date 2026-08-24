<?php
/**
 * panel/includes/header_panel.php — Standalone layout panelu wolontariusza.
 * Stały kolor — nie zależy od ustawień organizacji.
 */

if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_once dirname(dirname(__DIR__)) . '/includes/asystent_ai.php';

require_login();

$_pv_title  = $PAGE_TITLE ?? 'Panel wolontariusza';
$_pv_uri    = $_SERVER['REQUEST_URI'] ?? '';
$_pv_org    = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');

// Migracja kolumn per-user
try { db()->exec("ALTER TABLE users ADD COLUMN panel_color TEXT"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN panel_theme TEXT"); } catch (\Throwable $e) {}

// Kolor i motyw: per-user, fallback na org setting
$_pv_user_row  = db_one("SELECT panel_color, panel_theme FROM users WHERE id=?", [(int)(current_user()['id'] ?? 0)]);
$_pv_theme     = in_array($_pv_user_row['panel_theme'] ?? '', ['light','dark','hc'], true)
    ? $_pv_user_row['panel_theme'] : '';
$_vol_color    = (($_pv_user_row['panel_color'] ?? '') !== '')
    ? $_pv_user_row['panel_color']
    : (org_setting('volunteer_color') ?: '#1D4ED8');

// Lekkie tło (10% krycia na białym) — bez zewnętrznych zależności
function _pv_light_bg(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
    return sprintf('#%02X%02X%02X',
        (int)round($r*.1 + 255*.9),
        (int)round($g*.1 + 255*.9),
        (int)round($b*.1 + 255*.9)
    );
}
$_vol_bg = _pv_light_bg($_vol_color);

$_pu = current_user();
$_pu_ini = '?'; $_pu_name = '';
if ($_pu) {
    $n = trim(($_pu['first_name']??'').' '.($_pu['last_name']??'')) ?: ($_pu['name']??'');
    $_pu_name = $n ?: ($_pu['email']??'Użytkownik');
    $_pu_ini = '';
    foreach (preg_split('/\s+/', trim($n)) as $w) $_pu_ini .= mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8');
    $_pu_ini = mb_substr($_pu_ini, 0, 2, 'UTF-8') ?: '?';
}

function _pv_nav_active(string $path): string {
    global $_pv_uri;
    return str_contains($_pv_uri, $path) ? ' pv-active' : '';
}

// ── Oświadczenie o ochronie danych — blokada panelu do momentu podpisania ─────
try { db()->exec("ALTER TABLE users ADD COLUMN gdpr_statement_signed_at DATETIME"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN gdpr_statement_ip TEXT"); } catch (\Throwable $e) {}
$_gdpr_signed   = !empty(db_one("SELECT gdpr_statement_signed_at FROM users WHERE id=?",
    [(int)($_pu['id'] ?? 0)])['gdpr_statement_signed_at']);
$_gdpr_has_m365 = false; // zachowane tylko do wyświetlenia ikony w menu
if (!$_gdpr_signed && basename($_SERVER['SCRIPT_NAME']) !== 'gdpr_statement.php') {
    header('Location: ' . APP_URL . '/panel/gdpr_statement.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="pl"<?= $_pv_theme ? ' data-theme="'.h($_pv_theme).'"' : '' ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($_pv_title) ?> — Panel<?= $_pv_org ? ' · '.h($_pv_org) : '' ?></title>
<link rel="manifest" href="<?= APP_URL ?>/manifest.php">
<meta name="theme-color" content="<?= h($_vol_color) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
:root {
  --vol-color: <?= h($_vol_color) ?>;
  --vol-bg:    <?= h($_vol_bg) ?>;
  --vol-on:    #ffffff;
  /* Tokeny layoutu (navbar / sidebar / body) */
  --pvl-body:#FFFFFF;--pvl-nav:#fff;--pvl-nav-b:#E5E7EB;
  --pvl-txt:#374151;--pvl-sub:#9CA3AF;
  --pvl-btn:#fff;--pvl-btn-b:#E5E7EB;--pvl-btn-t:#374151;
  --pvl-div:#F3F4F6;--pvl-brand:#111827;
  --bs-offcanvas-bg:var(--pvl-nav);
}
/* Ciemny motyw systemowy (nie nadpisuje explicit light/hc) */
@media(prefers-color-scheme:dark){
  :root:not([data-theme="light"]):not([data-theme="hc"]){
    --pvl-body:#090e1a;--pvl-nav:#0f172a;--pvl-nav-b:#1e2535;
    --pvl-txt:#cbd5e0;--pvl-sub:#64748b;
    --pvl-btn:#1e2535;--pvl-btn-b:#2d3748;--pvl-btn-t:#e2e8f0;
    --pvl-div:#1e2535;--pvl-brand:#f1f5f9;
  }
}
:root[data-theme="dark"]{--pvl-body:#090e1a;--pvl-nav:#0f172a;--pvl-nav-b:#1e2535;--pvl-txt:#cbd5e0;--pvl-sub:#64748b;--pvl-btn:#1e2535;--pvl-btn-b:#2d3748;--pvl-btn-t:#e2e8f0;--pvl-div:#1e2535;--pvl-brand:#f1f5f9}
:root[data-theme="hc"]{--pvl-body:#fff;--pvl-nav:#fff;--pvl-nav-b:#000;--pvl-txt:#000;--pvl-sub:#111;--pvl-btn:#fff;--pvl-btn-b:#000;--pvl-btn-t:#000;--pvl-div:#000;--pvl-brand:#000}
/* Akcenty Bootstrap idą kolorem panelu (przyciski .btn-primary, linki) */
:root{--bs-primary:var(--vol-color);--bs-link-color:var(--vol-color);--bs-link-hover-color:var(--vol-color)}
.btn-primary{--bs-btn-bg:var(--vol-color);--bs-btn-border-color:var(--vol-color);--bs-btn-hover-bg:var(--vol-color);--bs-btn-hover-border-color:var(--vol-color);--bs-btn-active-bg:var(--vol-color);--bs-btn-active-border-color:var(--vol-color)}
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0}
body{background:var(--pvl-body);font-family:system-ui,-apple-system,sans-serif;min-height:100vh;display:flex;flex-direction:column}

/* Skip link */
.pv-skip{position:absolute;top:-100%;left:1rem;z-index:9999;background:var(--vol-color);color:var(--vol-on);padding:.75rem 1.5rem;border-radius:0 0 8px 8px;font-size:1rem;font-weight:700;text-decoration:none;border:3px solid #FBBF24}
.pv-skip:focus{top:0}
*:focus-visible{outline:3px solid #FBBF24 !important;outline-offset:3px !important;border-radius:3px}

/* Górny pasek (navbar) */
.pv-navbar{background:var(--pvl-nav);border-bottom:1px solid var(--pvl-nav-b);box-shadow:0 1px 3px rgba(0,0,0,.04);position:sticky;top:0;z-index:1040}
.pv-menu-btn{border:1px solid var(--pvl-btn-b);background:var(--pvl-btn);border-radius:9px;width:40px;height:40px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;color:var(--pvl-btn-t);cursor:pointer;flex-shrink:0;transition:border-color .12s,color .12s}
.pv-menu-btn:hover{border-color:var(--vol-color);color:var(--vol-color)}
.pv-brand{display:flex;align-items:center;gap:.6rem;text-decoration:none;color:var(--pvl-brand);font-weight:800;font-size:.98rem;min-width:0}
.pv-brand:hover{color:var(--pvl-brand)}
.pv-brand-icon{width:34px;height:34px;background:var(--vol-color);color:var(--vol-on);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.pv-brand-sub{font-size:.66rem;opacity:.6;font-weight:500;line-height:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px}
.pv-avatar{width:36px;height:36px;border-radius:50%;background:var(--vol-color);color:var(--vol-on);display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;cursor:pointer;border:none;line-height:1}

/* Menu sekcji w offcanvas */
.pv-offcanvas{max-width:285px}
.pv-offcanvas .offcanvas-header{background:var(--vol-color);color:var(--vol-on)}
.pv-offcanvas .offcanvas-body{display:flex;flex-direction:column;padding:.35rem 0}
.pv-nav-label{font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--pvl-sub);padding:.85rem 1rem .3rem;user-select:none}
.pv-nav-link{display:flex;align-items:center;gap:.6rem;padding:.5rem .75rem;border-radius:8px;font-size:.88rem;font-weight:500;color:var(--pvl-txt);text-decoration:none;transition:background .1s,color .1s,border-color .1s;margin:.05rem .5rem;border-left:3px solid transparent}
.pv-nav-link i{font-size:1rem;width:20px;text-align:center;flex-shrink:0;color:var(--pvl-sub);transition:color .1s}
.pv-nav-link:hover{background:var(--vol-bg);color:var(--vol-color);border-left-color:var(--vol-color)}
.pv-nav-link:hover i{color:var(--vol-color)}
.pv-nav-link.pv-active{background:var(--vol-bg);color:var(--vol-color);font-weight:700;border-left-color:var(--vol-color)}
.pv-nav-link.pv-active i{color:var(--vol-color)}
.pv-nav-link .pv-badge{margin-left:auto;background:var(--vol-color);color:var(--vol-on);font-size:.65rem;font-weight:700;padding:.1rem .4rem;border-radius:10px;min-width:18px;text-align:center}
.pv-nav-divider{height:1px;background:var(--pvl-div);margin:.4rem .75rem}
.pv-sidebar-bottom{margin-top:auto;border-top:1px solid var(--pvl-div);padding:.5rem}

/* Treść — wyśrodkowany kontener */
#pv-main{flex:1 0 auto;width:100%}
.pv-footer{border-top:1px solid var(--pvl-nav-b);padding:.6rem 1.5rem;font-size:.75rem;color:var(--pvl-sub);background:var(--pvl-nav);display:flex;justify-content:space-between;flex-wrap:wrap;gap:.5rem}

/* Live region */
.pv-live{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}

@media(max-width:768px){ #pv-main{padding-left:.75rem;padding-right:.75rem} }
@media(prefers-contrast:high){.pv-nav-link{border-left-width:5px}.pv-nav-link.pv-active{border-left-width:5px}}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
/* Wysoki kontrast — layout */
[data-theme="hc"] .pv-navbar{border-bottom-width:2px;box-shadow:none}
[data-theme="hc"] .pv-menu-btn{border-width:2px}
[data-theme="hc"] .pv-nav-link:hover,[data-theme="hc"] .pv-nav-link.pv-active{background:#000;color:#fff;border-left-color:#000}
[data-theme="hc"] .pv-nav-link:hover i,[data-theme="hc"] .pv-nav-link.pv-active i{color:#fff}
[data-theme="hc"] .pv-nav-divider{height:2px}
[data-theme="hc"] .pv-footer{border-top-width:2px}
[data-theme="hc"] .dropdown-menu{border:2px solid #000}
[data-theme="hc"] .dropdown-item:hover,[data-theme="hc"] .dropdown-item:focus{background:#000;color:#fff}

/* ═══════════════════════════════════════════════════════════════════════════
 * Warstwa wizualna modułu Tożsamość (tozsamosc/_head.php) — panel ma wyglądać
 * tak samo: spokojny biały pasek, kolumna 960 px, karty 12 px z cienką ramką,
 * przyciski 8 px/600, pigułkowe znaczniki. Kolor akcentu pozostaje kolorem
 * wybranym przez wolontariusza (--vol-color), struktura jest z Tożsamości.
 * Tryb wysokiego kontrastu (data-theme="hc") zachowuje własne reguły.
 * ═══════════════════════════════════════════════════════════════════════════ */
:root { --tz-line:#e5e7eb; --tz-muted:#6b7280; --tz-radius:12px; }
:root:not([data-theme="hc"]) { --pvl-nav-b:var(--tz-line); }

/* Pasek górny 1:1 jak .tzbar w Tożsamości: płaski, bez cienia, kolumna 960 px,
   stonowane linki tekstowe zamiast wypełnionych przycisków. */
:root:not([data-theme="hc"]) .pv-navbar { box-shadow:none; border-bottom:1px solid var(--tz-line); }
.pv-bar-inner { max-width:960px; margin:0 auto; padding:.55rem 1rem; display:flex; align-items:center; gap:.75rem; }
.pv-bar-inner .pv-brand { font-weight:700; font-size:.95rem; line-height:1.05; }
.pv-bar-inner .pv-brand-icon { width:32px; height:32px; border-radius:8px; font-size:.95rem; }
.pv-bar-inner .pv-brand-sub { font-size:.65rem; color:var(--pvl-sub); opacity:1; }
.pv-bar-inner .pv-avatar { width:30px; height:30px; font-size:.72rem; }
/* Przycisk menu — jedyne wejście do nawigacji panelu, więc musi być widoczny
   od pierwszego spojrzenia: ikona + słowo „Menu" w ramce koloru wolontariusza. */
.pv-bar-inner .pv-menu-btn { width:auto; height:34px; padding:0 .7rem; border:1.5px solid var(--vol-color);
  background:var(--vol-bg); border-radius:8px; display:inline-flex; align-items:center; gap:.4rem;
  font-size:.85rem; font-weight:700; color:var(--vol-color); }
.pv-bar-inner .pv-menu-btn > i { font-size:1.15rem; line-height:1 }
.pv-bar-inner .pv-menu-btn:hover, .pv-bar-inner .pv-menu-btn:focus-visible { background:var(--vol-color); color:var(--vol-on); border-color:var(--vol-color); }
@media(max-width:420px){ .pv-menu-btn__lbl { display:none } .pv-bar-inner .pv-menu-btn { padding:0 .5rem } }
[data-theme="hc"] .pv-bar-inner .pv-menu-btn { border:2px solid #000; background:#fff; color:#000 }
[data-theme="hc"] .pv-bar-inner .pv-menu-btn:hover, [data-theme="hc"] .pv-bar-inner .pv-menu-btn:focus-visible { background:#000; color:#fff }
.pv-bar-inner > nav { margin-left:auto; display:flex; align-items:center; gap:.15rem; }
.pv-lnk { color:var(--pvl-sub); text-decoration:none; font-size:.83rem; padding:.35rem .6rem; border-radius:6px;
  display:inline-flex; align-items:center; gap:.35rem; background:none; border:none; cursor:pointer; white-space:nowrap; }
.pv-lnk:hover, .pv-lnk:focus-visible { background:var(--pvl-div); color:var(--pvl-txt); }
.pv-lnk-badge { background:var(--vol-color); color:var(--vol-on); font-size:.62rem; font-weight:700; line-height:1;
  padding:.15rem .35rem; border-radius:999px; }
[data-theme="hc"] .pv-lnk { color:#000; border:1px solid #000; }
[data-theme="hc"] .pv-lnk:hover { background:#000; color:#fff; }
:root:not([data-theme="hc"]) { --pvl-sub:var(--tz-muted); }

/* Nagłówek strony — .tz-h */
#pv-main > h1:first-child, #pv-main .pv-page-title, #pv-main .tz-h h1 {
  font-size:1.35rem; font-weight:700; letter-spacing:-.01em; margin:0 0 .1rem; color:var(--pvl-brand);
}
#pv-main .tz-h { margin-bottom:1rem }
#pv-main .tz-h p { color:var(--pvl-sub); margin:.1rem 0 0; font-size:.85rem }

/* Karty Bootstrap w wyglądzie .tz-card */
:root:not([data-theme="hc"]) #pv-main .card {
  background:var(--pvl-nav); border:1px solid var(--tz-line); border-radius:var(--tz-radius);
  box-shadow:none; overflow:hidden;
}
:root:not([data-theme="hc"]) #pv-main .card-header {
  background:var(--pvl-nav); border-bottom:1px solid var(--tz-line);
  padding:.85rem 1.1rem; font-weight:600; font-size:.92rem; color:var(--pvl-brand);
}
:root:not([data-theme="hc"]) #pv-main .card-header i { color:var(--vol-color) }
#pv-main .card-body { padding:1.1rem }
:root:not([data-theme="hc"]) #pv-main .card-footer {
  background:#f9fafb; border-top:1px solid var(--tz-line); font-size:.78rem; color:var(--pvl-sub);
}

/* Przyciski, pola i znaczniki — kształty z Tożsamości */
#pv-main .btn, .pv-offcanvas .btn { border-radius:8px; font-weight:600; font-size:.87rem }
#pv-main .btn-lg { border-radius:10px; font-size:.95rem }
#pv-main .btn-sm { border-radius:7px; font-size:.78rem }
#pv-main .badge { border-radius:999px; font-weight:600 }
:root:not([data-theme="hc"]) #pv-main .form-control,
:root:not([data-theme="hc"]) #pv-main .form-select { border-radius:8px; border-color:var(--tz-line) }
:root:not([data-theme="hc"]) #pv-main .list-group-item { border-color:var(--tz-line) }
:root:not([data-theme="hc"]) #pv-main .table > :not(caption) > * > * { border-color:var(--tz-line) }

/* Komponenty Tożsamości dostępne wprost w treści panelu (tz-*) */
#pv-main .tz-card { background:var(--pvl-nav); border:1px solid var(--tz-line); border-radius:var(--tz-radius); overflow:hidden; margin-bottom:1rem }
#pv-main .tz-card__hd { padding:.85rem 1.1rem; border-bottom:1px solid var(--tz-line); display:flex; align-items:center; gap:.6rem; font-weight:600; font-size:.92rem }
#pv-main .tz-card__hd i { color:var(--vol-color) }
#pv-main .tz-card__bd { padding:1.1rem }
#pv-main .tz-btn { background:var(--vol-color); color:var(--vol-on); border:none; border-radius:8px; padding:.55rem 1.2rem;
  font-weight:600; font-size:.87rem; display:inline-flex; align-items:center; gap:.4rem; text-decoration:none }
#pv-main .tz-btn:hover { filter:brightness(.92); color:var(--vol-on) }
#pv-main .tz-btn--ghost { background:var(--pvl-nav); color:var(--pvl-txt); border:1.5px solid var(--tz-line) }
#pv-main .tz-btn--ghost:hover { background:#f9fafb; color:var(--pvl-brand) }
#pv-main .tz-badge { font-size:.7rem; font-weight:600; padding:.2rem .55rem; border-radius:999px; display:inline-flex; align-items:center; gap:.3rem }
#pv-main .tz-badge--ok { background:#ecfdf5; color:#047857; border:1px solid #a7f3d0 }
#pv-main .tz-badge--warn { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa }
#pv-main .tz-badge--off { background:#f3f4f6; color:#6b7280; border:1px solid #e5e7eb }
#pv-main .tz-dl { display:grid; grid-template-columns:1fr }
@media(min-width:576px){ #pv-main .tz-dl { grid-template-columns:repeat(2,1fr) } }
@media(min-width:992px){ #pv-main .tz-dl { grid-template-columns:repeat(3,1fr) } }
#pv-main .tz-dl > div { padding:.75rem 1.1rem; border-top:1px solid var(--tz-line) }
#pv-main .tz-dl dt { font-size:.68rem; color:var(--pvl-sub); margin:0; text-transform:uppercase; letter-spacing:.04em; font-weight:600 }
#pv-main .tz-dl dd { font-weight:600; margin:.15rem 0 0; font-size:.9rem; word-break:break-word; color:var(--pvl-brand) }
#pv-main .tz-tiles { display:grid; gap:.85rem; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); margin:.2rem 0 .65rem }
#pv-main .tz-tile { display:block; text-align:left; background:var(--pvl-nav); border:1.5px solid var(--tz-line); border-radius:10px;
  padding:1rem; text-decoration:none; color:inherit; transition:border-color .13s, box-shadow .13s }
#pv-main .tz-tile:hover, #pv-main .tz-tile:focus-visible { border-color:var(--vol-color); box-shadow:0 0 0 3px var(--vol-bg); outline:none }
#pv-main .tz-tile__ico { width:38px; height:38px; border-radius:9px; background:var(--vol-color); color:var(--vol-on);
  display:flex; align-items:center; justify-content:center; font-size:1.1rem; margin-bottom:.6rem }
#pv-main .tz-note { background:#f9fafb; border-top:1px solid var(--tz-line); padding:.55rem 1rem; font-size:.78rem;
  color:var(--pvl-sub); display:flex; gap:.4rem; align-items:flex-start }
</style>
<?php /* Wspólny system stylów podstron (.pv-page-*, .pv-card, .vol-detail-*, …) */ ?>
<?php require_once __DIR__ . '/pv_styles.php'; ?>
<?php /* Tło aplikacji — geometria jak na ekranie logowania (kolor kanwy z motywu panelu) */ ?>
<?php require_once dirname(dirname(__DIR__)) . '/includes/app_bg.php'; app_bg_css('var(--pvl-body)'); ?>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('<?= APP_URL ?>/sw.js')
    .catch(e => console.warn('SW:', e));
}
</script>
</head>
<body>
<?= function_exists('ctx_banner_html') ? ctx_banner_html() : '' ?>
<a href="#pv-main" class="pv-skip">Przejdź do treści</a>
<div role="status" aria-live="polite" class="pv-live" id="pv-live"></div>

<?php
// Komunikaty — liczba nieprzeczytanych (odznaka w pasku). Idempotentny require.
$_pv_unread = 0;
try {
    require_once dirname(dirname(__DIR__)) . '/includes/notifications.php';
    if (function_exists('notif_unread_count')) $_pv_unread += notif_unread_count((int)($_pu['id'] ?? 0));
    if (function_exists('ann_list_for_user')) {
        $_ann = ann_list_for_user((int)($_pu['id'] ?? 0), $_pu['role'] ?? '');
        $_pv_unread += count(array_filter($_ann, fn($a) => empty($a['is_read_by_me'])));
    }
} catch (\Throwable $e) {}
?>
<!-- Navbar -->
<header class="pv-navbar" role="banner">
  <div class="pv-bar-inner">
    <button class="pv-menu-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#pvNav"
            aria-controls="pvNav" aria-label="Otwórz menu nawigacji panelu">
      <i class="bi bi-list" aria-hidden="true"></i>
      <span class="pv-menu-btn__lbl">Menu</span>
    </button>
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-brand" aria-label="Panel wolontariusza — strona główna">
      <span class="pv-brand-icon" aria-hidden="true"><i class="bi bi-person-circle"></i></span>
      <span class="d-flex flex-column">
        <span>Panel</span>
        <?php if ($_pv_org): ?><span class="pv-brand-sub" title="<?= h($_pv_org) ?>"><?= h($_pv_org) ?></span><?php endif; ?>
      </span>
    </a>
    <nav class="ms-auto d-flex align-items-center gap-2" aria-label="Akcje użytkownika">
      <a href="<?= APP_URL ?>/komunikaty/index.php" class="pv-lnk"
         title="Komunikaty organizacji"
         aria-label="Komunikaty<?= $_pv_unread ? " — {$_pv_unread} nieprzeczytanych" : '' ?>">
        <i class="bi bi-megaphone<?= $_pv_unread ? '-fill' : '' ?>" aria-hidden="true"></i>
        <span class="d-none d-sm-inline">Komunikaty</span>
        <?php if ($_pv_unread): ?>
        <span class="pv-lnk-badge"><?= min($_pv_unread, 99) ?></span>
        <?php endif; ?>
      </a>
      <?php if (current_user() && org_setting('bug_report_enabled') !== '0'): ?>
      <button type="button" class="pv-lnk"
              data-bs-toggle="modal" data-bs-target="#bugReportModal"
              title="Zgłoś błąd na tej stronie" aria-label="Zgłoś błąd">
        <i class="bi bi-bug-fill" aria-hidden="true"></i>
        <span class="d-none d-sm-inline">Zgłoś błąd</span>
      </button>
      <?php endif; ?>
      <?php if ($_pu): ?>
      <div class="dropdown">
        <button type="button" class="pv-avatar" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Menu użytkownika <?= h($_pu_name) ?>">
          <?= h($_pu_ini) ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size:.88rem;min-width:200px">
          <li class="px-3 py-2 border-bottom">
            <div class="fw-bold"><?= h($_pu_name) ?></div>
            <div class="text-muted small"><?= h($_pu['email']??'') ?></div>
          </li>
          <li><a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/password.php"><i class="bi bi-gear me-2" aria-hidden="true"></i>Ustawienia konta</a></li>
          <li><a class="dropdown-item py-2" href="<?= APP_URL ?>/panel/panel_color.php"><i class="bi bi-palette2 me-2" aria-hidden="true"></i>Motyw i kontrast</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item py-2 text-danger" href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Wyloguj się</a></li>
        </ul>
      </div>
      <?php endif; ?>
    </nav>
  </div>
</header>
<?php require_once dirname(dirname(__DIR__)) . '/includes/bug_report_widget.php'; ?>
<?php $ASAI_WIDGET_SCOPE = 'panel';
      require_once dirname(dirname(__DIR__)) . '/includes/asystent_widget.php'; ?>

<!-- Menu sekcji (offcanvas) -->
<div class="offcanvas offcanvas-start pv-offcanvas" tabindex="-1" id="pvNav" aria-label="Nawigacja panelu wolontariusza">
  <div class="offcanvas-header">
    <span class="offcanvas-title fw-bold d-flex align-items-center gap-2">
      <i class="bi bi-person-circle" aria-hidden="true"></i>Panel wolontariusza
    </span>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Zamknij menu"></button>
  </div>
  <nav class="offcanvas-body" aria-label="Sekcje panelu">
  <?php
  // Liczba otwartych zgłoszeń helpdesk (dla odznaki)
  $_hd_open = 0;
  try {
      $_hd_open = (int)(db_one(
          "SELECT COUNT(*) AS c FROM helpdesk_tickets
           WHERE requester_id=? AND status NOT IN ('zamknięte','rozwiązane')",
          [(int)($_pu['id'] ?? 0)]
      )['c'] ?? 0);
  } catch (\Throwable $e) {}

  // Zgoda przedstawiciela ustawowego na wolontariat małoletniego — liczba umów
  // wymagających złożenia/odnowienia zgody, dopasowanie po e-mailu opiekuna.
  $_gc_pending = 0;
  try {
      require_once dirname(dirname(__DIR__)) . '/includes/guardian_consent.php';
      $_gc_pending = count(guardian_consent_pending_for_email($_pu['email'] ?? ''));
  } catch (\Throwable $e) {}

  // Pokaż link do RODO jeśli użytkownik ma aktywne upoważnienie
  $_has_rodo = false;
  try {
      $_pv_email = $_pu['email'] ?? '';
      if ($_pv_email) {
          $_wol_ids = db_all("SELECT id FROM umowy_wolontariat WHERE email=? OR m365_login=? LIMIT 5", [$_pv_email, $_pv_email]);
          if ($_wol_ids) {
              $_wol_ph = implode(',', array_fill(0, count($_wol_ids), '?'));
              $_rodo_check = db_one(
                  "SELECT id FROM rodo_authorizations WHERE contract_type='wolontariat' AND contract_id IN ({$_wol_ph}) LIMIT 1",
                  array_column($_wol_ids, 'id')
              );
              $_has_rodo = (bool)$_rodo_check;
          }
      }
  } catch (\Throwable $e) {}
  ?>

  <?php /* ── Pulpit: najważniejsze codzienne akcje (bez nagłówka) ──────── */ ?>
  <a href="<?= APP_URL ?>/panel/index.php" class="pv-nav-link<?= _pv_nav_active('/panel/index') ?>" aria-label="Moja umowa — przegląd">
    <i class="bi bi-house-door" aria-hidden="true"></i>Pulpit
  </a>
  <?php if (module_enabled('messages_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/messages.php" class="pv-nav-link<?= _pv_nav_active('/panel/messages') ?>" aria-label="Wiadomości">
    <i class="bi bi-chat-left-text" aria-hidden="true"></i>Wiadomości
  </a>
  <?php endif; ?>
  <?php if (module_enabled('tasks_enabled')):
      $_my_tasks_count = 0;
      try {
          $_my_tasks_count = (int)(db_one(
              "SELECT COUNT(*) AS c FROM tasks t JOIN task_assignments ta ON ta.task_id=t.id
               WHERE ta.user_id=? AND t.completed_at IS NULL AND t.deleted_at IS NULL",
              [(int)(current_user()['id'] ?? 0)]
          )['c'] ?? 0);
      } catch (\Throwable $e) {}
  ?>
  <a href="<?= APP_URL ?>/tasks/index.php" class="pv-nav-link<?= _pv_nav_active('/tasks/') ?>"
     aria-label="Zadania<?= $_my_tasks_count ? " — {$_my_tasks_count} przypisanych" : '' ?>">
    <i class="bi bi-list-check" style="color:#059669" aria-hidden="true"></i>Zadania
    <?php if ($_my_tasks_count): ?>
    <span class="pv-badge" style="background:#059669" aria-label="<?= $_my_tasks_count ?> przypisanych zadań"><?= $_my_tasks_count ?></span>
    <?php endif; ?>
  </a>
  <?php endif; ?>
  <a href="<?= APP_URL ?>/panel/apply.php" class="pv-nav-link<?= _pv_nav_active('/panel/apply') ?>">
    <i class="bi bi-send" aria-hidden="true"></i>Wyślij wniosek
  </a>
  <a href="<?= APP_URL ?>/panel/helpdesk.php"
     class="pv-nav-link<?= _pv_nav_active('/panel/helpdesk') ?>"
     aria-label="Helpdesk IT<?= $_hd_open ? " — {$_hd_open} otwartych" : '' ?>">
    <i class="bi bi-headset" aria-hidden="true"></i>Helpdesk IT
    <?php if ($_hd_open): ?>
    <span class="pv-badge" aria-label="<?= $_hd_open ?> otwartych zgłoszeń"><?= $_hd_open ?></span>
    <?php endif; ?>
  </a>

  <div class="pv-nav-divider" role="separator" aria-hidden="true"></div>
  <div class="pv-nav-label" aria-hidden="true">Moje koszulki</div>

  <?php if (module_enabled('letters_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/letters.php" class="pv-nav-link<?= _pv_nav_active('/panel/letters') ?>">
    <i class="bi bi-archive" aria-hidden="true"></i>Pisma
  </a>
  <?php endif; ?>
  <?php if (module_enabled('dyspozycyjnosc_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/dyspozycyjnosc.php" class="pv-nav-link<?= _pv_nav_active('/panel/dyspozycyjnosc') ?>"
     aria-label="Moja dyspozycyjność i urlopy">
    <i class="bi bi-calendar-heart" aria-hidden="true"></i>Dyspozycyjność i urlopy
  </a>
  <?php endif; ?>
  <?php if (module_enabled('timesheets_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/timesheets.php" class="pv-nav-link<?= _pv_nav_active('/panel/timesheets') ?>">
    <i class="bi bi-clock-history" aria-hidden="true"></i>Ewidencja godzin
  </a>
  <?php endif; ?>
  <?php if (module_enabled('certificates_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/certificates.php" class="pv-nav-link<?= _pv_nav_active('/panel/certificates') ?>">
    <i class="bi bi-award" aria-hidden="true"></i>Zaświadczenia
  </a>
  <?php endif; ?>
  <?php if (module_enabled('ezd_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/zaswiadczenia.php" class="pv-nav-link<?= _pv_nav_active('/panel/zaswiadczenia') ?>">
    <i class="bi bi-award-fill" aria-hidden="true"></i>Zaświadczenia EZD
  </a>
  <?php endif; ?>
  <?php if (module_enabled('moodle_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/moodle.php" class="pv-nav-link<?= _pv_nav_active('/panel/moodle') ?>">
    <i class="bi bi-mortarboard" aria-hidden="true"></i>Kursy Moodle
  </a>
  <?php endif; ?>
  <?php if (module_enabled('tidycal_enabled') && trim(org_setting('tidycal_api_key')) !== ''): ?>
  <a href="<?= APP_URL ?>/panel/szkolenie.php" class="pv-nav-link<?= _pv_nav_active('/panel/szkolenie') ?>">
    <i class="bi bi-calendar2-check" aria-hidden="true"></i>Umów się na szkolenie
  </a>
  <?php endif; ?>
  <?php if ($_has_rodo): ?>
  <a href="<?= APP_URL ?>/panel/rodo.php" class="pv-nav-link<?= _pv_nav_active('/panel/rodo') ?>">
    <i class="bi bi-shield-lock" aria-hidden="true"></i>Upoważnienie RODO
  </a>
  <?php endif; ?>
  <?php if ($_gc_pending): ?>
  <a href="<?= APP_URL ?>/panel/zgody.php" class="pv-nav-link<?= _pv_nav_active('/panel/zgody') ?>"
     aria-label="Zgody i Oświadczenia — <?= $_gc_pending ?> do odnowienia">
    <i class="bi bi-file-earmark-check" aria-hidden="true"></i>Zgody i Oświadczenia
    <span class="pv-badge" aria-label="<?= $_gc_pending ?> do odnowienia"><?= $_gc_pending ?></span>
  </a>
  <?php endif; ?>
  <?php if (module_enabled('terminations_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/terminations.php" class="pv-nav-link<?= _pv_nav_active('/panel/terminations') ?>">
    <i class="bi bi-file-earmark-x" aria-hidden="true"></i>Rozwiązanie umowy
  </a>
  <?php endif; ?>
  <?php if (module_enabled('doc_signing_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/sign_document.php" class="pv-nav-link<?= _pv_nav_active('/panel/sign_document') ?>">
    <i class="bi bi-pen" aria-hidden="true"></i>Podpisz dokument
  </a>
  <?php endif; ?>

  <div class="pv-nav-divider" role="separator" aria-hidden="true"></div>
  <div class="pv-nav-label" aria-hidden="true">Organizacja</div>

  <?php if (panel_visible('asystent') && function_exists('asai_enabled') && asai_enabled()): ?>
  <a href="<?= APP_URL ?>/panel/asystent.php" class="pv-nav-link<?= _pv_nav_active('/panel/asystent') ?>"
     aria-label="Asystent AI — pytania o procedury, system i moje sprawy">
    <i class="bi bi-stars" style="color:#7c3aed" aria-hidden="true"></i>Asystent AI
  </a>
  <?php endif; ?>
  <?php if (module_enabled('procedures_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/procedures.php" class="pv-nav-link<?= _pv_nav_active('/panel/procedures') ?>"
     aria-label="Procedury i instrukcje organizacji">
    <i class="bi bi-journal-text" aria-hidden="true"></i>Procedury
  </a>
  <?php endif; ?>
  <?php if (module_enabled('org_documents_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/org_documents.php" class="pv-nav-link<?= _pv_nav_active('/panel/org_documents') ?>"
     aria-label="Dokumenty organizacji do pobrania">
    <i class="bi bi-folder2-open" aria-hidden="true"></i>Dokumenty organizacji
  </a>
  <?php endif; ?>
  <?php if (module_enabled('whatsapp_group_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/whatsapp_group.php" class="pv-nav-link<?= _pv_nav_active('/panel/whatsapp_group') ?>"
     aria-label="Grupa organizacji na WhatsApp">
    <i class="bi bi-whatsapp" aria-hidden="true"></i>WhatsApp — grupa
  </a>
  <?php endif; ?>
  <?php if (module_enabled('org_calendar_enabled')): ?>
  <a href="<?= APP_URL ?>/panel/calendar.php" class="pv-nav-link<?= _pv_nav_active('/panel/calendar') ?>">
    <i class="bi bi-calendar3" aria-hidden="true"></i>Kalendarz organizacji
  </a>
  <?php endif; ?>
  <a href="<?= APP_URL ?>/org_intro/index.php" class="pv-nav-link<?= _pv_nav_active('/org_intro/index') ?>"
     aria-label="Zasady i wprowadzenie do organizacji">
    <i class="bi bi-building-heart" aria-hidden="true"></i>Zasady organizacji
  </a>
  <a href="<?= APP_URL ?>/org_intro/panel_guide.php" class="pv-nav-link<?= _pv_nav_active('/org_intro/panel_guide') ?>"
     aria-label="Przewodnik po panelu — jak korzystać z systemu">
    <i class="bi bi-book" aria-hidden="true"></i>Przewodnik po panelu
  </a>

  <div class="pv-nav-divider" role="separator" aria-hidden="true"></div>
  <div class="pv-nav-label" aria-hidden="true">Konto</div>

  <?php $_gdpr_needs_attention = !$_gdpr_signed && !$_gdpr_has_m365; ?>
  <a href="<?= APP_URL ?>/panel/gdpr_statement.php"
     class="pv-nav-link<?= _pv_nav_active('/panel/gdpr_statement') ?>"
     aria-label="Oświadczenie o ochronie danych<?= $_gdpr_needs_attention ? ' — wymagane podpisanie' : ($_gdpr_signed ? ' — podpisane' : '') ?>">
    <i class="bi bi-file-earmark-check<?= $_gdpr_signed ? '-fill text-success' : '' ?>" aria-hidden="true"></i>Oświadczenie IT
    <?php if ($_gdpr_needs_attention): ?>
    <span class="pv-badge" aria-label="wymagane">!</span>
    <?php endif; ?>
  </a>
  <a href="<?= APP_URL ?>/tozsamosc/index.php" class="pv-nav-link<?= _pv_nav_active('/tozsamosc/') ?>"
     aria-label="System Tożsamości — konto, dostępy, hasło">
    <i class="bi bi-person-vcard-fill" aria-hidden="true"></i>Tożsamość
  </a>
  <a href="<?= APP_URL ?>/panel/m365.php" class="pv-nav-link<?= _pv_nav_active('/panel/m365') ?>">
    <i class="bi bi-microsoft" aria-hidden="true"></i>Microsoft 365
  </a>
  <a href="<?= APP_URL ?>/panel/sessions.php" class="pv-nav-link<?= _pv_nav_active('/panel/sessions') ?>">
    <i class="bi bi-shield-lock" aria-hidden="true"></i>Sesje
  </a>
  <a href="<?= APP_URL ?>/panel/password.php" class="pv-nav-link<?= _pv_nav_active('/panel/password') ?>">
    <i class="bi bi-gear" aria-hidden="true"></i>Ustawienia konta
  </a>
  <a href="<?= APP_URL ?>/panel/panel_color.php" class="pv-nav-link<?= _pv_nav_active('/panel/panel_color') ?>">
    <i class="bi bi-palette2" aria-hidden="true"></i>Motyw i kontrast
  </a>

  <div class="pv-sidebar-bottom">
    <a href="<?= APP_URL ?>/auth/logout.php" class="pv-nav-link text-danger">
      <i class="bi bi-box-arrow-right" aria-hidden="true"></i>Wyloguj się
    </a>
  </div>
  </nav>
</div><!-- /offcanvas -->

<main class="container py-4" id="pv-main" role="main" tabindex="-1">

<?php if (!empty($_SESSION['_admin_original'])): ?>
<?php $_imp_name = $_SESSION['user']['name'] ?? 'użytkownik'; ?>
<a href="<?= APP_URL ?>/admin/impersonate_stop.php"
   style="display:flex;align-items:center;gap:1rem;
          background:linear-gradient(135deg,#b91c1c,#dc2626);
          border-radius:14px;padding:1.1rem 1.4rem;margin-bottom:1.25rem;
          text-decoration:none;color:#fff;
          box-shadow:0 4px 20px rgba(185,28,28,.45);
          animation:_impPulse 2.5s ease-in-out infinite;
          border:2px solid rgba(255,255,255,.25)"
   role="alert" aria-label="Tryb podglądu — kliknij aby wrócić do swojego konta">
  <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.3rem">
    👁
  </div>
  <div style="flex:1;min-width:0">
    <div style="font-size:.72rem;font-weight:700;opacity:.8;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.1rem">
      Tryb podglądu — przeglądasz jako:
    </div>
    <div style="font-size:1.05rem;font-weight:800">
      <?= h($_imp_name) ?>
    </div>
  </div>
  <div style="display:flex;align-items:center;gap:.5rem;background:rgba(0,0,0,.3);border-radius:10px;padding:.65rem 1.1rem;font-weight:700;font-size:.95rem;flex-shrink:0;white-space:nowrap;border:1.5px solid rgba(255,255,255,.3)">
    <i class="bi bi-arrow-left-circle-fill" style="font-size:1.1rem"></i>
    Powrót do admina
  </div>
</a>
<style>
@keyframes _impPulse {
  0%,100% { box-shadow: 0 4px 20px rgba(185,28,28,.45); }
  50%      { box-shadow: 0 4px 32px rgba(185,28,28,.75); }
}
</style>
<?php endif; ?>

<?php
$_flash = flash_get();
if ($_flash):
  $ft = $_flash['type'] ?? 'info';
  $fi = ['success'=>'bi-check-circle-fill','danger'=>'bi-exclamation-triangle-fill','warning'=>'bi-exclamation-circle','info'=>'bi-info-circle-fill'];
?>
<div class="pv-alert pv-alert-<?= h($ft) ?> alert-dismissible fade show" role="alert" aria-live="polite">
  <i class="bi <?= h($fi[$ft]??'bi-info-circle-fill') ?>" aria-hidden="true"></i>
  <span><?= h($_flash['msg']) ?></span>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Zamknij"></button>
</div>
<?php endif; ?>

<?php
// ── Komunikaty admina (baner / popup) ────────────────────────────────────────
require_once dirname(dirname(__DIR__)) . '/includes/notifications.php';
notif_migrate();
$_pv_msgs    = ann_panel_messages((int)($_pu['id'] ?? 0), $_pu['role'] ?? 'viewer');
$_pv_banners = array_values(array_filter($_pv_msgs, fn($a) => ($a['display_mode'] ?? '') === 'banner'));
$_pv_popups  = array_values(array_filter($_pv_msgs, fn($a) => ($a['display_mode'] ?? '') === 'popup'));
?>
<?php foreach ($_pv_banners as $_b):
  $_bid = (int)$_b['id'];
  $_pinned = (int)($_b['is_pinned'] ?? 0);
?>
<div class="pv-ann-banner" data-ann-id="<?= $_bid ?>" role="region" aria-label="Komunikat: <?= h($_b['title']) ?>"
     style="border-left:4px solid <?= $_pinned ? '#F59E0B' : 'var(--vol-color)' ?>;background:<?= $_pinned ? '#FFFBEB' : 'var(--vol-bg)' ?>;border-radius:12px;padding:1rem 1.15rem;margin-bottom:1.25rem;display:flex;gap:.9rem;align-items:flex-start">
  <i class="bi bi-megaphone-fill" aria-hidden="true" style="font-size:1.25rem;color:<?= $_pinned ? '#F59E0B' : 'var(--vol-color)' ?>;flex-shrink:0;margin-top:.1rem"></i>
  <div style="flex:1;min-width:0">
    <div class="fw-bold mb-1" style="font-size:.98rem;color:#1f2937"><?= h($_b['title']) ?></div>
    <?php if (($_b['body'] ?? '') !== ''): ?>
    <div style="font-size:.88rem;color:#374151;line-height:1.6;white-space:pre-wrap;word-break:break-word"><?= h($_b['body']) ?></div>
    <?php endif; ?>
    <div class="text-muted mt-2" style="font-size:.73rem"><i class="bi bi-person me-1"></i><?= h($_b['author_name'] ?? '') ?></div>
  </div>
  <button type="button" class="btn btn-sm pv-ann-read" data-ann-id="<?= $_bid ?>"
          style="background:var(--vol-color);color:#fff;font-size:.78rem;white-space:nowrap;flex-shrink:0">
    <i class="bi bi-check2 me-1" aria-hidden="true"></i>Przeczytane
  </button>
</div>
<?php endforeach; ?>

<?php if (!empty($_pv_popups)): ?>
<div class="modal fade" id="pvAnnPopup" tabindex="-1" aria-labelledby="pvAnnPopupLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden">
      <div class="modal-header" style="background:var(--vol-color);color:#fff;border:none">
        <h5 class="modal-title d-flex align-items-center gap-2" id="pvAnnPopupLabel">
          <i class="bi bi-megaphone-fill" aria-hidden="true"></i>
          <?= count($_pv_popups) > 1 ? 'Komunikaty' : 'Komunikat' ?>
        </h5>
      </div>
      <div class="modal-body" style="padding:1.25rem">
        <?php foreach ($_pv_popups as $_i => $_p):
          $_pid = (int)$_p['id'];
        ?>
        <div class="pv-popup-item<?= $_i > 0 ? ' pt-3 mt-3 border-top' : '' ?>" data-ann-id="<?= $_pid ?>">
          <div class="fw-bold mb-2" style="font-size:1.02rem;color:#1f2937"><?= h($_p['title']) ?></div>
          <?php if (($_p['body'] ?? '') !== ''): ?>
          <div style="font-size:.9rem;color:#374151;line-height:1.65;white-space:pre-wrap;word-break:break-word"><?= h($_p['body']) ?></div>
          <?php endif; ?>
          <div class="text-muted mt-2" style="font-size:.73rem"><i class="bi bi-person me-1"></i><?= h($_p['author_name'] ?? '') ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer" style="border:none">
        <button type="button" class="btn" id="pvAnnPopupAck"
                style="background:var(--vol-color);color:#fff">
          <i class="bi bi-check2-all me-1" aria-hidden="true"></i>Rozumiem
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($_pv_banners) || !empty($_pv_popups)): ?>
<script>
(function () {
  var APP_URL = '<?= APP_URL ?>';
  function markRead(id) {
    return fetch(APP_URL + '/api/announcements/read.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
      body: JSON.stringify({id: id})
    });
  }

  // Banery — przycisk „Przeczytane"
  document.querySelectorAll('.pv-ann-read').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = parseInt(btn.dataset.annId, 10);
      btn.disabled = true;
      markRead(id).finally(function () {
        var card = document.querySelector('.pv-ann-banner[data-ann-id="' + id + '"]');
        if (card) card.remove();
        var live = document.getElementById('pv-live');
        if (live) live.textContent = 'Komunikat oznaczono jako przeczytany.';
      });
    });
  });

  // Popup — pokaż przy wejściu, potwierdzenie oznacza wszystkie jako przeczytane
  var popupEl = document.getElementById('pvAnnPopup');
  if (popupEl && window.bootstrap) {
    var modal = new bootstrap.Modal(popupEl);
    modal.show();
    var ack = document.getElementById('pvAnnPopupAck');
    if (ack) {
      ack.addEventListener('click', function () {
        ack.disabled = true;
        var ids = Array.prototype.map.call(
          popupEl.querySelectorAll('.pv-popup-item'),
          function (el) { return parseInt(el.dataset.annId, 10); }
        );
        Promise.allSettled(ids.map(markRead)).finally(function () { modal.hide(); });
      });
    }
  }
})();
</script>
<?php endif; ?>

<?php
// ── Zgoda na weryfikację RPTS (popup, można odłożyć do następnego logowania) ──
require_once dirname(dirname(__DIR__)) . '/includes/rpts.php';
$_rpts_gate = null;
if (empty($_SESSION['rpts_consent_snoozed'])) {
    $_rpts_fresh_user = db_one("SELECT * FROM users WHERE id=?", [(int)($_pu['id'] ?? 0)]);
    if ($_rpts_fresh_user) $_rpts_gate = rpts_consent_needed($_rpts_fresh_user);
}
?>
<?php if ($_rpts_gate !== null): $_rc = $_rpts_gate['contract'] ?? null; ?>
<div class="modal fade" id="rptsConsentModal" tabindex="-1" aria-labelledby="rptsConsentLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
    <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden">
      <div class="modal-header" style="background:#DC2626;color:#fff;border:none">
        <h5 class="modal-title d-flex align-items-center gap-2" id="rptsConsentLabel">
          <i class="bi bi-shield-exclamation" aria-hidden="true"></i>Zgoda na weryfikację w RPTS
        </h5>
      </div>
      <div class="modal-body" style="padding:1.5rem">
        <div id="rptsConsentAlert"></div>
        <p class="small text-muted">
          Twoja współpraca z Fundacją Edukacji Empatii Rozwoju "FEER" obejmuje (lub może obejmować)
          działalność związaną z wychowaniem, edukacją, wypoczynkiem lub opieką nad małoletnimi.
          Ustawa z 13.05.2016 r. o przeciwdziałaniu zagrożeniom przestępczością na tle seksualnym
          wymaga w takim przypadku sprawdzenia w Rejestrze Sprawców Przestępstw na Tle Seksualnym —
          do tego potrzebna jest Twoja zgoda oraz kilka dodatkowych danych.
        </p>
        <div class="d-flex align-items-center gap-2 mb-3 p-2 rounded" style="background:#F9FAFB;font-size:.85rem">
          <i class="bi bi-person-badge text-muted" aria-hidden="true"></i>
          <span>Oświadczenie składa: <strong><?= h($_pu_name) ?></strong><?= !empty($_pu['email']) ? ' (' . h($_pu['email']) . ')' : '' ?></span>
        </div>
        <form id="rptsConsentForm">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label small fw-semibold" for="rpts-pesel">Numer PESEL</label>
              <input type="text" id="rpts-pesel" name="pesel" class="form-control form-control-sm" maxlength="11"
                     inputmode="numeric" value="<?= h($_rc['pesel'] ?? '') ?>" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label small fw-semibold" for="rpts-data-urodzenia">Data urodzenia</label>
              <input type="date" id="rpts-data-urodzenia" name="data_urodzenia" class="form-control form-control-sm"
                     value="<?= h($_rc['data_urodzenia'] ?? '') ?>" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label small fw-semibold" for="rpts-miejsce-urodzenia">Miejsce urodzenia</label>
              <input type="text" id="rpts-miejsce-urodzenia" name="miejsce_urodzenia" class="form-control form-control-sm" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label small fw-semibold" for="rpts-nazwisko-rodowe">Nazwisko rodowe</label>
              <input type="text" id="rpts-nazwisko-rodowe" name="nazwisko_rodowe" class="form-control form-control-sm" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label small fw-semibold" for="rpts-imie-ojca">Imię ojca</label>
              <input type="text" id="rpts-imie-ojca" name="imie_ojca" class="form-control form-control-sm" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label small fw-semibold" for="rpts-imie-matki">Imię matki</label>
              <input type="text" id="rpts-imie-matki" name="imie_matki" class="form-control form-control-sm" required>
            </div>
          </div>

          <div class="border rounded p-3 mb-3" style="max-height:220px;overflow-y:auto;background:#F9FAFB;font-size:.8rem;line-height:1.6">
            <div class="fw-bold text-center mb-2">
              OŚWIADCZENIE O WYRAŻENIU ZGODY<br>NA WERYFIKACJĘ W REJESTRZE SPRAWCÓW PRZESTĘPSTW NA TLE SEKSUALNYM (RSPTS)
            </div>
            <p class="mb-2">Dotyczy: Fundacja Edukacji Empatii Rozwoju "FEER", ul. Barbackiego 28/18, 33-300 Nowy Sącz</p>
            <p class="mb-2">
              Ja, niżej podpisany/a, w związku z zamiarem podjęcia współpracy (w charakterze pracownika /
              wolontariusza / zleceniobiorcy) z Fundacją Edukacji Empatii Rozwoju "FEER", która obejmować
              będzie działalność związaną z wychowaniem, edukacją, wypoczynkiem lub opieką nad małoletnimi,
              oświadczam, co następuje:
            </p>
            <p class="mb-2">
              1. Przyjmuję do wiadomości, że na Fundacji Edukacji Empatii Rozwoju "FEER", jako organizatorze
              ww. działalności, ciąży ustawowy obowiązek wynikający z art. 21 ustawy z dnia 13 maja 2016 r.
              o przeciwdziałaniu zagrożeniom przestępczością na tle seksualnym i ochronie małoletnich,
              polegający na weryfikacji, czy moje dane figurują w Rejestrze Sprawców Przestępstw na Tle Seksualnym.
            </p>
            <p class="mb-2">
              2. Działając świadomie i dobrowolnie, wyrażam zgodę na dokonanie przez Fundację Edukacji
              Empatii Rozwoju "FEER" weryfikacji w Rejestrze z dostępem ograniczonym przy użyciu danych
              osobowych wskazanych powyżej.
            </p>
            <p class="mb-0">
              3. Oświadczam, że podane przeze mnie dane osobowe są prawdziwe i kompletne. Zgadzam się na
              ich przetwarzanie przez Fundację wyłącznie w celu i zakresie niezbędnym do przeprowadzenia
              wyżej wymienionej weryfikacji.
            </p>
          </div>

          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="rptsConsentCheck" name="consent" value="1" required>
            <label class="form-check-label small" for="rptsConsentCheck">
              Zapoznałem/-am się z treścią oświadczenia powyżej i <strong>wyrażam zgodę</strong> na weryfikację
              moich danych w Rejestrze Sprawców Przestępstw na Tle Seksualnym.
            </label>
          </div>
        </form>
      </div>
      <div class="modal-footer" style="border:none">
        <button type="button" class="btn btn-link text-muted" id="rptsConsentSnooze" style="font-size:.85rem">
          Przypomnij później
        </button>
        <button type="submit" form="rptsConsentForm" class="btn" id="rptsConsentSubmit" style="background:#DC2626;color:#fff">
          <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Wyślij zgodę i dane
        </button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var APP_URL = '<?= APP_URL ?>';
  var modalEl = document.getElementById('rptsConsentModal');
  if (!modalEl || !window.bootstrap) return;
  var modal = new bootstrap.Modal(modalEl);

  // Jeśli jednocześnie pokazuje się popup ogłoszenia, poczekaj aż zniknie —
  // dwa modale naraz nakładałyby się na siebie (podwójne tło).
  var annPopup = document.getElementById('pvAnnPopup');
  if (annPopup) {
    annPopup.addEventListener('hidden.bs.modal', function () { modal.show(); }, { once: true });
  } else {
    modal.show();
  }

  var form   = document.getElementById('rptsConsentForm');
  var alertB = document.getElementById('rptsConsentAlert');
  var submit = document.getElementById('rptsConsentSubmit');
  var snooze = document.getElementById('rptsConsentSnooze');
  var csrf   = form.querySelector('[name="_csrf"]').value;

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    submit.disabled = true;
    var fd = new FormData(form);
    fd.append('action', 'submit');
    fetch(APP_URL + '/api/rpts_consent.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) {
          modal.hide();
        } else {
          alertB.innerHTML = '<div class="pv-alert pv-alert-danger py-2 px-3 mb-3" style="font-size:.85rem">' + (d.error || 'Błąd zapisu.') + '</div>';
          submit.disabled = false;
        }
      })
      .catch(function () {
        alertB.innerHTML = '<div class="pv-alert pv-alert-danger py-2 px-3 mb-3" style="font-size:.85rem">Błąd połączenia. Spróbuj ponownie.</div>';
        submit.disabled = false;
      });
  });

  snooze.addEventListener('click', function () {
    snooze.disabled = true;
    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('action', 'snooze');
    fetch(APP_URL + '/api/rpts_consent.php', { method: 'POST', body: fd })
      .finally(function () { modal.hide(); });
  });
})();
</script>
<?php endif; ?>

<?php
// ── Przypomnienie: zgoda przedstawiciela ustawowego (co 6 miesięcy) ──────────
// Odsyła do pełnej strony /panel/zgody.php zamiast osadzać długie oświadczenie
// w modalu. Można odłożyć do następnego logowania (sesja).
?>
<?php if ($_gc_pending > 0 && empty($_SESSION['gc_consent_nudge_snoozed']) && basename($_SERVER['SCRIPT_NAME']) !== 'zgody.php'): ?>
<div class="modal fade" id="gcConsentNudge" tabindex="-1" aria-labelledby="gcConsentNudgeLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden">
      <div class="modal-header" style="background:var(--vol-color,#1D4ED8);color:#fff;border:none">
        <h5 class="modal-title d-flex align-items-center gap-2" id="gcConsentNudgeLabel">
          <i class="bi bi-file-earmark-check" aria-hidden="true"></i>Wymagana odnowienie zgody
        </h5>
      </div>
      <div class="modal-body" style="padding:1.5rem">
        <p class="mb-0">
          Zgoda przedstawiciela ustawowego na wolontariat i przetwarzanie danych osobowych jest
          składana co 6 miesięcy. Dla <strong><?= $_gc_pending ?></strong>
          <?= $_gc_pending === 1 ? 'umowy wymagane jest złożenie/odnowienie zgody' : 'umów wymagane jest złożenie/odnowienie zgody' ?>.
          Przejdź do sekcji <strong>Zgody i Oświadczenia</strong>, aby dopełnić formalności.
        </p>
      </div>
      <div class="modal-footer" style="border:none">
        <button type="button" class="btn btn-link text-muted" id="gcNudgeSnooze" style="font-size:.85rem">
          Przypomnij później
        </button>
        <a href="<?= APP_URL ?>/panel/zgody.php" class="btn" style="background:var(--vol-color,#1D4ED8);color:#fff">
          <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Przejdź do Zgody i Oświadczeń
        </a>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var APP_URL = '<?= APP_URL ?>';
  var modalEl = document.getElementById('gcConsentNudge');
  if (!modalEl || !window.bootstrap) return;
  var modal = new bootstrap.Modal(modalEl);

  function show() { modal.show(); }
  var annPopup  = document.getElementById('pvAnnPopup');
  var rptsModal = document.getElementById('rptsConsentModal');
  if (rptsModal) {
    rptsModal.addEventListener('hidden.bs.modal', show, { once: true });
  } else if (annPopup) {
    annPopup.addEventListener('hidden.bs.modal', show, { once: true });
  } else {
    show();
  }

  var snooze = document.getElementById('gcNudgeSnooze');
  snooze.addEventListener('click', function () {
    snooze.disabled = true;
    var fd = new FormData();
    fd.append('_csrf', <?= json_encode(csrf_token()) ?>);
    fd.append('action', 'snooze');
    fetch(APP_URL + '/api/guardian_consent_nudge.php', { method: 'POST', body: fd })
      .finally(function () { modal.hide(); });
  });
})();
</script>
<?php endif; ?>
