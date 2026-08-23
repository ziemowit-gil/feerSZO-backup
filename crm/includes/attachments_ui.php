<?php
/**
 * crm/includes/attachments_ui.php — wspólny widżet załączników wysyłki e-mail.
 *
 * Używany przez kompozytor w modalu (crm/compose_modal.php) i przez pełny
 * formularz komunikacji (crm/communicate.php). Pliki lądują w poczekalni
 * (includes/crm_attachments.php) od razu po wyborze — formularz przenosi już
 * tylko tokeny, więc ta sama obsługa działa dla POST-a JSON i multipart.
 *
 * Parametry (ustaw przed include):
 *   $ATT_UI = [
 *     'form'  => bool,   // true = dopisuj ukryte inputy crm_att_tokens[] do formularza
 *     'hint'  => string, // dodatkowy tekst pod polem
 *   ];
 *
 * Po stronie JS: window.CrmAtt — .tokens(), .add(items), .clear(), .count().
 */
require_once dirname(dirname(__DIR__)) . '/includes/crm_attachments.php';

$att_ui   = $ATT_UI ?? [];
$att_form = !empty($att_ui['form']);
$att_od   = crm_att_onedrive_available();
$att_csrf = csrf_token();
$att_exts = crm_att_allowed_ext();
$att_acc  = '.' . implode(',.', $att_exts);
?>
<style>
.catt { border:1px solid #E5E7EB;border-radius:.5rem;background:#FBFCFD;padding:.6rem .7rem }
.catt-head { display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.45rem }
.catt-title { font-size:.8rem;font-weight:600;color:#374151;display:flex;align-items:center;gap:.35rem;margin:0 }
.catt-count { font-size:.68rem;font-weight:600;color:#1D4ED8;background:#EFF6FF;border:1px solid #BFDBFE;
              border-radius:999px;padding:.05rem .45rem }
.catt-actions { margin-left:auto;display:flex;gap:.35rem;flex-wrap:wrap }
.catt-btn { font-size:.74rem;line-height:1.4;padding:.22rem .6rem;border-radius:.375rem;cursor:pointer;
            border:1px solid #D1D5DB;background:#fff;color:#374151;display:inline-flex;align-items:center;gap:.3rem;
            transition:background .12s,border-color .12s }
.catt-btn:hover { background:#F3F4F6;border-color:#9CA3AF }
.catt-btn:focus-visible { outline:2px solid #2563EB;outline-offset:1px }
.catt-btn[disabled] { opacity:.55;cursor:not-allowed }
.catt-btn--ms { border-color:#BFDBFE;background:#EFF6FF;color:#1D4ED8 }
.catt-btn--ms:hover { background:#DBEAFE;border-color:#93C5FD }
.catt-drop { border:1.5px dashed #D1D5DB;border-radius:.4rem;padding:.55rem .7rem;text-align:center;
             font-size:.75rem;color:#6B7280;background:#fff;transition:border-color .12s,background .12s }
.catt-drop.is-over { border-color:#2563EB;background:#EFF6FF;color:#1D4ED8 }
.catt-list { display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.5rem }
.catt-list:empty { display:none }
.catt-chip { display:inline-flex;align-items:center;gap:.4rem;max-width:100%;
             border:1px solid #E5E7EB;border-radius:.4rem;background:#fff;padding:.25rem .35rem .25rem .45rem;
             font-size:.75rem;color:#111827;box-shadow:0 1px 2px rgba(0,0,0,.04) }
.catt-chip i.catt-ico { font-size:.9rem;color:#6B7280 }
.catt-chip .catt-name { max-width:190px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap }
.catt-chip .catt-size { color:#9CA3AF;font-size:.68rem;white-space:nowrap }
.catt-chip .catt-src { font-size:.62rem;color:#1D4ED8;background:#EFF6FF;border-radius:3px;padding:0 .25rem }
.catt-x { border:none;background:transparent;color:#9CA3AF;line-height:1;padding:.1rem .2rem;border-radius:3px;cursor:pointer }
.catt-x:hover { color:#DC2626;background:#FEF2F2 }
.catt-msg { font-size:.72rem;margin-top:.4rem }
.catt-hint { font-size:.68rem;color:#9CA3AF;margin-top:.35rem }
/* Przeglądarka OneDrive */
.od-row { display:flex;align-items:center;gap:.55rem;padding:.35rem .5rem;border-radius:.35rem;cursor:pointer;
          font-size:.82rem;border:1px solid transparent }
.od-row:hover { background:#F3F4F6 }
.od-row.is-sel { background:#EFF6FF;border-color:#BFDBFE }
.od-row .od-name { flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap }
.od-row .od-size { font-size:.7rem;color:#9CA3AF;white-space:nowrap }
.od-row.is-big { opacity:.5;cursor:not-allowed }
.od-crumbs { font-size:.75rem;color:#6B7280;display:flex;flex-wrap:wrap;align-items:center;gap:.2rem }
.od-crumbs button { border:none;background:transparent;color:#2563EB;padding:0 .1rem;cursor:pointer;font-size:.75rem }
</style>

<div class="catt" id="cattRoot" data-form="<?= $att_form ? '1' : '0' ?>">
  <div class="catt-head">
    <p class="catt-title"><i class="bi bi-paperclip" aria-hidden="true"></i>Załączniki
      <span class="catt-count" id="cattCount" hidden>0</span>
    </p>
    <div class="catt-actions">
      <button type="button" class="catt-btn" id="cattPickLocal">
        <i class="bi bi-hdd" aria-hidden="true"></i>Z dysku
      </button>
      <?php if ($att_od): ?>
      <button type="button" class="catt-btn catt-btn--ms" id="cattPickOd"
              data-bs-toggle="modal" data-bs-target="#cattOdModal">
        <i class="bi bi-microsoft" aria-hidden="true"></i>Z OneDrive
      </button>
      <?php endif; ?>
    </div>
  </div>

  <input type="file" id="cattFile" multiple accept="<?= h($att_acc) ?>" class="d-none">

  <div class="catt-drop" id="cattDrop">
    Przeciągnij pliki tutaj albo kliknij <strong>Z dysku</strong>
  </div>

  <div class="catt-list" id="cattList" aria-live="polite"></div>
  <div id="cattTokens"></div>
  <div class="catt-msg alert alert-danger py-1 px-2 d-none" id="cattErr" role="alert"></div>

  <div class="catt-hint">
    Max <?= CRM_ATT_MAX_FILES ?> pliki, po 15 MB. Dozwolone: <?= h(strtoupper(implode(', ', $att_exts))) ?>.
  </div>
</div>

<?php if ($att_od): ?>
<!-- Przeglądarka OneDrive (modal — przy kompozytorze jest zagnieżdżony, stąd z-index) -->
<div class="modal fade" id="cattOdModal" tabindex="-1" aria-labelledby="cattOdTitle" style="z-index:1065">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold" id="cattOdTitle">
          <i class="bi bi-microsoft text-primary me-1" aria-hidden="true"></i>Mój OneDrive
        </h6>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body pt-2">
        <div class="d-flex gap-2 align-items-center mb-2">
          <div class="od-crumbs flex-grow-1" id="odCrumbs"></div>
          <div class="input-group input-group-sm" style="max-width:230px">
            <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
            <input type="search" class="form-control" id="odSearch" placeholder="Szukaj w OneDrive…"
                   aria-label="Szukaj w OneDrive">
          </div>
        </div>
        <div id="odError" class="alert alert-warning py-2 small d-none" role="alert"></div>
        <div id="odItems" style="max-height:46vh;overflow-y:auto"></div>
      </div>
      <div class="modal-footer py-2">
        <span class="me-auto small text-muted" id="odSelInfo"></span>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-primary btn-sm" id="odAddBtn" disabled>
          <i class="bi bi-paperclip me-1" aria-hidden="true"></i>Dołącz wybrane
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
'use strict';

var API  = <?= json_encode(rtrim(APP_URL, '/') . '/crm/api/attachments.php') ?>;
var CSRF = <?= json_encode($att_csrf) ?>;
var MAX  = <?= (int)CRM_ATT_MAX_FILES ?>;
var FORM = document.getElementById('cattRoot').dataset.form === '1';

var _items = [];   // [{token,name,size,mime,source}]

var ICONS = {
    pdf:'bi-file-earmark-pdf', doc:'bi-file-earmark-word', docx:'bi-file-earmark-word',
    xls:'bi-file-earmark-excel', xlsx:'bi-file-earmark-excel', csv:'bi-file-earmark-spreadsheet',
    txt:'bi-file-earmark-text', png:'bi-file-earmark-image', jpg:'bi-file-earmark-image',
    jpeg:'bi-file-earmark-image', gif:'bi-file-earmark-image',
    zip:'bi-file-earmark-zip', rar:'bi-file-earmark-zip', '7z':'bi-file-earmark-zip',
    odt:'bi-file-earmark-richtext', ods:'bi-file-earmark-spreadsheet'
};

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
}); }

function fmtSize(b) {
    if (b >= 1048576) return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
    if (b >= 1024)    return Math.round(b / 1024) + ' KB';
    return b + ' B';
}

function showErr(msg) {
    var el = document.getElementById('cattErr');
    if (!msg) { el.classList.add('d-none'); el.textContent = ''; return; }
    el.textContent = msg; el.classList.remove('d-none');
}

function render() {
    var list = document.getElementById('cattList');
    var cnt  = document.getElementById('cattCount');
    list.innerHTML = _items.map(function (it, i) {
        var ext = (it.name.split('.').pop() || '').toLowerCase();
        return '<span class="catt-chip">'
             + '<i class="bi ' + (ICONS[ext] || 'bi-file-earmark') + ' catt-ico" aria-hidden="true"></i>'
             + '<span class="catt-name" title="' + esc(it.name) + '">' + esc(it.name) + '</span>'
             + '<span class="catt-size">' + fmtSize(it.size) + '</span>'
             + (it.source === 'onedrive' ? '<span class="catt-src">OneDrive</span>' : '')
             + (it.source === 'template' ? '<span class="catt-src">szablon</span>' : '')
             + '<button type="button" class="catt-x" data-i="' + i + '" '
             + 'aria-label="Usuń załącznik ' + esc(it.name) + '"><i class="bi bi-x-lg" aria-hidden="true"></i></button>'
             + '</span>';
    }).join('');
    list.querySelectorAll('.catt-x').forEach(function (b) {
        b.addEventListener('click', function () { drop(Number(this.dataset.i)); });
    });

    cnt.textContent = _items.length;
    cnt.hidden = _items.length === 0;

    if (FORM) {
        document.getElementById('cattTokens').innerHTML = _items.map(function (it) {
            return '<input type="hidden" name="crm_att_tokens[]" value="' + esc(it.token) + '">';
        }).join('');
    }
    var od = document.getElementById('cattPickOd');
    var full = _items.length >= MAX;
    document.getElementById('cattPickLocal').disabled = full;
    if (od) od.disabled = full;
}

function addItems(items) {
    (items || []).forEach(function (it) {
        if (_items.length >= MAX) return;
        if (_items.some(function (x) { return x.token === it.token; })) return;
        _items.push(it);
    });
    render();
}

function drop(i) {
    var it = _items[i];
    if (!it) return;
    _items.splice(i, 1);
    render();
    // keep:* to załączniki już zapisane przy szablonie — nie ma czego sprzątać w poczekalni
    if (String(it.token).indexOf('keep:') === 0) return;
    fetch(API, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_csrf: CSRF, a: 'drop', token: it.token})
    }).catch(function () {});
}

function upload(files) {
    if (!files || !files.length) return;
    showErr('');
    var fd = new FormData();
    fd.append('_csrf', CSRF);
    fd.append('a', 'upload');
    var room = MAX - _items.length;
    Array.prototype.slice.call(files, 0, room).forEach(function (f) { fd.append('files[]', f); });
    if (room <= 0) { showErr('Maksymalnie ' + MAX + ' załączników.'); return; }

    var drop = document.getElementById('cattDrop');
    var old  = drop.innerHTML;
    drop.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Wysyłam pliki…';

    fetch(API, {method: 'POST', body: fd})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            drop.innerHTML = old;
            if (d.items) addItems(d.items);
            if (d.errors && d.errors.length) showErr(d.errors.join(' '));
            else if (!d.ok) showErr(d.error || 'Nie udało się dodać załącznika.');
        })
        .catch(function () { drop.innerHTML = old; showErr('Błąd połączenia przy wysyłce pliku.'); });
}

/* ── Pliki z dysku ─────────────────────────────────────────────────────── */
var fileInput = document.getElementById('cattFile');
document.getElementById('cattPickLocal').addEventListener('click', function () { fileInput.click(); });
fileInput.addEventListener('change', function () { upload(this.files); this.value = ''; });

var dz = document.getElementById('cattDrop');
dz.addEventListener('click', function () { if (_items.length < MAX) fileInput.click(); });
['dragenter','dragover'].forEach(function (ev) {
    dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('is-over'); });
});
['dragleave','drop'].forEach(function (ev) {
    dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('is-over'); });
});
dz.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files) upload(e.dataTransfer.files);
});

/* ── OneDrive ──────────────────────────────────────────────────────────── */
var odModal = document.getElementById('cattOdModal');
if (odModal) {
    var _stack = [{id: '', name: 'OneDrive'}];   // ścieżka folderów
    var _sel   = {};                             // id → {id,name}
    var _timer = null;

    function odErr(msg) {
        var el = document.getElementById('odError');
        if (!msg) { el.classList.add('d-none'); el.textContent = ''; return; }
        el.textContent = msg; el.classList.remove('d-none');
    }

    function odSelInfo() {
        var n = Object.keys(_sel).length;
        document.getElementById('odSelInfo').textContent = n ? ('Wybrano: ' + n) : '';
        document.getElementById('odAddBtn').disabled = n === 0;
    }

    function crumbs() {
        document.getElementById('odCrumbs').innerHTML = _stack.map(function (f, i) {
            return (i ? '<span aria-hidden="true">/</span>' : '')
                 + '<button type="button" data-i="' + i + '">' + esc(f.name) + '</button>';
        }).join('');
        document.getElementById('odCrumbs').querySelectorAll('button').forEach(function (b) {
            b.addEventListener('click', function () {
                _stack = _stack.slice(0, Number(this.dataset.i) + 1);
                document.getElementById('odSearch').value = '';
                odLoad();
            });
        });
    }

    function odLoad(q) {
        var box = document.getElementById('odItems');
        box.innerHTML = '<div class="text-muted small py-3 text-center">'
                      + '<span class="spinner-border spinner-border-sm me-1"></span>Ładowanie…</div>';
        odErr('');
        crumbs();

        fetch(API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                _csrf: CSRF, a: 'onedrive_list',
                folder: _stack[_stack.length - 1].id, q: q || ''
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) { box.innerHTML = ''; odErr(d.error || 'Nie udało się otworzyć OneDrive.'); return; }
            if (!d.items.length) {
                box.innerHTML = '<div class="text-muted small py-3 text-center">Pusto — brak plików, '
                              + 'które można dołączyć.</div>';
                return;
            }
            box.innerHTML = d.items.map(function (it) {
                var ico = it.folder ? 'bi-folder-fill text-warning'
                                    : (ICONS[it.ext] || 'bi-file-earmark');
                return '<div class="od-row' + (it.big ? ' is-big' : '') + (_sel[it.id] ? ' is-sel' : '') + '" '
                     + 'data-id="' + esc(it.id) + '" data-folder="' + (it.folder ? 1 : 0) + '" '
                     + 'data-name="' + esc(it.name) + '" data-big="' + (it.big ? 1 : 0) + '" '
                     + 'role="button" tabindex="0">'
                     + '<i class="bi ' + ico + '" aria-hidden="true"></i>'
                     + '<span class="od-name">' + esc(it.name) + '</span>'
                     + '<span class="od-size">' + (it.folder ? '' : fmtSize(it.size)) + '</span>'
                     + (it.big ? '<span class="od-size text-danger">&gt; 15 MB</span>' : '')
                     + (it.folder ? '<i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>'
                                  : '<i class="bi ' + (_sel[it.id] ? 'bi-check-square' : 'bi-square')
                                    + ' text-primary" aria-hidden="true"></i>')
                     + '</div>';
            }).join('');
            box.querySelectorAll('.od-row').forEach(function (row) {
                function act() {
                    if (row.dataset.big === '1') return;
                    if (row.dataset.folder === '1') {
                        _stack.push({id: row.dataset.id, name: row.dataset.name});
                        document.getElementById('odSearch').value = '';
                        odLoad();
                        return;
                    }
                    var id = row.dataset.id;
                    if (_sel[id]) delete _sel[id];
                    else {
                        if (Object.keys(_sel).length + _items.length >= MAX) {
                            odErr('Maksymalnie ' + MAX + ' załączników.'); return;
                        }
                        _sel[id] = {id: id, name: row.dataset.name};
                    }
                    row.classList.toggle('is-sel', !!_sel[id]);
                    var chk = row.querySelector('.bi-square, .bi-check-square');
                    if (chk) chk.className = 'bi ' + (_sel[id] ? 'bi-check-square' : 'bi-square') + ' text-primary';
                    odSelInfo();
                }
                row.addEventListener('click', act);
                row.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); act(); }
                });
            });
        })
        .catch(function () { box.innerHTML = ''; odErr('Błąd połączenia z Microsoft 365.'); });
    }

    odModal.addEventListener('show.bs.modal', function () {
        _sel = {}; odSelInfo();
        document.getElementById('odSearch').value = '';
        _stack = [{id: '', name: 'OneDrive'}];
        odLoad();
    });

    document.getElementById('odSearch').addEventListener('input', function () {
        var q = this.value.trim();
        clearTimeout(_timer);
        _timer = setTimeout(function () { odLoad(q); }, 350);
    });

    document.getElementById('odAddBtn').addEventListener('click', function () {
        var ids = Object.keys(_sel);
        if (!ids.length) return;
        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Pobieram…';

        fetch(API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({_csrf: CSRF, a: 'onedrive_pick', ids: ids})
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.innerHTML = '<i class="bi bi-paperclip me-1"></i>Dołącz wybrane';
            btn.disabled = false;
            if (d.items && d.items.length) {
                addItems(d.items);
                showErr(d.errors && d.errors.length ? d.errors.join(' ') : '');
                bootstrap.Modal.getInstance(odModal).hide();
            } else {
                odErr(d.error || 'Nie udało się pobrać plików.');
            }
        })
        .catch(function () {
            btn.innerHTML = '<i class="bi bi-paperclip me-1"></i>Dołącz wybrane';
            btn.disabled = false;
            odErr('Błąd połączenia z Microsoft 365.');
        });
    });
}

/* ── API dla stron używających widżetu ─────────────────────────────────── */
window.CrmAtt = {
    tokens: function () { return _items.map(function (i) { return i.token; }); },
    items:  function () { return _items.slice(); },
    count:  function () { return _items.length; },
    add:    addItems,
    set:    function (items) { _items = (items || []).slice(0, MAX); render(); },
    clear:  function () { _items = []; render(); },
    /** Dokłada załączniki szablonu (serwer robi kopie plików). */
    fromTemplate: function (templateId) {
        if (!templateId) return;
        fetch(API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({_csrf: CSRF, a: 'template_atts', template_id: templateId})
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.items && d.items.length) addItems(d.items);
            if (d.errors && d.errors.length) showErr(d.errors.join(' '));
        })
        .catch(function () {});
    }
};

render();
})();
</script>
