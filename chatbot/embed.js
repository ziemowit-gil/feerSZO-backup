/**
 * chatbot/embed.js — Floating chat widget for embedding the FEER AI assistant on external pages.
 *
 * Usage:
 *   <script async src="https://APP_URL/chatbot/embed.js"
 *           data-token="abc123..."
 *           data-title="Asystent AI"
 *           data-accent="#2563eb"
 *           data-side="right"></script>
 *
 * Attributes:
 *   data-token   (required) — public chatbot token from admin/ai_settings.php
 *   data-title   (optional) — tooltip + aria label; default "Asystent AI"
 *   data-accent  (optional) — button/panel accent colour; default #2563eb
 *   data-side    (optional) — "right" (default) or "left"
 */
(function () {
  'use strict';

  var s = document.currentScript || (function () {
    var ss = document.getElementsByTagName('script');
    return ss[ss.length - 1];
  })();

  var BASE   = s.src.replace(/chatbot\/embed\.js.*$/, 'chatbot/');
  var token  = s.getAttribute('data-token')  || '';
  var title  = s.getAttribute('data-title')  || 'Asystent AI';
  var accent = s.getAttribute('data-accent') || '#2563eb';
  var side   = s.getAttribute('data-side')   === 'left' ? 'left' : 'right';

  if (!token) {
    console.warn('[feer-chatbot] data-token is required');
    return;
  }

  // ── Styles ──────────────────────────────────────────────────────────────────
  var style = document.createElement('style');
  style.textContent =
    '#feer-cb-btn{' +
      'position:fixed;bottom:20px;' + side + ':20px;z-index:2147483646;' +
      'width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;' +
      'background:' + accent + ';color:#fff;' +
      'display:flex;align-items:center;justify-content:center;' +
      'box-shadow:0 6px 24px rgba(0,0,0,.28);' +
      'transition:transform .18s,box-shadow .18s;' +
    '}' +
    '#feer-cb-btn:hover{transform:scale(1.07);box-shadow:0 8px 32px rgba(0,0,0,.34)}' +
    '#feer-cb-btn svg{display:block}' +
    '#feer-cb-panel{' +
      'position:fixed;bottom:88px;' + side + ':16px;z-index:2147483647;' +
      'width:min(420px,calc(100vw - 32px));height:min(600px,calc(100dvh - 120px));' +
      'border-radius:18px;overflow:hidden;' +
      'box-shadow:0 20px 70px rgba(0,0,0,.26);' +
      'border:1px solid rgba(0,0,0,.1);' +
      'transform:translateY(12px) scale(.97);opacity:0;pointer-events:none;' +
      'transition:transform .22s cubic-bezier(.34,1.44,.64,1),opacity .16s;' +
    '}' +
    '#feer-cb-panel.feer-cb-open{transform:translateY(0) scale(1);opacity:1;pointer-events:auto}' +
    '#feer-cb-panel iframe{display:block;width:100%;height:100%;border:0}';
  (document.head || document.documentElement).appendChild(style);

  // ── Icons ────────────────────────────────────────────────────────────────────
  var ICON_ROBOT =
    '<svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
      '<path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h4a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H7a2 ' +
      '2 0 0 1-2-2V9a2 2 0 0 1 2-2h4V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2M9 11a2 2 0 1 0 0 ' +
      '4 2 2 0 0 0 0-4m6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4m-3 7 1.5 1 1.5-1 1.5 1V20H8v-2l1.5-1Z"/>' +
    '</svg>';
  var ICON_CLOSE =
    '<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
      '<path d="M18 6 6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
    '</svg>';

  // ── Button ───────────────────────────────────────────────────────────────────
  var btn = document.createElement('button');
  btn.id = 'feer-cb-btn';
  btn.setAttribute('aria-label', title);
  btn.setAttribute('aria-expanded', 'false');
  btn.setAttribute('aria-haspopup', 'dialog');
  btn.title = title;
  btn.innerHTML = ICON_ROBOT;

  // ── Panel ────────────────────────────────────────────────────────────────────
  var panel = document.createElement('div');
  panel.id = 'feer-cb-panel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', title);
  panel.setAttribute('aria-modal', 'true');

  var iframeSrc = BASE + 'index.php?t=' + encodeURIComponent(token) + '&embed=1';
  var iframe = document.createElement('iframe');
  iframe.src = iframeSrc;
  iframe.title = title;
  iframe.setAttribute('allow', 'clipboard-write');
  iframe.setAttribute('loading', 'lazy');
  panel.appendChild(iframe);

  // ── State ────────────────────────────────────────────────────────────────────
  var isOpen = false;

  function open() {
    isOpen = true;
    panel.classList.add('feer-cb-open');
    btn.setAttribute('aria-expanded', 'true');
    btn.innerHTML = ICON_CLOSE;
    btn.title = 'Zamknij asystenta';
    btn.setAttribute('aria-label', 'Zamknij asystenta');
  }
  function close() {
    isOpen = false;
    panel.classList.remove('feer-cb-open');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = ICON_ROBOT;
    btn.title = title;
    btn.setAttribute('aria-label', title);
  }

  btn.addEventListener('click', function () { isOpen ? close() : open(); });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen) close();
  });

  // ── Mount ────────────────────────────────────────────────────────────────────
  function mount() {
    document.body.appendChild(btn);
    document.body.appendChild(panel);
  }
  if (document.body) {
    mount();
  } else {
    document.addEventListener('DOMContentLoaded', mount);
  }
})();
