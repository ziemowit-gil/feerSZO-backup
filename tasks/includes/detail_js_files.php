<script>
/* tasks/includes/detail_js_files.php — wydzielone z tasks/detail.php.
   Upload pliku, OneDrive, import z URL, usuwanie/podgląd (lightbox) plików.
   UWAGA: ten fragment jest wstrzykiwany do offcanvas przez fetch()+createContextualFragment()
   (nie pełne przeładowanie strony) — dlatego BEZ IIFE per plik: deklaracje top-level
   (const/let/function) współdzielą jeden "script scope" ze wszystkimi innymi
   detail_js_*.php tego fragmentu, doładowanymi jako kolejne <script> w tym samym
   dokumencie, dokładnie tak jak wcześniej działało jedno wspólne IIFE. */
/* Upload plików — wiele naraz (input multiple lub przeciągnięcie na strefę), po kolei, z postępem */
function tdUploadFiles(fileList) {
    const files = Array.from(fileList || []);
    if (!files.length) return;
    const zone   = document.getElementById('td-drop-zone');
    const maxB   = parseInt(zone?.dataset.maxBytes || '10485760', 10);
    const st     = document.getElementById('td-upload-status');
    const msg    = document.getElementById('td-upload-msg');
    const bar    = document.getElementById('td-upload-bar');
    const errors = [];
    let done = 0;

    const tooBig = files.filter(f => f.size > maxB);
    tooBig.forEach(f => errors.push(f.name + ': plik za duży (max ' + Math.round(maxB / 1048576) + ' MB)'));
    const queue = files.filter(f => f.size <= maxB);

    const setBar = pct => {
        if (!bar) return;
        bar.style.width = pct + '%';
        bar.setAttribute('aria-valuenow', String(Math.round(pct)));
    };

    const finish = () => {
        if (st) st.classList.add('d-none');
        if (errors.length) alert('Nie wszystkie pliki zostały dodane:\n\n' + errors.join('\n'));
        if (done) { srAnnounce(done === 1 ? 'Plik dodany.' : 'Dodano plików: ' + done + '.'); openTask(TID); }
    };

    const next = i => {
        if (i >= queue.length) return finish();
        const f = queue[i];
        if (st)  st.classList.remove('d-none');
        if (msg) msg.textContent = 'Wysyłanie ' + (i + 1) + '/' + queue.length + ': ' + f.name + '…';
        setBar(0);

        const fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('task_id', TID);
        fd.append('file', f);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', BASE + '/tasks/api/upload.php');
        xhr.upload.onprogress = e => { if (e.lengthComputable) setBar(e.loaded / e.total * 100); };
        xhr.onload = () => {
            let r = null;
            try { r = JSON.parse(xhr.responseText); } catch (e) {}
            if (r && r.ok) done++;
            else errors.push(f.name + ': ' + ((r && r.error) || ('błąd serwera ' + xhr.status)));
            next(i + 1);
        };
        xhr.onerror = () => { errors.push(f.name + ': błąd sieci'); next(i + 1); };
        xhr.send(fd);
    };

    next(0);
}

const fileInput = document.getElementById('td-file-input');
if (fileInput) {
    fileInput.addEventListener('change', function() {
        const list = Array.from(this.files);
        this.value = '';
        tdUploadFiles(list);
    });
}

const tdDropZone = document.getElementById('td-drop-zone');
if (tdDropZone) {
    // Cała sekcja plików przyjmuje upuszczenie, strefa tylko podpowiada
    const tdDropTarget = tdDropZone.closest('.td-section') || tdDropZone;
    let tdDragDepth = 0;
    tdDropTarget.addEventListener('dragenter', e => {
        if (!e.dataTransfer?.types?.includes('Files')) return;
        e.preventDefault(); tdDragDepth++; tdDropZone.classList.add('is-over');
    });
    tdDropTarget.addEventListener('dragover', e => {
        if (e.dataTransfer?.types?.includes('Files')) e.preventDefault();
    });
    tdDropTarget.addEventListener('dragleave', () => {
        if (--tdDragDepth <= 0) { tdDragDepth = 0; tdDropZone.classList.remove('is-over'); }
    });
    tdDropTarget.addEventListener('drop', e => {
        if (!e.dataTransfer?.files?.length) return;
        e.preventDefault(); tdDragDepth = 0; tdDropZone.classList.remove('is-over');
        tdUploadFiles(e.dataTransfer.files);
    });
}

/* OneDrive — okno wyboru z modules/onedrive_picker (Graph po stronie serwera; plik pobiera
   tasks/api/import_url.php po ID elementu — bez OneDrive.js i bez popupu logowania). */
window.tdOpenOneDrive = function() {
    function _open() {
        OneDrivePicker.open({
            endpoint: BASE + '/modules/onedrive_picker/router.php?_route=/list',
            csrf:     CSRF,
            accept:   <?= json_encode(task_upload_accept_attr()) ?>,
            maxBytes: <?= (int)TASK_UPLOAD_MAX_BYTES ?>,
            title:    'Dodaj pliki z OneDrive',
            onPick:   function(items) {
                (function next(i) {
                    if (i >= items.length) return;
                    tdImportFromOneDrive(items[i], () => next(i + 1));
                })(0);
            },
        });
    }
    if (window.OneDrivePicker) { _open(); return; }
    const s = document.createElement('script');
    s.src     = BASE + '/modules/onedrive_picker/view/picker.js?v=<?= (int)@filemtime(dirname(__DIR__, 2) . '/modules/onedrive_picker/view/picker.js') ?>';
    s.onload  = _open;
    s.onerror = () => alert('Nie udało się otworzyć okna OneDrive.');
    document.head.appendChild(s);
};

window.tdImportFromOneDrive = function(item, onDone) {
    const st  = document.getElementById('td-upload-status');
    const msg = document.getElementById('td-upload-msg');
    if (st)  st.classList.remove('d-none');
    if (msg) msg.textContent = 'Pobieram z OneDrive: ' + item.name + '…';

    api('/tasks/api/import_url.php', {task_id: TID, od_item_id: item.id})
    .then(r => {
        if (st) st.classList.add('d-none');
        if (r.ok) { srAnnounce('Plik ' + item.name + ' dodany z OneDrive.'); openTask(TID); }
        else alert('Błąd importu „' + item.name + '": ' + r.error);
        if (onDone) onDone();
    })
    .catch(() => {
        if (st) st.classList.add('d-none');
        alert('Błąd sieci przy imporcie pliku z OneDrive.');
        if (onDone) onDone();
    });
};

window.tdImportFromUrl = function(url, filename, onDone) {
    const st  = document.getElementById('td-upload-status');
    const msg = document.getElementById('td-upload-msg');
    if (st)  st.classList.remove('d-none');
    if (msg) msg.textContent = 'Importuję: ' + filename + '…';

    fetch(BASE + '/tasks/api/import_url.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body:    JSON.stringify({_csrf: CSRF, task_id: TID, url, filename}),
    })
    .then(r => r.json())
    .then(r => {
        if (st) st.classList.add('d-none');
        if (r.ok) { srAnnounce('Plik ' + filename + ' importowany.'); openTask(TID); }
        else alert('Błąd importu „' + filename + '": ' + r.error);
        if (onDone) onDone();
    })
    .catch(() => {
        if (st) st.classList.add('d-none');
        alert('Błąd sieci przy imporcie pliku.');
        if (onDone) onDone();
    });
};

/* Okładka karty: >0 plik, 0 = automatycznie, -1 = bez okładki */
window.tdSetCover = function(fileId) {
    api('/tasks/api/cover.php', {task_id: TID, file_id: fileId})
        .then(r => {
            if (!r.ok) { alert(r.error); return; }
            srAnnounce(fileId === -1 ? 'Karta bez okładki.' : 'Okładka ustawiona.');
            openTask(TID);
            if (typeof tkAjaxLoad === 'function') tkAjaxLoad();
        });
};

window.tdDeleteFile = function(fid) {
    if (!confirm('Usunąć ten plik?')) return;
    api('/tasks/api/upload.php', {action:'delete', file_id:fid, task_id:TID})
        .then(r => { if (r.ok) { openTask(TID); srAnnounce('Plik usunięty.'); } else alert(r.error); });
};

/* Podgląd pliku — lightbox dla obrazów / PDF / tekstu, fallback + pobieranie dla reszty */
window.tdPreviewFile = function(url, name, ext) {
    ext = (ext || '').toLowerCase();
    const IMG = ['jpg','jpeg','png','gif','webp','svg','bmp'];
    const TXT = ['txt','csv','log','md','json'];

    tdClosePreview();
    // Załączniki z tasks/api/file.php: podgląd inline, pobranie z &dl=1
    const dlUrl = url.includes('/tasks/api/file.php') ? url + '&dl=1' : url;

    const ov = document.createElement('div');
    ov.className = 'td-preview-overlay';
    ov.id = 'td-preview-overlay';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.setAttribute('aria-label', 'Podgląd pliku: ' + name);

    ov.innerHTML =
        '<div class="td-preview-bar">'
        + '<span class="td-preview-title">' + escHtml(name) + '</span>'
        + '<a href="' + escHtml(dlUrl) + '" download title="Pobierz"><i class="bi bi-download" aria-hidden="true"></i>Pobierz</a>'
        + '<button type="button" onclick="tdClosePreview()" aria-label="Zamknij podgląd"><i class="bi bi-x-lg" aria-hidden="true"></i>Zamknij</button>'
        + '</div>'
        + '<div class="td-preview-body" id="td-preview-body"></div>';

    document.body.appendChild(ov);
    const bodyEl = ov.querySelector('#td-preview-body');

    if (IMG.includes(ext)) {
        const img = document.createElement('img');
        img.src = url; img.alt = name;
        bodyEl.appendChild(img);
    } else if (ext === 'pdf') {
        const ifr = document.createElement('iframe');
        ifr.src = url; ifr.title = name;
        bodyEl.appendChild(ifr);
    } else if (TXT.includes(ext)) {
        bodyEl.innerHTML = '<pre>Wczytywanie…</pre>';
        fetch(url)
            .then(r => r.ok ? r.text() : Promise.reject(r.status))
            .then(t => {
                const pre = document.createElement('pre');
                pre.textContent = t.length > 200000 ? t.slice(0, 200000) + '\n\n… (plik skrócony)' : t;
                bodyEl.innerHTML = ''; bodyEl.appendChild(pre);
            })
            .catch(() => { bodyEl.innerHTML = '<div class="td-preview-fallback">Nie udało się wczytać pliku.</div>'; });
    } else {
        bodyEl.innerHTML =
            '<div class="td-preview-fallback">'
            + '<i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>'
            + 'Podgląd tego typu pliku nie jest dostępny.<br>'
            + '<a href="' + escHtml(url) + '" download class="btn btn-sm btn-primary mt-3">'
            + '<i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz plik</a>'
            + '</div>';
    }

    // Zamknięcie: klik w tło + Escape
    ov.addEventListener('click', function(e) { if (e.target === ov) tdClosePreview(); });
    document.addEventListener('keydown', tdPreviewKeydown);
    ov.querySelector('button').focus();
};

window.tdClosePreview = function() {
    const ov = document.getElementById('td-preview-overlay');
    if (ov) ov.remove();
    document.removeEventListener('keydown', tdPreviewKeydown);
};

function tdPreviewKeydown(e) { if (e.key === 'Escape') tdClosePreview(); }

</script>
