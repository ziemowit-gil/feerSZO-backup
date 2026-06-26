<?php
/**
 * helpdesk/index.php — Konsola helpdesku (inbox split-view).
 *  Lewa kolumna: lista zgłoszeń (filtry + szukanie, ładowane przez AJAX ?_ajax=1).
 *  Prawa kolumna: panel szczegółów wybranego zgłoszenia (helpdesk/view.php?_pane=1),
 *  z akcjami wykonywanymi przez XHR (X-Requested-With) → JSON.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();
require_login();

$u     = current_user();
$uid   = (int)$u['id'];
$is_op = hd_is_operator();

// ── Filtry ──────────────────────────────────────────────────────────────────
$f_status   = $_GET['status'] ?? '';
$f_category = $_GET['category'] ?? '';
$f_q        = trim($_GET['q'] ?? '');
$f_view     = ($is_op && in_array($_GET['view'] ?? '', ['all', 'unassigned'], true))
            ? $_GET['view'] : ($is_op ? 'all' : 'mine');
$sel_id     = (int)($_GET['id'] ?? 0);

$where = ['1=1']; $params = [];
if (!$is_op || $f_view === 'mine') {
    $where[] = 'requester_id = ?'; $params[] = $uid;
} elseif ($f_view === 'unassigned') {
    $where[] = 'assigned_to IS NULL';
    $where[] = "status NOT IN ('zamknięte')";
}
if ($f_status && isset(HD_STATUSES[$f_status])) { $where[] = 'status = ?'; $params[] = $f_status; }
if ($f_category && isset(HD_CATEGORIES[$f_category])) { $where[] = 'category = ?'; $params[] = $f_category; }
if ($f_q !== '') {
    $where[] = '(number LIKE ? OR title LIKE ? OR requester_name LIKE ?)';
    $like = '%' . $f_q . '%'; $params[] = $like; $params[] = $like; $params[] = $like;
}
$where_sql = implode(' AND ', $where);

$tickets = db_all(
    "SELECT t.*, u.name AS assigned_name
     FROM helpdesk_tickets t LEFT JOIN users u ON u.id = t.assigned_to
     WHERE {$where_sql}
     ORDER BY CASE t.status WHEN 'nowe' THEN 0 WHEN 'otwarte' THEN 1 WHEN 'oczekuje' THEN 2 ELSE 3 END,
              t.updated_at DESC", $params);

// Licznik nieodczytanych (tylko dla operatorów — zgłaszający nie potrzebują)
$unread_ids = $is_op ? hd_unread_ids($uid) : [];

// ── Endpoint AJAX: fragment listy ─────────────────────────────────────────────
if (isset($_GET['_ajax'])) {
    // Oznacz jako odczytane jeśli przeładowanie po kliknięciu konkretnego zgłoszenia
    if ($sel_id && $is_op) hd_mark_read($sel_id, $uid);
    $unread_ids = $is_op ? hd_unread_ids($uid) : [];
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok'          => true,
        'total'       => count($tickets),
        'list_html'   => hd_console_rows($tickets, $is_op, $sel_id, $unread_ids),
        'unread_count'=> count($unread_ids),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cnt_unassigned = $is_op ? (int)(db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE assigned_to IS NULL AND status NOT IN ('zamknięte')")['c'] ?? 0) : 0;
$cnt_mine_open  = (int)(db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE requester_id=? AND status NOT IN ('zamknięte','rozwiązane')", [$uid])['c'] ?? 0);
$cnt_unread     = count($unread_ids);

$PAGE_TITLE = 'Helpdesk IT';
include dirname(__DIR__) . '/includes/header.php';
echo hd_ui_css();
?>
<a href="#hdMain" class="hd-skip">Przejdź do treści</a>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold">
      <i class="bi bi-headset text-primary me-2"></i>Helpdesk IT
      <?php if ($cnt_unread): ?>
      <span class="hd-unread-badge ms-1" id="hdUnreadBadge" title="<?= $cnt_unread ?> nieprzeczytanych zgłoszeń"><?= $cnt_unread ?></span>
      <?php else: ?>
      <span class="hd-unread-badge ms-1 d-none" id="hdUnreadBadge"></span>
      <?php endif; ?>
    </h4>
    <div class="text-muted small">Konsola zgłoszeń · <span id="hdCount"><?= count($tickets) ?></span> na liście</div>
  </div>
  <a href="<?= APP_URL ?>/helpdesk/new.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nowe zgłoszenie</a>
</div>

<!-- Pasek filtrów -->
<form id="hdFilters" class="row g-2 mb-3" onsubmit="return false">
  <input type="hidden" name="view" id="hdView" value="<?= h($f_view) ?>">
  <div class="col-12 col-sm">
    <div class="input-group input-group-sm">
      <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
      <input name="q" class="form-control" placeholder="Szukaj numeru, tytułu, zgłaszającego…" value="<?= h($f_q) ?>" autocomplete="off">
    </div>
  </div>
  <div class="col-6 col-sm-auto">
    <select name="status" class="form-select form-select-sm">
      <option value="">Wszystkie statusy</option>
      <?php foreach (HD_STATUSES as $k => $s): ?>
      <option value="<?= h($k) ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= h($s['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-6 col-sm-auto">
    <select name="category" class="form-select form-select-sm">
      <option value="">Wszystkie kategorie</option>
      <?php foreach (HD_CATEGORIES as $k => $v): ?>
      <option value="<?= h($k) ?>" <?= $f_category === $k ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if ($is_op): ?>
<ul class="nav nav-pills nav-sm mb-3 gap-1" id="hdTabs">
  <li class="nav-item"><button class="nav-link py-1 px-3 <?= $f_view==='all'?'active':'' ?>" data-view="all"><i class="bi bi-list-ul me-1"></i>Wszystkie</button></li>
  <li class="nav-item"><button class="nav-link py-1 px-3 <?= $f_view==='unassigned'?'active':'' ?>" data-view="unassigned"><i class="bi bi-inbox me-1"></i>Nieprzypisane <?php if ($cnt_unassigned): ?><span class="badge bg-danger ms-1"><?= $cnt_unassigned ?></span><?php endif; ?></button></li>
  <li class="nav-item"><button class="nav-link py-1 px-3 <?= $f_view==='mine'?'active':'' ?>" data-view="mine"><i class="bi bi-person me-1"></i>Moje</button></li>
</ul>
<?php endif; ?>

<!-- ── Konsola split-view ───────────────────────────────────────────────────── -->
<div class="hd-console" id="hdConsole">
  <div class="hd-list-col">
    <div class="hd-list" id="hdListRegion" aria-label="Lista zgłoszeń"><?= hd_console_rows($tickets, $is_op, $sel_id, $unread_ids) ?></div>
  </div>
  <main class="hd-pane-col" id="hdMain">
    <div class="hd-pane" id="hdPane">
      <div class="hd-pane-empty">
        <i class="bi bi-arrow-left-circle"></i>
        <div>Wybierz zgłoszenie z listy, aby zobaczyć szczegóły.</div>
      </div>
    </div>
  </main>
</div>

<div class="hd-toast-wrap" id="hdToasts"></div>

<?php if (is_admin() || hd_is_operator()): ?>
<div class="mt-3 text-end d-flex justify-content-end gap-2 flex-wrap">
  <a href="<?= APP_URL ?>/helpdesk/admin_macros.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-card-text me-1"></i>Gotowe odpowiedzi</a>
  <?php if (is_admin()): ?>
  <a href="<?= APP_URL ?>/helpdesk/admin.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear me-1"></i>Ustawienia</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Quill CSS + JS musi być przed głównym skryptem konsoli -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
var QUILL_TOOLBAR = [
  [{ 'header': [false, 2, 3] }],
  ['bold', 'italic', 'underline', 'strike'],
  [{ 'list': 'ordered' }, { 'list': 'bullet' }],
  ['blockquote', 'link'],
  ['clean']
];
function hdInitQuill(root) {
  if (typeof Quill === 'undefined') return;
  (root || document).querySelectorAll('.hd-quill-wrap').forEach(function(wrap) {
    if (wrap._quill) return;
    var editorDiv = wrap.querySelector('[id]'); if (!editorDiv) return;
    var form = wrap.closest('form'); if (!form) return;
    var hiddenInput = form.querySelector('input[name="msg_body"]');
    var errDiv = wrap.nextElementSibling;
    if (errDiv && !errDiv.classList.contains('invalid-feedback')) errDiv = null;
    var q = new Quill(editorDiv, { theme:'snow', modules:{ toolbar: QUILL_TOOLBAR }, placeholder:'Wpisz odpowiedź…' });
    wrap._quill = q;
    var qlEditor = wrap.querySelector('.ql-editor');
    if (qlEditor) { qlEditor.setAttribute('aria-label','Treść odpowiedzi'); qlEditor.setAttribute('aria-multiline','true'); qlEditor.setAttribute('aria-required','true'); }
    q.on('text-change', function(){ if(q.getText().trim()!==''){wrap.classList.remove('is-invalid');if(errDiv)errDiv.classList.add('d-none');} });
    var submitBtn = form.querySelector('button[name="_add_msg"]');
    if (submitBtn) {
      submitBtn.addEventListener('click', function(e) {
        if (q.getText().trim()==='') { e.preventDefault(); e.stopImmediatePropagation(); wrap.classList.add('is-invalid'); if(errDiv)errDiv.classList.remove('d-none'); q.focus(); return; }
        if (hiddenInput) hiddenInput.value = q.root.innerHTML;
      });
    }
  });
}

function hdBindFullscreen(root) {
  root = root || document;
  root.querySelectorAll('.hd-fs-btn').forEach(function(btn) {
    if (btn._fsBound) return;
    btn._fsBound = true;
    btn.addEventListener('click', function() {
      var wrapId  = btn.dataset.target;
      var wrap    = document.getElementById(wrapId); if (!wrap) return;
      var overlay = document.getElementById(wrapId.replace('_wrap', '_fsOverlay')); if (!overlay) return;
      var fsBody  = document.getElementById(wrapId.replace('_wrap', '_fsBody')); if (!fsBody) return;
      var q = wrap._quill; if (!q) return;
      // Przenieś toolbar + container do fsBody
      var toolbar   = wrap.querySelector('.ql-toolbar');
      var container = wrap.querySelector('.ql-container');
      if (toolbar)   fsBody.appendChild(toolbar);
      if (container) fsBody.appendChild(container);
      overlay.classList.add('active');
      overlay._origWrap = wrap;
      // Focus edytor
      setTimeout(function(){ q.focus(); }, 80);
    });
  });
  root.querySelectorAll('.hd-fs-close').forEach(function(btn) {
    if (btn._fsBound) return;
    btn._fsBound = true;
    btn.addEventListener('click', function() {
      var overlay = document.getElementById(btn.dataset.overlay); if (!overlay) return;
      hdFsClose(overlay);
    });
  });
  // Zamknij na Escape
  if (!root._hdEscBound) {
    root._hdEscBound = true;
    document.addEventListener('keydown', function(e) {
      if (e.key !== 'Escape') return;
      var active = document.querySelector('.hd-quill-fs-overlay.active');
      if (active) { e.preventDefault(); hdFsClose(active); }
    });
  }
}

function hdFsClose(overlay) {
  var wrap = overlay._origWrap; if (!wrap) { overlay.classList.remove('active'); return; }
  var fsBody = overlay.querySelector('.hd-quill-fs-body'); if (!fsBody) { overlay.classList.remove('active'); return; }
  // Zwróć toolbar + container z powrotem do oryginalnego wrapa
  var toolbar   = fsBody.querySelector('.ql-toolbar');
  var container = fsBody.querySelector('.ql-container');
  if (toolbar)   wrap.appendChild(toolbar);
  if (container) wrap.appendChild(container);
  overlay.classList.remove('active');
}
</script>

<script>
(function () {
  'use strict';
  var APP  = <?= json_encode(APP_URL) ?>;
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var listEl = document.getElementById('hdListRegion');
  var paneEl = document.getElementById('hdPane');
  var consoleEl = document.getElementById('hdConsole');
  var filters = document.getElementById('hdFilters');
  var viewInp = document.getElementById('hdView');
  var countEl = document.getElementById('hdCount');
  var toastWrap = document.getElementById('hdToasts');
  var current = <?= $sel_id ?: 'null' ?>;
  var debTimer = null, abort = null;

  /* ── Toast ───────────────────────────────────────── */
  function toast(flash) {
    if (!flash || !flash.msg) return;
    var t = document.createElement('div');
    t.className = 'hd-toast ' + (flash.type === 'success' ? 'ok' : (flash.type === 'danger' || flash.type === 'error' ? 'err' : ''));
    t.textContent = flash.msg.replace(/<[^>]*>/g, '');
    toastWrap.appendChild(t);
    setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 300); }, 4500);
  }

  function cleanupModals() {
    document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
  }

  /* ── Filtry → parametry ──────────────────────────── */
  function params() {
    var p = new URLSearchParams();
    var fd = new FormData(filters);
    fd.forEach(function (v, k) { if (v) p.set(k, v); });
    p.set('view', viewInp.value);
    return p;
  }

  /* ── Ładuj listę (AJAX) ──────────────────────────── */
  function loadList(push) {
    if (abort) { try { abort.abort(); } catch (e) {} }
    abort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    listEl.setAttribute('aria-busy', 'true');
    var p = params();
    var url = APP + '/helpdesk/index.php?_ajax=1&' + p.toString();
    fetch(url, abort ? { signal: abort.signal } : {})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        listEl.innerHTML = d.list_html;
        listEl.removeAttribute('aria-busy');
        if (countEl) countEl.textContent = d.total;
        bindRows();
        markActive();
        if (push !== false) {
          var hu = new URL(window.location.href);
          hu.search = p.toString() + (current ? '&id=' + current : '');
          history.replaceState(null, '', hu.toString());
        }
      })
      .catch(function (e) { if (!e || e.name !== 'AbortError') listEl.removeAttribute('aria-busy'); });
  }

  function markActive() {
    listEl.querySelectorAll('.hd-row').forEach(function (r) {
      r.classList.toggle('active', current && +r.dataset.id === +current);
    });
  }

  /* ── Ładuj panel szczegółów (pane) ───────────────── */
  function loadPane(id, tpl) {
    current = id;
    markActive();
    consoleEl.classList.add('hd-show-pane');
    paneEl.style.opacity = '0.5';
    var url = APP + '/helpdesk/view.php?id=' + id + '&_pane=1' + (tpl ? '&tpl=' + encodeURIComponent(tpl) : '');
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        paneEl.innerHTML = html;
        paneEl.style.opacity = '1';
        bindPane(paneEl);
        paneEl.scrollIntoView({ block: 'start' });
        var hu = new URL(window.location.href);
        hu.searchParams.set('id', id);
        history.replaceState(null, '', hu.toString());
        // Oznacz odczytane i odśwież badge
        markReadLocally(id);
      })
      .catch(function () { paneEl.style.opacity = '1'; });
  }

  /* Oznacza wiersz jako odczytany (wizualnie) + aktualizuje badge */
  function markReadLocally(id) {
    var row = listEl.querySelector('.hd-row[data-id="' + id + '"]');
    if (row) {
      row.classList.remove('hd-row-unread');
      var dot = row.querySelector('.hd-unread-dot');
      if (dot) dot.remove();
    }
    // Wywołaj API mark_read w tle
    fetch(APP + '/helpdesk/api/mark_read.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: '_csrf=' + encodeURIComponent(CSRF) + '&ticket_id=' + id
    }).then(function(r){ return r.json(); }).then(function(d){
      var badge = document.getElementById('hdUnreadBadge');
      if (badge && d.unread_count !== undefined) {
        if (d.unread_count > 0) {
          badge.textContent = d.unread_count;
          badge.classList.remove('d-none');
        } else {
          badge.classList.add('d-none');
        }
      }
    }).catch(function(){});
  }

  /* ── Wiązanie wierszy listy ──────────────────────── */
  function bindRows() {
    listEl.querySelectorAll('.hd-row').forEach(function (a) {
      a.addEventListener('click', function (e) { e.preventDefault(); loadPane(+a.dataset.id); });
    });
  }

  /* ── Wiązanie panelu szczegółów ──────────────────── */
  function bindPane(root) {
    // Zwijanie wcześniejszych wiadomości
    root.querySelectorAll('[data-hd-older]').forEach(function (b) {
      b.addEventListener('click', function () {
        var w = root.querySelector('[data-hd-olderwrap]'); if (!w) return;
        var hid = w.classList.toggle('d-none');
        b.innerHTML = hid ? '<i class="bi bi-chevron-down me-1"></i>Pokaż wcześniejsze wiadomości'
                          : '<i class="bi bi-chevron-up me-1"></i>Ukryj wcześniejsze wiadomości';
      });
    });
    // Rozwijanie długich treści
    root.querySelectorAll('[data-hd-more]').forEach(function (b) {
      b.addEventListener('click', function () {
        var body = b.previousElementSibling; if (!body) return;
        b.textContent = body.classList.toggle('hd-expanded') ? 'Zwiń' : 'Pokaż całość';
      });
    });
    // Szablony odpowiedzi
    root.querySelectorAll('.hd-tpl-btn').forEach(function (b) {
      b.addEventListener('click', function () {
        var form = b.closest('form');
        var wrap = form.querySelector('.hd-quill-wrap');
        if (wrap && wrap._quill) {
          var q = wrap._quill;
          if (q.getText().trim() !== '' && !confirm('Zastąpić obecną treść wybranym szablonem?')) return;
          q.root.innerHTML = b.dataset.body.replace(/\n/g, '<br>');
          q.focus(); return;
        }
        var ta = form.querySelector('.hd-msg-body'); if (!ta) return;
        if (ta.value.trim() !== '' && !confirm('Zastąpić obecną treść wybranym szablonem?')) return;
        ta.value = b.dataset.body; ta.focus();
      });
    });
    // Kopiowanie linku
    root.querySelectorAll('[data-hd-copy]').forEach(function (b) {
      b.addEventListener('click', function () {
        var inp = b.closest('.input-group').querySelector('[data-hd-copyinput]'); if (!inp) return;
        if (navigator.clipboard) navigator.clipboard.writeText(inp.value);
        b.innerHTML = '<i class="bi bi-check2"></i>';
      });
    });
    // Checkbox „udostępnij firmie" w modalu
    var vs = root.querySelector('#hdShareVendor');
    if (vs) vs.addEventListener('change', function () {
      var box = root.querySelector('[data-hd-vendorshare]'); if (box) box.classList.toggle('d-none', !vs.checked);
    });
    // Powrót do listy (mobile)
    root.querySelectorAll('[data-hd-back]').forEach(function (b) {
      b.addEventListener('click', function () { consoleEl.classList.remove('hd-show-pane'); });
    });
    // Inicjalizuj Quill w panelu (tryb pane)
    hdInitQuill(root);
    // Fullscreen edytor
    hdBindFullscreen(root);

    // Formularze akcji → XHR
    root.querySelectorAll('form[data-hd-form]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        if (f.dataset.hdConfirm && !confirm(f.dataset.hdConfirm)) return;
        // Skopiuj treść Quilla do hidden input przed serializacją
        var wrap = f.querySelector('.hd-quill-wrap');
        if (wrap && wrap._quill) {
          var q = wrap._quill;
          var empty = q.getText().trim() === '';
          var hiddenBody = f.querySelector('input[name="msg_body"]');
          var errDiv = wrap.nextElementSibling;
          if (empty) {
            wrap.classList.add('is-invalid');
            if (errDiv && errDiv.classList.contains('invalid-feedback')) errDiv.classList.remove('d-none');
            q.focus(); return;
          }
          wrap.classList.remove('is-invalid');
          if (errDiv && errDiv.classList.contains('invalid-feedback')) errDiv.classList.add('d-none');
          if (hiddenBody) hiddenBody.value = q.root.innerHTML;
        }
        var fd = new FormData(f);
        if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value || '1');
        fetch(f.action, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            cleanupModals();
            if (!d) return;
            toast(d.flash);
            if (!d.ok) { return; }
            if (d.deleted) { current = null; paneEl.innerHTML = '<div class="hd-pane-empty"><i class="bi bi-check2-circle"></i><div>Zgłoszenie usunięte.</div></div>'; consoleEl.classList.remove('hd-show-pane'); loadList(false); return; }
            loadPane(d.ticket_id || current, d.tpl);
            loadList(false);
          })
          .catch(function () { cleanupModals(); });
      });
    });
  }

  /* ── Zdarzenia filtrów ───────────────────────────── */
  var search = filters.querySelector('input[name="q"]');
  if (search) search.addEventListener('input', function () {
    clearTimeout(debTimer); debTimer = setTimeout(function () { loadList(true); }, 380);
  });
  filters.querySelectorAll('select').forEach(function (s) { s.addEventListener('change', function () { loadList(true); }); });

  var tabs = document.getElementById('hdTabs');
  if (tabs) tabs.querySelectorAll('[data-view]').forEach(function (b) {
    b.addEventListener('click', function () {
      tabs.querySelectorAll('.nav-link').forEach(function (x) { x.classList.remove('active'); });
      b.classList.add('active');
      viewInp.value = b.dataset.view;
      loadList(true);
    });
  });

  /* ── Init ────────────────────────────────────────── */
  bindRows();
  if (current) loadPane(current);
})();
</script>


<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
