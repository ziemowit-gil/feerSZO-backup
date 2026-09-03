<script>
/* tasks/includes/detail_js_subtasks.php — wydzielone z tasks/detail.php.
   Podzadania: render listy, toggle/dodaj/usuń/edytuj inline.
   UWAGA: ten fragment jest wstrzykiwany do offcanvas przez fetch()+createContextualFragment()
   (nie pełne przeładowanie strony) — dlatego BEZ IIFE per plik: deklaracje top-level
   (const/let/function) współdzielą jeden "script scope" ze wszystkimi innymi
   detail_js_*.php tego fragmentu, doładowanymi jako kolejne <script> w tym samym
   dokumencie, dokładnie tak jak wcześniej działało jedno wspólne IIFE. */
/* Podzadania */
function tdStApiUrl() { return BASE + '/tasks/api/subtask.php'; }

function tdStRefreshUI() {
    const rows  = document.querySelectorAll('#td-st-list .td-st-row');
    const done  = document.querySelectorAll('#td-st-list .td-st-row.td-st-done').length;
    const total = rows.length;
    const pct   = total ? Math.round(done / total * 100) : 0;

    const bar   = document.getElementById('td-st-progress');
    const wrap  = document.getElementById('td-st-progress-wrap');
    const pctEl = document.getElementById('td-st-pct');
    const ctr   = document.getElementById('td-st-counter');
    const empty = document.getElementById('td-st-empty');

    if (ctr)   ctr.textContent   = total ? '(' + done + '/' + total + ')' : '';
    if (pctEl) pctEl.textContent = total ? pct + '%' : '';
    if (bar) {
        bar.style.width = pct + '%';
        bar.className   = 'td-progress-fill ' + (done === total && total > 0 ? 'bg-success' : 'bg-primary');
        bar.parentElement?.setAttribute('aria-valuenow', pct);
    }
    if (wrap) {
        wrap.classList.toggle('d-none', !total);
        wrap.setAttribute('aria-hidden', total ? 'false' : 'true');
    }
    if (empty) empty.style.display = total ? 'none' : '';
}

function tdStBuildRow(st) {
    const row = document.createElement('div');
    row.id    = 'strow-' + st.id;
    row.className = 'td-st-row' + (st.is_done ? ' td-st-done' : '');
    row.setAttribute('role', 'listitem');

    const cb  = document.createElement('input');
    cb.type   = 'checkbox';
    cb.className = 'form-check-input flex-shrink-0 mt-0';
    cb.style.cssText = 'cursor:pointer;width:15px;height:15px';
    cb.checked = !!st.is_done;
    cb.setAttribute('aria-label', st.title);
    cb.addEventListener('change', function() { tdStToggle(st.id, cb); });

    const span = document.createElement('span');
    span.className = 'flex-grow-1 small td-st-title';
    span.style.cssText = 'line-height:1.4;cursor:text';
    span.textContent   = st.title;
    span.setAttribute('role', 'button');
    span.setAttribute('tabindex', '0');
    span.setAttribute('aria-label', 'Edytuj podzadanie: ' + st.title);
    span.addEventListener('click', function() { tdStStartEdit(span, st.id); });
    span.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); tdStStartEdit(span, st.id); }
    });

    const del = document.createElement('button');
    del.type  = 'button';
    del.className = 'btn-close flex-shrink-0';
    del.style.cssText = 'font-size:.5rem;opacity:.5';
    del.setAttribute('aria-label', 'Usuń podzadanie: ' + st.title);
    del.addEventListener('click', function() { tdStDelete(st.id); });

    row.append(cb, span, del);
    return row;
}

window.tdStToggle = function(stId, cb) {
    const is_done = cb.checked ? 1 : 0;
    const row  = document.getElementById('strow-' + stId);
    const span = row?.querySelector('.td-st-title');
    if (row)  row.classList.toggle('td-st-done', !!is_done);
    tdStRefreshUI();
    fetch(tdStApiUrl(), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_csrf: CSRF, action: 'toggle', task_id: TID, subtask_id: stId, is_done})
    }).then(r => r.json()).then(r => {
        if (!r.ok) {
            cb.checked = !cb.checked;
            if (row) row.classList.toggle('td-st-done', !is_done);
            tdStRefreshUI();
            alert(r.error);
        }
    });
};

window.tdStAdd = function() {
    const inp   = document.getElementById('td-st-input');
    if (!inp) return;
    const title = inp.value.trim();
    if (!title) { inp.focus(); return; }
    const btn   = inp.nextElementSibling;
    inp.disabled = true;
    if (btn) btn.disabled = true;
    fetch(tdStApiUrl(), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_csrf: CSRF, action: 'add', task_id: TID, title})
    }).then(r => r.json()).then(r => {
        inp.disabled = false;
        if (btn) btn.disabled = false;
        if (r.ok) {
            const list = document.getElementById('td-st-list');
            if (list) list.appendChild(tdStBuildRow(r.data));
            inp.value = '';
            tdStRefreshUI();
            srAnnounce('Podzadanie dodane.');
        } else alert(r.error);
        inp.focus();
    });
};

window.tdStDelete = function(stId) {
    if (!confirm('Usunąć podzadanie?')) return;
    fetch(tdStApiUrl(), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_csrf: CSRF, action: 'delete', task_id: TID, subtask_id: stId})
    }).then(r => r.json()).then(r => {
        if (r.ok) { document.getElementById('strow-' + stId)?.remove(); tdStRefreshUI(); srAnnounce('Podzadanie usunięte.'); }
        else alert(r.error);
    });
};

window.tdStStartEdit = function(span, stId) {
    if (span.querySelector('input')) return;
    const old = span.textContent.trim();
    const inp = document.createElement('input');
    inp.type  = 'text';
    inp.value = old;
    inp.className = 'form-control form-control-sm py-0 px-1';
    inp.style.cssText = 'font-size:.84rem;height:auto;border-radius:3px';
    inp.setAttribute('aria-label', 'Edytuj podzadanie');

    function commit() {
        const val = inp.value.trim() || old;
        span.textContent = val;
        if (val !== old) {
            fetch(tdStApiUrl(), {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({_csrf: CSRF, action: 'rename', task_id: TID, subtask_id: stId, title: val})
            }).then(r => r.json()).then(r => {
                if (!r.ok) { span.textContent = old; alert(r.error); }
            });
        }
    }

    inp.addEventListener('blur', commit);
    inp.addEventListener('keydown', function(e) {
        if (e.key === 'Enter')  { e.preventDefault(); inp.blur(); }
        if (e.key === 'Escape') { inp.value = old; inp.blur(); }
    });

    span.textContent = '';
    span.appendChild(inp);
    inp.focus();
    inp.select();
};

</script>
