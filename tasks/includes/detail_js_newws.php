<script>
/* tasks/includes/detail_js_newws.php — wydzielone z tasks/detail.php.
   Nowy obszar roboczy ze struktury zadania + Duplikuj/Usuń zadanie.
   UWAGA: ten fragment jest wstrzykiwany do offcanvas przez fetch()+createContextualFragment()
   (nie pełne przeładowanie strony) — dlatego BEZ IIFE per plik: deklaracje top-level
   (const/let/function) współdzielą jeden "script scope" ze wszystkimi innymi
   detail_js_*.php tego fragmentu, doładowanymi jako kolejne <script> w tym samym
   dokumencie, dokładnie tak jak wcześniej działało jedno wspólne IIFE. */
// ── Nowy obszar roboczy z zadania ─────────────────────────────────────────
window.tdOpenNewWsModal = function() {
    const m = document.getElementById('td-ws-modal');
    const b = document.getElementById('td-ws-modal-backdrop');
    if (!m || !b) return;
    m.style.display = 'block';
    b.style.display = 'block';
    b.removeAttribute('aria-hidden');
    document.getElementById('td-ws-name')?.focus();
};

window.tdCloseNewWsModal = function() {
    document.getElementById('td-ws-modal').style.display        = 'none';
    document.getElementById('td-ws-modal-backdrop').style.display = 'none';
    document.getElementById('td-ws-modal-backdrop').setAttribute('aria-hidden','true');
    document.getElementById('td-ws-err')?.classList.add('d-none');
};

window.tdSubmitNewWs = function() {
    const name = document.getElementById('td-ws-name')?.value?.trim();
    if (!name) { document.getElementById('td-ws-name')?.focus(); return; }

    const btn = document.getElementById('td-ws-submit');
    btn.disabled = true; btn.textContent = 'Tworzę…';

    const color = document.getElementById('td-ws-color')?.value || '#2563eb';
    const icon  = document.getElementById('td-ws-icon')?.value?.trim() || 'kanban';

    api('/tasks/api/create_workspace.php', {
        name, color, icon, task_id: TID
    })
    .then(r => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-grid-plus me-1" aria-hidden="true"></i>Utwórz obszar';
        if (r.ok) {
            tdCloseNewWsModal();
            srAnnounce('Obszar „' + r.data.name + '" utworzony. Zadanie przeniesione.');
            // Otwórz nowy obszar w nowej karcie
            window.open(r.data.workspace_url, '_blank');
            // Odśwież offcanvas
            openTask(TID);
        } else {
            const err = document.getElementById('td-ws-err');
            err.textContent = r.error || 'Błąd tworzenia obszaru.';
            err.classList.remove('d-none');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-grid-plus me-1" aria-hidden="true"></i>Utwórz obszar';
        const err = document.getElementById('td-ws-err');
        err.textContent = 'Błąd połączenia.';
        err.classList.remove('d-none');
    });
};

// Zamknij modal klawiszem Esc
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('td-ws-modal')?.style.display !== 'none') {
        tdCloseNewWsModal();
    }
});

window.tdDuplicate = function() {
    api('/tasks/api/task.php', {action: 'duplicate', id: TID})
        .then(r => {
            if (r.ok && r.data) {
                srAnnounce('Zadanie zduplikowane.');
                // Otwórz duplikat w offcanvasie
                openTask(r.data.id);
            } else {
                alert(r.error || 'Błąd duplikowania.');
            }
        });
};

window.tdDelete = function() {
    if (!confirm('Na pewno usunąć to zadanie? Tej operacji nie można cofnąć.')) return;
    api('/tasks/api/task.php', {action:'delete', id:TID})
        .then(r => {
            if (r.ok) {
                bootstrap.Offcanvas.getInstance(
                    document.getElementById('taskOffcanvas')
                )?.hide();
                const card = document.querySelector('[data-task-id="' + TID + '"]');
                if (card) {
                    const col = card.closest('.kanban-cards');
                    card.closest('li')?.remove() || card.remove();
                    if (col && typeof updateColCount === 'function')
                        updateColCount(col.id.replace('col-', ''), col.querySelectorAll('.task-card').length);
                }
                srAnnounce('Zadanie usunięte.');
            } else alert(r.error);
        });
};

</script>
