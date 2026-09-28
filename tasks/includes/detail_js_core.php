<script>
/* tasks/includes/detail_js_core.php — wydzielone z tasks/detail.php.
   Narzędzia (escHtml/api/srAnnounce), edycja pól inline (tdPatch/tdSetUnit/tdMoveToList/tdToggleTag), przypisani (TomSelect) i komentarze/wzmianki.
   UWAGA: ten fragment jest wstrzykiwany do offcanvas przez fetch()+createContextualFragment()
   (nie pełne przeładowanie strony) — dlatego BEZ IIFE per plik: deklaracje top-level
   (const/let/function) współdzielą jeden "script scope" ze wszystkimi innymi
   detail_js_*.php tego fragmentu, doładowanymi jako kolejne <script> w tym samym
   dokumencie, dokładnie tak jak wcześniej działało jedno wspólne IIFE. */
const CSRF      = <?= json_encode($csrf) ?>;
const BASE      = <?= json_encode(rtrim(APP_URL,'/')) ?>;
const TID       = <?= (int)$id ?>;
const MS_APP_ID = <?= json_encode($ms_app_id) ?>;
const HAS_MS    = <?= $has_ms ? 'true' : 'false' ?>;
const ALL_USERS = <?= json_encode(array_values(array_map(
    fn($u) => ['id' => (int)$u['id'], 'name' => $u['name']],
    $all_users
))) ?>;

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function getInitials(n) {
    return n.trim().split(/\s+/).slice(0,2).map(p=>p[0]||'').join('').toUpperCase()||'?';
}

function api(url, data) {
    return fetch(BASE + url, {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body:    JSON.stringify({_csrf: CSRF, ...data})
    }).then(r => r.json());
}

function srAnnounce(msg) {
    const el = document.getElementById('kb-sr-announce');
    if (el) { el.textContent = ''; setTimeout(() => { el.textContent = msg; }, 50); }
}

window.tdPatch = function(data) {
    api('/tasks/api/task.php', {action:'update', id:TID, ...data})
        .then(r => { if (!r.ok) alert('Błąd zapisu: ' + r.error); });
};

window.tdSetUnit = function(sel) {
    const newVal = parseInt(sel.value) || null;
    const prev   = parseInt(sel.dataset.prev) || null;
    if (!newVal) {
        sel.dataset.prev = '0';
        tdPatch({unit_id: null});
        return;
    }
    const unitName      = sel.options[sel.selectedIndex].text.trim();
    const hasAssignees  = _tdUserTs ? _tdUserTs.getValue().length > 0 : false;

    const proceed = (clearAssignees) => {
        sel.dataset.prev = sel.value;
        if (clearAssignees) {
            _tdUserTs?.clear(true); // silent — bez wywołania API per-osoba, całość idzie przez clear_assignees
            tdPatch({unit_id: newVal, clear_assignees: true});
        } else {
            tdPatch({unit_id: newVal});
        }
    };

    if (hasAssignees) {
        if (!confirm('Przypisać zadanie do jednostki „' + unitName + '"?\n\nZadanie ma przypisane osoby — usunąć przypisania osobiste?')) {
            sel.value = prev || '0';
            return;
        }
        proceed(true);
    } else {
        if (!confirm('Przypisać zadanie do jednostki „' + unitName + '"?\n(Osoba preferowana — wróć do trybu osoby, gdy znasz konkretnego wykonawcę)')) {
            sel.value = prev || '0';
            return;
        }
        proceed(false);
    }
};

window.tdMoveToList = function(listId) {
    api('/tasks/api/move.php', {task_id:TID, list_id:listId, position:9999, ordered_ids:[]})
        .then(r => { if (r.ok) openTask(TID); else alert(r.error); });
};

window.tdToggleTag = function(tagId, btn) {
    const act = btn.dataset.active === '1' ? 'remove' : 'add';
    btn.disabled = true;
    api('/tasks/api/tag.php', {task_id:TID, tag_id:tagId, action:act})
        .then(r => {
            btn.disabled = false;
            if (r.ok) openTask(TID);
            else alert(r.error);
        });
};

let _tdUserTs = null;

/* Odśwież panel po zmianie przypisań — nazwa funkcji hosta różni się
 * w zależności od strony, z której otwarto offcanvas (index/dashboard/inbox). */
function tdReloadPanel() {
    if (typeof window.openTask === 'function') window.openTask(TID);
    else if (typeof window.taskOpenById === 'function') window.taskOpenById(TID);
}

function tdAssignApi(userId, action) {
    api('/tasks/api/assign.php', {task_id: TID, user_id: userId, action: action})
        .then(r => {
            if (!r.ok) {
                alert(r.error || 'Błąd zapisu przypisania.');
                // Cofnij zmianę w UI bez ponownego wywołania API (silent)
                if (action === 'add') _tdUserTs?.removeItem(String(userId), true);
                else _tdUserTs?.addItem(String(userId), true);
            }
        })
        .catch(() => alert('Błąd połączenia.'));
}

function tdInitUsersSelect() {
    const el = document.getElementById('td-users-select');
    if (!el || typeof TomSelect === 'undefined') return;
    const readOnly = el.disabled;
    _tdUserTs = new TomSelect(el, {
        plugins:     readOnly ? [] : ['remove_button'],
        placeholder: 'Wyszukaj i dodaj osobę…',
    });
    if (readOnly) { _tdUserTs.disable(); return; }

    let prev = new Set(_tdUserTs.getValue().map(Number));
    let reloadTimer = null;

    _tdUserTs.on('change', function(values) {
        const next    = new Set(values.map(Number));
        const added   = [...next].filter(id => !prev.has(id));
        const removed = [...prev].filter(id => !next.has(id));
        prev = next;

        added.forEach(id   => tdAssignApi(id, 'add'));
        removed.forEach(id => tdAssignApi(id, 'remove'));

        // Odśwież panel (historia, itp.) dopiero po chwili ciszy — nie przy każdym pojedynczym wyborze.
        if (added.length || removed.length) {
            clearTimeout(reloadTimer);
            reloadTimer = setTimeout(tdReloadPanel, 900);
        }
    });
}

tdInitUsersSelect();

window.tdAddComment = function() {
    const ta   = document.getElementById('td-new-cmt');
    const body = (ta ? ta.value : '').trim();
    if (!body) { if (ta) ta.focus(); return; }
    tdMentionHide();
    const btn = document.querySelector('#td-new-cmt ~ button');
    if (btn) btn.disabled = true;
    ta.disabled = true;
    api('/tasks/api/comment.php', {action:'add', task_id:TID, body})
        .then(r => {
            ta.disabled = false;
            if (btn) btn.disabled = false;
            if (r.ok) { srAnnounce('Komentarz dodany.'); openTask(TID); }
            else alert(r.error);
        });
};

/* @mentions */
var _mFiltered = [], _mActive = -1;

function tdMentionQuery() {
    const ta  = document.getElementById('td-new-cmt');
    if (!ta) return null;
    const pos = ta.selectionStart;
    const val = ta.value.substring(0, pos);
    const m   = val.match(/@([^\n@]*)$/);
    if (!m) return null;
    return { query: m[1], atPos: pos - m[0].length };
}

function tdMentionHide() {
    const dd = document.getElementById('td-mention-dd');
    if (dd) dd.style.display = 'none';
    _mFiltered = []; _mActive = -1;
}

function tdMentionSetActive(idx) {
    const dd = document.getElementById('td-mention-dd');
    if (!dd) return;
    dd.querySelectorAll('.mi-item').forEach(function(el, i) {
        el.classList.toggle('mi-active', i === idx);
        if (i === idx) el.scrollIntoView({block:'nearest'});
    });
    _mActive = idx;
}

function tdMentionInsert(name, atPos) {
    const ta = document.getElementById('td-new-cmt');
    if (!ta) return;
    const pos = ta.selectionStart;
    ta.value  = ta.value.substring(0, atPos) + '@' + name + ' ' + ta.value.substring(pos);
    const np  = atPos + 1 + name.length + 1;
    ta.selectionStart = ta.selectionEnd = np;
    tdMentionHide();
    ta.focus();
}

function tdMentionShow(users, atPos) {
    const dd = document.getElementById('td-mention-dd');
    const ta = document.getElementById('td-new-cmt');
    if (!dd || !ta) return;
    if (!users.length) { tdMentionHide(); return; }
    _mFiltered = users; _mActive = -1;
    dd.innerHTML = '';

    users.forEach(function(u, i) {
        const item = document.createElement('div');
        item.className = 'mi-item';
        item.setAttribute('role', 'option');
        item.setAttribute('aria-selected', 'false');
        item.innerHTML = '<span class="td-mention-av">' + escHtml(getInitials(u.name)) + '</span>'
                       + '<span>' + escHtml(u.name) + '</span>';
        item.addEventListener('mousedown', function(e) {
            e.preventDefault();
            const q = tdMentionQuery();
            if (q) tdMentionInsert(u.name, q.atPos);
        });
        dd.appendChild(item);
    });

    const r = ta.getBoundingClientRect();
    dd.style.top   = (r.bottom + 3) + 'px';
    dd.style.left  = r.left + 'px';
    dd.style.width = Math.max(r.width, 200) + 'px';
    dd.style.display = 'block';
}

window.tdCmtKeydown = function(e) {
    const dd     = document.getElementById('td-mention-dd');
    const ddOpen = dd && dd.style.display !== 'none';

    if (ddOpen) {
        if (e.key === 'ArrowDown')  { e.preventDefault(); tdMentionSetActive(Math.min(_mActive + 1, _mFiltered.length - 1)); return; }
        if (e.key === 'ArrowUp')    { e.preventDefault(); tdMentionSetActive(Math.max(_mActive - 1, 0)); return; }
        if (e.key === 'Escape')     { tdMentionHide(); return; }
        if ((e.key === 'Enter' || e.key === 'Tab') && _mActive >= 0 && _mFiltered[_mActive]) {
            e.preventDefault();
            const q = tdMentionQuery();
            if (q) tdMentionInsert(_mFiltered[_mActive].name, q.atPos);
            return;
        }
    }

    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        tdAddComment();
    }
};

(function(){
    const ta = document.getElementById('td-new-cmt');
    if (!ta) return;

    ta.addEventListener('input', function() {
        const q = tdMentionQuery();
        if (!q) { tdMentionHide(); return; }
        const query   = q.query.toLowerCase();
        const matched = ALL_USERS.filter(u => {
            const n = u.name.toLowerCase();
            return n.startsWith(query) || n.includes(' ' + query);
        }).slice(0, 7);
        tdMentionShow(matched, q.atPos);
    });

    ta.addEventListener('blur', () => setTimeout(tdMentionHide, 160));

    const oc = document.querySelector('.offcanvas-body');
    if (oc) oc.addEventListener('scroll', tdMentionHide, {passive: true});
})();

window.tdToggleWatch = function() {
    const btn = document.getElementById('td-watch-btn');
    if (!btn) return;
    const on = btn.getAttribute('aria-pressed') !== 'true';
    btn.disabled = true;
    api('/tasks/api/watch.php', {task_id: TID, watch: on})
        .then(r => {
            btn.disabled = false;
            if (!r.ok) { alert(r.error); return; }
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.classList.toggle('btn-secondary', on);
            btn.classList.toggle('btn-outline-secondary', !on);
            btn.querySelector('i').className = 'bi ' + (on ? 'bi-eye-fill' : 'bi-eye') + ' me-1';
            btn.querySelector('span').textContent = on ? 'Obserwujesz' : 'Obserwuj';
            const list = document.getElementById('td-watch-list');
            if (list) list.textContent = r.watchers.length
                ? 'Obserwują: ' + r.watchers.map(w => w.name).join(', ')
                : 'Nikt nie obserwuje — obserwujący dostają powiadomienia o komentarzach, plikach i zmianie statusu.';
            srAnnounce(on ? 'Obserwujesz to zadanie.' : 'Przestałeś/aś obserwować zadanie.');
        });
};

window.tdEditComment = function(cid) {
    const bodyEl = document.querySelector('#cmt-' + cid + ' .td-comment-body');
    if (!bodyEl || bodyEl.querySelector('textarea')) return;
    const raw = bodyEl.dataset.raw || '';
    const prevHtml = bodyEl.innerHTML;
    bodyEl.innerHTML =
        '<label class="visually-hidden" for="td-edit-cmt-' + cid + '">Edycja komentarza</label>'
        + '<textarea id="td-edit-cmt-' + cid + '" class="form-control form-control-sm" rows="3"></textarea>'
        + '<div class="d-flex gap-1 mt-1">'
        + '<button type="button" class="btn btn-sm btn-primary py-0" data-act="save">Zapisz</button>'
        + '<button type="button" class="btn btn-sm btn-outline-secondary py-0" data-act="cancel">Anuluj</button>'
        + '</div>';
    const ta = bodyEl.querySelector('textarea');
    ta.value = raw;
    ta.focus();
    const cancel = () => { bodyEl.innerHTML = prevHtml; };
    const save = () => {
        const text = ta.value.trim();
        if (!text) { ta.focus(); return; }
        if (text === raw) return cancel();
        bodyEl.querySelectorAll('button, textarea').forEach(el => el.disabled = true);
        api('/tasks/api/comment.php', {action:'edit', task_id:TID, comment_id:cid, body:text})
            .then(r => {
                if (r.ok) { srAnnounce('Komentarz zapisany.'); openTask(TID); }
                else { alert(r.error); bodyEl.querySelectorAll('button, textarea').forEach(el => el.disabled = false); }
            });
    };
    bodyEl.querySelector('[data-act="save"]').addEventListener('click', save);
    bodyEl.querySelector('[data-act="cancel"]').addEventListener('click', cancel);
    ta.addEventListener('keydown', e => {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancel(); }
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(); }
    });
};

window.tdDeleteComment = function(cid) {
    if (!confirm('Usunąć ten komentarz?')) return;
    api('/tasks/api/comment.php', {action:'delete', task_id:TID, comment_id:cid})
        .then(r => {
            if (r.ok) { document.getElementById('cmt-' + cid)?.remove(); srAnnounce('Komentarz usunięty.'); }
            else alert(r.error);
        });
};

</script>
