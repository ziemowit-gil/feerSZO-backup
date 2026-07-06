/**
 * owncloud_picker.js — modal wyboru plików z ownCloud w oknie tworzenia maila.
 *
 * Ten sam wzorzec co onedrive_picker.js — świadomie prosty (location.reload()
 * po dołączeniu, zamiast wewnętrznego rcmail.add2attachment_list()).
 */
(function () {
    function openModal() {
        var modal = document.getElementById('owncloud-picker-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'owncloud-picker-modal';
            modal.className = 'owncloud-picker-modal';
            modal.innerHTML =
                '<div class="owncloud-picker-box">' +
                '  <div class="owncloud-picker-header">' +
                '    <span>ownCloud — wybierz plik</span>' +
                '    <button type="button" class="owncloud-picker-close" aria-label="Zamknij">&times;</button>' +
                '  </div>' +
                '  <div class="owncloud-picker-list">Wczytywanie…</div>' +
                '</div>';
            document.body.appendChild(modal);
            modal.querySelector('.owncloud-picker-close').addEventListener('click', closeModal);
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal();
            });
        }
        modal.style.display = 'flex';
        loadFolder('');
    }

    function closeModal() {
        var modal = document.getElementById('owncloud-picker-modal');
        if (modal) modal.style.display = 'none';
    }

    function loadFolder(path) {
        var list = document.querySelector('#owncloud-picker-modal .owncloud-picker-list');
        list.innerHTML = 'Wczytywanie…';

        var url = rcmail.url('plugin.owncloud_list', { path: path });
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) {
                    list.innerHTML = '<div class="owncloud-picker-error">' + escapeHtml(res.message || 'Błąd.') + '</div>';
                    return;
                }
                if (!res.items.length) {
                    list.innerHTML = '<div class="owncloud-picker-empty">Folder jest pusty.</div>';
                    return;
                }
                list.innerHTML = '';
                if (path) {
                    var up = document.createElement('div');
                    up.className = 'owncloud-picker-row';
                    up.textContent = '⬆ .. (wyżej)';
                    up.addEventListener('click', function () {
                        loadFolder(path.split('/').slice(0, -1).join('/'));
                    });
                    list.appendChild(up);
                }
                res.items.forEach(function (item) {
                    var row = document.createElement('div');
                    row.className = 'owncloud-picker-row';
                    row.textContent = (item.is_folder ? '📁 ' : '📄 ') + item.name;
                    row.addEventListener('click', function () {
                        if (item.is_folder) {
                            loadFolder(item.path);
                        } else {
                            attachFile(item.path, row);
                        }
                    });
                    list.appendChild(row);
                });
            })
            .catch(function () {
                list.innerHTML = '<div class="owncloud-picker-error">Błąd połączenia z ownCloud.</div>';
            });
    }

    function attachFile(path, rowEl) {
        rowEl.textContent = 'Dołączanie…';
        var url = rcmail.url('plugin.owncloud_attach', { path: path, id: rcmail.env.compose_id });
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
                alert('Błąd połączenia z ownCloud.');
            });
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    if (window.rcmail) {
        rcmail.addEventListener('init', function () {
            var btn = document.getElementById('owncloud-picker-btn');
            if (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    openModal();
                });
            }
        });
    }
})();
