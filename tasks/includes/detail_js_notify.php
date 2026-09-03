<script>
/* tasks/includes/detail_js_notify.php — wydzielone z tasks/detail.php.
   Modal „Zgłoś problem liderowi” + link/unlink plików z Koszulek (workspaces).
   UWAGA: ten fragment jest wstrzykiwany do offcanvas przez fetch()+createContextualFragment()
   (nie pełne przeładowanie strony) — dlatego BEZ IIFE per plik: deklaracje top-level
   (const/let/function) współdzielą jeden "script scope" ze wszystkimi innymi
   detail_js_*.php tego fragmentu, doładowanymi jako kolejne <script> w tym samym
   dokumencie, dokładnie tak jak wcześniej działało jedno wspólne IIFE. */
// ── Powiadom lidera — modal ────────────────────────────────────────────────
var _notifyPrevFocus = null;   // zapamiętaj focus przed otwarciem

window.tdOpenNotifyModal = function() {
    const modal    = document.getElementById('td-notify-modal');
    const backdrop = document.getElementById('td-notify-backdrop');
    if (!modal || !backdrop) return;

    // Reset stanu
    const ta = document.getElementById('td-notify-msg');
    if (ta) { ta.value = ''; ta.style.borderColor = ''; }
    const cnt = document.getElementById('td-notify-count-label');
    if (cnt) cnt.textContent = '0 / 1000';
    document.getElementById('td-notify-ok')?.classList.add('d-none');
    document.getElementById('td-notify-err')?.classList.add('d-none');

    // Pokaż
    _notifyPrevFocus = document.activeElement;
    backdrop.style.display = 'block';
    modal.style.display    = 'block';
    backdrop.removeAttribute('aria-hidden');

    // Focus na textarea po animacji
    requestAnimationFrame(() => {
        document.getElementById('td-notify-msg')?.focus();
    });

    // Trap focus wewnątrz modala
    modal.addEventListener('keydown', _notifyTrapFocus);
};

window.tdCloseNotifyModal = function() {
    const modal    = document.getElementById('td-notify-modal');
    const backdrop = document.getElementById('td-notify-backdrop');
    if (!modal || !backdrop) return;

    modal.style.display    = 'none';
    backdrop.style.display = 'none';
    backdrop.setAttribute('aria-hidden', 'true');
    modal.removeEventListener('keydown', _notifyTrapFocus);

    // Przywróć focus do przycisku który otworzył modal
    (_notifyPrevFocus || document.getElementById('td-notify-open-btn'))?.focus();
    _notifyPrevFocus = null;
};

function _notifyTrapFocus(e) {
    if (e.key !== 'Tab' && e.key !== 'Escape') return;
    if (e.key === 'Escape') { e.preventDefault(); tdCloseNotifyModal(); return; }

    const modal      = document.getElementById('td-notify-modal');
    const focusable  = Array.from(modal.querySelectorAll(
        'button:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
    )).filter(el => el.offsetParent !== null);
    if (!focusable.length) return;

    const first = focusable[0];
    const last  = focusable[focusable.length - 1];

    if (e.shiftKey) {
        if (document.activeElement === first) { e.preventDefault(); last.focus(); }
    } else {
        if (document.activeElement === last)  { e.preventDefault(); first.focus(); }
    }
}

window.tdNotifyInput = function(ta) {
    const n   = ta.value.length;
    const cnt = document.getElementById('td-notify-count-label');
    if (cnt) {
        cnt.textContent = n + ' / 1000';
        cnt.style.color = n > 900 ? '#dc2626' : '#94a3b8';
    }
    ta.style.borderColor = '';  // usuń błąd walidacji przy pisaniu
};

window.tdNotifyLeader = function() {
    const ta  = document.getElementById('td-notify-msg');
    const btn = document.getElementById('td-notify-btn');
    const ok  = document.getElementById('td-notify-ok');
    const err = document.getElementById('td-notify-err');

    const msg = (ta ? ta.value : '').trim();
    if (!msg) {
        if (ta) {
            ta.style.borderColor = '#dc2626';
            ta.setAttribute('aria-invalid', 'true');
            ta.focus();
        }
        return;
    }
    if (ta) { ta.style.borderColor = ''; ta.removeAttribute('aria-invalid'); }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Wysyłanie…';
    ok?.classList.add('d-none');
    err?.classList.add('d-none');

    api('/tasks/api/notify_leader.php', { task_id: TID, message: msg })
        .then(r => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-megaphone me-1" aria-hidden="true"></i>Wyślij zgłoszenie';
            if (r.ok) {
                if (ta) { ta.value = ''; }
                const cnt = document.getElementById('td-notify-count-label');
                if (cnt) cnt.textContent = '0 / 1000';
                if (ok) {
                    ok.textContent = '✓ Zgłoszenie wysłane — lider otrzymał powiadomienie.';
                    ok.classList.remove('d-none');
                }
                srAnnounce('Zgłoszenie problemu wysłane do lidera.');
                // Zamknij modal po 2.5s
                setTimeout(tdCloseNotifyModal, 2500);
            } else {
                if (err) { err.textContent = r.error || 'Błąd wysyłania.'; err.classList.remove('d-none'); }
                ta?.focus();
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-megaphone me-1" aria-hidden="true"></i>Wyślij zgłoszenie';
            if (err) { err.textContent = 'Błąd połączenia z serwerem.'; err.classList.remove('d-none'); }
        });
};

// ── Pliki z Koszulek — link / unlink ─────────────────────────────────────────
(function() {
    const WS_API = <?= json_encode(APP_URL . '/workspaces/api.php') ?>;
    const CSRF_W = <?= json_encode($csrf) ?>;

    // Odepnij plik od zadania
    document.querySelectorAll('.td-ws-unlink').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Odpiąć ten plik od zadania?')) return;
            const r = await fetch(WS_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({_csrf: CSRF_W, action: 'unlink_task',
                                      file_id: +btn.dataset.fileId, task_id: +btn.dataset.taskId})
            }).then(r => r.json()).catch(() => ({ok: false, error: 'Błąd połączenia.'}));
            if (r.ok) {
                document.getElementById('wsfile-' + btn.dataset.fileId)?.remove();
                const badge = document.querySelector('#td-ws-files ~ .badge, .td-label .badge');
                // Pokaż komunikat jeśli lista pusta
                if (!document.querySelector('#td-ws-files .td-file-row')) {
                    let p = document.getElementById('td-ws-empty');
                    if (!p) {
                        p = document.createElement('p');
                        p.id = 'td-ws-empty';
                        p.className = 'text-muted small mb-0';
                        p.textContent = 'Brak powiązanych plików z koszulki.';
                        document.getElementById('td-ws-files')?.appendChild(p);
                    }
                }
            } else alert(r.error || 'Błąd odpinania.');
        });
    });

    // Otwórz / zamknij picker
    const linkBtn    = document.getElementById('td-ws-link-btn');
    const picker     = document.getElementById('td-ws-picker');
    const closeBtn   = document.getElementById('td-ws-picker-close');
    const searchInput = document.getElementById('td-ws-search');
    const searchBtn  = document.getElementById('td-ws-search-btn');
    const resultsList = document.getElementById('td-ws-results');

    if (!linkBtn || !picker) return;

    linkBtn.addEventListener('click', () => {
        picker.style.display = picker.style.display === 'none' ? '' : 'none';
        if (picker.style.display !== 'none') {
            searchInput?.focus();
            doSearch('');
        }
    });
    closeBtn?.addEventListener('click', () => { picker.style.display = 'none'; });

    async function doSearch(q) {
        if (!resultsList) return;
        resultsList.innerHTML = '<div class="list-group-item text-muted py-1"><span class="spinner-border spinner-border-sm me-1"></span>Szukam…</div>';
        const r = await fetch(WS_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({_csrf: CSRF_W, action: 'list_ws_files',
                                  workspace_id: linkBtn.dataset.wsId, q})
        }).then(r => r.json()).catch(() => ({ok: false, files: []}));

        if (!r.ok || !r.files?.length) {
            resultsList.innerHTML = '<div class="list-group-item text-muted py-1 small">Brak dostępnych plików.</div>';
            return;
        }
        resultsList.innerHTML = r.files.map(f =>
            `<button type="button" class="list-group-item list-group-item-action py-1 px-2 d-flex align-items-center gap-2 td-ws-pick"
                     data-file-id="${f.id}" data-name="${escHtml(f.name)}">
               <i class="bi bi-file-earmark text-primary flex-shrink-0" aria-hidden="true"></i>
               <span class="text-truncate flex-grow-1">${escHtml(f.name)}</span>
               <small class="text-muted flex-shrink-0">${f.size_label}</small>
             </button>`
        ).join('');

        resultsList.querySelectorAll('.td-ws-pick').forEach(row => {
            row.addEventListener('click', async () => {
                const fileId = +row.dataset.fileId;
                const taskId = TID;
                row.disabled = true;
                row.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + row.dataset.name;

                const r2 = await fetch(WS_API, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({_csrf: CSRF_W, action: 'link_task', file_id: fileId, task_id: taskId})
                }).then(r => r.json()).catch(() => ({ok: false, error: 'Błąd połączenia.'}));

                if (r2.ok) {
                    picker.style.display = 'none';
                    // Przeładuj offcanvas żeby zobaczyć nowy plik
                    if (typeof openTask === 'function') openTask(taskId);
                } else {
                    alert(r2.error || 'Błąd łączenia.');
                    row.disabled = false;
                }
            });
        });
    }

    searchBtn?.addEventListener('click', () => doSearch(searchInput.value.trim()));
    searchInput?.addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(searchInput.value.trim()); });
})();

</script>
