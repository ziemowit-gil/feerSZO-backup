/**
 * assets/js/tasks-settings-workspaces.js
 * Logika tasks/settings/workspaces.php — wydzielona z kilku inline <script>:
 * podgląd ikony, modal kolumny, sortowanie kolumn, modal usuwania obszaru,
 * dodawanie folderów SharePoint w modalu tworzenia obszaru, przypisywanie
 * zespołów do obszaru (przez tasks/api/team.php).
 * Oczekuje window.TSK_WORKSPACES = {csrf, base} i globalnego Sortable
 * (załadowany w tasks/includes/header_tasks.php).
 */
(function () {
    // ── Podgląd ikony (zakładka Ustawienia) ──────────────────────────────────
    const iconInp = document.getElementById('ws-icon-input');
    const iconPrv = document.getElementById('ws-icon-preview');
    if (iconInp && iconPrv) iconInp.addEventListener('input', () => iconPrv.className = 'bi bi-' + iconInp.value.trim());

    // ── Modal: dodaj folder SharePoint (przy tworzeniu obszaru) ──────────────
    document.getElementById('wsAddFolder')?.addEventListener('click', function () {
        const list = document.getElementById('wsFolderList');
        const row  = document.createElement('div');
        row.className = 'd-flex gap-1 align-items-center';
        row.innerHTML = `
    <input type="text" name="folders[]" class="form-control form-control-sm flex-grow-1"
           placeholder="Nazwa folderu" maxlength="120" style="font-size:.83rem">
    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 ws-rm-folder" title="Usuń">
      <i class="bi bi-x-lg" style="font-size:.75rem"></i>
    </button>`;
        row.querySelector('.ws-rm-folder').addEventListener('click', () => row.remove());
        list.appendChild(row);
        row.querySelector('input').focus();
    });

    // ── Modal: Kolumna ────────────────────────────────────────────────────────
    window.openListModal = function (id, wsId, name = '', color = '#e2e8f0', done = 0, wip = null) {
        document.getElementById('lm-ws-id').value   = wsId;
        document.getElementById('lm-list-id').value = id;
        document.getElementById('lm-name').value    = name;
        document.getElementById('lm-color').value   = color || '#e2e8f0';
        document.getElementById('lm-done').checked  = !!done;
        document.getElementById('lm-wip').value     = wip || '';
        document.getElementById('lm-title').textContent = id ? 'Edytuj kolumnę' : 'Nowa kolumna';
        new bootstrap.Modal(document.getElementById('listModal')).show();
    };

    window.confirmDelete = function (cnt) {
        if (cnt > 0) { alert('Kolumna zawiera ' + cnt + ' zadań. Przenieś je najpierw.'); return false; }
        return confirm('Usunąć tę kolumnę?');
    };

    // ── Sortowanie kolumn ─────────────────────────────────────────────────────
    const sortEl = document.getElementById('lists-sortable');
    if (sortEl && typeof Sortable !== 'undefined') {
        Sortable.create(sortEl, {
            animation: 150, handle: '.bi-grip-vertical',
            onEnd: function () {
                const ids = Array.from(sortEl.querySelectorAll('[data-list-id]')).map(e => e.dataset.listId).join(',');
                document.getElementById('lists-order-input').value = ids;
                document.getElementById('save-order-btn').classList.remove('d-none');
            }
        });
    }

    // ── Modal: usuwanie obszaru ───────────────────────────────────────────────
    window.prepareDeleteWs = function (name, wsId, tasks) {
        document.getElementById('del-ws-id').value = wsId;
        document.getElementById('del-ws-name').textContent = name;
        document.getElementById('del-ws-chk').checked = false;
        const warn = document.getElementById('del-ws-warn');
        const cnt  = document.getElementById('del-ws-cnt');
        if (tasks > 0) { cnt.textContent = tasks; warn.classList.remove('d-none'); }
        else warn.classList.add('d-none');
    };

    // ── Zespoły przypisane do obszaru ─────────────────────────────────────────
    const CSRF = window.TSK_WORKSPACES.csrf;
    const BASE = window.TSK_WORKSPACES.base;

    function teamApi(action, extra) {
        return fetch(BASE + '/tasks/api/team.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify(Object.assign({_csrf: CSRF, action: action}, extra || {}))
        }).then(r => r.json());
    }

    window.wsLinkTeam = function (wsId) {
        const sel  = document.getElementById('ws-link-team-select');
        const role = document.getElementById('ws-link-team-role').value;
        if (!sel || !sel.value) return;
        teamApi('link_workspace', {workspace_id: wsId, team_id: parseInt(sel.value, 10), role: role})
            .then(r => { if (r.ok) window.location.reload(); else alert(r.error || 'Błąd przypisania zespołu.'); });
    };

    window.wsUnlinkTeam = function (teamId) {
        const wsId = parseInt(document.querySelector('input[name="ws_id"]')?.value || '0', 10);
        if (!confirm('Odpiąć ten zespół od obszaru? Jego członkowie stracą dostęp nadany przez zespół (chyba że są dodani też pojedynczo).')) return;
        teamApi('unlink_workspace', {workspace_id: wsId, team_id: teamId})
            .then(r => { if (r.ok) window.location.reload(); else alert(r.error || 'Błąd.'); });
    };
})();
