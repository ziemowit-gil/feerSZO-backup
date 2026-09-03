<script>
/* tasks/includes/detail_js_takeover.php — wydzielone z tasks/detail.php.
   Panel „Przekaż zadanie” (wybór osoby z komórki organizacyjnej, wysyłka prośby).
   UWAGA: ten fragment jest wstrzykiwany do offcanvas przez fetch()+createContextualFragment()
   (nie pełne przeładowanie strony) — dlatego BEZ IIFE per plik: deklaracje top-level
   (const/let/function) współdzielą jeden "script scope" ze wszystkimi innymi
   detail_js_*.php tego fragmentu, doładowanymi jako kolejne <script> w tym samym
   dokumencie, dokładnie tak jak wcześniej działało jedno wspólne IIFE. */
// ── Przekaż zadanie ───────────────────────────────────────────────────────
var _tpSelectedUid  = 0;
var _tpSelectedName = '';

window.tdToggleTakeover = function() {
    const panel = document.getElementById('td-takeover-panel');
    const btn   = document.getElementById('td-takeover-btn');
    if (!panel) return;

    const isOpen = panel.classList.toggle('open');
    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

    if (isOpen) {
        // Zamknij kliknięciem poza / klawiszem Escape
        setTimeout(() => {
            document.addEventListener('click', _tpOutsideClick, {once: false});
            document.addEventListener('keydown', _tpKeydown);
        }, 10);
        document.getElementById('td-tp-search')?.focus();
    } else {
        _tpCleanup();
    }
};

function _tpClose(returnFocus) {
    document.getElementById('td-takeover-panel')?.classList.remove('open');
    const btn = document.getElementById('td-takeover-btn');
    btn?.setAttribute('aria-expanded', 'false');
    _tpCleanup();
    if (returnFocus) btn?.focus();
}

function _tpCleanup() {
    document.removeEventListener('click', _tpOutsideClick);
    document.removeEventListener('keydown', _tpKeydown);
}

function _tpKeydown(e) {
    if (e.key === 'Escape') { e.preventDefault(); _tpClose(true); }
}

function _tpOutsideClick(e) {
    const wrap = document.getElementById('td-takeover-wrap');
    if (wrap && !wrap.contains(e.target)) _tpClose(false);
}

window.tdTpFilter = function(q) {
    const lq   = q.toLowerCase();
    const btns = document.querySelectorAll('.td-tp-person');
    let   vis  = 0;
    btns.forEach(b => {
        const match = !lq || (b.dataset.search || '').includes(lq);
        b.style.display = match ? '' : 'none';
        if (match) vis++;
    });
    // Pokaż/ukryj nagłówki sekcji
    document.querySelectorAll('.td-tp-group').forEach(g => {
        const next = g.nextElementSibling;
        // Sprawdź czy chociaż jeden button w tej grupie jest widoczny
        let anyVis = false;
        let el = g.nextElementSibling;
        while (el && !el.classList.contains('td-tp-group')) {
            if (el.classList.contains('td-tp-person') && el.style.display !== 'none') anyVis = true;
            el = el.nextElementSibling;
        }
        g.style.display = anyVis ? '' : 'none';
    });
    const noRes = document.getElementById('td-tp-no-results');
    if (noRes) noRes.style.display = vis === 0 ? '' : 'none';
};

window.tdTpSelect = function(btn) {
    _tpSelectedUid  = parseInt(btn.dataset.uid);
    _tpSelectedName = btn.dataset.name;

    // Pokaż wybraną osobę
    const sel = document.getElementById('td-tp-selected');
    if (sel) {
        document.getElementById('td-tp-sel-av').style.background = btn.dataset.bg;
        document.getElementById('td-tp-sel-av').textContent = btn.dataset.initials;
        document.getElementById('td-tp-sel-name').textContent = _tpSelectedName;
        sel.classList.add('show');
    }

    // Pokaż pole wiadomości i przycisk
    const sw = document.getElementById('td-tp-send-wrap');
    if (sw) sw.style.display = '';

    // Ukryj listę, wyczyść szukanie
    document.getElementById('td-tp-list').style.display = 'none';
    const srch = document.getElementById('td-tp-search');
    if (srch) srch.style.display = 'none';

    // Focus na pole wiadomości
    setTimeout(() => document.getElementById('td-tp-msg-ta')?.focus(), 50);
};

window.tdTpClearSelection = function() {
    _tpSelectedUid  = 0;
    _tpSelectedName = '';

    const sel = document.getElementById('td-tp-selected');
    if (sel) sel.classList.remove('show');

    const sw = document.getElementById('td-tp-send-wrap');
    if (sw) sw.style.display = 'none';

    document.getElementById('td-tp-list').style.display = '';
    const srch = document.getElementById('td-tp-search');
    if (srch) { srch.style.display = ''; srch.value = ''; }

    tdTpFilter('');

    const ok  = document.getElementById('td-tp-ok');
    const err = document.getElementById('td-tp-err');
    if (ok)  ok.classList.add('d-none');
    if (err) err.classList.add('d-none');
};

window.tdTpSend = function() {
    if (!_tpSelectedUid) return;
    const btn = document.getElementById('td-tp-send-btn');
    const msg = document.getElementById('td-tp-msg-ta')?.value?.trim() || '';
    const ok  = document.getElementById('td-tp-ok');
    const err = document.getElementById('td-tp-err');

    btn.disabled    = true;
    btn.textContent = 'Wysyłam…';
    if (ok)  ok.classList.add('d-none');
    if (err) err.classList.add('d-none');

    api('/tasks/api/request_takeover.php', {
        task_id:        TID,
        target_user_id: _tpSelectedUid,
        message:        msg
    })
    .then(r => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij prośbę';
        if (r.ok) {
            if (r.ok) {
                ok.textContent = 'Prośba o przejęcie wysłana do ' + (r.data?.target_name || _tpSelectedName) + '. Czeka na akceptację.';
                ok.classList.remove('d-none');
            }
            srAnnounce('Prośba o przejęcie wysłana do ' + _tpSelectedName + '.');
            // Zamknij panel po 2s
            setTimeout(() => {
                document.getElementById('td-takeover-panel')?.classList.remove('open');
                document.getElementById('td-takeover-btn')?.setAttribute('aria-expanded','false');
                tdTpClearSelection();
            }, 2200);
        } else {
            if (err) { err.textContent = r.error || 'Błąd wysyłania.'; err.classList.remove('d-none'); }
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij prośbę';
        if (err) { err.textContent = 'Błąd połączenia z serwerem.'; err.classList.remove('d-none'); }
    });
};

// Zamknij panel klawiszem Esc
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const panel = document.getElementById('td-takeover-panel');
        if (panel?.classList.contains('open')) {
            panel.classList.remove('open');
            document.getElementById('td-takeover-btn')?.setAttribute('aria-expanded','false');
            document.getElementById('td-takeover-btn')?.focus();
        }
    }
});

</script>
