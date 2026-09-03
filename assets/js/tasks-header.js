/**
 * assets/js/tasks-header.js
 * Zachowanie wspólnego szkieletu modułu Zadania — wydzielone z
 * tasks/includes/header_tasks.php: przełącznik obszaru roboczego,
 * mini centrum powiadomień (polling + dźwięk + natywne powiadomienia),
 * banery zachęcające do włączenia powiadomień / konfiguracji e-mail.
 *
 * Oczekuje globalnego obiektu window.TSK_HEADER = {appUrl, unread}
 * ustawionego przez header_tasks.php przed załadowaniem tego pliku.
 */
(function () {
    const APP_URL = (window.TSK_HEADER && window.TSK_HEADER.appUrl) || '';

    /* ── Przełącznik obszaru roboczego — wyszukiwarka ─────────────────── */
    window.tskWsFilter = function (query) {
        const q    = query.trim().toLowerCase();
        const list = document.getElementById('tsk-ws-switch-list');
        if (!list) return;
        const items = list.querySelectorAll('.tsk-ws-switch-item');
        const sep   = list.querySelector('.tsk-ws-switch-sep');
        let visible = 0;
        items.forEach(function (item) {
            const match = !q || (item.dataset.name || '').includes(q);
            item.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        if (sep) sep.style.display = q ? 'none' : '';
        const empty = document.getElementById('tsk-ws-switch-empty');
        if (empty) empty.style.display = visible ? 'none' : '';
    };

    (function () {
        const wrap = document.getElementById('tsk-ws-switch-wrap');
        if (!wrap) return;
        wrap.addEventListener('shown.bs.dropdown', function () {
            const search = document.getElementById('tsk-ws-switch-search');
            if (search) { search.value = ''; window.tskWsFilter(''); search.focus(); }
        });
    })();

    /* ── Mini centrum powiadomień — polling + dźwięk + natywne powiadomienia ── */
    const POLL_MS  = 25000;
    let lastUnread = (window.TSK_HEADER && window.TSK_HEADER.unread) || 0;
    let audioCtx   = null;

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }

    function tskPlayDing() {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            const now = audioCtx.currentTime;
            [[880, 0], [1318.5, 0.09]].forEach(([freq, delay]) => {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine'; osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.0001, now + delay);
                gain.gain.exponentialRampToValueAtTime(0.18, now + delay + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + delay + 0.5);
                osc.connect(gain).connect(audioCtx.destination);
                osc.start(now + delay); osc.stop(now + delay + 0.55);
            });
        } catch (e) {}
    }

    function tskNativeNotif(title, body, url) {
        try {
            if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return;
            const n = new Notification(title, {
                body: body || '',
                icon: APP_URL + '/assets/img/icon-192.png',
                tag:  'tsk-notif'
            });
            if (url) n.onclick = function () { window.focus(); window.location = url; n.close(); };
        } catch (e) {}
    }

    function tskRenderNotifList(items) {
        const list = document.getElementById('tsk-notif-list');
        if (!list) return;
        if (!items.length) {
            list.innerHTML = '<div class="text-center py-4 text-muted" style="font-size:.82rem"><i class="bi bi-bell-slash d-block mb-1" style="font-size:1.5rem;opacity:.3" aria-hidden="true"></i>Brak powiadomień</div>';
            return;
        }
        list.innerHTML = items.map(n => (
            '<a href="' + escHtml(n.url || (APP_URL + '/tasks/notifications.php')) + '"'
            + ' class="dropdown-item py-2 px-3 tsk-notif-item ' + (n.is_read ? '' : 'fw-semibold') + '"'
            + ' style="white-space:normal;font-size:.82rem;border-bottom:1px solid #f1f5f9" data-notif-id="' + n.id + '">'
            + '<div class="d-flex gap-2 align-items-start">'
            + '<i class="bi bi-kanban-fill mt-1 flex-shrink-0" style="color:#8B5CF6;font-size:.9rem" aria-hidden="true"></i>'
            + '<div class="flex-grow-1"><div>' + escHtml(n.title) + '</div>'
            + (n.body ? '<div class="text-muted fw-normal" style="font-size:.74rem">' + escHtml(n.body) + '</div>' : '')
            + '<div class="text-muted fw-normal" style="font-size:.72rem">' + escHtml((n.created_at || '').slice(0, 16)) + '</div></div>'
            + (n.is_read ? '' : '<span class="rounded-circle flex-shrink-0" style="width:7px;height:7px;margin-top:5px;background:var(--tsk-green)" aria-hidden="true"></span>')
            + '</div></a>'
        )).join('');
    }

    function tskApplyUnread(unread) {
        const badge   = document.getElementById('tsk-notif-count');
        const markAll = document.getElementById('tsk-notif-mark-all');
        const btn     = document.getElementById('tsk-notif-btn');
        if (badge) { badge.textContent = unread > 99 ? '99+' : unread; badge.classList.toggle('d-none', unread === 0); }
        if (markAll) markAll.classList.toggle('d-none', unread === 0);
        if (btn) btn.setAttribute('aria-label', 'Powiadomienia zadań — ' + unread + ' nieprzeczytanych');
    }

    function tskPollNotifications() {
        fetch(APP_URL + '/tasks/api/notif_poll.php', {cache: 'no-store'})
            .then(r => r.json())
            .then(d => {
                if (!d.ok) return;
                if (d.unread > lastUnread) {
                    tskPlayDing();
                    const btn = document.getElementById('tsk-notif-btn');
                    if (btn) { btn.classList.remove('tsk-notif-shake'); void btn.offsetWidth; btn.classList.add('tsk-notif-shake'); }
                    /* Powiadomienie natywne przeglądarki — pierwsze nowe powiadomienie z listy */
                    const newest = (d.latest || []).find(n => !n.is_read);
                    if (newest) tskNativeNotif(newest.title, newest.body, newest.url);
                }
                lastUnread = d.unread;
                tskApplyUnread(d.unread);
                tskRenderNotifList(d.latest || []);
            })
            .catch(() => {});
    }

    document.addEventListener('click', function (e) {
        const link = e.target.closest('#tsk-notif-menu [data-notif-id]');
        if (link) {
            fetch(APP_URL + '/tasks/api/notif_poll.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'mark_read', id: parseInt(link.dataset.notifId, 10)})
            }).catch(() => {});
            return;
        }
        const markAll = e.target.closest('#tsk-notif-mark-all');
        if (markAll) {
            fetch(APP_URL + '/tasks/api/notif_poll.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'mark_all'})
            }).then(r => r.json()).then(d => {
                if (d.ok) { lastUnread = d.unread; tskApplyUnread(d.unread); tskRenderNotifList(d.latest || []); }
            }).catch(() => {});
        }
    });

    setInterval(tskPollNotifications, POLL_MS);

    /* ── Baner zachęty do powiadomień przeglądarkowych ────────────────── */
    const DISMISS_KEY  = 'tskNotifBannerDismissed';
    const DISMISS_DAYS = 14;

    function bannerShouldShow() {
        if (typeof Notification === 'undefined') return false;
        if (Notification.permission !== 'default') return false;
        const ts = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
        if (ts && (Date.now() - ts) < DISMISS_DAYS * 86400 * 1000) return false;
        return true;
    }

    function bannerHide() {
        const el = document.getElementById('tsk-notif-banner');
        if (el) el.style.display = 'none';
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!bannerShouldShow()) return;
        const banner = document.getElementById('tsk-notif-banner');
        if (!banner) return;
        banner.style.display = '';

        const enableBtn = document.getElementById('tsk-notif-enable-btn');
        if (enableBtn) enableBtn.addEventListener('click', function () {
            Notification.requestPermission().then(function (result) {
                bannerHide();
                if (result === 'granted') {
                    try { new Notification('Powiadomienia włączone', { body: 'Będziesz informowany/a o nowych zadaniach i komentarzach.', tag: 'tsk-welcome' }); }
                    catch (e) {}
                }
            }).catch(function () { bannerHide(); });
        });

        const dismissBtn = document.getElementById('tsk-notif-dismiss-btn');
        if (dismissBtn) dismissBtn.addEventListener('click', function () {
            localStorage.setItem(DISMISS_KEY, String(Date.now()));
            bannerHide();
        });
    });

    /* ── Baner konfiguracji powiadomień e-mail — nowi/niekonfigurowani ──── */
    (function () {
        const KEY  = 'tskSetupBannerDismissed';
        const DAYS = 7;
        document.addEventListener('DOMContentLoaded', function () {
            const el  = document.getElementById('tsk-setup-banner');
            const btn = document.getElementById('tsk-setup-dismiss-btn');
            if (!el) return;
            const ts = parseInt(localStorage.getItem(KEY) || '0', 10);
            if (ts && (Date.now() - ts) < DAYS * 86400 * 1000) {
                el.style.display = 'none';
            }
            if (btn) btn.addEventListener('click', function () {
                localStorage.setItem(KEY, String(Date.now()));
                el.style.display = 'none';
            });
        });
    })();

    /* ── Modal: Ustawienia powiadomień — AJAX loader ──────────────────── */
    (function () {
        const FRAG_URL  = APP_URL + '/tasks/notification_settings.php?_fragment=1';
        let modalEl     = null;
        let modalInst   = null;
        let loaded      = false;

        function getInst() {
            if (!modalEl) {
                modalEl   = document.getElementById('tskNotifSettingsModal');
                modalInst = bootstrap.Modal.getOrCreateInstance(modalEl);
            }
            return modalInst;
        }

        function getBody() {
            return document.getElementById('tskNotifSettingsModalBody');
        }

        function showBanner(body, ok, msg) {
            const old = body.querySelector('.tsk-ns-alert-wrap');
            if (old) old.remove();
            const wrap = document.createElement('div');
            wrap.className = 'tsk-ns-alert-wrap';
            wrap.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'warning')
                + ' d-flex align-items-center gap-2 py-2 mx-3 mt-3 mb-0" role="status" style="font-size:.83rem">'
                + '<i class="bi bi-' + (ok ? 'check-circle-fill' : 'exclamation-triangle-fill') + '"></i>'
                + '<span>' + escHtml(msg) + '</span>'
                + '<button type="button" class="btn-close btn-sm ms-auto py-0" onclick="this.closest(\'.tsk-ns-alert-wrap\').remove()" aria-label="Zamknij"></button>'
                + '</div>';
            body.prepend(wrap);
        }

        function wireBody(body) {
            body.addEventListener('submit', function (e) {
                const form = e.target.closest('form');
                if (!form) return;
                e.preventDefault();
                const submitter = e.submitter;
                const fd = new FormData(form);
                if (submitter && submitter.name) fd.set(submitter.name, submitter.value);

                const btn = submitter || form.querySelector('[type=submit]');
                const origHtml = btn ? btn.innerHTML : '';
                if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:.85em;height:.85em" aria-hidden="true"></span>'; }

                fetch(FRAG_URL, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                        showBanner(body, d.ok, d.msg || (d.ok ? 'OK' : 'Błąd'));
                        if (d.reload) loadContent(true);
                    })
                    .catch(function () {
                        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
                        showBanner(body, false, 'Błąd połączenia. Spróbuj ponownie.');
                    });
            });
        }

        function loadContent(force) {
            const body = getBody();
            if (!body) return;
            if (loaded && !force) return;
            loaded = false;
            body.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Ładowanie…</span></div></div>';
            fetch(FRAG_URL)
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    body.innerHTML = '';
                    const frag = document.createRange().createContextualFragment(html);
                    body.appendChild(frag);
                    loaded = true;
                    wireBody(body);
                })
                .catch(function () {
                    body.innerHTML = '<div class="alert alert-danger m-3">Błąd ładowania ustawień powiadomień.</div>';
                });
        }

        window.tskOpenNotifSettings = function () {
            getInst().show();
            loadContent(false);
        };

        document.addEventListener('DOMContentLoaded', function () {
            const el = document.getElementById('tskNotifSettingsModal');
            if (el) {
                el.addEventListener('hidden.bs.modal', function () { loaded = false; });
            }
        });
    })();
})();
