<?php
// ── includes/chat_widget.php ─────────────────────────────────────────────
// Floating chat panel — admin/editor only.
// Included by footer.php just before </body>.
if (defined('_CHAT_WIDGET_LOADED')) return;
define('_CHAT_WIDGET_LOADED', true);
if (!function_exists('can_edit') || !can_edit()) return;

$_cw_api    = APP_URL . '/api/msg.php';
$_cw_csrf   = csrf_token();
$_cw_unread = function_exists('msg_unread_admin') ? msg_unread_admin() : 0;
?>
<style>
/* ══════════════════════════════════════════════════════════════
   Floating Action Button
══════════════════════════════════════════════════════════════ */
#msg-fab {
  position: fixed;
  right: 1.5rem;
  bottom: 3.5rem;
  z-index: 9100;
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: #2563eb;
  color: #fff;
  border: none;
  cursor: pointer;
  box-shadow: 0 4px 18px rgba(37,99,235,.45);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.35rem;
  transition: transform .15s, box-shadow .15s;
}
#msg-fab:hover  { transform: scale(1.09); box-shadow: 0 6px 22px rgba(37,99,235,.55); }
#msg-fab:active { transform: scale(.96); }
#msg-fab-badge {
  position: absolute;
  top: -3px;
  right: -3px;
  background: #ef4444;
  color: #fff;
  border-radius: 10px;
  min-width: 19px;
  height: 19px;
  font-size: .65rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 4px;
  border: 2px solid #fff;
  line-height: 1;
}

/* ══════════════════════════════════════════════════════════════
   Panel
══════════════════════════════════════════════════════════════ */
#msg-float-panel {
  position: fixed;
  right: 1.4rem;
  bottom: 7.5rem;
  z-index: 9099;
  width: 380px;
  height: 520px;
  max-height: calc(100vh - 7rem);
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 10px 40px rgba(0,0,0,.2), 0 2px 8px rgba(0,0,0,.08);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transform-origin: bottom right;
  transform: scale(.88) translateY(16px);
  opacity: 0;
  pointer-events: none;
  transition: transform .2s cubic-bezier(.34,1.56,.64,1), opacity .18s ease;
}
#msg-float-panel.open {
  transform: scale(1) translateY(0);
  opacity: 1;
  pointer-events: all;
}
@media (max-width: 479px) {
  #msg-float-panel { right: .5rem; left: .5rem; width: auto; bottom: 6.5rem; }
  #msg-fab         { right: 1rem; bottom: 3rem; }
}

/* ── Panel header ──────────────────────────────────────────── */
#msg-panel-hdr {
  background: #1e293b;
  color: #fff;
  padding: .65rem .9rem;
  display: flex;
  align-items: center;
  gap: .4rem;
  flex-shrink: 0;
  min-height: 46px;
}
#msg-panel-hdr .mph-title {
  font-weight: 600;
  font-size: .875rem;
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
#msg-panel-hdr .mph-btn {
  background: none;
  border: none;
  color: rgba(255,255,255,.65);
  cursor: pointer;
  padding: .25rem .4rem;
  border-radius: .35rem;
  line-height: 1;
  font-size: .95rem;
  flex-shrink: 0;
  transition: color .1s, background .1s;
}
#msg-panel-hdr .mph-btn:hover { color: #fff; background: rgba(255,255,255,.12); }

/* ── Panel body ────────────────────────────────────────────── */
#msg-panel-body {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
  background: #f8fafc;
}
#msg-panel-body::-webkit-scrollbar { width: 4px; }
#msg-panel-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

/* ── Panel footer (send form) ──────────────────────────────── */
#msg-panel-footer { flex-shrink: 0; }
.msg-send-form {
  padding: .55rem .75rem;
  border-top: 1px solid #e2e8f0;
  display: flex;
  gap: .45rem;
  align-items: flex-end;
  background: #fff;
}
.msg-send-form textarea {
  flex: 1;
  resize: none;
  border: 1px solid #cbd5e1;
  border-radius: .5rem;
  font-size: .84rem;
  padding: .4rem .6rem;
  line-height: 1.4;
  min-height: 34px;
  max-height: 96px;
  overflow-y: auto;
  font-family: inherit;
  transition: border-color .15s;
  background: #fff;
}
.msg-send-form textarea:focus { outline: none; border-color: #2563eb; }
.msg-send-btn {
  background: #2563eb;
  color: #fff;
  border: none;
  border-radius: .5rem;
  width: 34px;
  height: 34px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
  font-size: .95rem;
  transition: background .12s;
}
.msg-send-btn:hover    { background: #1d4ed8; }
.msg-send-btn:disabled { background: #93c5fd; cursor: not-allowed; }

/* ══════════════════════════════════════════════════════════════
   Inbox thread list
══════════════════════════════════════════════════════════════ */
.msg-thread-item {
  width: 100%;
  text-align: left;
  background: none;
  border: none;
  border-bottom: 1px solid #f1f5f9;
  padding: .62rem .85rem;
  cursor: pointer;
  display: flex;
  align-items: flex-start;
  gap: .6rem;
  transition: background .1s;
}
.msg-thread-item:hover     { background: #f1f5f9; }
.msg-thread-item.has-unread { background: #eff6ff; }
.msg-thread-item.has-unread:hover { background: #dbeafe; }

.msg-ti-icon {
  width: 36px; height: 36px;
  border-radius: 50%;
  background: #e2e8f0;
  color: #475569;
  font-size: .72rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  letter-spacing: -.02em;
}
.msg-ti-icon.is-onb { background: #fef9c3; color: #92400e; }

.msg-ti-body { flex: 1; min-width: 0; }
.msg-ti-name {
  font-size: .82rem;
  font-weight: 600;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.msg-ti-name .msg-ti-sub { font-weight: 400; color: #64748b; font-size: .77rem; }
.msg-ti-preview {
  font-size: .76rem;
  color: #64748b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: 1px;
}

.msg-ti-meta { flex-shrink: 0; text-align: right; min-width: 40px; }
.msg-ti-time { font-size: .67rem; color: #94a3b8; }
.msg-ti-unread-badge {
  background: #ef4444;
  color: #fff;
  border-radius: 10px;
  padding: 0 5px;
  font-size: .66rem;
  font-weight: 700;
  display: inline-block;
  margin-top: 3px;
  min-width: 18px;
  text-align: center;
}

/* ══════════════════════════════════════════════════════════════
   Chat bubbles
══════════════════════════════════════════════════════════════ */
.msg-chat-area {
  padding: .8rem .75rem;
  display: flex;
  flex-direction: column;
  gap: .4rem;
}
.msg-date-sep {
  text-align: center;
  margin: .5rem 0 .3rem;
}
.msg-date-sep span {
  background: #e2e8f0;
  color: #64748b;
  font-size: .67rem;
  padding: .15rem .55rem;
  border-radius: 8px;
}

.msg-bubble-row { display: flex; }
.msg-bubble-row.mine  { justify-content: flex-end; }
.msg-bubble-row.theirs { justify-content: flex-start; }
.msg-bubble-row .msg-bw { max-width: 82%; }

.msg-sender-name {
  font-size: .7rem;
  font-weight: 600;
  color: #475569;
  margin-bottom: 2px;
  padding-left: 3px;
}
.msg-bubble {
  padding: .42rem .72rem;
  border-radius: 14px;
  font-size: .84rem;
  line-height: 1.45;
  word-break: break-word;
}
.msg-bubble-row.mine   .msg-bubble { background: #2563eb; color: #fff; border-bottom-right-radius: 4px; }
.msg-bubble-row.theirs .msg-bubble { background: #fff; color: #1e293b; border: 1px solid #e2e8f0; border-bottom-left-radius: 4px; }
/* CKEditor HTML in bubbles */
.msg-bubble p  { margin: 0 0 .4em; }
.msg-bubble p:last-child { margin-bottom: 0; }
.msg-bubble ul, .msg-bubble ol { padding-left: 1.2em; margin: .3em 0; }
.msg-bubble strong { font-weight: 600; }
.msg-bubble a  { color: inherit; text-decoration: underline; }
.msg-bubble-row.mine .msg-bubble a { color: #bfdbfe; }

.msg-bubble-time {
  font-size: .65rem;
  color: #94a3b8;
  margin-top: 2px;
}
.msg-bubble-row.mine .msg-bubble-time  { text-align: right; padding-right: 2px; }
.msg-bubble-row.theirs .msg-bubble-time { padding-left: 3px; }
.msg-read-check { color: #22c55e; }

/* ── Empty/Loading states ──────────────────────────────────── */
.msg-state-center {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #94a3b8;
  padding: 2rem;
  text-align: center;
}
.msg-state-center i { font-size: 2.4rem; opacity: .3; }
.msg-state-center p { margin-top: .6rem; font-size: .82rem; }
</style>

<!-- ── FAB button ─────────────────────────────────────────────────────── -->
<button id="msg-fab" type="button" title="Wiadomości" aria-label="Wiadomości">
  <i class="bi bi-chat-dots-fill"></i>
  <span id="msg-fab-badge" style="<?= $_cw_unread ? '' : 'display:none' ?>">
    <?= min($_cw_unread, 99) ?><?= $_cw_unread > 99 ? '+' : '' ?>
  </span>
</button>

<!-- ── Floating panel ─────────────────────────────────────────────────── -->
<div id="msg-float-panel" role="dialog" aria-label="Komunikator">
  <div id="msg-panel-hdr"></div>
  <div id="msg-panel-body"></div>
  <div id="msg-panel-footer"></div>
</div>

<script>
/* ═══════════════════════════════════════════════════════════════════════
   Floating Chat Widget
═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  const API  = <?= json_encode($_cw_api) ?>;
  const CSRF = <?= json_encode($_cw_csrf) ?>;

  // ── DOM refs ─────────────────────────────────────────────────────────
  const $fab    = document.getElementById('msg-fab');
  const $badge  = document.getElementById('msg-fab-badge');
  const $panel  = document.getElementById('msg-float-panel');
  const $hdr    = document.getElementById('msg-panel-hdr');
  const $body   = document.getElementById('msg-panel-body');
  const $footer = document.getElementById('msg-panel-footer');

  // ── State ────────────────────────────────────────────────────────────
  let _open    = false;
  let _screen  = 'inbox';                // 'inbox' | 'thread'
  let _thread  = null;                   // {ctx_type, ctx_id, contract_type}
  let _lastId  = 0;
  let _sending = false;
  let _pollT   = null;
  let _unreadT = null;

  // ── FAB ──────────────────────────────────────────────────────────────
  $fab.addEventListener('click', () => _open ? panelClose() : panelOpen());

  // Close on outside click
  document.addEventListener('click', e => {
    if (_open && !$panel.contains(e.target) && e.target !== $fab && !$fab.contains(e.target)) {
      panelClose();
    }
  });

  function panelOpen() {
    _open = true;
    $panel.classList.add('open');
    showInbox();
    startPoll();
  }

  function panelClose() {
    _open = false;
    $panel.classList.remove('open');
    stopPoll();
  }

  // ═══════════════════════════════════════════════════════════════════
  // INBOX
  // ═══════════════════════════════════════════════════════════════════
  function showInbox() {
    _screen = 'inbox';
    _thread = null;
    _lastId = 0;

    $hdr.innerHTML = `
      <span class="mph-title"><i class="bi bi-chat-dots-fill" style="margin-right:.3rem"></i>Wiadomości</span>
      <button class="mph-btn" id="mfb-ext" title="Otwórz pełną stronę">
        <i class="bi bi-box-arrow-up-right"></i>
      </button>
      <button class="mph-btn" id="mfb-close" title="Zamknij">
        <i class="bi bi-x-lg"></i>
      </button>`;
    $footer.innerHTML = '';

    document.getElementById('mfb-ext').addEventListener('click',
      () => window.location.href = <?= json_encode(APP_URL . '/admin/messages.php') ?>);
    document.getElementById('mfb-close').addEventListener('click', panelClose);

    setBodyLoading();

    fetch(API + '?action=threads')
      .then(r => r.json())
      .then(d => { if (d.ok) renderInbox(d.threads); else setBodyError(); })
      .catch(setBodyError);
  }

  function renderInbox(threads) {
    if (!threads || !threads.length) {
      $body.innerHTML = `<div class="msg-state-center">
        <i class="bi bi-chat-dots"></i>
        <p>Brak wiadomości</p>
      </div>`;
      return;
    }

    const now  = new Date();
    const html = threads.map(t => {
      const unread  = parseInt(t.unread_admin) || 0;
      const isOnb   = t.context_type === 'onboarding';
      const initials = String(t.label || '?').slice(0, 3).toUpperCase();
      const timeStr  = _fmtTime(t.last_at, now);
      const senderPfx = t.last_sender ? _esc(t.last_sender).split(' ')[0] + ': ' : '';

      return `<button class="msg-thread-item${unread ? ' has-unread' : ''}"
          data-ct="${_esc(t.context_type)}"
          data-ci="${parseInt(t.context_id)}"
          data-ctype="${_esc(t.contract_type || '')}"
          data-label="${_esc(t.label || '')}"
          data-url="${_esc(t.url || '#')}">
        <div class="msg-ti-icon${isOnb ? ' is-onb' : ''}">${initials}</div>
        <div class="msg-ti-body">
          <div class="msg-ti-name">${_esc(t.label)}${t.sublabel
            ? ' <span class="msg-ti-sub">' + _esc(t.sublabel) + '</span>' : ''}</div>
          <div class="msg-ti-preview">${_esc(senderPfx + (t.last_body_preview || ''))}</div>
        </div>
        <div class="msg-ti-meta">
          <div class="msg-ti-time">${timeStr}</div>
          ${unread ? `<span class="msg-ti-unread-badge">${unread}</span>` : ''}
        </div>
      </button>`;
    }).join('');

    $body.innerHTML = html;

    $body.querySelectorAll('.msg-thread-item').forEach(btn => {
      btn.addEventListener('click', () =>
        showThread(btn.dataset.ct, parseInt(btn.dataset.ci),
                   btn.dataset.ctype, { label: btn.dataset.label, url: btn.dataset.url }));
    });
  }

  // ═══════════════════════════════════════════════════════════════════
  // THREAD
  // ═══════════════════════════════════════════════════════════════════
  function showThread(ctx_type, ctx_id, contract_type, ctx_info) {
    _screen = 'thread';
    _thread = { ctx_type, ctx_id, contract_type };
    _lastId = 0;

    const typeBadge = ctx_type === 'onboarding'
      ? '<span style="font-size:.62rem;background:#fef9c3;color:#92400e;padding:1px 5px;border-radius:4px;margin-right:.35rem;vertical-align:middle">Zgłoszenie</span>'
      : '<span style="font-size:.62rem;background:#f1f5f9;border:1px solid #e2e8f0;color:#475569;padding:1px 5px;border-radius:4px;margin-right:.35rem;vertical-align:middle">Umowa</span>';

    $hdr.innerHTML = `
      <button class="mph-btn" id="mfb-back" title="Powrót do listy">
        <i class="bi bi-arrow-left"></i>
      </button>
      <span class="mph-title">${typeBadge}${_esc(ctx_info.label)}</span>
      <button class="mph-btn" id="mfb-open" title="Otwórz stronę">
        <i class="bi bi-box-arrow-up-right"></i>
      </button>
      <button class="mph-btn" id="mfb-close2" title="Zamknij">
        <i class="bi bi-x-lg"></i>
      </button>`;

    document.getElementById('mfb-back').addEventListener('click', showInbox);
    document.getElementById('mfb-open').addEventListener('click',
      () => window.location.href = _esc(ctx_info.url));
    document.getElementById('mfb-close2').addEventListener('click', panelClose);

    // Send form
    $footer.innerHTML = `
      <div class="msg-send-form">
        <textarea id="msg-input" rows="1"
          placeholder="Napisz wiadomość… (Enter — wyślij, Shift+Enter — nowa linia)"></textarea>
        <button class="msg-send-btn" id="msg-send-btn" type="button">
          <i class="bi bi-send-fill"></i>
        </button>
      </div>`;

    const $input = document.getElementById('msg-input');
    const $sendBtn = document.getElementById('msg-send-btn');

    $input.addEventListener('input', () => {
      $input.style.height = 'auto';
      $input.style.height = Math.min($input.scrollHeight, 96) + 'px';
    });
    $input.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); }
    });
    $sendBtn.addEventListener('click', doSend);

    // Load messages
    setBodyLoading();

    fetch(`${API}?action=thread&ctx_type=${encodeURIComponent(ctx_type)}&ctx_id=${ctx_id}`)
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { setBodyError(); return; }
        $body.innerHTML = '<div class="msg-chat-area" id="msg-chat-area"></div>';
        _appendMessages(d.messages);
        if (d.messages.length) _lastId = d.messages[d.messages.length - 1].id;
        _scrollBottom();
        setTimeout(() => $input && $input.focus(), 80);
      })
      .catch(setBodyError);

    function doSend() {
      const body = $input.value.trim();
      if (!body || _sending) return;
      _sending = true;
      $sendBtn.disabled = true;
      $input.disabled   = true;

      const fd = new FormData();
      fd.append('action', 'send');
      fd.append('_csrf',  CSRF);
      fd.append('ctx_type',      ctx_type);
      fd.append('ctx_id',        ctx_id);
      fd.append('contract_type', contract_type);
      fd.append('body',          $input.value.trim());

      fetch(API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
          _sending = false;
          $sendBtn.disabled = false;
          $input.disabled   = false;
          if (d.ok && d.message) {
            $input.value = '';
            $input.style.height = 'auto';
            _appendMessages([d.message]);
            _lastId = Math.max(_lastId, d.message.id);
            _scrollBottom();
          }
          $input.focus();
        })
        .catch(() => {
          _sending = false;
          $sendBtn.disabled = false;
          $input.disabled   = false;
          $input.focus();
        });
    }
  }

  // ── Render messages into #msg-chat-area ──────────────────────────────
  function _appendMessages(msgs) {
    const $area = document.getElementById('msg-chat-area');
    if (!$area || !msgs || !msgs.length) return;

    // Date tracking across calls
    const lastSepEl = $area.querySelector('.msg-date-sep:last-of-type');
    let prevDate = lastSepEl ? lastSepEl.dataset.date : null;

    msgs.forEach(m => {
      const mine      = m.sender_type === 'admin';
      const msgDate   = String(m.created_at).slice(0, 10);
      const msgTime   = String(m.created_at).slice(11, 16);
      const isRead    = parseInt(m.is_read);

      // Date separator
      if (msgDate !== prevDate) {
        prevDate = msgDate;
        const sep = document.createElement('div');
        sep.className   = 'msg-date-sep';
        sep.dataset.date = msgDate;
        sep.innerHTML   = `<span>${_fmtDate(msgDate)}</span>`;
        $area.appendChild(sep);
      }

      const row = document.createElement('div');
      row.className = 'msg-bubble-row ' + (mine ? 'mine' : 'theirs');
      row.dataset.id = m.id;

      // Body: admin → raw HTML (CKEditor or plain); user → escaped + nl2br
      const bodyHtml = mine ? m.body : _esc(m.body).replace(/\n/g, '<br>');

      // Read receipt
      const readHtml = mine
        ? (isRead
            ? ' <span class="msg-read-check">✓✓</span>'
            : ' <span style="color:#94a3b8">✓</span>')
        : '';

      row.innerHTML = `<div class="msg-bw">
        ${!mine && m.sender_name
          ? `<div class="msg-sender-name">${_esc(m.sender_name)}</div>` : ''}
        <div class="msg-bubble">${bodyHtml}</div>
        <div class="msg-bubble-time">${msgTime}${readHtml}</div>
      </div>`;

      $area.appendChild(row);
    });
  }

  function _scrollBottom() {
    setTimeout(() => { $body.scrollTop = $body.scrollHeight; }, 30);
  }

  // ═══════════════════════════════════════════════════════════════════
  // POLLING
  // ═══════════════════════════════════════════════════════════════════
  function startPoll() {
    stopPoll();
    _pollT = setInterval(_doPoll, 12000);
  }
  function stopPoll() {
    if (_pollT) { clearInterval(_pollT); _pollT = null; }
  }

  function _doPoll() {
    if (!_open) return;

    if (_screen === 'thread' && _thread) {
      const url = `${API}?action=thread&ctx_type=${encodeURIComponent(_thread.ctx_type)}`
                + `&ctx_id=${_thread.ctx_id}&since_id=${_lastId}`;
      fetch(url)
        .then(r => r.json())
        .then(d => {
          if (d.ok && d.messages && d.messages.length) {
            _appendMessages(d.messages);
            _lastId = d.messages[d.messages.length - 1].id;
            _scrollBottom();
          }
        })
        .catch(() => {});

    } else if (_screen === 'inbox') {
      fetch(API + '?action=threads')
        .then(r => r.json())
        .then(d => { if (d.ok) renderInbox(d.threads); })
        .catch(() => {});
    }
  }

  // ── Unread badge polling (always running) ────────────────────────────
  function _refreshBadge() {
    fetch(API + '?action=unread')
      .then(r => r.json())
      .then(d => {
        if (!d.ok) return;
        const n = parseInt(d.count) || 0;

        // FAB badge
        $badge.textContent = n > 99 ? '99+' : n;
        $badge.style.display = n > 0 ? 'flex' : 'none';

        // Sidebar badge (if present)
        document.querySelectorAll('[data-msg-sb-badge]').forEach(el => {
          el.textContent = n;
          el.style.display = n > 0 ? '' : 'none';
        });
      })
      .catch(() => {});
  }

  _refreshBadge();
  _unreadT = setInterval(_refreshBadge, 28000);

  // ═══════════════════════════════════════════════════════════════════
  // Utilities
  // ═══════════════════════════════════════════════════════════════════
  function setBodyLoading() {
    $body.innerHTML = `<div class="msg-state-center">
      <div class="spinner-border spinner-border-sm text-secondary"></div>
    </div>`;
  }
  function setBodyError() {
    $body.innerHTML = `<div class="msg-state-center">
      <i class="bi bi-exclamation-circle text-danger"></i>
      <p>Błąd ładowania — sprawdź połączenie</p>
    </div>`;
  }

  function _esc(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function _fmtTime(dateStr, now) {
    if (!dateStr) return '';
    const d    = new Date(String(dateStr).replace(' ', 'T'));
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const mDay  = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    if (mDay.getTime() === today.getTime()) {
      return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    }
    const yest = new Date(today); yest.setDate(yest.getDate() - 1);
    if (mDay.getTime() === yest.getTime()) return 'wczoraj';
    return String(d.getDate()).padStart(2, '0') + '.'
         + String(d.getMonth() + 1).padStart(2, '0');
  }

  function _fmtDate(dateStr) {
    if (!dateStr) return '';
    const p = dateStr.split('-');
    if (p.length !== 3) return dateStr;
    const months = ['', 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj',
                    'czerwiec', 'lipiec', 'sierpień', 'wrzesień',
                    'październik', 'listopad', 'grudzień'];
    return parseInt(p[2]) + ' ' + (months[parseInt(p[1])] || p[1]) + ' ' + p[0];
  }

  // Expose for external use (e.g. clicking a thread from dashboard widget)
  window.MsgWidget = {
    open:       panelOpen,
    openThread: (ctx_type, ctx_id, contract_type, label, url) => {
      panelOpen();
      showThread(ctx_type, ctx_id, contract_type, { label, url });
    },
  };

})();
</script>
