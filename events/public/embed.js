/**
 * embed.js — Widget osadzania listy wydarzeń FEER na zewnętrznych stronach.
 * Użycie:
 *   <div id="feer-events"></div>
 *   <script async src="https://.../events/public/embed.js"
 *           data-target="#feer-events" data-limit="6"
 *           data-ids="" data-type="" data-title=""></script>
 */
(function () {
  var script = document.currentScript || (function () {
    var scripts = document.getElementsByTagName('script');
    return scripts[scripts.length - 1];
  })();

  var apiBase   = script.src.replace(/embed\.js.*$/, 'embed_feed.php');
  var limit     = script.getAttribute('data-limit') || 6;
  var ids       = script.getAttribute('data-ids') || '';
  var type      = script.getAttribute('data-type') || '';
  var title     = script.getAttribute('data-title') || '';
  var targetSel = script.getAttribute('data-target');

  var container = targetSel ? document.querySelector(targetSel) : null;
  if (!container) {
    container = document.createElement('div');
    script.parentNode.insertBefore(container, script.nextSibling);
  }
  container.classList.add('feer-ev-embed');

  if (!document.getElementById('feer-ev-embed-style')) {
    var style = document.createElement('style');
    style.id = 'feer-ev-embed-style';
    style.textContent = [
      '.feer-ev-embed{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#1e1b2e;display:grid;gap:14px}',
      '.feer-ev-embed h3{font-size:1.15rem;font-weight:800;margin:0 0 6px;color:#4c1d95}',
      '.feer-ev-card{border:1px solid #e5e0f5;border-radius:12px;padding:14px 16px;background:#fff;box-shadow:0 1px 3px rgba(76,29,149,.06);display:flex;flex-direction:column;gap:4px}',
      '.feer-ev-card a.feer-ev-title{color:#4c1d95;font-weight:700;text-decoration:none;font-size:1rem}',
      '.feer-ev-card a.feer-ev-title:hover{text-decoration:underline}',
      '.feer-ev-meta{font-size:.85rem;color:#64748b;display:flex;flex-wrap:wrap;gap:10px}',
      '.feer-ev-desc{font-size:.85rem;color:#334155}',
      '.feer-ev-btn{align-self:flex-start;margin-top:6px;background:#7c3aed;color:#fff;text-decoration:none;font-size:.82rem;font-weight:600;padding:6px 14px;border-radius:8px}',
      '.feer-ev-btn:hover{background:#6d28d9;color:#fff}',
      '.feer-ev-empty{color:#94a3b8;font-size:.9rem}'
    ].join('');
    document.head.appendChild(style);
  }

  container.innerHTML = '<div class="feer-ev-empty">Wczytywanie wydarzeń…</div>';

  var url = apiBase + '?limit=' + encodeURIComponent(limit)
    + (ids ? '&ids=' + encodeURIComponent(ids) : '')
    + (type ? '&type=' + encodeURIComponent(type) : '');

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function formatDate(iso) {
    var d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric' })
      + ' ' + d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
  }

  fetch(url).then(function (r) { return r.json(); }).then(function (json) {
    var events = (json && json.data) || [];
    if (!events.length) {
      container.innerHTML = '<div class="feer-ev-empty">Brak nadchodzących wydarzeń.</div>';
      return;
    }
    var html = title ? '<h3>' + escapeHtml(title) + '</h3>' : '';
    events.forEach(function (ev) {
      var date = ev.start_at ? formatDate(ev.start_at) : '';
      html += '<div class="feer-ev-card">'
        + '<a class="feer-ev-title" href="' + escapeHtml(ev.register_url) + '" target="_blank" rel="noopener">' + escapeHtml(ev.title) + '</a>'
        + '<div class="feer-ev-meta">'
        + (date ? '<span>🗓 ' + date + '</span>' : '')
        + (ev.venue ? '<span>📍 ' + escapeHtml(ev.venue) + '</span>' : (ev.type_label ? '<span>' + escapeHtml(ev.type_label) + '</span>' : ''))
        + (ev.spots_left !== null && ev.spots_left !== undefined ? '<span>' + ev.spots_left + ' miejsc</span>' : '')
        + '</div>'
        + (ev.description ? '<div class="feer-ev-desc">' + escapeHtml(ev.description) + '</div>' : '')
        + '<a class="feer-ev-btn" href="' + escapeHtml(ev.register_url) + '" target="_blank" rel="noopener">Zarejestruj się</a>'
        + '</div>';
    });
    container.innerHTML = html;
  }).catch(function () {
    container.innerHTML = '<div class="feer-ev-empty">Nie udało się wczytać wydarzeń.</div>';
  });
})();
