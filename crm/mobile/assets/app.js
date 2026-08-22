/**
 * crm/mobile/assets/app.js — logika mobilnego dialera CRM.
 *
 * Przebieg: wpisz → lista → „Zadzwoń" (link tel:) → powrót do aplikacji →
 * arkusz „Jak poszło?" → zapis wyniku do aktywności CRM.
 *
 * Podręczna kopia listy trzymana jest w sessionStorage, nie w localStorage ani
 * Cache Storage: dane osobowe (imiona, numery) nie mają zostawać na telefonie
 * po zamknięciu aplikacji — CRM jest bramkowany weryfikacją IKA.
 */
(function () {
    'use strict';

    const CFG  = window.D_CFG || {};
    const API  = CFG.base + '/api';
    const SNAP_KEY    = 'dialerSnapshot';
    const PENDING_KEY = 'dialerPendingCall';
    const SNAP_LIMIT  = 1000;

    const el = {
        query:     document.getElementById('dQuery'),
        clear:     document.getElementById('dClear'),
        refresh:   document.getElementById('dRefresh'),
        list:      document.getElementById('dList'),
        tabRecent: document.getElementById('dTabRecent'),
        tabAll:    document.getElementById('dTabAll'),
        banner:    document.getElementById('dBanner'),
        bannerTxt: document.getElementById('dBannerText'),
        sheet:     document.getElementById('dSheet'),
        backdrop:  document.getElementById('dSheetBackdrop'),
        sheetSub:  document.getElementById('dSheetSub'),
        outcomes:  document.getElementById('dOutcomes'),
        note:      document.getElementById('dNote'),
        save:      document.getElementById('dSave'),
        skip:      document.getElementById('dSkip'),
        toast:     document.getElementById('dToast')
    };

    let snapshot = null;   // lista wszystkich „dzwonialnych" kontaktów
    let mode     = 'recent';
    let seq      = 0;      // numer żądania — chroni przed wyprzedzaniem się odpowiedzi

    // ── Narzędzia ────────────────────────────────────────────────────────────

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** Klucz do szukania bez akcentów — „Łódź" ma się znaleźć po „lodz". */
    const FOLD = { 'ł': 'l', 'Ł': 'l', 'ø': 'o', 'đ': 'd' };
    function fold(s) {
        return String(s || '')
            .replace(/[łŁøđ]/g, ch => FOLD[ch] || ch)
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();
    }

    function digitsOf(s) { return String(s || '').replace(/\D+/g, ''); }

    let toastTimer = null;
    function toast(msg) {
        el.toast.textContent = msg;
        el.toast.classList.add('is-on');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => el.toast.classList.remove('is-on'), 2600);
    }

    function banner(msg) {
        if (msg) { el.bannerTxt.textContent = msg; el.banner.classList.add('is-on'); }
        else     { el.banner.classList.remove('is-on'); }
    }

    /** Wywołanie API modułu. Wygaśnięcie IKA → przeładowanie na bramkę weryfikacji. */
    async function api(path, options) {
        const res  = await fetch(API + path, Object.assign({ credentials: 'same-origin' }, options));
        let data = null;
        try { data = await res.json(); } catch (e) { /* nie-JSON, np. strona bramki */ }

        if (res.status === 401 && data && data.reauth) {
            location.reload();                 // przejdź przez ekran IKA
            throw new Error('reauth');
        }
        if (!res.ok || !data || data.ok !== true) {
            throw new Error((data && data.error) || 'Błąd połączenia (' + res.status + ').');
        }
        return data;
    }

    // ── Renderowanie listy ───────────────────────────────────────────────────

    function callBtn(item, phone, label) {
        return '<a class="d-call" href="tel:' + esc(phone.tel) + '"' +
               ' data-call="1" data-cid="' + item.cid + '" data-pid="' + item.pid + '"' +
               ' data-tel="' + esc(phone.tel) + '" data-name="' + esc(item.name) + '"' +
               ' aria-label="Zadzwoń do ' + esc(item.name) + ', numer ' + esc(phone.label) + '">' +
               CFG.icons.phone + '<span>' + esc(label || 'Zadzwoń') + '</span></a>';
    }

    function smsBtn(item, phone) {
        return '<a class="d-sms" href="sms:' + esc(phone.tel) + '"' +
               ' aria-label="Napisz SMS do ' + esc(item.name) + '">' + CFG.icons.sms + '</a>';
    }

    function cardHtml(item) {
        const first = item.phones[0];
        const badge = item.type === 'osoba_kontaktowa' ? '<span class="d-badge">osoba kontaktowa</span>' : '';
        const sub   = badge + esc(item.sub || first.label);

        let html = '<li class="d-card">' +
            '<div class="d-card-main">' +
              '<span class="d-avatar" aria-hidden="true">' + esc(item.initials) + '</span>' +
              '<a class="d-card-body" href="' + esc(CFG.crmBase) + '/contact/view.php?id=' + item.cid + '"' +
                 ' style="text-decoration:none;color:inherit">' +
                '<div class="d-name">' + esc(item.name) + '</div>' +
                '<div class="d-sub">' + sub + '</div>' +
              '</a>' +
              '<span class="d-actions">' + callBtn(item, first) + smsBtn(item, first) + '</span>' +
            '</div>';

        // Kolejne numery tego samego kontaktu — każdy z własnym przyciskiem.
        if (item.phones.length > 1) {
            html += '<div class="d-phones">';
            for (let i = 1; i < item.phones.length; i++) {
                const p = item.phones[i];
                html += '<div class="d-phone-row">' +
                          '<span class="d-phone-num">' + esc(p.label) + '</span>' +
                          '<span class="d-actions">' + callBtn(item, p, 'Dzwoń') + smsBtn(item, p) + '</span>' +
                        '</div>';
            }
            html += '</div>';
        }
        return html + '</li>';
    }

    function renderList(items, emptyMsg) {
        el.list.setAttribute('aria-busy', 'false');
        if (!items.length) {
            el.list.innerHTML = '<li class="d-empty">' + CFG.icons.nores +
                                '<div>' + esc(emptyMsg) + '</div></li>';
            return;
        }
        el.list.innerHTML = items.map(cardHtml).join('');
    }

    function skeleton() {
        el.list.setAttribute('aria-busy', 'true');
        el.list.innerHTML = '<li class="d-skeleton"></li><li class="d-skeleton"></li><li class="d-skeleton"></li>';
    }

    // ── Dane ─────────────────────────────────────────────────────────────────

    function loadSnapshotFromSession() {
        try {
            const raw = sessionStorage.getItem(SNAP_KEY);
            if (raw) snapshot = JSON.parse(raw);
        } catch (e) { snapshot = null; }
    }

    async function fetchSnapshot() {
        try {
            const data = await api('/search.php?mode=snapshot&limit=' + SNAP_LIMIT);
            snapshot = data.items || [];
            try { sessionStorage.setItem(SNAP_KEY, JSON.stringify(snapshot)); } catch (e) { /* limit — działamy z pamięci */ }
            if (snapshot.length >= SNAP_LIMIT) {
                banner('Podręczna lista obcięta do ' + SNAP_LIMIT + ' kontaktów — dalsze wyniki szukane na serwerze.');
            }
        } catch (e) {
            if (e.message !== 'reauth') snapshot = snapshot || [];
        }
    }

    /** Szukanie lokalne w kopii listy — natychmiastowe i działa bez sieci. */
    function searchLocal(q) {
        if (!snapshot) return [];
        const f = fold(q);
        const d = digitsOf(q);
        return snapshot.filter(it => {
            if (f && (fold(it.name).includes(f) || fold(it.sub).includes(f))) return true;
            if (d && it.phones.some(p => digitsOf(p.label).includes(d) || digitsOf(p.tel).includes(d))) return true;
            return false;
        }).slice(0, 60);
    }

    async function runSearch(q) {
        const my = ++seq;
        const local = searchLocal(q);
        if (local.length) renderList(local, '');

        // Kopia lokalna bywa niepełna (limit / świeże zmiany) — dopytaj serwer.
        if (!navigator.onLine) {
            if (my === seq && !local.length) renderList([], 'Brak wyników w kopii offline.');
            return;
        }
        if (local.length >= 12) return;
        if (!local.length) skeleton();

        try {
            const data = await api('/search.php?q=' + encodeURIComponent(q));
            if (my !== seq) return;                       // wpisano już coś nowszego
            const seen = new Set(local.map(i => i.key));
            const merged = local.concat((data.items || []).filter(i => !seen.has(i.key)));
            renderList(merged, 'Nic nie znaleziono dla „' + q + '".');
        } catch (e) {
            if (my === seq && !local.length) renderList([], 'Nie udało się wyszukać.');
        }
    }

    async function showTab(next) {
        mode = next;
        el.tabRecent.setAttribute('aria-selected', String(mode === 'recent'));
        el.tabAll.setAttribute('aria-selected', String(mode === 'all'));

        const my = ++seq;
        if (mode === 'all') {
            if (!snapshot) { skeleton(); await fetchSnapshot(); }
            if (my !== seq) return;
            renderList((snapshot || []).slice(0, 60),
                       'Żaden kontakt nie ma zapisanego numeru telefonu.');
            return;
        }

        skeleton();
        try {
            const data = await api('/search.php?mode=recent&limit=30');
            if (my !== seq) return;
            renderList(data.items || [], 'Brak historii rozmów — zacznij od zakładki „Wszystkie".');
        } catch (e) {
            if (e.message === 'reauth' || my !== seq) return;
            renderList([], 'Nie udało się pobrać historii rozmów.');
        }
    }

    // ── Dzwonienie + rejestracja rozmowy ─────────────────────────────────────

    function setPending(p) {
        try { sessionStorage.setItem(PENDING_KEY, JSON.stringify(p)); } catch (e) {}
    }
    function getPending() {
        try { return JSON.parse(sessionStorage.getItem(PENDING_KEY) || 'null'); } catch (e) { return null; }
    }
    function clearPending() {
        try { sessionStorage.removeItem(PENDING_KEY); } catch (e) {}
    }

    // Klik nie jest przechwytywany — link tel: musi odpalić dialer natywnie,
    // także gdy zapis aktywności się nie uda.
    document.addEventListener('click', function (ev) {
        const a = ev.target.closest('a[data-call]');
        if (!a) return;

        const pending = {
            cid:  parseInt(a.dataset.cid, 10),
            pid:  parseInt(a.dataset.pid, 10) || 0,
            tel:  a.dataset.tel,
            name: a.dataset.name,
            at:   Date.now(),
            id:   0
        };
        setPending(pending);

        if (!CFG.canWrite) return;   // tylko odczyt — dzwonimy bez zapisu do CRM

        api('/call.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf },
            body:    JSON.stringify({ action: 'start', cid: pending.cid, pid: pending.pid, tel: pending.tel })
        }).then(res => {
            const cur = getPending();
            if (cur && cur.at === pending.at) { cur.id = res.data.id; setPending(cur); }
        }).catch(err => {
            if (err.message !== 'reauth') toast('Rozmowa nie została zapisana w CRM.');
        });
    });

    // ── Arkusz „Jak poszło?" ─────────────────────────────────────────────────

    let chosen = null;

    function openSheet(pending) {
        chosen = null;
        el.note.value = '';
        el.outcomes.querySelectorAll('.d-outcome').forEach(b => b.setAttribute('aria-pressed', 'false'));
        el.sheetSub.textContent = pending.name + ' · ' + pending.tel;

        el.backdrop.hidden = false;
        el.sheet.hidden = false;
        requestAnimationFrame(() => {
            el.backdrop.classList.add('is-on');
            el.sheet.classList.add('is-on');
            el.outcomes.querySelector('.d-outcome').focus();
        });
    }

    function closeSheet() {
        el.sheet.classList.remove('is-on');
        el.backdrop.classList.remove('is-on');
        setTimeout(() => { el.sheet.hidden = true; el.backdrop.hidden = true; }, 220);
    }

    el.outcomes.addEventListener('click', function (ev) {
        const b = ev.target.closest('.d-outcome');
        if (!b) return;
        chosen = b.dataset.outcome;
        el.outcomes.querySelectorAll('.d-outcome')
            .forEach(x => x.setAttribute('aria-pressed', String(x === b)));
    });

    el.save.addEventListener('click', async function () {
        const pending = getPending();
        if (!pending) { closeSheet(); return; }
        if (!chosen)  { toast('Wybierz wynik rozmowy.'); return; }

        if (!pending.id) {
            // Zapis startowy nie doszedł (brak sieci w chwili połączenia) — spróbuj teraz.
            try {
                const res = await api('/call.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf },
                    body:    JSON.stringify({ action: 'start', cid: pending.cid, pid: pending.pid, tel: pending.tel })
                });
                pending.id = res.data.id;
            } catch (e) {
                if (e.message !== 'reauth') toast('Nie udało się zapisać rozmowy.');
                return;
            }
        }

        el.save.disabled = true;
        try {
            await api('/call.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf },
                body:    JSON.stringify({ action: 'outcome', id: pending.id, outcome: chosen, note: el.note.value })
            });
            clearPending();
            closeSheet();
            toast('Zapisano w kartotece kontaktu.');
            if (mode === 'recent' && !el.query.value) showTab('recent');
        } catch (e) {
            if (e.message !== 'reauth') toast('Nie udało się zapisać wyniku.');
        } finally {
            el.save.disabled = false;
        }
    });

    el.skip.addEventListener('click', function () { clearPending(); closeSheet(); });
    el.backdrop.addEventListener('click', function () { clearPending(); closeSheet(); });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !el.sheet.hidden) { clearPending(); closeSheet(); }
    });

    /** Powrót do aplikacji po połączeniu — zapytaj o wynik. */
    function maybeAskOutcome() {
        if (document.visibilityState !== 'visible') return;
        if (!el.sheet.hidden) return;
        const pending = getPending();
        if (!pending) return;
        if (!CFG.canWrite) { clearPending(); return; }
        if (Date.now() - pending.at < 1500) return;   // dialer jeszcze się nie otworzył
        openSheet(pending);
    }

    document.addEventListener('visibilitychange', maybeAskOutcome);
    window.addEventListener('pageshow', maybeAskOutcome);

    // ── Zdarzenia interfejsu ────────────────────────────────────────────────

    let debounce = null;
    el.query.addEventListener('input', function () {
        const q = el.query.value.trim();
        el.clear.hidden = q === '';
        clearTimeout(debounce);

        if (q === '') { showTab(mode); return; }
        debounce = setTimeout(() => runSearch(q), 180);
    });

    el.query.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); el.query.blur(); }
    });

    el.clear.addEventListener('click', function () {
        el.query.value = '';
        el.clear.hidden = true;
        el.query.focus();
        showTab(mode);
    });

    el.tabRecent.addEventListener('click', () => { el.query.value = ''; el.clear.hidden = true; showTab('recent'); });
    el.tabAll.addEventListener('click',    () => { el.query.value = ''; el.clear.hidden = true; showTab('all'); });

    el.refresh.addEventListener('click', async function () {
        try { sessionStorage.removeItem(SNAP_KEY); } catch (e) {}
        snapshot = null;
        banner('');
        skeleton();
        await fetchSnapshot();
        const q = el.query.value.trim();
        if (q) runSearch(q); else showTab(mode);
        toast('Lista odświeżona.');
    });

    window.addEventListener('offline', () => banner('Brak połączenia — działa tylko kopia z tej sesji.'));
    window.addEventListener('online',  () => { banner(''); if (!snapshot) fetchSnapshot(); });

    // ── Start ───────────────────────────────────────────────────────────────

    loadSnapshotFromSession();
    if (!navigator.onLine) banner('Brak połączenia — działa tylko kopia z tej sesji.');
    showTab('recent');
    if (!snapshot) fetchSnapshot();
    maybeAskOutcome();

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register(CFG.base + '/sw.js', { scope: CFG.base + '/' })
            .catch(() => { /* np. brak HTTPS — aplikacja działa dalej, bez offline */ });
    }
})();
