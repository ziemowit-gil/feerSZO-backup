<?php
/**
 * includes/auth_screen.php — wspólna powłoka ekranów wejścia do systemu.
 *
 * Jeden wygląd dla: logowania (/auth/login.php), rejestracji
 * (/user/register.php) i odzyskiwania dostępu (/user/verify_reset.php):
 * pasek dostępności (rozmiar tekstu, wysoki kontrast), pełnoekranowe tło
 * w kolorze marki z geometrią, logo organizacji zawsze białe, zakładki
 * Zaloguj / Rejestracja i biała karta z treścią.
 *
 * Użycie:
 *   require_once __DIR__ . '/../includes/auth_screen.php';
 *   auth_screen_head(['title'=>'Załóż konto', 'tab'=>'register']);
 *   ... treść ...
 *   auth_screen_foot();
 *
 * Wymaga: config.php, functions.php (h()), branding.php.
 */

require_once __DIR__ . '/branding.php';

/**
 * Otwiera stronę: <head>, pasek dostępności, marka, zakładki, karta.
 *
 * @param array $o title      — tytuł w <title> (bez nazwy organizacji)
 *                 tab        — 'login' | 'register' | '' (bez zakładek)
 *                 mode       — 'card' (biała karta) | 'plain' (treść na tle)
 *                 width      — szerokość kolumny w px (domyślnie 700)
 *                 narrow     — true: treść karty w kolumnie 420 px
 *                 bootstrap  — true: dołącz CSS Bootstrapa + harmonizację
 *                 main_id    — id elementu <main> (kotwica skip-linka)
 */
function auth_screen_head(array $o = []): void {
    $b         = branding_load();
    $org_name  = $b['org_name'] ?: (defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : '');
    $title     = $o['title']     ?? 'Logowanie';
    $tab       = $o['tab']       ?? '';
    $mode      = $o['mode']      ?? 'card';
    $width     = (int)($o['width'] ?? 700);
    $narrow    = $o['narrow']    ?? ($mode === 'card');
    $bootstrap = !empty($o['bootstrap']);
    $main_id   = $o['main_id']   ?? 'ks-main';
    $GLOBALS['__ks_mode']    = $mode;
    $GLOBALS['__ks_bs']      = $bootstrap;
    $GLOBALS['__ks_org']     = $org_name;
    ?><!DOCTYPE html>
<html lang="pl" data-fs="m">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — <?= h($org_name) ?></title>
<?php if ($bootstrap): ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php endif; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($b); ?>
<script>
/* Ustawienia dostępności przed pierwszym malowaniem — bez mignięcia */
(function(){try{
  var fs=localStorage.getItem('szoFs'); if(fs==='L'||fs==='XL') document.documentElement.dataset.fs=fs;
  if(localStorage.getItem('szoHc')==='1') document.documentElement.dataset.theme='hc';
  if(localStorage.getItem('szoNews')==='2026-08') document.documentElement.dataset.news='off';
}catch(e){}})();
</script>
<style>
*,*::before,*::after{box-sizing:border-box}
:root{
  --ks:var(--c,#DC2626);
  --ks-dark:var(--c-dark,#B91C1C);
  --ks-on:var(--c-text,#fff);
  --ks-ink:#1f2937;
  --ks-line:#d1d5db;
  --ks-muted:#6b7280;
  --ks-card:#fff;
  --ks-radius:18px;
  --ks-col:<?= $width ?>px;
}
html{font-size:16px}
html[data-fs="L"]{font-size:18px}
html[data-fs="XL"]{font-size:20px}
html,body{margin:0;padding:0;min-height:100%}
body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:var(--ks-ink);background:var(--ks)}
*:focus-visible{outline:3px solid #FBBF24!important;outline-offset:2px!important}
*:focus:not(:focus-visible){outline:none}
.skip-link{position:absolute;top:-100%;left:1rem;z-index:9999;background:#fff;color:var(--ks);padding:.5rem 1.25rem;border-radius:0 0 8px 8px;font-weight:700;text-decoration:none}
.skip-link:focus{top:0}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}

/* ── Pasek dostępności ─────────────────────────────────────────────────── */
.ks-a11y{background:var(--ks-dark);color:#fff;font-size:.8rem}
.ks-a11y .in{max-width:1200px;margin:0 auto;padding:.35rem 1rem;display:flex;align-items:center;justify-content:flex-end;gap:1.25rem;flex-wrap:wrap}
.ks-a11y .grp{display:flex;align-items:center;gap:.4rem}
.ks-a11y .lbl{opacity:.9}
.ks-a11y button{background:transparent;border:1px solid rgba(255,255,255,.45);color:#fff;border-radius:4px;
  min-width:30px;min-height:26px;padding:0 .4rem;font-family:inherit;font-weight:700;cursor:pointer;line-height:1}
.ks-a11y button:hover{background:rgba(255,255,255,.18)}
.ks-a11y button[aria-pressed="true"]{background:#fff;color:var(--ks-dark);border-color:#fff}
.ks-a11y .fs-m{font-size:.72rem}.ks-a11y .fs-l{font-size:.82rem}.ks-a11y .fs-xl{font-size:.92rem}

/* ── Tło z geometrią ───────────────────────────────────────────────────── */
.ks-hero{position:relative;min-height:calc(100vh - 34px);padding:2.25rem 1rem 3rem;overflow:hidden}
.ks-hero::before{content:'';position:absolute;inset:0;pointer-events:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='420' height='420' viewBox='0 0 420 420'%3E%3Cg fill='%23000' fill-opacity='.055'%3E%3Crect x='24' y='40' width='120' height='120' rx='8'/%3E%3Ccircle cx='330' cy='96' r='58'/%3E%3Crect x='210' y='250' width='150' height='150' rx='8'/%3E%3Cpath d='M0 210l70-70v46l-24 24zm52 132l96-96v46l-50 50z'/%3E%3Cpath d='M300 0l60 60-24 24-60-60z'/%3E%3C/g%3E%3C/svg%3E"),
    repeating-linear-gradient(135deg,rgba(0,0,0,.045) 0 3px,transparent 3px 26px);
  background-size:420px 420px,auto}
.ks-shell{position:relative;max-width:var(--ks-col);margin:0 auto}

/* ── Marka ─────────────────────────────────────────────────────────────── */
.ks-brand{display:flex;flex-direction:column;align-items:center;gap:.55rem;color:#fff;margin-bottom:2.25rem}
.ks-brand img{max-height:74px;max-width:260px;object-fit:contain;
  /* logo organizacji zawsze białe — kolorowe/ciemne znaki giną na tle marki */
  filter:brightness(0) invert(1)}
.ks-brand .mark{width:56px;height:56px;border-radius:14px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:1.7rem}
.ks-brand .nm{font-size:1.2rem;font-weight:800;letter-spacing:-.01em;text-align:center;line-height:1.25}

/* ── Zakładki nad kartą ────────────────────────────────────────────────── */
.ks-toprow{display:flex;align-items:flex-end;justify-content:flex-end;gap:1rem;flex-wrap:wrap}
.ks-tabs{display:flex;gap:.2rem;margin-left:auto}
.ks-tab{padding:.55rem 1.15rem;border-radius:10px 10px 0 0;text-decoration:none;font-size:.92rem;font-weight:600;color:#fff}
.ks-tab:hover{background:rgba(255,255,255,.16);color:#fff}
.ks-tab[aria-current="page"]{background:var(--ks-card);color:var(--ks)}

/* ── Karta ─────────────────────────────────────────────────────────────── */
.ks-card{background:var(--ks-card);border-radius:var(--ks-radius);padding:2.75rem 1.5rem 2.5rem;
  box-shadow:0 18px 44px rgba(0,0,0,.16)}
@media(min-width:576px){.ks-card{padding:3rem 3.5rem 2.75rem}}
.ks-h1{font-size:1.75rem;font-weight:800;letter-spacing:-.02em;text-align:center;margin:0 0 .5rem;line-height:1.25}
.ks-lead{text-align:center;color:var(--ks-muted);font-size:.9rem;line-height:1.55;margin:0 0 2rem}
.ks-inner{<?= $narrow ? 'max-width:420px;' : '' ?>margin:0 auto}

/* ── Komunikat o nowym wyglądzie ───────────────────────────────────────── */
.ks-news{position:relative;display:flex;gap:.75rem;align-items:flex-start;
  background:var(--c-bg,rgba(220,38,38,.07));border:1px solid var(--c-ring,rgba(220,38,38,.18));
  border-radius:12px;padding:.9rem 2.4rem .9rem 1rem;margin:0 0 1.75rem;font-size:.87rem;line-height:1.55}
.ks-news i.ico{color:var(--ks);font-size:1.15rem;line-height:1.2;flex-shrink:0}
.ks-news strong{display:block;font-size:.92rem;margin-bottom:.15rem}
.ks-news p{margin:0;color:var(--ks-muted)}
.ks-news .x{position:absolute;top:.5rem;right:.5rem;background:none;border:none;color:var(--ks-muted);
  cursor:pointer;border-radius:6px;width:28px;height:28px;line-height:1;font-size:.9rem}
.ks-news .x:hover{background:rgba(0,0,0,.06);color:var(--ks-ink)}
html[data-news="off"] .ks-news{display:none}

/* ── Kroki (rejestracja, odzyskiwanie) ─────────────────────────────────── */
.ks-steps{display:flex;align-items:flex-start;justify-content:center;gap:0;margin:0 0 2rem}
.ks-step{display:flex;flex-direction:column;align-items:center;gap:.3rem;min-width:74px}
.ks-step .dot{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-weight:700;font-size:.88rem;border:2px solid var(--ks-line);background:#fff;color:var(--ks-muted)}
.ks-step.is-active .dot{border-color:var(--ks);color:var(--ks)}
.ks-step.is-done .dot{background:var(--ks);border-color:var(--ks);color:var(--ks-on)}
.ks-step .cap{font-size:.72rem;text-align:center;color:var(--ks-muted);max-width:88px;line-height:1.3}
.ks-step.is-active .cap{color:var(--ks);font-weight:600}
.ks-line{flex:1;max-width:70px;height:2px;background:var(--ks-line);margin:17px .35rem 0}
.ks-line.is-done{background:var(--ks)}
/* wariant na tle marki (tryb 'plain') */
.ks-steps--light .ks-step .dot{background:transparent;border-color:rgba(255,255,255,.6);color:#fff}
.ks-steps--light .ks-step .cap{color:rgba(255,255,255,.85)}
.ks-steps--light .ks-step.is-active .dot,.ks-steps--light .ks-step.is-done .dot{background:#fff;border-color:#fff;color:var(--ks)}
.ks-steps--light .ks-step.is-active .cap{color:#fff}
.ks-steps--light .ks-line{background:rgba(255,255,255,.4)}
.ks-steps--light .ks-line.is-done{background:#fff}

/* Nagłówek na tle marki (tryb 'plain') */
.ks-hero-h1{color:#fff;text-align:center;font-size:1.6rem;font-weight:800;letter-spacing:-.02em;margin:0 0 .4rem}
.ks-hero-lead{color:rgba(255,255,255,.85);text-align:center;font-size:.9rem;line-height:1.55;margin:0 0 2rem}
.ks-hero-ico{width:60px;height:60px;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;
  display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin:0 auto .9rem}

/* ── Formularz ─────────────────────────────────────────────────────────── */
.ks-field{margin-bottom:1.35rem}
.ks-field label{display:block;font-size:.92rem;color:var(--ks-ink);margin-bottom:.4rem}
.form-control{width:100%;padding:.7rem .9rem;border:1px solid var(--ks-line);border-radius:6px;font-size:1rem;
  font-family:inherit;color:var(--ks-ink);background:#fff;min-height:46px}
.form-control:focus{border-color:var(--ks);box-shadow:0 0 0 3px var(--c-ring,rgba(220,38,38,.18));outline:none}
.form-control[aria-invalid=true]{border-color:#dc2626}
.pass-wrap{position:relative}
.pass-wrap .form-control{padding-right:2.9rem}
.pass-toggle{position:absolute;right:.55rem;top:50%;transform:translateY(-50%);background:none;border:none;
  color:var(--ks-ink);cursor:pointer;padding:.3rem;border-radius:4px;line-height:1}
.ks-forgot{display:inline-block;margin-top:.75rem;font-size:.9rem;color:var(--ks);text-decoration:underline}
.ks-forgot:hover{color:var(--ks-dark)}
.ks-hint{font-size:.8rem;color:var(--ks-muted);text-align:center;margin:.5rem 0 0;line-height:1.5}
.ks-fieldhint{font-size:.8rem;color:var(--ks-muted);margin:.35rem 0 0;line-height:1.5}
.ks-btn{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;min-height:48px;
  padding:.75rem 1.25rem;border-radius:6px;font-size:1rem;font-weight:600;font-family:inherit;
  cursor:pointer;text-decoration:none;border:1px solid transparent;transition:background .13s,border-color .13s}
.ks-btn--primary{background:var(--ks);color:var(--ks-on);border-color:var(--ks)}
.ks-btn--primary:hover{background:var(--ks-dark);border-color:var(--ks-dark);color:var(--ks-on)}
.ks-btn--ok{background:#15803d;color:#fff;border-color:#15803d}
.ks-btn--ok:hover{background:#166534;border-color:#166534;color:#fff}
.ks-btn--ghost{background:#fff;color:var(--ks-ink);border-color:var(--ks-line)}
.ks-btn--ghost:hover{border-color:var(--ks);color:var(--ks-ink);background:#fff}
.ks-btn + .ks-btn,form + form > .ks-btn{margin-top:.75rem}
.ks-sep{border:0;border-top:1px solid #e5e7eb;margin:2rem 0 1.5rem}
.ks-sub{text-align:center;font-size:.95rem;font-weight:600;margin:0 0 1rem}
.ks-or{display:flex;align-items:center;gap:.75rem;color:var(--ks-muted);font-size:.82rem;margin:1.5rem 0}
.ks-or::before,.ks-or::after{content:'';flex:1;height:1px;background:#e5e7eb}
.ks-optsub{display:block;font-size:.78rem;color:var(--ks-muted);font-weight:400;margin-top:.1rem}
.ks-note{font-size:.82rem;color:var(--ks-muted);text-align:center;line-height:1.6;margin:1.5rem 0 0}
.ks-note a{color:var(--ks)}
.ks-otp{font-size:1.9rem;letter-spacing:.6rem;text-align:center;font-weight:700;font-family:ui-monospace,monospace;
  padding-left:.6rem}

/* ── Alerty ────────────────────────────────────────────────────────────── */
.l-alert{display:flex;gap:.6rem;align-items:flex-start;padding:.8rem 1rem;border-radius:8px;font-size:.9rem;
  margin-bottom:1.25rem;line-height:1.5}
.l-alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.l-alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.l-alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}

/* ── Stopka ────────────────────────────────────────────────────────────── */
.ks-links{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:.3rem .9rem;margin:1.5rem 0 .5rem}
.ks-links a{font-size:.85rem;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;opacity:.92}
.ks-links a:hover{color:#fff;text-decoration:underline;opacity:1}
.ks-links .dot{color:rgba(255,255,255,.5);font-size:.7rem}
.ks-copy{text-align:center;color:rgba(255,255,255,.75);font-size:.78rem}

/* ── Modale ────────────────────────────────────────────────────────────── */
.lm-content{border:none;border-radius:14px;overflow:hidden}
.lm-hd{padding:.9rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between}
.lm-title{font-size:1.02rem;font-weight:700;margin:0;display:flex;align-items:center;gap:.45rem}
.lm-body{padding:1.25rem 1.5rem 1.5rem}
.sms-otp{font-size:1.8rem;letter-spacing:.45rem;text-align:center;font-family:monospace;font-weight:700}
<?php if ($bootstrap): ?>

/* ── Harmonizacja Bootstrapa z tym ekranem ─────────────────────────────── */
.ks-shell .card{border:1px solid #e5e7eb;border-radius:14px;box-shadow:none}
.ks-shell .card.border-primary{border-color:var(--ks)!important}
.ks-shell .card-body{padding:1.5rem}
.ks-shell .btn{border-radius:6px;font-weight:600;min-height:46px;display:inline-flex;align-items:center;justify-content:center;gap:.4rem}
.ks-shell .btn-sm{min-height:36px}
.ks-shell .btn-primary{--bs-btn-bg:var(--ks);--bs-btn-border-color:var(--ks);--bs-btn-hover-bg:var(--ks-dark);
  --bs-btn-hover-border-color:var(--ks-dark);--bs-btn-active-bg:var(--ks-dark);--bs-btn-color:var(--ks-on);--bs-btn-hover-color:var(--ks-on)}
.ks-shell .btn-success{--bs-btn-bg:#15803d;--bs-btn-border-color:#15803d;--bs-btn-hover-bg:#166534;--bs-btn-hover-border-color:#166534}
.ks-shell .btn-outline-primary{--bs-btn-color:var(--ks);--bs-btn-border-color:var(--ks-line);
  --bs-btn-hover-bg:var(--c-bg,rgba(220,38,38,.07));--bs-btn-hover-color:var(--ks);--bs-btn-hover-border-color:var(--ks)}
.ks-shell .text-primary{color:var(--ks)!important}
/* UWAGA: tylko treść na białym tle. Reguła `.ks-shell a` przejmowała też
   zakładki i linki stopki, które leżą na tle marki — kolor marki na kolorze
   marki znikał (np. niewidoczna zakładka „Rejestracja"). */
.ks-card a,.ks-shell .card a,.ks-shell .alert a,.ks-shell .tz-card a{color:var(--ks)}
.ks-shell .card a.text-muted,.ks-card a.text-muted{color:var(--ks-muted)!important}
.ks-shell .form-label{font-size:.92rem;color:var(--ks-ink)}
.ks-shell .input-group-text{background:#f9fafb;border-color:var(--ks-line);color:var(--ks-muted)}
.ks-shell .input-group .form-control{min-height:46px}
.ks-shell .alert{border-radius:10px}
<?php endif; ?>

/* ── Zgodność z komponentami „Tożsamości" (bramka IKA i pokrewne) ──────── */
.ks-shell{--tz:var(--ks);--tz-strong:var(--ks-dark);--tz-line:#e5e7eb;--tz-muted:var(--ks-muted);
  --tz-canvas:#f9fafb;--tz-50:var(--c-bg,rgba(220,38,38,.07))}
.ks-shell .tz-h{margin-bottom:1.25rem;text-align:center}
.ks-shell .tz-h h1{color:#fff;font-size:1.6rem;font-weight:800;letter-spacing:-.02em;margin:0 0 .35rem}
.ks-shell .tz-h h1 i{color:#fff!important}
.ks-shell .tz-h p{color:rgba(255,255,255,.85);font-size:.9rem;margin:0}
.ks-shell .tz-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;margin-bottom:1rem}
.ks-shell .tz-card__hd{display:flex;align-items:center;gap:.6rem;padding:.9rem 1.15rem;border-bottom:1px solid #e5e7eb;font-weight:700;font-size:.95rem}
.ks-shell .tz-card__hd i{color:var(--ks)}
.ks-shell .tz-card__bd{padding:1.15rem}
.ks-shell .tz-btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;min-height:46px;
  padding:.7rem 1.2rem;border:1px solid var(--ks);border-radius:6px;background:var(--ks);color:var(--ks-on);
  font-weight:600;font-family:inherit;font-size:1rem;text-decoration:none;cursor:pointer}
.ks-shell .tz-btn:hover{background:var(--ks-dark);border-color:var(--ks-dark);color:var(--ks-on)}
.ks-shell .tz-btn--ghost{background:#fff;color:var(--ks-ink);border-color:var(--ks-line)}
.ks-shell .tz-btn--ghost:hover{background:#fff;border-color:var(--ks);color:var(--ks-ink)}
.ks-shell .tz-btn--wide{width:100%}
.ks-shell .tz-badge{font-size:.74rem;font-weight:600;padding:.2rem .6rem;border-radius:999px;
  display:inline-flex;align-items:center;gap:.3rem;border:1px solid transparent}
.ks-shell .tz-badge--ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
.ks-shell .tz-subnav{display:flex;justify-content:center;margin-bottom:1rem}
.ks-shell .tz-subnav .seg{display:inline-flex;gap:.15rem;padding:.2rem;background:rgba(255,255,255,.16);border-radius:10px}
.ks-shell .tz-subnav a{font-size:.86rem;padding:.4rem .9rem;border-radius:8px;text-decoration:none;
  color:#fff;display:inline-flex;align-items:center;gap:.35rem;font-weight:600}
.ks-shell .tz-subnav a:hover{background:rgba(255,255,255,.18);color:#fff}
.ks-shell .tz-subnav a.on{background:#fff;color:var(--ks)}
.ks-shell .tz-subnav a.on i{color:var(--ks)}

/* ── Elementy na tle marki: kolor tekstu niezależny od reguł treści ────── */
.ks-shell .ks-tabs a,.ks-shell .ks-links a,.ks-shell .ks-links a:hover{color:#fff}
.ks-shell .ks-tab[aria-current="page"]{color:var(--ks)}
.ks-shell .ks-hero-h1,.ks-shell .ks-hero-lead{color:#fff}
.ks-shell .ks-hero-lead{color:rgba(255,255,255,.85)}

/* ── Wysoki kontrast ───────────────────────────────────────────────────── */
html[data-theme="hc"]{--ks:#000;--ks-dark:#000;--ks-on:#fff;--ks-ink:#000;--ks-line:#000;--ks-muted:#000}
html[data-theme="hc"] body{background:#000}
html[data-theme="hc"] .ks-hero::before{display:none}
html[data-theme="hc"] .ks-card{box-shadow:none;border:3px solid #000}
html[data-theme="hc"] .form-control{border-width:2px}
html[data-theme="hc"] .ks-btn{border-width:2px}
html[data-theme="hc"] .ks-btn--ghost{background:#fff;color:#000;border-color:#000}
html[data-theme="hc"] .ks-btn--ghost:hover{background:#000;color:#fff}
html[data-theme="hc"] .l-alert,html[data-theme="hc"] .ks-news{background:#fff;border:2px solid #000;color:#000}
html[data-theme="hc"] .ks-a11y{border-bottom:2px solid #fff}
html[data-theme="hc"] .ks-tab[aria-current="page"]{background:#fff;color:#000}
html[data-theme="hc"] .ks-shell .card,html[data-theme="hc"] .ks-shell .tz-card{border:2px solid #000}
html[data-theme="hc"] .ks-shell .tz-btn{border-width:2px}
html[data-theme="hc"] .ks-step .dot{border-width:3px}
html[data-theme="hc"] .ks-hero-h1,html[data-theme="hc"] .ks-hero-lead{color:#fff}

@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important;animation:none!important}}
@media(max-width:575.98px){.ks-h1{font-size:1.45rem}.ks-hero{padding-top:1.5rem}.ks-step{min-width:60px}}
</style>
</head>
<body>

<a href="#<?= h($main_id) ?>" class="skip-link">Przejdź do treści</a>
<div role="status" aria-live="polite"    aria-atomic="true" id="login-live"  class="sr"></div>
<div role="alert"  aria-live="assertive" aria-atomic="true" id="login-alert" class="sr"></div>

<div class="ks-a11y">
  <div class="in">
    <div class="grp" role="group" aria-label="Rozmiar tekstu">
      <span class="lbl">Rozmiar tekstu:</span>
      <button type="button" class="fs-m"  data-fs="m"  aria-pressed="true">m</button>
      <button type="button" class="fs-l"  data-fs="L"  aria-pressed="false">L</button>
      <button type="button" class="fs-xl" data-fs="XL" aria-pressed="false">XL</button>
    </div>
    <div class="grp">
      <span class="lbl" id="hc-lbl">Wysoki kontrast:</span>
      <button type="button" id="hc-btn" aria-pressed="false" aria-label="Wysoki kontrast — włącz">
        <i class="bi bi-circle-half" aria-hidden="true"></i>
      </button>
    </div>
  </div>
</div>

<div class="ks-hero">
<div class="ks-shell">

  <div class="ks-brand">
    <?php if ($b['logo_url']): ?>
      <img src="<?= h($b['logo_url']) ?>" alt="<?= h($org_name) ?>">
    <?php else: ?>
      <span class="mark" aria-hidden="true"><i class="bi bi-building-heart"></i></span>
      <span class="nm"><?= h($org_name) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($tab): ?>
  <div class="ks-toprow">
    <nav class="ks-tabs" aria-label="Logowanie lub rejestracja">
      <a class="ks-tab" href="<?= APP_URL ?>/auth/login.php"    <?= $tab === 'login'    ? 'aria-current="page"' : '' ?>>Zaloguj</a>
      <a class="ks-tab" href="<?= APP_URL ?>/user/register.php" <?= $tab === 'register' ? 'aria-current="page"' : '' ?>>Rejestracja</a>
    </nav>
  </div>
  <?php endif; ?>

  <?php if ($mode === 'card'): ?>
  <main class="ks-card" id="<?= h($main_id) ?>" tabindex="-1">
  <div class="ks-inner">
  <?php else: ?>
  <main id="<?= h($main_id) ?>" tabindex="-1">
  <?php endif; ?>
<?php
}

/** Renderuje wskaźnik kroków (1..N); $light — wariant na tle marki (tryb 'plain'). */
function auth_screen_steps(array $labels, int $current, bool $light = false): void {
    echo '<div class="ks-steps' . ($light ? ' ks-steps--light' : '')
       . '" role="status" aria-label="Krok ' . (int)$current . ' z ' . count($labels) . '">';
    $i = 0;
    foreach ($labels as $num => $label) {
        $num = (int)$num;
        if ($i++ > 0) echo '<div class="ks-line' . ($current > $num - 1 ? ' is-done' : '') . '"></div>';
        $cls = $current > $num ? 'is-done' : ($current === $num ? 'is-active' : '');
        echo '<div class="ks-step ' . $cls . '">'
           . '<span class="dot">' . ($current > $num ? '<i class="bi bi-check-lg" aria-hidden="true"></i>' : $num) . '</span>'
           . '<span class="cap">' . h($label) . '</span></div>';
    }
    echo '</div>';
}

/**
 * Zamyka stronę: karta, linki, skrypty (dostępność + podgląd hasła).
 *
 * @param array $o links — [['url'=>, 'label'=>, 'icon'=>], …]; pusta tablica = bez linków
 *                 bootstrap  — dołącz bundle JS Bootstrapa
 *                 extra_html — HTML wstawiony po </main> (np. modale)
 *                 extra_js   — dodatkowy kod JS wstawiony na końcu
 */
function auth_screen_foot(array $o = []): void {
    $mode  = $GLOBALS['__ks_mode'] ?? 'card';
    $bs    = $o['bootstrap'] ?? ($GLOBALS['__ks_bs'] ?? false);
    $org   = $GLOBALS['__ks_org'] ?? '';
    $links = $o['links'] ?? [
        ['url' => APP_URL . '/karty30/ti/dydaktyk/login.php', 'label' => 'Panel dydaktyka',    'icon' => 'bi-easel2'],
        ['url' => APP_URL . '/auth/report_login_issue.php',   'label' => 'Problem z logowaniem','icon' => 'bi-life-preserver'],
    ];
    ?>
  <?php if ($mode === 'card'): ?>
  </div>
  </main>
  <?php else: ?>
  </main>
  <?php endif; ?>

  <?= $o['extra_html'] ?? '' ?>

  <?php if ($links): ?>
  <div class="ks-links">
    <?php $first = true; foreach ($links as $l): ?>
      <?php if (!$first): ?><span class="dot" aria-hidden="true">•</span><?php endif; $first = false; ?>
      <a href="<?= h($l['url']) ?>"><?php if (!empty($l['icon'])): ?><i class="bi <?= h($l['icon']) ?>" aria-hidden="true"></i><?php endif; ?><?= h($l['label']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="ks-copy">&copy; <?= date('Y') ?> <?= h($org) ?></div>

</div>
</div>

<script>
(function(){
'use strict';
/* Podgląd hasła — używane przez ekrany logowania, rejestracji i resetu */
function togglePass(id, btn){
  var inp=document.getElementById(id); if(!inp) return;
  var hidden=inp.type!=='password'; inp.type=hidden?'password':'text';
  btn.setAttribute('aria-pressed',hidden?'false':'true');
  btn.setAttribute('aria-label',hidden?'Pokaż hasło':'Ukryj hasło');
  var i=btn.querySelector('i'); if(i) i.className=hidden?'bi bi-eye':'bi bi-eye-slash';
}
window.togglePass=togglePass;

/* Pasek dostępności: rozmiar tekstu + wysoki kontrast */
var root=document.documentElement;
function setFs(v){
  root.dataset.fs=v;
  try{localStorage.setItem('szoFs',v);}catch(e){}
  document.querySelectorAll('.ks-a11y button[data-fs]').forEach(function(b){
    b.setAttribute('aria-pressed', b.dataset.fs===v?'true':'false');
  });
}
document.querySelectorAll('.ks-a11y button[data-fs]').forEach(function(b){
  b.addEventListener('click',function(){setFs(b.dataset.fs);});
});
setFs(root.dataset.fs||'m');

var hcBtn=document.getElementById('hc-btn');
function setHc(on){
  if(on) root.dataset.theme='hc'; else root.removeAttribute('data-theme');
  hcBtn.setAttribute('aria-pressed',on?'true':'false');
  hcBtn.setAttribute('aria-label',on?'Wysoki kontrast — wyłącz':'Wysoki kontrast — włącz');
  try{localStorage.setItem('szoHc',on?'1':'0');}catch(e){}
}
if(hcBtn){
  hcBtn.addEventListener('click',function(){setHc(root.dataset.theme!=='hc');});
  setHc(root.dataset.theme==='hc');
}

/* Komunikat „coś tu się zmieniło" — zamknięcie zapamiętywane */
var newsX=document.getElementById('ks-news-x');
if(newsX) newsX.addEventListener('click',function(){
  root.dataset.news='off';
  try{localStorage.setItem('szoNews','2026-08');}catch(e){}
});

/* Błąd do czytnika ekranu */
var errText=document.getElementById('login-error-text');
var liveErr=document.getElementById('login-alert');
if(errText && liveErr) liveErr.textContent=errText.textContent.trim();

/* Kod SMS: 6 cyfr → wyślij formularz */
var smsInput=document.getElementById('sms_code');
if(smsInput) smsInput.addEventListener('input',function(){
  if(this.value.replace(/\D/g,'').length===6) this.form.submit();
});
})();
</script>
<?= $o['extra_js'] ?? '' ?>
<?php if ($bs): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>
</body>
</html>
<?php
}

/** Wypisuje komunikat „coś tu się zmieniło" (zamykany, zapamiętywany). */
function auth_screen_news(): void {
    ?>
    <div class="ks-news" id="ks-news" role="note">
      <i class="bi bi-stars ico" aria-hidden="true"></i>
      <div>
        <strong>Coś tu się zmieniło 👋</strong>
        <p>Odświeżyliśmy ekrany wejścia do systemu. Na górze strony ustawisz teraz <strong>rozmiar tekstu</strong>
           i <strong>wysoki kontrast</strong> — ustawienie zostaje zapamiętane. Logowanie, rejestracja
           i odzyskiwanie hasła działają tak samo jak dotąd.</p>
      </div>
      <button type="button" class="x" id="ks-news-x" aria-label="Zamknij komunikat o nowym wyglądzie">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>
    <?php
}
