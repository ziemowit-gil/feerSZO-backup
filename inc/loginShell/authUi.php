<?php
/**
 * inc/loginShell/authUi.php — jasny wygląd ekranów wejścia do SZO (przebudowa 2026-11-14).
 *
 * Jedna biała karta na jasnoszarym tle, logo Fundacji u góry karty, czcionka systemowa, granatowy akcent,
 * duże pola (48 px) i przyciski. Używa go tryb 'light' powłoki includes/auth_screen.php, a przez nią:
 * logowanie (auth/login.php), CPL (tozsamosc/cpl.php), bramka IKA (contracts/ika_gate.php), 2FA, nowe logowanie,
 * ustawianie hasła, hasło awaryjne, rejestracja, odzyskiwanie dostępu i dawne logowanie Tożsamości (?stary=1).
 * Wygląd METRO (inc/loginShell/loginShell.php: loginShellCss) zostaje bez zmian dla stron spoza tej listy.
 *
 * Arkusz styluje wspólne klasy ekranów wejścia (ks-*, l-alert*, pass-wrap, lg-new) i elementy Bootstrapa,
 * więc treść stron nie musi znać nowych nazw. Nowe elementy: au-wrap, au-card, au-brand, au-two, au-kicker,
 * au-ferr (błąd przy polu), au-links, au-foot.
 *
 * WCAG 2.1 AA (kontrast na białym tle):
 *   tekst #111827 = 17,7:1; tekst drugorzędny #4b5563 = 7,6:1 (na #f3f4f6 = 6,9:1);
 *   granat #1e3a8a: biały napis na przycisku i link na białym = 10,4:1;
 *   błąd #b91c1c = 6,5:1; ramka pola #6b7280 = 4,8:1 (wymóg dla elementów UI: 3:1);
 *   fokus: czerwony obrys #c50f1f, 3 px, odsunięty o 2 px (6,1:1 na białym, 5,6:1 na tle strony).
 */

/** Arkusz stylów jasnego wyglądu (bez znacznika <style>). $cardWidth — maks. szerokość karty w px. */
function authUiCss(int $cardWidth = 480): string {
    $col = max(360, min($cardWidth, 960));
    return <<<CSS
:root{
  --au-bg:#f3f4f6;--au-card:#fff;--au-line:#e5e7eb;--au-line2:#d1d5db;--au-field:#6b7280;
  --au-ink:#111827;--au-ink2:#374151;--au-muted:#4b5563;
  --au-acc:#1e3a8a;--au-acc-h:#172554;--au-acc-soft:#eff6ff;--au-acc-line:#bfdbfe;
  --au-focus:#c50f1f;
  --au-err:#b91c1c;--au-err-ink:#7f1d1d;--au-err-bg:#fef2f2;--au-err-line:#fecaca;
  --au-ok:#15803d;--au-ok-ink:#14532d;--au-ok-bg:#f0fdf4;--au-ok-line:#bbf7d0;
  --au-warn:#b45309;--au-warn-ink:#78350f;--au-warn-bg:#fffbeb;--au-warn-line:#fde68a;
  --au-r:12px;--au-rs:8px;--au-col:{$col}px;
  /* Zgodność: zmienne, których używa treść starszych ekranów (style w atrybutach, modale logowania, bramka IKA). */
  --c:var(--au-acc);--c-dark:var(--au-acc-h);--c-darker:var(--au-acc-h);--c-light:var(--au-acc-line);--c-text:#fff;
  --c-ring:rgba(30,58,138,.18);--c-bg:var(--au-acc-soft);
  --ks:var(--au-acc);--ks-dark:var(--au-acc-h);--ks-on:#fff;--ks-ink:var(--au-ink);--ks-line:var(--au-field);--ks-muted:var(--au-muted);
  --tz:var(--au-acc);--tz-strong:var(--au-acc-h);--tz-line:var(--au-line2);--tz-muted:var(--au-muted);--tz-50:var(--au-acc-soft);--tz-canvas:var(--au-bg)
}
*,*::before,*::after{box-sizing:border-box}
html{font-size:16px}
html[data-fs="L"]{font-size:18px}
html[data-fs="XL"]{font-size:20px}
html,body{margin:0;padding:0}
body.au{min-height:100vh;display:flex;flex-direction:column;background:var(--au-bg);color:var(--au-ink);
  font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;font-size:1rem;line-height:1.5;
  -webkit-font-smoothing:antialiased}
.au :focus-visible{outline:3px solid var(--au-focus)!important;outline-offset:2px!important;box-shadow:none!important}
.au :focus:not(:focus-visible){outline:none}
/* Kontener treści dostaje fokus tylko po „Przejdź do treści” — bez obrysu wokół całej strony */
.au .au-wrap:focus,.au .au-wrap:focus-visible{outline:none!important}
.au .skip-link{position:absolute;left:1rem;top:-6rem;z-index:2000;background:var(--au-ink);color:#fff;padding:.7rem 1.1rem;
  border-radius:0 0 var(--au-rs) var(--au-rs);font-weight:600;text-decoration:none}
.au .skip-link:focus{top:0}
.au .sr,.au .vh{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.au [hidden]{display:none!important}

/* ── Układ: karta na środku, pod nią stopka ─────────────────────────────── */
.au-wrap{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2.5rem 1rem 2rem}
.au-card{width:100%;max-width:var(--au-col);background:var(--au-card);border:1px solid var(--au-line);border-radius:var(--au-r);
  box-shadow:0 1px 2px rgba(17,24,39,.04),0 14px 34px -18px rgba(17,24,39,.22);padding:2.5rem 2.25rem 2.1rem}
.au-brand{display:flex;align-items:center;gap:.75rem;min-height:40px;margin:0 0 1.75rem}
.au-brand img{display:block;height:40px;width:auto;max-width:220px;object-fit:contain}
.au-brand__name{font-size:1rem;font-weight:700;color:var(--au-ink)}
.au-kicker{margin:0 0 .35rem;font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--au-acc)}
/* Dwie kolumny (logowanie: informacja + karta). W DOM najpierw karta; szeroko informacja po lewej. */
.au-two{display:grid;grid-template-columns:minmax(0,1fr);grid-template-areas:"card" "aside";gap:1.25rem;width:100%;max-width:var(--au-col)}
.au-two>.au-card{grid-area:card;max-width:none}
.au-two>:not(.au-card){grid-area:aside}
@media(min-width:900px){
  .au-two{grid-template-columns:minmax(0,1fr) var(--au-col);grid-template-areas:"aside card";max-width:calc(var(--au-col) + 600px);align-items:start}
}
@media(max-width:520px){
  .au-wrap{padding:1rem .75rem 1.5rem}
  .au-card{padding:1.75rem 1.25rem 1.5rem;border-radius:10px}
}

/* ── Nagłówki ───────────────────────────────────────────────────────────── */
.au .ks-h1,.au .ks-hero-h1,.au-h1{margin:0 0 .35rem;font-size:1.625rem;font-weight:700;letter-spacing:-.01em;line-height:1.25;color:var(--au-ink);text-align:left}
.au .ks-lead,.au .ks-hero-lead,.au-lead{margin:0 0 1.75rem;font-size:1rem;line-height:1.55;color:var(--au-muted);text-align:left}
.au .ks-hero-ico{display:flex;align-items:center;justify-content:center;width:48px;height:48px;margin:0 0 1rem;border-radius:12px;
  background:var(--au-acc-soft);color:var(--au-acc);font-size:1.4rem}
.au .ks-sub{margin:0 0 .9rem;font-size:1rem;font-weight:600}

/* ── Pola formularzy ────────────────────────────────────────────────────── */
.au .ks-field,.au-field{margin:0 0 1.15rem}
.au .ks-field>label,.au-field>label,.au .form-label{display:block;margin:0 0 .4rem;font-size:.95rem;font-weight:600;color:var(--au-ink)}
.au .form-control,.au .form-select{display:block;width:100%;min-height:48px;padding:.65rem .875rem;font:inherit;font-size:1rem;line-height:1.4;
  color:var(--au-ink);background-color:#fff;border:1px solid var(--au-field);border-radius:var(--au-rs);box-shadow:none;
  transition:border-color .12s,box-shadow .12s}
.au select.form-control{appearance:auto}
.au .form-control::placeholder{color:var(--au-field);opacity:1}
.au .form-control:focus,.au .form-select:focus{border-color:var(--au-acc);box-shadow:0 0 0 1px var(--au-acc)}
.au .form-control[aria-invalid="true"],.au .form-control.is-invalid,.au .form-select[aria-invalid="true"]{border-color:var(--au-err);box-shadow:0 0 0 1px var(--au-err);background-image:none}
.au .form-control-lg{min-height:52px;font-size:1.1rem}
.au .form-control-sm{min-height:40px;padding:.45rem .7rem;font-size:.95rem}
.au input[type=checkbox],.au input[type=radio]{accent-color:var(--au-acc)}
.au .pass-wrap{position:relative}
.au .pass-wrap .form-control{padding-right:3.25rem}
.au .pass-toggle{position:absolute;top:2px;right:2px;bottom:2px;width:2.9rem;display:flex;align-items:center;justify-content:center;
  border:0;border-radius:6px;background:transparent;color:var(--au-ink);font-size:1.1rem;cursor:pointer}
.au .pass-toggle:hover{background:var(--au-bg)}
.au .ks-fieldhint,.au .ks-hint,.au .form-text,.au-hint{margin:.4rem 0 0;font-size:.875rem;line-height:1.5;color:var(--au-muted)}
.au .ks-hint{text-align:left}
.au .ks-note{margin:1.25rem 0 0;font-size:.875rem;line-height:1.55;color:var(--au-muted)}
/* Błąd przy polu: tekst pod polem, role="alert", pole ma aria-invalid i aria-describedby na ten element. */
.au-ferr{display:flex;gap:.45rem;align-items:flex-start;margin:.45rem 0 0;font-size:.9rem;font-weight:600;line-height:1.45;color:var(--au-err)}
.au-ferr>i{line-height:1.45}
.au .invalid-feedback{font-size:.9rem;font-weight:600;color:var(--au-err)}
.au .ks-otp,.au .sms-otp{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:1.75rem;font-weight:700;
  letter-spacing:.5em;text-align:center;padding-left:calc(.875rem + .5em)}

/* ── Przyciski ──────────────────────────────────────────────────────────── */
.au .ks-btn{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;min-height:48px;padding:.7rem 1.25rem;
  border:1px solid transparent;border-radius:var(--au-rs);font:inherit;font-size:1rem;font-weight:600;line-height:1.25;
  text-align:center;text-decoration:none;cursor:pointer;transition:background-color .12s,border-color .12s,color .12s}
.au .ks-btn--primary{background:var(--au-acc);border-color:var(--au-acc);color:#fff}
.au .ks-btn--primary:hover{background:var(--au-acc-h);border-color:var(--au-acc-h);color:#fff}
.au .ks-btn--ok{background:var(--au-ok);border-color:var(--au-ok);color:#fff}
.au .ks-btn--ok:hover{background:#166534;border-color:#166534;color:#fff}
.au .ks-btn--ghost{background:#fff;border-color:var(--au-acc);color:var(--au-acc)}
.au .ks-btn--ghost:hover{background:var(--au-acc-soft);border-color:var(--au-acc-h);color:var(--au-acc-h)}
.au .ks-btn:disabled,.au .ks-btn[aria-disabled="true"]{opacity:.6;cursor:not-allowed}
.au .ks-btn + .ks-btn,.au form + form > .ks-btn{margin-top:.75rem}
.au .ks-or{display:flex;align-items:center;gap:.75rem;margin:1.5rem 0;font-size:.875rem;color:var(--au-muted)}
.au .ks-or::before,.au .ks-or::after{content:"";flex:1;height:1px;background:var(--au-line2)}
.au .ks-sep{border:0;border-top:1px solid var(--au-line);margin:1.75rem 0 1.25rem}
.au .ks-optsub{display:block;margin-top:.1rem;font-size:.85rem;font-weight:400;color:var(--au-muted)}

/* ── Linki ──────────────────────────────────────────────────────────────── */
/* :where() — reguła ogólna o niskiej wadze, więc kafle, przyciski i linki w komunikatach mają własny wygląd */
.au-card a:where(:not(.ks-btn,.btn,.nav-link,.dropdown-item)){color:var(--au-acc);text-decoration:underline;text-underline-offset:2px}
.au-card a:where(:not(.ks-btn,.btn,.nav-link,.dropdown-item)):hover{color:var(--au-acc-h);text-decoration-thickness:2px}
.au .ks-forgot{display:inline-block;margin-top:.75rem;font-size:.95rem}
.au-links,.au .lg-links{display:flex;justify-content:space-between;flex-wrap:wrap;gap:.25rem 1rem;margin:1.25rem 0 0;font-size:.95rem}
.au-links a,.au .lg-links a{display:inline-flex;align-items:center;gap:.35rem;min-height:44px;font-weight:600}

/* ── Komunikaty ─────────────────────────────────────────────────────────── */
.au .l-alert{display:flex;gap:.65rem;align-items:flex-start;margin:0 0 1.25rem;padding:.8rem 1rem;border:1px solid;border-left-width:4px;
  border-radius:var(--au-rs);font-size:.95rem;line-height:1.5}
.au .l-alert>i{flex-shrink:0;font-size:1.1rem;line-height:1.35}
.au .l-alert-danger{background:var(--au-err-bg);border-color:var(--au-err-line);border-left-color:var(--au-err);color:var(--au-err-ink)}
.au .l-alert-success{background:var(--au-ok-bg);border-color:var(--au-ok-line);border-left-color:var(--au-ok);color:var(--au-ok-ink)}
.au .l-alert-info{background:var(--au-acc-soft);border-color:var(--au-acc-line);border-left-color:var(--au-acc);color:var(--au-acc)}
.au .l-alert-warn{background:var(--au-warn-bg);border-color:var(--au-warn-line);border-left-color:var(--au-warn);color:var(--au-warn-ink)}
.au .l-alert a{color:inherit;font-weight:600}

/* ── Kroki (rejestracja, odzyskiwanie dostępu) ──────────────────────────── */
.au .ks-steps{display:flex;align-items:flex-start;justify-content:flex-start;margin:0 0 1.75rem}
.au .ks-step{display:flex;flex-direction:column;align-items:center;gap:.35rem;min-width:76px}
.au .ks-step .dot{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;
  border:2px solid var(--au-field);background:#fff;color:var(--au-muted);font-size:.875rem;font-weight:700}
.au .ks-step.is-active .dot{border-color:var(--au-acc);color:var(--au-acc);box-shadow:0 0 0 3px #dbeafe}
.au .ks-step.is-done .dot{background:var(--au-acc);border-color:var(--au-acc);color:#fff}
.au .ks-step .cap{max-width:92px;font-size:.78rem;line-height:1.3;text-align:center;color:var(--au-muted)}
.au .ks-step.is-active .cap{color:var(--au-ink);font-weight:600}
.au .ks-line{flex:1;max-width:64px;height:2px;margin:15px .25rem 0;background:var(--au-line2)}
.au .ks-line.is-done{background:var(--au-acc)}

/* ── Kafle „Pierwsze logowanie” ─────────────────────────────────────────── */
.au .lg-new{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin:1.75rem 0 0}
.au .lg-new a,.au .lg-new button{display:flex;align-items:center;gap:.75rem;min-height:60px;padding:.75rem .85rem;
  border:1px solid var(--au-line2);border-radius:10px;background:#fff;color:var(--au-ink);font:inherit;text-align:left;
  text-decoration:none;cursor:pointer;transition:border-color .12s,background-color .12s}
.au .lg-new a:hover,.au .lg-new button:hover{border-color:var(--au-acc);background:#f9fafb}
.au .lg-new i{flex:0 0 2.5rem;display:flex;align-items:center;justify-content:center;height:2.5rem;border-radius:8px;
  background:var(--au-acc-soft);color:var(--au-acc);font-size:1.2rem}
.au .lg-new span{display:flex;flex-direction:column;font-size:.85rem;line-height:1.3;color:var(--au-muted)}
.au .lg-new strong{font-size:.95rem;color:var(--au-acc)}
.au .lg-new em{font-style:normal;font-size:.8rem;color:var(--au-muted)}
@media(max-width:400px){.au .lg-new{grid-template-columns:1fr}}

/* ── Stopka strony ──────────────────────────────────────────────────────── */
.au-foot{padding:0 1rem 1.5rem;font-size:.875rem;line-height:1.6;text-align:center;color:var(--au-muted)}
.au-foot a{color:var(--au-muted);text-decoration:underline;text-underline-offset:2px}
.au-foot a:hover{color:var(--au-ink)}

/* ── Okna dialogowe (logowanie kodem, SMS, certyfikatem) ────────────────── */
.au .modal-content{border:0;border-radius:var(--au-r);box-shadow:0 24px 48px -20px rgba(17,24,39,.4)}
.au .lm-content{overflow:hidden}
.au .lm-hd{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--au-line);background:#fff}
.au .lm-title{display:flex;align-items:center;gap:.5rem;margin:0;font-size:1.1rem;font-weight:700}
.au .lm-body{padding:1.25rem 1.5rem 1.5rem}

/* ── Bootstrap w karcie (odzyskiwanie dostępu, bramka IKA, hasło awaryjne) ─ */
.au .btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;min-height:44px;border-radius:var(--au-rs);font-weight:600}
.au .btn-sm{min-height:36px}
.au .btn-lg{min-height:52px}
.au .btn-primary{--bs-btn-color:#fff;--bs-btn-bg:#1e3a8a;--bs-btn-border-color:#1e3a8a;--bs-btn-hover-color:#fff;--bs-btn-hover-bg:#172554;
  --bs-btn-hover-border-color:#172554;--bs-btn-active-color:#fff;--bs-btn-active-bg:#172554;--bs-btn-active-border-color:#172554;
  --bs-btn-disabled-color:#fff;--bs-btn-disabled-bg:#1e3a8a;--bs-btn-disabled-border-color:#1e3a8a}
.au .btn-success{--bs-btn-bg:#15803d;--bs-btn-border-color:#15803d;--bs-btn-hover-bg:#166534;--bs-btn-hover-border-color:#166534;
  --bs-btn-active-bg:#166534;--bs-btn-active-border-color:#166534;--bs-btn-disabled-bg:#15803d;--bs-btn-disabled-border-color:#15803d}
.au .btn-outline-primary{--bs-btn-color:#1e3a8a;--bs-btn-border-color:#1e3a8a;--bs-btn-hover-color:#172554;--bs-btn-hover-bg:#eff6ff;
  --bs-btn-hover-border-color:#172554;--bs-btn-active-color:#172554;--bs-btn-active-bg:#dbeafe;--bs-btn-active-border-color:#172554}
.au .btn-outline-secondary{--bs-btn-color:#374151;--bs-btn-border-color:#6b7280;--bs-btn-hover-color:#111827;--bs-btn-hover-bg:#f3f4f6;
  --bs-btn-hover-border-color:#374151;--bs-btn-active-color:#111827;--bs-btn-active-bg:#e5e7eb;--bs-btn-active-border-color:#374151}
.au .btn-link{color:var(--au-acc)}
.au .text-primary{color:var(--au-acc)!important}
.au .text-muted,.au .text-secondary{color:var(--au-muted)!important}
.au .form-check-input{border-color:var(--au-field)}
.au .form-check-input:checked{background-color:var(--au-acc);border-color:var(--au-acc)}
.au .input-group-text{background:#f9fafb;border-color:var(--au-field);color:var(--au-ink2)}
.au .input-group>.form-control{min-height:48px}
.au .alert{border-left-width:4px;border-radius:var(--au-rs);font-size:.95rem}
.au .alert-danger{--bs-alert-bg:var(--au-err-bg);--bs-alert-border-color:var(--au-err-line);--bs-alert-color:var(--au-err-ink);border-left-color:var(--au-err)}
.au .alert-success{--bs-alert-bg:var(--au-ok-bg);--bs-alert-border-color:var(--au-ok-line);--bs-alert-color:var(--au-ok-ink);border-left-color:var(--au-ok)}
.au .alert-info,.au .alert-primary{--bs-alert-bg:var(--au-acc-soft);--bs-alert-border-color:var(--au-acc-line);--bs-alert-color:var(--au-acc);border-left-color:var(--au-acc)}
.au .alert-warning{--bs-alert-bg:var(--au-warn-bg);--bs-alert-border-color:var(--au-warn-line);--bs-alert-color:var(--au-warn-ink);border-left-color:var(--au-warn)}
.au .alert-secondary{--bs-alert-bg:#f9fafb;--bs-alert-border-color:var(--au-line);--bs-alert-color:var(--au-ink2);border-left-color:var(--au-field)}
.au .alert a{color:inherit;font-weight:600;text-decoration:underline}
.au .au-card .card{border:1px solid var(--au-line);border-radius:10px;box-shadow:none!important;background:#fff}
.au .au-card .card.border-primary{border-color:var(--au-acc-line)!important;border-left:4px solid var(--au-acc)!important;background:var(--au-acc-soft)}
.au .card-header{background:#f9fafb;border-bottom-color:var(--au-line)}
.au .nav-tabs{border-bottom-color:var(--au-line2)}
.au .nav-tabs .nav-link{display:flex;align-items:center;min-height:44px;color:var(--au-acc);border-radius:var(--au-rs) var(--au-rs) 0 0}
.au .nav-tabs .nav-link.active{color:var(--au-ink);font-weight:600;border-color:var(--au-line2) var(--au-line2) #fff}

/* ── Wysoki kontrast (zapamiętany wybór z dawnego paska ułatwień) ───────── */
html[data-theme="hc"] body.au{--au-bg:#fff;--au-line:#000;--au-line2:#000;--au-field:#000;--au-muted:#000;--au-ink2:#000;
  --au-acc:#000;--au-acc-h:#000;--au-acc-soft:#fff;--au-acc-line:#000}
html[data-theme="hc"] .au-card{border:2px solid #000;box-shadow:none}
html[data-theme="hc"] .au .form-control,html[data-theme="hc"] .au .ks-btn{border-width:2px}

@media(prefers-reduced-motion:reduce){.au *,.au *::before,.au *::after{transition:none!important;animation:none!important}}
CSS;
}
