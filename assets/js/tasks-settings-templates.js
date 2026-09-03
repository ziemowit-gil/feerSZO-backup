/**
 * assets/js/tasks-settings-templates.js
 * Logika tasks/settings/templates.php — CRUD szablonów zadań i ich pozycji,
 * przez tasks/api/template.php. Oczekuje window.TSK_TEMPLATES = {csrf, base}.
 */
(function () {
    const CSRF = window.TSK_TEMPLATES.csrf;
    const BASE = window.TSK_TEMPLATES.base;

    function api(action, extra) {
        return fetch(BASE + '/tasks/api/template.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify(Object.assign({_csrf: CSRF, action: action}, extra || {}))
        }).then(r => r.json());
    }

    function reload() { window.location.reload(); }

    let _templateModal = null;
    function getTemplateModal() {
        if (!_templateModal) _templateModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('tpTemplateModal'));
        return _templateModal;
    }

    window.tpOpenTemplateModal = function (t) {
        document.getElementById('tp-template-error').classList.add('d-none');
        document.getElementById('tp-template-id').value = t ? t.id : 0;
        document.getElementById('tp-template-modal-title').textContent = t ? 'Edytuj szablon' : 'Nowy szablon';
        document.getElementById('tp-name').value = t ? t.name : '';
        document.getElementById('tp-desc').value = t ? t.description : '';
        getTemplateModal().show();
        setTimeout(() => document.getElementById('tp-name').focus(), 300);
    };

    window.tpSaveTemplate = function () {
        const name = document.getElementById('tp-name').value.trim();
        const err  = document.getElementById('tp-template-error');
        if (!name) {
            err.textContent = 'Nazwa szablonu jest wymagana.';
            err.classList.remove('d-none');
            return;
        }
        const templateId = parseInt(document.getElementById('tp-template-id').value, 10) || 0;
        const btn = document.getElementById('tp-template-save');
        btn.disabled = true;
        api(templateId ? 'update' : 'create', {
            template_id: templateId,
            name:        name,
            description: document.getElementById('tp-desc').value,
        }).then(r => {
            btn.disabled = false;
            if (r.ok) { reload(); }
            else { err.textContent = r.error || 'Błąd zapisu.'; err.classList.remove('d-none'); }
        }).catch(() => { btn.disabled = false; err.textContent = 'Błąd połączenia.'; err.classList.remove('d-none'); });
    };

    window.tpToggleTemplate = function (templateId) {
        api('toggle', {template_id: templateId}).then(r => { if (r.ok) reload(); else alert(r.error || 'Błąd.'); });
    };

    window.tpDeleteTemplate = function (templateId, name) {
        if (!confirm(`Usunąć szablon „${name}" wraz ze wszystkimi pozycjami? Tej operacji nie można cofnąć.`)) return;
        api('delete', {template_id: templateId}).then(r => { if (r.ok) reload(); else alert(r.error || 'Błąd.'); });
    };

    window.tpAddItem = function (e, templateId) {
        e.preventDefault();
        const form  = e.target;
        const input = form.querySelector('input[type=text]');
        const title = input.value.trim();
        if (!title) return;
        input.disabled = true;
        api('add_item', {template_id: templateId, title: title}).then(r => {
            input.disabled = false;
            if (r.ok) { reload(); }
            else { alert(r.error || 'Błąd dodawania pozycji.'); }
        }).catch(() => { input.disabled = false; alert('Błąd połączenia.'); });
    };

    window.tpDeleteItem = function (btn, itemId) {
        api('delete_item', {item_id: itemId}).then(r => {
            if (r.ok) {
                const row = btn.closest('[data-item-id]');
                if (row) row.remove();
            } else {
                alert(r.error || 'Błąd.');
            }
        });
    };
})();
