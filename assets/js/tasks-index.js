/**
 * assets/js/tasks-index.js
 * Logika strony tasks/index.php (lista/kanban zadań) — wydzielona z inline
 * <script> w index.php. Oczekuje globalnego window.TSK_INDEX = {csrf, wsId,
 * canEdit, base, wsName} ustawionego przez index.php przed załadowaniem tego pliku.
 */
(function () {
    const CSRF     = window.TSK_INDEX.csrf;
    const WS_ID    = window.TSK_INDEX.wsId;
    const CAN_EDIT = window.TSK_INDEX.canEdit;
    const BASE     = window.TSK_INDEX.base;
    const WS_NAME  = window.TSK_INDEX.wsName;

    /* SR announce */
    function tkAnnounce(msg) {
        const el = document.getElementById('tk-sr');
        if (!el) return;
        el.textContent = '';
        setTimeout(() => { el.textContent = msg; }, 50);
    }
    window.tkAnnounce = tkAnnounce;

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* Otwórz offcanvas z detalami */
    window.openTask = function (taskId) {
        const body = document.getElementById('taskOffcanvasBody');
        body.innerHTML = '<div class="text-center py-5 text-muted">'
            + '<div class="spinner-border spinner-border-sm" role="status">'
            + '<span class="visually-hidden">Ładowanie…</span></div>'
            + '<div class="mt-2 small">Ładowanie…</div></div>';
        bootstrap.Offcanvas.getOrCreateInstance(
            document.getElementById('taskOffcanvas')
        ).show();
        fetch(BASE + '/tasks/detail.php?id=' + taskId + '&in_tasks=1')
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(html => {
                body.innerHTML = '';
                body.appendChild(document.createRange().createContextualFragment(html));
            })
            .catch(err => {
                body.innerHTML = '<div class="alert alert-danger m-3">Błąd ładowania: '
                    + escHtml(String(err)) + '</div>';
            });
    };

    /* Głęboki link ?task=ID (maile, przycisk „Otwórz zadanie" w podglądzie) —
       otwórz szczegóły od razu po wejściu i wyczyść parametr z adresu. */
    document.addEventListener('DOMContentLoaded', function() {
        const dlTask = new URLSearchParams(location.search).get('task');
        if (dlTask && /^\d+$/.test(dlTask)) {
            window.openTask(parseInt(dlTask, 10));
            const url = new URL(location.href);
            url.searchParams.delete('task');
            history.replaceState(null, '', url);
        }
    });

    /* Weź / Oddaj */
    window.claimTask = function (taskId, action, btn) {
        btn.disabled = true;
        btn.textContent = action === 'add' ? 'Biorę…' : 'Oddaję…';
        fetch(BASE + '/tasks/api/claim.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({_csrf: CSRF, task_id: taskId, action: action})
        })
        .then(r => r.json())
        .then(r => {
            if (r.ok) {
                tkAnnounce(action === 'add' ? 'Zadanie przypisane.' : 'Zadanie oddane.');
                window.tkAjaxLoad();
            } else {
                btn.disabled = false;
                btn.innerHTML = action === 'add'
                    ? '<i class="bi bi-hand-index me-1"></i>Weź'
                    : '<i class="bi bi-person-dash me-1"></i>Oddaj';
                alert(r.error || 'Błąd.');
            }
        })
        .catch(() => { btn.disabled = false; alert('Błąd połączenia.'); });
    };

    /* Czy jakiś filtr (poza domyślnym „wszystkie") jest aktywny */
    function tkHasActiveFilters(form) {
        const fd = new FormData(form);
        if ((fd.get('q') || '').trim() !== '') return true;
        if ((fd.get('status') || 'all') !== 'all') return true;
        for (const key of ['pri', 'list', 'tag', 'area', 'unit']) {
            const v = fd.get(key);
            if (v !== null && v !== '0') return true;
        }
        return false;
    }

    function tkUpdateClearButton() {
        const form = document.getElementById('tkFilterForm');
        const btn  = document.getElementById('tk-clear-filters');
        if (!form || !btn) return;
        btn.classList.toggle('d-none', !tkHasActiveFilters(form));
    }

    /* Licznik aktywnych filtrów w panelu "Filtry" (pri/list/tag/area/unit — bez q/status) */
    function tkUpdateFiltersBadge() {
        const form  = document.getElementById('tkFilterForm');
        const btn   = document.getElementById('tk-filters-btn');
        const badge = document.getElementById('tk-filters-badge');
        if (!form || !btn || !badge) return;
        const fd = new FormData(form);
        let n = 0;
        for (const key of ['pri', 'list', 'tag', 'area', 'unit']) {
            const v = fd.get(key);
            if (v !== null && v !== '0') n++;
        }
        badge.textContent = n;
        badge.classList.toggle('d-none', n === 0);
        btn.classList.toggle('has-active', n > 0);
        const icon = btn.querySelector('i');
        if (icon) icon.className = n > 0 ? 'bi bi-funnel-fill' : 'bi bi-funnel';
    }

    /* Resetuje wszystkie filtry naraz i przeładowuje listę */
    window.tkClearFilters = function () {
        const form = document.getElementById('tkFilterForm');
        if (!form) return;
        const q = document.getElementById('tk-q');
        if (q) q.value = '';
        ['tk-pri', 'tk-list', 'tk-tag', 'tk-area', 'tk-unit'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '0';
        });
        const hidden = document.getElementById('tk-status-hidden');
        if (hidden) hidden.value = 'all';
        document.querySelectorAll('.tk-pill[data-s]').forEach(p => {
            const active = p.dataset.s === 'all';
            p.classList.toggle('active', active);
            p.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        window.tkAjaxLoad();
    };

    document.addEventListener('DOMContentLoaded', function() { tkUpdateClearButton(); tkUpdateFiltersBadge(); });

    /* AJAX: załaduj region listy */
    function tkAjaxLoadImpl(e) {
        if (e && e.preventDefault) e.preventDefault();
        const form   = document.getElementById('tkFilterForm');
        if (!form) return;
        tkUpdateClearButton();
        tkUpdateFiltersBadge();
        const params = new URLSearchParams(new FormData(form));
        params.set('_ajax', '1');
        fetch(BASE + '/tasks/index.php?' + params.toString())
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    const region = document.getElementById('tkListRegion');
                    if (region) {
                        const tmp = document.createElement('div');
                        tmp.innerHTML = d.list_html;
                        const newRegion = tmp.querySelector('#tkListRegion') || tmp.firstElementChild;
                        if (newRegion) region.replaceWith(newRegion);
                        else region.innerHTML = d.list_html;
                    }
                    if (d.counts) updateCounts(d.counts);
                    tkAnnounce('Znaleziono ' + d.total + ' zadań.');
                }
            })
            .catch(() => {});
    }

    /* Aktualizuj liczniki na pillach statusu */
    function updateCounts(counts) {
        ['all','open','taken','done','mine'].forEach(k => {
            const el = document.getElementById('tk-cnt-' + k);
            if (el && counts[k] !== undefined) el.textContent = counts[k];
        });
    }

    /* Ustaw status pill + hidden input */
    window.tkSetStatus = function (val, btn) {
        const hidden = document.getElementById('tk-status-hidden');
        if (hidden) hidden.value = val;
        document.querySelectorAll('.tk-pill[data-s]').forEach(p => {
            const active = p.dataset.s === val;
            p.classList.toggle('active', active);
            p.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        window.tkAjaxLoad();
    };

    /* Wyszukiwanie z debounce 350ms → AJAX */
    let _searchTimer;
    document.addEventListener('DOMContentLoaded', function() {
        const qInput = document.getElementById('tk-q');
        if (qInput) {
            qInput.addEventListener('input', function() {
                clearTimeout(_searchTimer);
                _searchTimer = setTimeout(window.tkAjaxLoad, 350);
            });
        }
    });

    /* Inline zmiana statusu — toggle done/reopen */
    window.tkToggleDone = function (btn, taskId) {
        const isDone = btn.classList.contains('s-done');
        const action = isDone ? 'reopen' : 'complete';
        if (action === 'complete' && !confirm('Oznaczyć zadanie jako ukończone?')) return;
        fetch(BASE + '/tasks/api/task.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({_csrf: CSRF, action: action, id: taskId})
        }).then(r => r.json()).then(d => { if (d.ok) window.tkAjaxLoad(); else alert(d.error || 'Błąd.'); })
          .catch(() => alert('Błąd połączenia.'));
    };

    /* Inline zmiana priorytetu */
    window.tkSetPriority = function (taskId, pri) {
        fetch(BASE + '/tasks/api/task.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({_csrf: CSRF, action: 'update', id: taskId, priority: pri})
        }).then(r => r.json()).then(d => { if (d.ok) window.tkAjaxLoad(); else alert(d.error || 'Błąd.'); })
          .catch(() => alert('Błąd połączenia.'));
    };

    /* Dodaj zadanie — opcjonalny listId (z quick-add w kanbanie) */
    window.openAddModal = function (listId) {
        const el = document.getElementById('addTaskModal');
        if (!el) return;
        document.getElementById('at-title').value    = '';
        document.getElementById('at-desc').value     = '';
        document.getElementById('at-due').value      = '';
        document.getElementById('at-priority').value = '2';
        document.getElementById('at-error').classList.add('d-none');
        const listSel = document.getElementById('at-list');
        if (listSel && listId) listSel.value = String(listId);
        // Reset trybu przypisania → osoba
        const modePersonRadio = document.getElementById('at-mode-person');
        if (modePersonRadio) {
            modePersonRadio.checked = true;
            document.getElementById('at-person-panel')?.classList.remove('d-none');
            document.getElementById('at-unit-panel')?.classList.add('d-none');
        }
        // Reset wyboru osób
        atInitUserSelect();
        _atUserTs?.clear(true);
        // Reset unit select
        const unitSel = document.getElementById('at-unit');
        if (unitSel) unitSel.value = '0';
        bootstrap.Modal.getOrCreateInstance(el).show();
        setTimeout(() => document.getElementById('at-title').focus(), 350);
    };

    window.atToggleMode = function (mode) {
        const pp = document.getElementById('at-person-panel');
        const up = document.getElementById('at-unit-panel');
        if (mode === 'unit') {
            const selected = _atUserTs ? _atUserTs.getValue() : [];
            if (selected.length > 0) {
                if (!confirm('Przełączyć na przypisanie do jednostki?\nWybrane osoby zostaną odznaczone.')) {
                    document.getElementById('at-mode-person').checked = true;
                    return;
                }
                _atUserTs.clear(true);
            } else if (!confirm('Przypisać zadanie do jednostki organizacyjnej?\n(Osoby preferowane — tylko jeśli brak konkretnej osoby)')) {
                document.getElementById('at-mode-person').checked = true;
                return;
            }
            pp?.classList.add('d-none');
            up?.classList.remove('d-none');
        } else {
            pp?.classList.remove('d-none');
            up?.classList.add('d-none');
            const unitSel = document.getElementById('at-unit');
            if (unitSel) unitSel.value = '0';
        }
    };

    window.submitAddTask = function () {
        const titleEl = document.getElementById('at-title');
        const title   = titleEl.value.trim();
        if (!title) { titleEl.classList.add('is-invalid'); titleEl.focus(); return; }
        titleEl.classList.remove('is-invalid');

        const listId = parseInt(document.getElementById('at-list')?.value);
        if (!WS_ID || !listId) {
            const err = document.getElementById('at-error');
            err.textContent = !WS_ID ? 'Nie wybrano obszaru roboczego.' : 'Ten obszar nie ma kolumn — dodaj je w ustawieniach.';
            err.classList.remove('d-none');
            return;
        }

        const btn = document.getElementById('at-submit');
        btn.disabled    = true;
        btn.textContent = 'Dodawanie…';

        const assignMode = document.querySelector('input[name="at-assign-mode"]:checked')?.value || 'person';
        const assignees  = assignMode === 'person'
            ? (_atUserTs ? _atUserTs.getValue().map(v => parseInt(v)) : [])
            : [];
        const unitId     = assignMode === 'unit'
            ? (parseInt(document.getElementById('at-unit')?.value || '0') || null)
            : null;

        fetch(BASE + '/tasks/api/task.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                _csrf:        CSRF,
                action:       'create',
                title:        title,
                description:  document.getElementById('at-desc').value,
                due_date:     document.getElementById('at-due').value || null,
                priority:     parseInt(document.getElementById('at-priority').value),
                list_id:      parseInt(document.getElementById('at-list').value),
                area_id:      parseInt(document.getElementById('at-area')?.value || '0') || null,
                unit_id:      unitId,
                assignees:    assignees,
                workspace_id: WS_ID
            })
        })
        .then(r => r.json())
        .then(r => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj zadanie';
            if (r.ok) {
                bootstrap.Modal.getInstance(document.getElementById('addTaskModal')).hide();
                tkAnnounce('Zadanie dodane.');
                window.tkAjaxLoad();
            } else {
                const err = document.getElementById('at-error');
                err.textContent = r.error || 'Błąd zapisu.';
                err.classList.remove('d-none');
            }
        })
        .catch(() => {
            btn.disabled = false;
            const err = document.getElementById('at-error');
            err.textContent = 'Błąd połączenia.';
            err.classList.remove('d-none');
        });
    };

    // ── Zbiorcze akcje ────────────────────────────────────────────────────────
    function bulkSelected() {
        return Array.from(document.querySelectorAll('.tk-row-select:checked')).map(el => parseInt(el.dataset.id));
    }

    function bulkUpdateBar() {
        const ids  = bulkSelected();
        const bar  = document.getElementById('tk-bulk-bar');
        const cnt  = document.getElementById('tk-bulk-count');
        const all  = document.getElementById('tk-check-all');
        const rows = document.querySelectorAll('.tk-row-select');
        if (bar)  bar.classList.toggle('visible', ids.length > 0);
        if (cnt)  cnt.textContent = ids.length + ' zaznaczon' + (ids.length === 1 ? 'e' : 'ych');
        if (all)  all.indeterminate = ids.length > 0 && ids.length < rows.length;
        if (all)  all.checked = ids.length === rows.length && rows.length > 0;
        document.querySelectorAll('#tk-tbody tr').forEach(tr => {
            const cb = tr.querySelector('.tk-row-select');
            tr.classList.toggle('selected', cb?.checked || false);
        });
    }

    window.bulkOnCheck = function () { bulkUpdateBar(); };

    window.bulkToggleAll = function (masterCb) {
        document.querySelectorAll('.tk-row-select').forEach(cb => { cb.checked = masterCb.checked; });
        bulkUpdateBar();
    };

    window.bulkClear = function () {
        document.querySelectorAll('.tk-row-select,.tk-check-all').forEach(cb => { cb.checked = false; });
        const all = document.getElementById('tk-check-all');
        if (all) { all.checked = false; all.indeterminate = false; }
        bulkUpdateBar();
    };

    window.bulkAction = function (action, extra = {}) {
        const ids = bulkSelected();
        if (!ids.length) return;

        const labels = { assign_me:'Przypisz do mnie', unassign_me:'Odpnij mnie',
                         priority:'Zmień priorytet', move:'Przenieś', complete:'Zakończ', delete:'Usuń' };

        if (action === 'delete') {
            if (!confirm(`Usunąć ${ids.length} zadań? Tej operacji nie można cofnąć.`)) return;
        }
        if (action === 'complete') {
            if (!confirm(`Oznaczyć ${ids.length} zadań jako ukończone?`)) return;
        }

        fetch(BASE + '/tasks/api/bulk.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ _csrf: CSRF, action, task_ids: ids, ...extra })
        })
        .then(r => r.json())
        .then(r => {
            if (r.ok) {
                tkAnnounce((labels[action] || action) + ': ' + (r.data?.affected || ids.length) + ' zadań.');
                window.bulkClear();
                window.tkAjaxLoad();
            } else {
                alert(r.error || 'Błąd zbiorczej akcji.');
            }
        })
        .catch(() => alert('Błąd połączenia.'));
    };

    document.addEventListener('DOMContentLoaded', function () {
        const atTitle = document.getElementById('at-title');
        if (atTitle) atTitle.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); window.submitAddTask(); }
        });
    });

    // ── Kanban drag & drop (SortableJS) ──────────────────────────────────────
    let _tkDragActive = false;

    function tkInitKanban() {
        const bodies = document.querySelectorAll('.tk-kanban-body');
        if (!bodies.length || typeof Sortable === 'undefined') return;

        bodies.forEach(col => {
            Sortable.create(col, {
                group:     'tk-kanban',
                animation: 150,
                ghostClass:'tk-card-ghost',
                dragClass: 'tk-card-dragging',
                handle:    '.tk-card-inner',
                onStart: function() { _tkDragActive = true; },
                onEnd: function(evt) {
                    _tkDragActive = false;
                    const taskId     = parseInt(evt.item.dataset.taskId);
                    const newListId  = parseInt(evt.to.dataset.listId);
                    const orderedIds = Array.from(evt.to.querySelectorAll('[data-task-id]'))
                                           .map(c => parseInt(c.dataset.taskId));

                    if (!taskId || !newListId) return;

                    fetch(BASE + '/tasks/api/move.php', {
                        method:  'POST',
                        headers: {'Content-Type': 'application/json'},
                        body:    JSON.stringify({
                            _csrf:       CSRF,
                            task_id:     taskId,
                            list_id:     newListId,
                            position:    evt.newIndex + 1,
                            ordered_ids: orderedIds
                        })
                    })
                    .then(r => r.json())
                    .then(r => {
                        if (!r.ok) {
                            tkAnnounce('Błąd przenoszenia: ' + (r.error || ''));
                            window.tkAjaxLoad(); // cofnij wizualnie
                        } else {
                            // Odśwież liczniki kolumn
                            document.querySelectorAll('.tk-kanban-col').forEach(col => {
                                const cnt = col.querySelectorAll('.tk-card').length;
                                const badge = col.querySelector('.tk-kanban-hdr .badge');
                                if (badge) badge.textContent = cnt;
                            });
                            // Usuń hint "brak zadań" jeśli kolumna niepusta
                            evt.to.querySelector('.tk-card-drop-hint')?.remove();
                            // Dodaj hint jeśli kolumna źródłowa pusta
                            if (!evt.from.querySelector('[data-task-id]')) {
                                const hint = document.createElement('div');
                                hint.className = 'tk-card-drop-hint';
                                hint.textContent = 'Przeciągnij tu lub kliknij +';
                                evt.from.appendChild(hint);
                            }
                        }
                    })
                    .catch(() => { tkAnnounce('Błąd połączenia.'); window.tkAjaxLoad(); });
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', tkInitKanban);

    // Po przeładowaniu AJAX — reinicjuj kanban
    window.tkAjaxLoad = function (e) {
        tkAjaxLoadImpl(e);
        // Krótkie opóźnienie — daj czas na podmianę DOM
        setTimeout(tkInitKanban, 250);
    };

    // ── Dynamiczne odświeżanie co 30 sekund ────────────────────────────────────
    // Ta sama zasada co w tasks/inbox.php: pauza gdy karta w tle lub trwa
    // przeciąganie/otwarty modal, natychmiastowe odświeżenie po powrocie karty.
    let _tkPollTimer  = null;
    let _tkPollPaused = false;

    function tkStartPolling() {
        clearInterval(_tkPollTimer);
        _tkPollTimer = setInterval(tkPollTick, 30000);
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                _tkPollPaused = true;
            } else {
                _tkPollPaused = false;
                tkPollTick();
            }
        });
    }

    function tkPollTick() {
        if (_tkPollPaused || _tkDragActive) return;
        // Nie przerywaj, gdy otwarty jest modal (np. tworzenie zadania) lub offcanvas ze szczegółami.
        if (document.querySelector('.modal.show, .offcanvas.show')) return;
        window.tkAjaxLoad();
    }

    document.addEventListener('DOMContentLoaded', tkStartPolling);

    // ── Wybór osoby w modalu tworzenia (wyszukiwarka zamiast siatki chipów) ───
    let _atUserTs = null;

    function atInitUserSelect() {
        const el = document.getElementById('at-user-select');
        if (!el || typeof TomSelect === 'undefined' || _atUserTs) return;
        _atUserTs = new TomSelect(el, {
            plugins:     ['remove_button'],
            placeholder: 'Wyszukaj i dodaj osobę…',
        });
    }

    document.addEventListener('DOMContentLoaded', atInitUserSelect);

    // ── Modal powiadomień ─────────────────────────────────────────────────────
    window.openNotifyModal = function () {
        bootstrap.Modal.getOrCreateInstance(
            document.getElementById('notifyPrefModal')
        ).show();
    };

    window.saveNotifyPrefs = function () {
        const btn = document.getElementById('np-save');
        btn.disabled = true;
        fetch(BASE + '/tasks/api/notify_prefs.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                _csrf:        CSRF,
                workspace_id: WS_ID,
                notify_email: document.getElementById('np-email')?.checked ? 1 : 0,
                notify_sms:   document.getElementById('np-sms')?.checked   ? 1 : 0,
                notify_push:  document.getElementById('np-push')?.checked  ? 1 : 0,
            })
        })
        .then(r => r.json())
        .then(r => {
            btn.disabled = false;
            if (r.ok) {
                bootstrap.Modal.getInstance(document.getElementById('notifyPrefModal')).hide();
                // Aktualizuj ikonę dzwonka
                const bellBtn = document.querySelector('button[onclick="openNotifyModal()"] i');
                const anyOn = r.notify_email || r.notify_sms || r.notify_push;
                if (bellBtn) {
                    bellBtn.className = 'bi bi-bell' + (anyOn ? '-fill text-warning' : '');
                }
            } else {
                alert('Błąd: ' + (r.error || 'Nie udało się zapisać.'));
            }
        })
        .catch(() => { btn.disabled = false; alert('Błąd połączenia.'); });
    };

    // ── Modal: Usuń obszar ────────────────────────────────────────────────────
    let _delWsPrevFocus = null;

    window.openDeleteWsModal = function() {
        const modal    = document.getElementById('del-ws-modal');
        const backdrop = document.getElementById('del-ws-backdrop');
        const inp      = document.getElementById('del-ws-confirm');
        const btn      = document.getElementById('del-ws-submit-btn');
        const err      = document.getElementById('del-ws-err');
        if (!modal) return;

        inp.value      = '';
        btn.disabled   = true;
        err.classList.add('d-none');

        _delWsPrevFocus       = document.activeElement;
        backdrop.style.display = 'block';
        modal.style.display    = 'block';
        backdrop.removeAttribute('aria-hidden');

        requestAnimationFrame(() => inp.focus());
        modal.addEventListener('keydown', _delWsTrapFocus);
    };

    window.closeDeleteWsModal = function() {
        document.getElementById('del-ws-modal').style.display    = 'none';
        document.getElementById('del-ws-backdrop').style.display = 'none';
        document.getElementById('del-ws-backdrop').setAttribute('aria-hidden', 'true');
        document.getElementById('del-ws-modal').removeEventListener('keydown', _delWsTrapFocus);
        (_delWsPrevFocus || document.querySelector('[onclick="openDeleteWsModal()"]'))?.focus();
        _delWsPrevFocus = null;
    };

    function _delWsTrapFocus(e) {
        if (e.key === 'Escape') { e.preventDefault(); window.closeDeleteWsModal(); return; }
        if (e.key !== 'Tab') return;
        const modal    = document.getElementById('del-ws-modal');
        const focusable = Array.from(modal.querySelectorAll(
            'button:not([disabled]),input,[tabindex]:not([tabindex="-1"])'
        )).filter(el => el.offsetParent !== null);
        if (!focusable.length) return;
        const first = focusable[0], last = focusable[focusable.length - 1];
        if (e.shiftKey) { if (document.activeElement === first) { e.preventDefault(); last.focus(); } }
        else            { if (document.activeElement === last)  { e.preventDefault(); first.focus(); } }
    }

    window.delWsCheckConfirm = function(inp) {
        const btn = document.getElementById('del-ws-submit-btn');
        btn.disabled = inp.value.trim() !== WS_NAME;
        inp.style.borderColor = '';
    };

    window.delWsSubmit = function() {
        const inp = document.getElementById('del-ws-confirm');
        const btn = document.getElementById('del-ws-submit-btn');
        const err = document.getElementById('del-ws-err');

        if (inp.value.trim() !== WS_NAME) {
            inp.style.borderColor = '#dc2626';
            inp.focus();
            return;
        }

        btn.disabled  = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Usuwam…';
        err.classList.add('d-none');

        fetch(BASE + '/tasks/api/delete_workspace.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ _csrf: CSRF, workspace_id: WS_ID })
        })
        .then(r => r.json())
        .then(r => {
            if (r.ok) {
                window.closeDeleteWsModal();
                window.location.href = BASE + '/tasks/dashboard.php';
            } else {
                btn.disabled  = false;
                btn.innerHTML = '<i class="bi bi-trash3 me-1"></i>Usuń bezpowrotnie';
                err.textContent = r.error || 'Błąd usuwania.';
                err.classList.remove('d-none');
            }
        })
        .catch(() => {
            btn.disabled  = false;
            btn.innerHTML = '<i class="bi bi-trash3 me-1"></i>Usuń bezpowrotnie';
            err.textContent = 'Błąd połączenia.';
            err.classList.remove('d-none');
        });
    };
})();
