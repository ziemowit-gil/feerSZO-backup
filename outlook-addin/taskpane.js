/* eslint-disable no-undef */
/**
 * taskpane.js — Add-in Outlook feerSZO CRM.
 * Czyta bieżący mail (Office.js), dopasowuje kontakt CRM po e-mailu i pozwala
 * przypiąć mail do kartoteki (crm/api/addin_pin.php). Token API w RoamingSettings.
 */
(function () {
  'use strict';

  // API CRM jest w tej samej domenie co task pane (.../outlook-addin/ → .../crm/api/)
  var API = location.href.replace(/\/outlook-addin\/.*$/, '/crm/api/');
  var TOKEN_KEY = 'crm_api_token';

  var el = function (id) { return document.getElementById(id); };
  var token = '';
  var ctx = null; // {direction, emails, subject, dateISO, messageId, itemRef}

  Office.onReady(function (info) {
    if (!info || info.host !== Office.HostType.Outlook) {
      showAlert('err', 'Ten dodatek działa tylko w Outlooku.');
      return;
    }
    token = (Office.context.roamingSettings.get(TOKEN_KEY) || '').trim();

    el('token-save').onclick = saveToken;
    el('reconfigure').onclick = function () { showSetup(true); };

    if (!token) { showSetup(false); return; }
    loadMessage();
  });

  // ── Token / konfiguracja ───────────────────────────────────────────────────
  function showSetup(prefill) {
    el('message').classList.add('hidden');
    el('result').innerHTML = '';
    el('setup').classList.remove('hidden');
    if (prefill) el('token-input').value = token;
    el('token-input').focus();
  }

  function saveToken() {
    var v = el('token-input').value.trim();
    if (!v) { showAlert('err', 'Wklej token API.'); return; }
    Office.context.roamingSettings.set(TOKEN_KEY, v);
    Office.context.roamingSettings.saveAsync(function (res) {
      if (res.status === Office.AsyncResultStatus.Succeeded) {
        token = v;
        el('setup').classList.add('hidden');
        clearAlert();
        loadMessage();
      } else {
        showAlert('err', 'Nie udało się zapisać tokenu.');
      }
    });
  }

  // ── Odczyt bieżącego maila ───────────────────────────────────────────────────
  function loadMessage() {
    var item = Office.context.mailbox.item;
    if (!item) { showAlert('err', 'Brak otwartej wiadomości.'); return; }

    var me = (Office.context.mailbox.userProfile.emailAddress || '').toLowerCase();
    var fromAddr = (item.from && item.from.emailAddress ? item.from.emailAddress : '').toLowerCase();
    var isOut = fromAddr && fromAddr === me;

    // Adresy do dopasowania: wychodzący → odbiorcy; przychodzący → nadawca
    var emails = [];
    if (isOut) {
      (item.to || []).forEach(function (r) { if (r.emailAddress) emails.push(r.emailAddress.toLowerCase()); });
      (item.cc || []).forEach(function (r) { if (r.emailAddress) emails.push(r.emailAddress.toLowerCase()); });
    } else if (fromAddr) {
      emails.push(fromAddr);
    }
    emails = emails.filter(function (e, i, a) { return e && a.indexOf(e) === i; });

    var dt = item.dateTimeCreated ? new Date(item.dateTimeCreated) : new Date();
    ctx = {
      direction: isOut ? 'out' : 'in',
      emails: emails,
      subject: item.normalizedSubject || item.subject || '',
      dateISO: dt.toISOString(),
      messageId: item.internetMessageId || item.itemId || ''
    };

    // UI: karta maila
    el('msg-dir').textContent = isOut ? 'wychodzący' : 'przychodzący';
    el('msg-dir').className = 'dir ' + (isOut ? 'out' : 'in');
    el('msg-date').textContent = dt.toLocaleString('pl-PL');
    el('msg-subject').textContent = ctx.subject || '(bez tematu)';
    el('msg-addr').textContent = emails.length ? emails.join(', ') : '—';
    el('message').classList.remove('hidden');

    if (!emails.length) {
      el('result').innerHTML = '<div class="card muted">Brak adresu do dopasowania.</div>';
      return;
    }
    lookup(emails);
  }

  // ── Lookup kontaktów ─────────────────────────────────────────────────────────
  function lookup(emails) {
    el('result').innerHTML = '<div class="card muted"><span class="spinner"></span> Szukam kontaktu…</div>';
    apiGet('addin_lookup.php?emails=' + encodeURIComponent(emails.join(',')))
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || 'Błąd lookup.');
        renderContacts(data.contacts || []);
      })
      .catch(handleError);
  }

  function renderContacts(contacts) {
    if (!contacts.length) {
      el('result').innerHTML =
        '<div class="card"><div class="muted">Nie znaleziono kontaktu w CRM dla tego adresu.</div>'
        + '<div style="margin-top:8px"><a href="' + crmAddUrl() + '" target="_blank" rel="noopener">+ Dodaj kontakt w CRM</a></div></div>';
      return;
    }
    var html = '';
    contacts.forEach(function (c) {
      html += '<div class="contact" data-id="' + c.id + '">'
        + '<div class="name">' + esc(c.name) + '</div>'
        + '<div class="meta">'
        + (c.organizacja ? esc(c.organizacja) + ' · ' : '')
        + '<span class="badge">' + esc(c.type === 'organizacja' ? 'Organizacja' : 'Osoba') + '</span> '
        + '<span class="badge">' + esc(c.status) + '</span>'
        + '</div>'
        + '<div class="row">'
        + '<button class="btn-primary" data-pin="' + c.id + '">📌 Przypnij do kartoteki</button>'
        + '<a class="btn-ghost" style="text-decoration:none;display:inline-block" href="' + esc(c.url) + '" target="_blank" rel="noopener">Otwórz</a>'
        + '</div></div>';
    });
    el('result').innerHTML = html;
    Array.prototype.forEach.call(document.querySelectorAll('[data-pin]'), function (b) {
      b.onclick = function () { pin(parseInt(b.getAttribute('data-pin'), 10), b); };
    });
  }

  // ── Przypięcie maila ─────────────────────────────────────────────────────────
  function pin(contactId, btn) {
    btn.disabled = true;
    var orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner"></span> Przypinam…';

    getBody(function (body) {
      apiPost('addin_pin.php', {
        contact_id: contactId,
        direction: ctx.direction,
        subject: ctx.subject,
        body: body,
        message_id: ctx.messageId,
        sent_at: ctx.dateISO
      })
        .then(function (data) {
          if (!data.ok) throw new Error(data.error || 'Błąd zapisu.');
          btn.innerHTML = data.duplicate ? '✓ Już przypięty' : '✓ Przypięto';
          showAlert('ok', data.duplicate
            ? 'Ten mail był już w kartotece.'
            : 'Mail przypięty do kartoteki.');
        })
        .catch(function (e) { btn.disabled = false; btn.innerHTML = orig; handleError(e); });
    });
  }

  function getBody(cb) {
    try {
      Office.context.mailbox.item.body.getAsync(Office.CoercionType.Text, function (res) {
        cb(res.status === Office.AsyncResultStatus.Succeeded ? (res.value || '') : '');
      });
    } catch (e) { cb(''); }
  }

  // ── HTTP helpers ───────────────────────────────────────────────────────────
  function apiGet(path) {
    return fetch(API + path, { headers: { 'Authorization': 'Bearer ' + token } })
      .then(checkResp);
  }
  function apiPost(path, payload) {
    return fetch(API + path, {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(checkResp);
  }
  function checkResp(r) {
    if (r.status === 403) { showSetup(true); throw new Error('Token odrzucony — sprawdź token API.'); }
    return r.json();
  }

  // ── UI helpers ───────────────────────────────────────────────────────────────
  function handleError(e) { showAlert('err', (e && e.message) ? e.message : 'Błąd połączenia.'); }
  function showAlert(kind, msg) {
    var a = el('alert'); a.textContent = msg; a.className = 'alert show ' + kind;
  }
  function clearAlert() { el('alert').className = 'alert'; }
  function crmAddUrl() { return API.replace(/\/api\/$/, '/contact/add.php'); }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
})();
