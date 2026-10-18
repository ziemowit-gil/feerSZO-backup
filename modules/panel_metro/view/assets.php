<?php
/**
 * modules/panel_metro/view/assets.php — CSS + skrypt pivota zestawu METRO Panelu współpracownika.
 * Emitowane przez panelMetroAssets() (raz na żądanie). Wejście: $accent, $accentOn.
 */
$_pal = panelMetroPalette();
?>
<style>
/* ══ Panel współpracownika — METRO (jak System Tożsamości) ══════════════════ */
.pvm{
  --m-blue:<?= $_pal['blue'] ?>;--m-green:<?= $_pal['green'] ?>;--m-orange:<?= $_pal['orange'] ?>;--m-red:<?= $_pal['red'] ?>;
  --m-teal:<?= $_pal['teal'] ?>;--m-violet:<?= $_pal['violet'] ?>;--m-magenta:<?= $_pal['magenta'] ?>;--m-indigo:<?= $_pal['indigo'] ?>;
  --m-dark:<?= $_pal['dark'] ?>;--m-grey:<?= $_pal['grey'] ?>;
  --pvm-acc:<?= h($accent) ?>;--pvm-acc-on:<?= h($accentOn) ?>;
  --pvm-light:#f2f2f2;--pvm-light-h:#e6e6e6;--pvm-bg:#fff;
  --pvm-ink:var(--tz-ink,#111827);--pvm-muted:var(--tz-muted,#5b6472);--pvm-line:var(--tz-line,#e1e1e1);
  max-width:1100px;margin:0 auto;color:var(--pvm-ink);
  font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif;
}
@media(prefers-color-scheme:dark){:root:not([data-theme="light"]):not([data-theme="hc"]) .pvm{--pvm-light:#1a2232;--pvm-light-h:#222c3f;--pvm-bg:#0f172a}}
:root[data-theme="dark"] .pvm{--pvm-light:#1a2232;--pvm-light-h:#222c3f;--pvm-bg:#0f172a}
:root[data-theme="hc"] .pvm{--pvm-light:#fff;--pvm-light-h:#f0f0f0;--pvm-bg:#fff}
.pvm *:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}

/* nagłówek */
.pvm .mt-kicker{font-size:.74rem;font-weight:600;text-transform:uppercase;letter-spacing:.18em;color:var(--pvm-muted);margin-bottom:.35rem}
.pvm .mt-title{margin:0;font-weight:300;line-height:1.05;letter-spacing:-.02em;color:var(--pvm-ink);font-size:clamp(1.9rem,4vw,2.5rem)}
.pvm .mt-lead{font-size:.95rem;color:var(--pvm-muted);margin:.5rem 0 0;max-width:70ch;font-weight:300}
.pvm .mt-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1.5rem}

/* kafle Start */
.pvm .mt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));grid-auto-rows:150px;gap:8px;grid-auto-flow:dense;margin:0}
@media(max-width:480px){.pvm .mt-grid{grid-template-columns:repeat(2,1fr);grid-auto-rows:136px}}
.pvm .mt-grid--sm{grid-auto-rows:120px}
.pvm .mt-tile{position:relative;display:flex;flex-direction:column;justify-content:space-between;padding:14px 16px;color:#fff;text-decoration:none;background:var(--c,var(--m-blue));overflow:hidden;border:0;border-radius:0;text-align:left;font:inherit;cursor:pointer;transition:transform .08s,filter .12s;min-width:0}
.pvm .mt-tile:hover{filter:brightness(1.1);color:#fff;text-decoration:none}
.pvm .mt-tile:active{transform:scale(.97)}
.pvm .mt-tile:focus-visible{outline:3px solid #fbbf24;outline-offset:-3px}
.pvm .mt-tile--acc{background:var(--pvm-acc);color:var(--pvm-acc-on)}
.pvm .mt-tile--acc:hover{color:var(--pvm-acc-on)}
.pvm .mt-tile--w{grid-column:span 2}
.pvm .mt-tile--l{grid-column:span 2;grid-row:span 2}
.pvm .mt-tile .ic{font-size:2rem;line-height:1}
.pvm .mt-tile--l .ic{font-size:3.2rem}
.pvm .mt-tile .lbl{display:block;font-size:.84rem;font-weight:600;line-height:1.2}
.pvm .mt-tile .val{display:block;font-size:1.85rem;font-weight:300;line-height:1;letter-spacing:-.02em;margin-bottom:.2rem}
.pvm .mt-tile--l .val{font-size:2.3rem}
.pvm .mt-tile .sub{display:block;font-size:.74rem;opacity:.9;margin-top:.15rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pvm .mt-tile--l .sub{white-space:normal}
.pvm .mt-tile .tag{position:absolute;top:10px;right:12px;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:rgba(0,0,0,.32);padding:2px 7px}
.pvm .mt-tile .ava{width:64px;height:64px;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:600}
.pvm .mt-tile .bar{display:block;height:6px;background:rgba(255,255,255,.3);margin-top:.55rem}
.pvm .mt-tile .bar>span{display:block;height:100%;background:currentColor}
@media(max-width:480px){.pvm .mt-tile--l .ic{font-size:2.4rem}.pvm .mt-tile--l .val{font-size:1.7rem}}

/* pivot (zakładki) */
.pvm .mt-pivot{display:flex;gap:1.6rem;overflow-x:auto;border-bottom:1px solid var(--pvm-line);margin:1.9rem 0 1.4rem;scrollbar-width:none}
.pvm .mt-pivot::-webkit-scrollbar{display:none}
.pvm .mt-pivot button{background:none;border:0;border-bottom:3px solid transparent;margin-bottom:-1px;padding:.35rem 0 .6rem;font:inherit;font-size:1.3rem;font-weight:300;color:var(--pvm-muted);cursor:pointer;white-space:nowrap;letter-spacing:-.01em;display:inline-flex;align-items:center;gap:.4rem}
.pvm .mt-pivot button:hover{color:var(--pvm-ink)}
.pvm .mt-pivot button[aria-selected="true"]{color:var(--pvm-ink);font-weight:600;border-bottom-color:var(--pvm-acc)}
.pvm .mt-pivot .cnt{font-size:.7rem;font-weight:700;background:var(--m-red);color:#fff;padding:1px 6px;line-height:1.4}
.pvm [data-pvm-panel][hidden]{display:none!important}

/* sekcje, grupy */
.pvm .mt-h2{margin:0 0 .8rem;font-size:1.35rem;font-weight:300;color:var(--pvm-ink);letter-spacing:-.01em}
.pvm .mt-h2 b{font-weight:600}
.pvm .mt-h3{margin:1.5rem 0 .6rem;font-size:1.02rem;font-weight:600;color:var(--pvm-ink)}
.pvm .mt-h3:first-child{margin-top:0}
.pvm .mt-group{background:var(--pvm-light);padding:1.25rem;margin-bottom:1rem}
.pvm .mt-kv{display:grid;grid-template-columns:1fr;gap:1rem 1.5rem;margin:0}
@media(min-width:576px){.pvm .mt-kv{grid-template-columns:repeat(2,1fr)}}
@media(min-width:1100px){.pvm .mt-kv{grid-template-columns:repeat(3,1fr)}}
.pvm .mt-kv dt{margin:0;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--pvm-muted)}
.pvm .mt-kv dd{margin:.15rem 0 0;font-size:1.05rem;font-weight:300;color:var(--pvm-ink);word-break:break-word}
.pvm .mt-kv dd.big{font-size:1.5rem}
.pvm .mt-yes{color:var(--m-green);font-weight:600}
.pvm .mt-no{color:var(--pvm-muted)}
.pvm .mt-progress{height:8px;background:var(--pvm-line);margin-top:1.1rem}
.pvm .mt-progress>span{display:block;height:100%;background:var(--pvm-acc)}
.pvm .mt-progress-lbl{display:flex;justify-content:space-between;gap:.5rem;font-size:.78rem;color:var(--pvm-muted);margin-top:.35rem}

/* usługi: wiersze z grubym paskiem koloru */
.pvm .mt-svc{display:flex;align-items:flex-start;gap:1rem;background:var(--pvm-light);padding:1rem;min-width:0;border-left:8px solid var(--c,var(--m-blue));margin-bottom:8px}
.pvm .mt-svc>.ic{font-size:1.6rem;color:var(--pvm-ink);width:2.25rem;text-align:center;flex-shrink:0;line-height:1.2}
.pvm .mt-svc__bd{flex:1;min-width:0}
.pvm .mt-svc h3{margin:0;font-size:1rem;font-weight:600;color:var(--pvm-ink);display:flex;flex-wrap:wrap;align-items:center;gap:.4rem}
.pvm .mt-svc p{margin:.2rem 0 0;font-size:.85rem;color:var(--pvm-muted)}
.pvm .mt-svc code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--pvm-ink);background:var(--pvm-bg);padding:.1rem .4rem;font-size:.85rem;word-break:break-all}
.pvm .mt-links{display:flex;flex-wrap:wrap;gap:.4rem 1.1rem;margin-top:.55rem}
.pvm .mt-links a{font-size:.85rem;font-weight:600;color:var(--pvm-ink);text-decoration:underline;text-underline-offset:3px;display:inline-flex;align-items:center;gap:.35rem}
.pvm .mt-links a:hover{text-decoration-thickness:2px}

/* plakietki, przyciski, puste stany */
.pvm .mt-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.1rem .45rem;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#fff;background:var(--m-grey);white-space:nowrap}
.pvm .mt-badge--ok{background:var(--m-green)}.pvm .mt-badge--warn{background:var(--m-orange)}.pvm .mt-badge--info{background:var(--m-blue)}
.pvm .mt-btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;background:var(--pvm-acc);color:var(--pvm-acc-on);border:2px solid var(--pvm-acc);border-radius:0;padding:.5rem 1.1rem;font-size:.88rem;font-weight:600;text-decoration:none;cursor:pointer;min-height:44px;font-family:inherit}
.pvm .mt-btn:hover{filter:brightness(1.1);color:var(--pvm-acc-on)}
.pvm .mt-btn--ghost{background:transparent;color:var(--pvm-ink);border-color:var(--pvm-ink)}
.pvm .mt-btn--ghost:hover{background:var(--pvm-ink);color:var(--pvm-bg);filter:none}
.pvm .mt-copy{background:none;border:0;padding:.15rem .35rem;cursor:pointer;color:var(--pvm-muted);line-height:1}
.pvm .mt-copy:hover{color:var(--pvm-ink);background:var(--pvm-light-h)}
.pvm .mt-empty{background:var(--pvm-light);padding:2rem 1rem;text-align:center;color:var(--pvm-muted)}
.pvm .mt-note{background:var(--pvm-light);border-left:6px solid var(--pvm-acc);padding:.85rem 1rem;font-size:.88rem;color:var(--pvm-ink);display:flex;gap:.6rem;align-items:flex-start;margin-bottom:1rem}
.pvm .mt-note>i{font-size:1.1rem;color:var(--pvm-acc);margin-top:.1rem}

/* Ważne informacje (komunikaty admina) */
.pvm .mt-imp{margin:0 0 1.5rem}
.pvm .mt-imp__hd{display:flex;align-items:center;gap:.6rem;margin:0 0 .6rem;font-size:1.35rem;font-weight:300;color:var(--pvm-ink)}
.pvm .mt-imp__hd .sq{width:34px;height:34px;background:var(--m-orange);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:1.05rem}
.pvm .mt-imp__list{display:grid;gap:8px;grid-template-columns:1fr}
@media(min-width:900px){.pvm .mt-imp__list--2{grid-template-columns:1fr 1fr}}
.pvm .mt-imp__item{background:var(--pvm-light);border-left:8px solid var(--c,var(--m-orange));padding:1rem 1.15rem;min-width:0}
.pvm .mt-imp__meta{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--pvm-muted);display:flex;flex-wrap:wrap;gap:.35rem .8rem;margin-bottom:.3rem}
.pvm .mt-imp__ttl{margin:0;font-size:1.1rem;font-weight:600;color:var(--pvm-ink);line-height:1.3}
.pvm .mt-imp__body{margin:.4rem 0 0;font-size:.92rem;line-height:1.6;color:var(--pvm-ink);white-space:pre-wrap;word-break:break-word}
.pvm .mt-imp__more{display:inline-block;margin-top:.45rem;font-size:.85rem;font-weight:600;color:var(--pvm-ink);text-decoration:underline;text-underline-offset:3px}

/* wiadomości */
.pvm .mt-feed{list-style:none;margin:0;padding:0}
.pvm .mt-feed li{display:flex;gap:.75rem;padding:.85rem 1rem;background:var(--pvm-light);margin-bottom:4px;align-items:flex-start}
.pvm .mt-feed .av{width:38px;height:38px;background:var(--m-grey);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;flex-shrink:0}
.pvm .mt-feed .av--admin{background:var(--pvm-acc);color:var(--pvm-acc-on)}
.pvm .mt-feed .meta{font-size:.74rem;color:var(--pvm-muted);display:flex;justify-content:space-between;gap:.5rem}
.pvm .mt-feed .meta strong{color:var(--pvm-ink)}
.pvm .mt-feed .txt{font-size:.9rem;color:var(--pvm-ink);margin-top:.15rem;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.pvm .mt-feed li.unread .txt{font-weight:600}

/* treść Bootstrap / starszych komponentów wewnątrz METRO — spłaszczona */
.pvm .card,.pvm .card-header,.pvm .tz-card,.pvm .tz-note,.pvm .vol-activity,.pvm .btn,.pvm .form-control,.pvm .form-select,.pvm .badge,.pvm .alert,.pvm .pvp-sms-nudge{border-radius:0!important;box-shadow:none!important}
.pvm .form-control,.pvm .form-select{border:2px solid #8a8a8a}

/* wysoki kontrast: czarno-białe kafle z obrysem */
:root[data-theme="hc"] .pvm .mt-tile,:root[data-theme="hc"] .pvm .mt-tile--acc{background:#000!important;color:#fff!important;outline:2px solid #000}
:root[data-theme="hc"] .pvm .mt-tile:focus-visible{outline:4px solid #fbbf24}
:root[data-theme="hc"] .pvm .mt-group,:root[data-theme="hc"] .pvm .mt-svc,:root[data-theme="hc"] .pvm .mt-imp__item,:root[data-theme="hc"] .pvm .mt-feed li{border:2px solid #000}
:root[data-theme="hc"] .pvm .mt-svc,:root[data-theme="hc"] .pvm .mt-imp__item{border-left-width:8px}
@media(prefers-reduced-motion:reduce){.pvm .mt-tile{transition:none}.pvm .mt-tile:active{transform:none}}
</style>
<script>
/* Pivot METRO: jedna sekcja naraz, #hash w adresie, klawiatura (strzałki/Home/End), [data-pvm-go] z kafli. */
(function () {
  function init() {
    var bar = document.querySelector('.pvm [data-pvm-pivot]');
    if (!bar) return;
    var tabs = Array.prototype.slice.call(bar.querySelectorAll('[role="tab"]'));
    var keys = tabs.map(function (t) { return t.dataset.tab; });
    function panel(k) { return document.querySelector('.pvm [data-pvm-panel="' + k + '"]'); }
    function go(k, opts) {
      opts = opts || {};
      if (keys.indexOf(k) < 0) return false;
      tabs.forEach(function (t) {
        var on = t.dataset.tab === k;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        var p = panel(t.dataset.tab);
        if (p) p.hidden = !on;
      });
      if (opts.hash !== false && history.replaceState) history.replaceState(null, '', '#' + k);
      if (opts.scroll) {
        var top = bar.getBoundingClientRect().top + window.scrollY - 80;
        window.scrollTo({ top: Math.max(0, top), behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        var p = panel(k); if (p) p.focus({ preventScroll: true });
      }
      return true;
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { go(t.dataset.tab); });
      t.addEventListener('keydown', function (e) {
        var n = tabs.length, j = null;
        if (e.key === 'ArrowRight') j = (i + 1) % n;
        else if (e.key === 'ArrowLeft') j = (i - 1 + n) % n;
        else if (e.key === 'Home') j = 0;
        else if (e.key === 'End') j = n - 1;
        if (j === null) return;
        e.preventDefault();
        go(keys[j]); tabs[j].focus();
      });
    });
    document.addEventListener('click', function (e) {
      var a = e.target.closest('[data-pvm-go]');
      if (a && go(a.dataset.pvmGo, { scroll: true })) e.preventDefault();
    });
    var h = (location.hash || '').slice(1);
    go(keys.indexOf(h) >= 0 ? h : keys[0], { hash: keys.indexOf(h) >= 0 });
    window.addEventListener('hashchange', function () {
      var h2 = (location.hash || '').slice(1);
      if (keys.indexOf(h2) >= 0) go(h2, { hash: false });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  /* Kopiowanie loginów: [data-copy] */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.pvm [data-copy]');
    if (!btn || !navigator.clipboard) return;
    navigator.clipboard.writeText(btn.dataset.copy).then(function () {
      var i = btn.querySelector('i'); if (!i) return;
      var old = i.className; i.className = 'bi bi-check-lg';
      var live = document.getElementById('pv-live'); if (live) live.textContent = 'Skopiowano do schowka.';
      setTimeout(function () { i.className = old; }, 1600);
    });
  });
})();
</script>
