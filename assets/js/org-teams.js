/**
 * assets/js/org-teams.js — Zespoły w Strukturze Organizacyjnej (org/teams.php, org/team.php, sekcja na karcie umowy).
 * Wymaga window.ORG_TEAMS = { csrf, api }.
 *
 * <form data-team-form="akcja">   — pola formularza idą do org/team_api.php; błąd pola → #<id formularza>-err-<pole>
 *                                    (aria-invalid + aria-describedby), komunikat ogólny → [data-team-live] (role=status).
 *   pole contract_key="typ:id"    — rozbijane na contract_type + contract_id.
 *   data-success="reload|url"     — po powodzeniu przeładuj (domyślnie) albo przejdź pod data.url.
 * <button data-team-action="akcja" data-*>  — akcja jednym kliknięciem (data-confirm = pytanie przed wykonaniem).
 * <select data-person-contracts="#selectUmowy" data-team-id> — po wyborze osoby wczytuje jej aktywne umowy.
 */
(function () {
    'use strict';
    const CFG = window.ORG_TEAMS || {};

    function live(msg, isError) {
        document.querySelectorAll('[data-team-live]').forEach(el => {
            el.textContent = '';
            el.classList.toggle('text-danger', !!isError);
            el.classList.toggle('text-success', !isError);
            window.setTimeout(() => { el.textContent = msg; }, 30);
        });
    }

    function api(action, data) {
        return fetch(CFG.api, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf },
            body: JSON.stringify(Object.assign({ _csrf: CFG.csrf, action: action }, data || {})),
        }).then(r => r.json().catch(() => ({ ok: false, error: 'Błąd serwera (' + r.status + ').' })))
          .catch(() => ({ ok: false, error: 'Brak połączenia z serwerem.' }));
    }

    function formData(form) {
        const out = {};
        new FormData(form).forEach((v, k) => { out[k] = typeof v === 'string' ? v : ''; });
        form.querySelectorAll('input[type=checkbox][name]').forEach(cb => { out[cb.name] = cb.checked ? 1 : 0; });
        if ('contract_key' in out) {
            const [t, i] = String(out.contract_key || '').split(':');
            out.contract_type = t || '';
            out.contract_id = i ? parseInt(i, 10) : 0;
            delete out.contract_key;
        }
        return out;
    }

    function clearErrors(form) {
        form.querySelectorAll('[aria-invalid="true"]').forEach(el => el.removeAttribute('aria-invalid'));
        form.querySelectorAll('.team-field-error').forEach(el => { el.textContent = ''; el.hidden = true; });
        const g = form.querySelector('.team-form-error');
        if (g) { g.textContent = ''; g.hidden = true; }
    }

    function showErrors(form, res) {
        const errs = res.errors || {};
        let first = null;
        Object.keys(errs).forEach(name => {
            const field = form.querySelector('[name="' + name + '"]');
            const box = document.getElementById(form.id + '-err-' + name);
            if (box) { box.textContent = errs[name]; box.hidden = false; }
            if (field) {
                field.setAttribute('aria-invalid', 'true');
                if (box) {
                    const ids = new Set((field.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
                    ids.add(box.id);
                    field.setAttribute('aria-describedby', Array.from(ids).join(' '));
                }
                first = first || field;
            }
        });
        const g = form.querySelector('.team-form-error');
        if (g && (!first || !Object.keys(errs).length)) { g.textContent = res.error || 'Nie udało się zapisać.'; g.hidden = false; }
        live(res.error || 'Nie udało się zapisać.', true);
        (first || g || form).focus && (first || g || form).focus();
    }

    function after(res, el) {
        const mode = el.getAttribute('data-success') || 'reload';
        if (mode === 'url' && res.data && res.data.url) { window.location.href = res.data.url; return; }
        window.location.reload();
    }

    function handleLinkSuggestion(form, payload, res) {
        const p = res.data && res.data.suggest_person;
        if (!p) return Promise.resolve(res);
        if (!window.confirm('Wykonawca umowy (' + p.name + ') nie należy do zespołu. Dopisać go do składu?')) return Promise.resolve(res);
        return api('contract_link', Object.assign({}, payload, { add_person: 1 }));
    }

    document.addEventListener('submit', function (ev) {
        const form = ev.target.closest('form[data-team-form]');
        if (!form) return;
        ev.preventDefault();
        clearErrors(form);
        const action = form.getAttribute('data-team-form');
        const payload = formData(form);
        const btn = form.querySelector('[type=submit]');
        if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }
        api(action, payload)
            .then(res => (res.ok && action === 'contract_link') ? handleLinkSuggestion(form, payload, res) : res)
            .then(res => {
                if (btn) { btn.disabled = false; btn.removeAttribute('aria-busy'); }
                if (!res.ok) { showErrors(form, res); return; }
                live('Zapisano.', false);
                after(res, form);
            });
    });

    document.addEventListener('click', function (ev) {
        const btn = ev.target.closest('[data-team-action]');
        if (!btn) return;
        ev.preventDefault();
        const q = btn.getAttribute('data-confirm');
        if (q && !window.confirm(q)) return;
        const data = {};
        Object.keys(btn.dataset).forEach(k => {
            if (k === 'teamAction' || k === 'confirm' || k === 'success') return;
            data[k.replace(/[A-Z]/g, m => '_' + m.toLowerCase())] = btn.dataset[k];
        });
        btn.disabled = true;
        api(btn.getAttribute('data-team-action'), data).then(res => {
            btn.disabled = false;
            if (!res.ok) { live(res.error || 'Nie udało się.', true); window.alert(res.error || 'Nie udało się.'); return; }
            live('Zapisano.', false);
            after(res, btn);
        });
    });

    // Umowy osoby jako podpowiedź podstawy udziału.
    document.addEventListener('change', function (ev) {
        const sel = ev.target.closest('select[data-person-contracts]');
        if (!sel) return;
        const target = document.querySelector(sel.getAttribute('data-person-contracts'));
        if (!target) return;
        const keep = target.getAttribute('data-current') || '';
        target.innerHTML = '<option value="">— bez umowy —</option>';
        const hint = document.getElementById(target.id + '-hint');
        if (!sel.value) { if (hint) hint.textContent = ''; return; }
        target.disabled = true;
        api('person_contracts', { team_id: sel.getAttribute('data-team-id'), user_id: sel.value }).then(res => {
            target.disabled = false;
            const list = (res.ok && res.data && res.data.contracts) || [];
            list.forEach(c => {
                const o = document.createElement('option');
                o.value = c.contract_type + ':' + c.contract_id;
                o.textContent = c.label + (c.health && c.health.code === 'expiring' ? ' — ' + c.health.label.toLowerCase() : '');
                if (o.value === keep) o.selected = true;
                target.appendChild(o);
            });
            if (list.length === 1 && !keep) target.value = list[0].contract_type + ':' + list[0].contract_id;
            if (hint) hint.textContent = list.length ? 'Aktywne umowy tej osoby: ' + list.length + '.' : 'Ta osoba nie ma aktywnej umowy.';
        });
    });

    // Okno edycji osoby / zastępstwa: wypełnienie pól z data-fill (JSON) przycisku otwierającego.
    document.addEventListener('show.bs.modal', function (ev) {
        const trigger = ev.relatedTarget;
        const modal = ev.target;
        if (!trigger || !trigger.hasAttribute('data-fill')) return;
        let data = {};
        try { data = JSON.parse(trigger.getAttribute('data-fill')); } catch (e) {}
        modal.querySelectorAll('form').forEach(clearErrors);
        Object.keys(data).forEach(k => {
            modal.querySelectorAll('[name="' + k + '"]').forEach(f => {
                if (f.type === 'checkbox') f.checked = !!data[k]; else f.value = data[k] == null ? '' : data[k];
                if (f.tagName === 'SELECT' && k === 'contract_key') f.setAttribute('data-current', data[k] || '');
            });
            modal.querySelectorAll('[data-fill-text="' + k + '"]').forEach(t => { t.textContent = data[k] || ''; });
        });
        const ps = modal.querySelector('select[data-person-contracts]');
        if (ps && ps.value) ps.dispatchEvent(new Event('change', { bubbles: true }));
    });

    // Typ zespołu: pola okresu tylko dla zespołu projektowego.
    function syncType(form) {
        const t = form.querySelector('[name=type]');
        const box = form.querySelector('[data-project-only]');
        if (t && box) box.hidden = t.value !== 'project';
    }
    document.addEventListener('change', ev => { const f = ev.target.closest('form[data-team-form]'); if (f && ev.target.name === 'type') syncType(f); });
    document.addEventListener('show.bs.modal', ev => ev.target.querySelectorAll('form[data-team-form]').forEach(syncType));
    document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('form[data-team-form]').forEach(syncType));
})();
