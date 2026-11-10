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

// ── Folder zadania w Koszulkach: wgrywanie do folderu, przypinanie z folderów obszaru, odpinanie ──
(function() {
    const WS_API = <?= json_encode(APP_URL . '/workspaces/api.php') ?>;
    const CSRF_W = <?= json_encode($csrf) ?>;
    const box = document.getElementById('td-ws');
    if (!box) return;
    // X-Requested-With: odmowa dostępu z ws_require_access() przychodzi wtedy jako JSON, nie HTML
    const HDR = {'X-Requested-With': 'XMLHttpRequest'};
    const wsApi = body => fetch(WS_API, {
        method: 'POST', headers: Object.assign({'Content-Type': 'application/json'}, HDR),
        body: JSON.stringify(Object.assign({_csrf: CSRF_W}, body))
    }).then(r => r.json()).catch(() => ({ok: false, error: 'Błąd połączenia.'}));
    const say = (t, isErr) => {
        const p = document.getElementById('td-ws-progress');
        if (!p) return;
        p.hidden = !t; p.textContent = t || ''; p.classList.toggle('is-err', !!isErr);
    };
    const reload = () => { if (typeof openTask === 'function') openTask(TID); };

    // Odepnij plik przypięty z innego folderu (plik zostaje w swoim folderze)
    box.querySelectorAll('.td-ws-unlink').forEach(btn => btn.addEventListener('click', async () => {
        if (!confirm('Odpiąć ten plik od zadania? Plik zostanie w swoim folderze.')) return;
        btn.disabled = true;
        const r = await wsApi({action: 'unlink_task', file_id: +btn.dataset.fileId, task_id: TID});
        if (r.ok) reload(); else { alert(r.error || 'Błąd odpinania.'); btn.disabled = false; }
    }));

    // Wgraj do folderu zadania — folder powstaje przy pierwszym wgraniu
    const upBtn = document.getElementById('td-ws-upload-btn'), upIn = document.getElementById('td-ws-upload');
    if (upBtn && upIn) {
        upBtn.addEventListener('click', () => upIn.click());
        upIn.addEventListener('change', async () => {
            const list = Array.from(upIn.files || []);
            if (!list.length) return;
            upBtn.disabled = true;
            let folderId = +box.dataset.folderId || 0;
            if (!folderId) {
                say('Tworzę folder zadania w Koszulkach…');
                const r = await wsApi({action: 'ensure_task_folder', task_id: TID});
                if (!r.ok || !r.folder) { say(r.error || 'Nie udało się utworzyć folderu zadania.', true); upBtn.disabled = false; upIn.value = ''; return; }
                folderId = r.folder.id; box.dataset.folderId = String(folderId);
            }
            let ok = 0; const errs = [];
            for (let i = 0; i < list.length; i++) {
                say('Wgrywam ' + (i + 1) + ' z ' + list.length + ': ' + list[i].name + '…');
                const fd = new FormData();
                fd.append('_csrf', CSRF_W); fd.append('action', 'upload_file'); fd.append('folder_id', folderId); fd.append('file', list[i]);
                const r = await fetch(WS_API, {method: 'POST', headers: HDR, body: fd})
                    .then(x => x.json()).catch(() => ({ok: false, error: 'Błąd połączenia.'}));
                if (r.ok) ok++; else errs.push(list[i].name + ': ' + (r.error || 'błąd'));
            }
            upIn.value = ''; upBtn.disabled = false;
            if (errs.length) say('Wgrano ' + ok + ' z ' + list.length + '. ' + errs.join(' · '), true);
            else reload();
        });
    }

    // Dodaj z folderów obszaru: wybór folderu + wyszukiwanie, „Dodaj” przypina plik do zadania
    const linkBtn = document.getElementById('td-ws-link-btn'), picker = document.getElementById('td-ws-picker');
    const fSel = document.getElementById('td-ws-folder'), sIn = document.getElementById('td-ws-search');
    const res = document.getElementById('td-ws-results');
    if (!linkBtn || !picker || !res) return;
    let timer = null;
    const setOpen = open => {
        picker.hidden = !open;
        linkBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { load(); sIn?.focus(); } else linkBtn.focus();
    };
    linkBtn.addEventListener('click', () => setOpen(picker.hidden));
    document.getElementById('td-ws-picker-close')?.addEventListener('click', () => setOpen(false));
    picker.addEventListener('keydown', e => { if (e.key === 'Escape') { e.stopPropagation(); setOpen(false); } });
    fSel?.addEventListener('change', load);
    sIn?.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 300); });

    async function load() {
        res.innerHTML = '<p class="td-ws-note">Wczytuję…</p>';
        const fid = +(fSel?.value || 0), q = (sIn?.value || '').trim();
        const r = fid ? await wsApi({action: 'folder_files', folder_id: fid, q})
                      : await wsApi({action: 'list_ws_files', workspace_id: +box.dataset.wsId, q});
        const have = new Set(Array.from(box.querySelectorAll('[data-ws-file]')).map(el => +el.dataset.wsFile));
        const files = (r.files || []).filter(f => !have.has(+f.id));
        if (!r.ok || !files.length) {
            res.innerHTML = '<p class="td-ws-note">' + (r.ok ? (q ? 'Nic nie pasuje do wyszukiwania.' : 'Brak plików do dodania.') : escHtml(r.error || 'Błąd.')) + '</p>';
            return;
        }
        res.innerHTML = files.map(f =>
            `<div class="td-ws-pick-row" role="listitem">
               <i class="bi bi-file-earmark" aria-hidden="true"></i>
               <span class="td-ws-main"><span class="td-ws-pick-name" title="${escHtml(f.name)}">${escHtml(f.name)}</span>
                 <span class="td-ws-meta">${escHtml(f.folder_name || '')}${f.folder_name ? ' · ' : ''}${escHtml(f.size_label || '')}</span></span>
               <button type="button" class="td-mini-btn td-ws-pick" data-file-id="${+f.id}" aria-label="Dodaj do zadania: ${escHtml(f.name)}">
                 <i class="bi bi-plus-lg" aria-hidden="true"></i>Dodaj</button>
             </div>`).join('');
        res.querySelectorAll('.td-ws-pick').forEach(b => b.addEventListener('click', async () => {
            b.disabled = true;
            const r2 = await wsApi({action: 'link_task', file_id: +b.dataset.fileId, task_id: TID});
            if (r2.ok) reload(); else { alert(r2.error || 'Błąd przypinania.'); b.disabled = false; }
        }));
    }
})();

</script>
