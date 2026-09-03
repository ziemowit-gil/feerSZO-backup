/**
 * assets/js/tasks-settings-teams.js
 * Logika tasks/settings/teams.php — CRUD zespołów i zarządzanie członkami,
 * przez tasks/api/team.php. Oczekuje window.TSK_TEAMS = {csrf, base}.
 */
(function () {
    const CSRF = window.TSK_TEAMS.csrf;
    const BASE = window.TSK_TEAMS.base;

    function api(action, extra) {
        return fetch(BASE + '/tasks/api/team.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify(Object.assign({_csrf: CSRF, action: action}, extra || {}))
        }).then(r => r.json());
    }

    function reload() { window.location.reload(); }

    // ── Modal: dodaj/edytuj zespół ───────────────────────────────────────────
    let _teamModal = null;
    function getTeamModal() {
        if (!_teamModal) _teamModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('tmTeamModal'));
        return _teamModal;
    }

    window.tmOpenTeamModal = function (team) {
        document.getElementById('tm-team-error').classList.add('d-none');
        document.getElementById('tm-team-id').value = team ? team.id : 0;
        document.getElementById('tm-team-modal-title').textContent = team ? 'Edytuj zespół' : 'Nowy zespół';
        document.getElementById('tm-name').value  = team ? team.name : '';
        document.getElementById('tm-desc').value  = team ? team.description : '';
        document.getElementById('tm-color').value = team ? team.color : '#2563eb';
        document.getElementById('tm-icon').value  = team ? (team.icon || '').replace(/^bi-/, '') : 'people-fill';
        getTeamModal().show();
        setTimeout(() => document.getElementById('tm-name').focus(), 300);
    };

    window.tmSaveTeam = function () {
        const name = document.getElementById('tm-name').value.trim();
        const err  = document.getElementById('tm-team-error');
        if (!name) {
            err.textContent = 'Nazwa zespołu jest wymagana.';
            err.classList.remove('d-none');
            return;
        }
        const teamId = parseInt(document.getElementById('tm-team-id').value, 10) || 0;
        const btn = document.getElementById('tm-team-save');
        btn.disabled = true;
        api(teamId ? 'update' : 'create', {
            team_id:     teamId,
            name:        name,
            description: document.getElementById('tm-desc').value,
            color:       document.getElementById('tm-color').value,
            icon:        document.getElementById('tm-icon').value,
        }).then(r => {
            btn.disabled = false;
            if (r.ok) { reload(); }
            else { err.textContent = r.error || 'Błąd zapisu.'; err.classList.remove('d-none'); }
        }).catch(() => { btn.disabled = false; err.textContent = 'Błąd połączenia.'; err.classList.remove('d-none'); });
    };

    window.tmToggleTeam = function (teamId) {
        api('toggle', {team_id: teamId}).then(r => { if (r.ok) reload(); else alert(r.error || 'Błąd.'); });
    };

    window.tmDeleteTeam = function (teamId, name, wsCount) {
        let msg = `Usunąć zespół „${name}"? Tej operacji nie można cofnąć.`;
        if (wsCount > 0) msg += `\n\nZespół jest przypisany do ${wsCount} obszar(ów) — dostęp nadany przez ten zespół zniknie.`;
        if (!confirm(msg)) return;
        api('delete', {team_id: teamId}).then(r => { if (r.ok) reload(); else alert(r.error || 'Błąd.'); });
    };

    // ── Modal: członkowie ────────────────────────────────────────────────────
    let _membersModal = null;
    let _tsAddUser = null;
    function getMembersModal() {
        if (!_membersModal) _membersModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('tmMembersModal'));
        return _membersModal;
    }

    function renderMembersList(teamId, members) {
        const list = document.getElementById('tm-members-list');
        if (!members.length) {
            list.innerHTML = '<li class="list-group-item text-muted small">Brak członków.</li>';
            return;
        }
        list.innerHTML = members.map(m => (
            '<li class="list-group-item d-flex align-items-center justify-content-between py-2">'
            + '<span>' + m.name.replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])) + '</span>'
            + '<button type="button" class="btn btn-sm btn-outline-danger" onclick="tmRemoveMember(' + teamId + ',' + m.id + ',this)">'
            + '<i class="bi bi-x-lg"></i></button></li>'
        )).join('');
    }

    window.tmOpenMembersModal = function (team) {
        document.getElementById('tm-members-team-id').value = team.id;
        document.getElementById('tm-members-team-name').textContent = team.name;
        renderMembersList(team.id, team.members);
        getMembersModal().show();
        const sel = document.getElementById('tm-add-user-select');
        if (typeof TomSelect !== 'undefined') {
            if (_tsAddUser) { _tsAddUser.destroy(); _tsAddUser = null; }
            _tsAddUser = new TomSelect(sel, { placeholder: 'Wyszukaj osobę…' });
            _tsAddUser.on('item_add', function (userId) {
                const teamId = parseInt(document.getElementById('tm-members-team-id').value, 10);
                api('add_member', {team_id: teamId, user_id: parseInt(userId, 10)}).then(r => {
                    if (r.ok) {
                        const name = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : _tsAddUser.options[userId]?.text;
                        const chosen = _tsAddUser.options[userId];
                        const list = document.getElementById('tm-members-list');
                        if (list.querySelector('.text-muted')) list.innerHTML = '';
                        const li = document.createElement('li');
                        li.className = 'list-group-item d-flex align-items-center justify-content-between py-2';
                        li.innerHTML = '<span></span><button type="button" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>';
                        li.querySelector('span').textContent = chosen ? chosen.text : '';
                        li.querySelector('button').onclick = function () { window.tmRemoveMember(teamId, parseInt(userId, 10), li.querySelector('button')); };
                        list.appendChild(li);
                        _tsAddUser.clear(true);
                        _tsAddUser.removeOption(userId);
                    } else {
                        alert(r.error || 'Błąd dodawania.');
                        _tsAddUser.clear(true);
                    }
                });
            });
        }
    };

    window.tmRemoveMember = function (teamId, userId, btn) {
        api('remove_member', {team_id: teamId, user_id: userId}).then(r => {
            if (r.ok) {
                const li = btn.closest('li');
                if (li) li.remove();
                if (_tsAddUser) {
                    const name = li ? li.querySelector('span').textContent : '';
                    _tsAddUser.addOption({value: String(userId), text: name});
                }
                if (!document.getElementById('tm-members-list').children.length) {
                    document.getElementById('tm-members-list').innerHTML = '<li class="list-group-item text-muted small">Brak członków.</li>';
                }
            } else {
                alert(r.error || 'Błąd.');
            }
        });
    };
})();
