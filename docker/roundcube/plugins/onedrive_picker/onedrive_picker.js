/**
 * onedrive_picker.js — modal wyboru plików z OneDrive w oknie tworzenia maila.
 *
 * Świadomie prosta implementacja: po dołączeniu pliku odświeżamy stronę
 * (location.reload) zamiast wołać wewnętrzne rcmail.add2attachment_list(),
 * którego dokładny format argumentów nie jest publicznie dokumentowany i mógłby
 * się różnić między wersjami Roundcube — bezpieczniej dociągnąć to po
 * zweryfikowaniu na żywym wdrożeniu (zob. docker/roundcube/README.md).
 */
(function () {
    function openModal() {
        var modal = document.getElementById('onedrive-picker-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'onedrive-picker-modal';
            modal.className = 'onedrive-picker-modal';
            modal.innerHTML =
                '<div class="onedrive-picker-box">' +
                '  <div class="onedrive-picker-header">' +
                '    <span>OneDrive — wybierz plik</span>' +
                '    <button type="button" class="onedrive-picker-close" aria-label="Zamknij">&times;</button>' +
                '  </div>' +
                '  <div class="onedrive-picker-list">Wczytywanie…</div>' +
                '</div>';
            document.body.appendChild(modal);
            modal.querySelector('.onedrive-picker-close').addEventListener('click', closeModal);
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal();
            });
        }
        modal.style.display = 'flex';
        loadFolder('root');
    }

    function closeModal() {
        var modal = document.getElementById('onedrive-picker-modal');
        if (modal) modal.style.display = 'none';
    }

    function loadFolder(folderId) {
        var list = document.querySelector('#onedrive-picker-modal .onedrive-picker-list');
        list.innerHTML = 'Wczytywanie…';

        var url = rcmail.url('plugin.onedrive_list', { folder_id: folderId });
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) {
                    list.innerHTML = '<div class="onedrive-picker-error">' + escapeHtml(res.message || 'Błąd.') + '</div>';
                    return;
                }
                if (!res.items.length) {
                    list.innerHTML = '<div class="onedrive-picker-empty">Folder jest pusty.</div>';
                    return;
                }
                list.innerHTML = '';
                res.items.forEach(function (item) {
                    var row = document.createElement('div');
                    row.className = 'onedrive-picker-row';
                    row.textContent = (item.is_folder ? '📁 ' : '📄 ') + item.name;
                    row.addEventListener('click', function () {
                        if (item.is_folder) {
                            loadFolder(item.id);
                        } else {
                            attachFile(item.id, row);
                        }
                    });
                    list.appendChild(row);
                });
            })
            .catch(function () {
                list.innerHTML = '<div class="onedrive-picker-error">Błąd połączenia z Microsoft Graph.</div>';
            });
    }

    function attachFile(itemId, rowEl) {
        rowEl.textContent = 'Dołączanie…';
        var url = rcmail.url('plugin.onedrive_attach', { item_id: itemId, id: rcmail.env.compose_id });
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) {
                    alert(res.message || 'Nie udało się dołączyć pliku.');
                    return;
                }
                closeModal();
                location.reload();
            })
            .catch(function () {
                alert('Błąd połączenia z Microsoft Graph.');
            });
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    if (window.rcmail) {
        rcmail.addEventListener('init', function () {
            var btn = document.getElementById('onedrive-picker-btn');
            if (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    openModal();
                });
            }
        });
    }
})();
