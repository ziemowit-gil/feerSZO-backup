<script>
/* tasks/includes/detail_js_review.php — wydzielone z tasks/detail.php.
   Fanfary po ukończeniu (konfetti), Zakończ/Wznów, Potwierdź/Odrzuć wykonanie.
   UWAGA: ten fragment jest wstrzykiwany do offcanvas przez fetch()+createContextualFragment()
   (nie pełne przeładowanie strony) — dlatego BEZ IIFE per plik: deklaracje top-level
   (const/let/function) współdzielą jeden "script scope" ze wszystkimi innymi
   detail_js_*.php tego fragmentu, doładowanymi jako kolejne <script> w tym samym
   dokumencie, dokładnie tak jak wcześniej działało jedno wspólne IIFE. */
/* Fanfary po ukończeniu zadania — konfetti + dźwięk, bez zewnętrznych zasobów */
window.tdCelebrate = function() {
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    try {
        if (reduceMotion) throw new Error('reduced-motion');
        const colors = ['#16a34a', '#f59e0b', '#2563eb', '#dc2626', '#7c3aed', '#0891b2'];
        const layer  = document.createElement('div');
        layer.className = 'td-confetti-layer';
        layer.setAttribute('aria-hidden', 'true');
        document.body.appendChild(layer);
        for (let i = 0; i < 70; i++) {
            const p = document.createElement('span');
            p.className = 'td-confetti-piece';
            const size     = 5 + Math.random() * 6;
            const duration = 1.5 + Math.random() * 1.1;
            p.style.left               = (Math.random() * 100) + 'vw';
            p.style.background         = colors[Math.floor(Math.random() * colors.length)];
            p.style.width              = size + 'px';
            p.style.height             = (size * 0.4) + 'px';
            p.style.animationDelay     = (Math.random() * 0.25) + 's';
            p.style.animationDuration  = duration + 's';
            p.style.setProperty('--td-rot',   (360 + Math.random() * 540) + 'deg');
            p.style.setProperty('--td-drift', ((Math.random() - 0.5) * 200) + 'px');
            layer.appendChild(p);
        }
        setTimeout(() => layer.remove(), 3000);
    } catch (e) {}

    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const now = ctx.currentTime;
        [523.25, 659.25, 783.99, 1046.5].forEach((freq, i) => {
            const osc  = ctx.createOscillator();
            const gain = ctx.createGain();
            const t    = now + i * 0.09;
            osc.type = 'triangle';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.0001, t);
            gain.gain.exponentialRampToValueAtTime(0.2, t + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.35);
            osc.connect(gain).connect(ctx.destination);
            osc.start(t);
            osc.stop(t + 0.4);
        });
    } catch (e) {}
};

window.tdMarkDone = function() {
    if (!confirm('Oznaczyć zadanie jako ukończone?')) return;
    const btn = document.getElementById('td-btn-done');
    if (btn) { btn.disabled = true; btn.textContent = 'Zapisuję…'; }
    const send = force => api('/tasks/api/task.php', {action:'complete', id:TID, force:!!force})
        .then(r => (!r.ok && r.blocked && !force && confirm(r.error + '\n\nMimo to oznaczyć jako ukończone?')) ? send(true) : r);
    send(false)
        .then(r => {
            if (r.ok) {
                srAnnounce('Zadanie oznaczone jako ukończone.');
                tdCelebrate();
                openTask(TID);
                const card = document.querySelector('[data-task-id="<?= $id ?>"]');
                if (card) card.classList.add('opacity-50');
                if (typeof tkAjaxLoad === 'function') tkAjaxLoad();
                else setTimeout(() => location.reload(), 500);
            } else {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zakończ';
                }
                if (!r.blocked) alert(r.error);   // blocked = użytkownik anulował ostrzeżenie o zależnościach
            }
        });
};

window.tdReopen = function() {
    api('/tasks/api/task.php', {action:'reopen', id:TID})
        .then(r => {
            if (r.ok) { srAnnounce('Zadanie wznowione.'); openTask(TID); if (typeof tkAjaxLoad === 'function') tkAjaxLoad(); else setTimeout(() => location.reload(), 400); }
            else alert(r.error);
        });
};

window.tdConfirm = function() {
    if (!confirm('Potwierdzić wykonanie tego zadania? Wykonawca otrzyma powiadomienie.')) return;
    const btn = document.getElementById('td-btn-confirm');
    if (btn) { btn.disabled = true; btn.innerHTML = 'Zapisuję…'; }
    api('/tasks/api/task.php', {action:'confirm', id:TID})
        .then(r => {
            if (r.ok) {
                srAnnounce('Wykonanie zadania potwierdzone.');
                tdCelebrate();
                openTask(TID);
                if (typeof tkAjaxLoad === 'function') tkAjaxLoad();
            } else {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-patch-check-fill me-1" aria-hidden="true"></i>Potwierdź wykonanie';
                }
                alert(r.error);
            }
        });
};

// ── Odrzuć wykonanie — modal z powodem ──────────────────────────────────────
var _rejectPrevFocus = null;

window.tdOpenRejectModal = function() {
    const modal    = document.getElementById('td-reject-modal');
    const backdrop = document.getElementById('td-reject-backdrop');
    if (!modal || !backdrop) return;

    const ta = document.getElementById('td-reject-msg');
    if (ta) { ta.value = ''; ta.style.borderColor = ''; }
    const cnt = document.getElementById('td-reject-count-label');
    if (cnt) cnt.textContent = '0 / 1000';
    document.getElementById('td-reject-ok')?.classList.add('d-none');
    document.getElementById('td-reject-err')?.classList.add('d-none');

    _rejectPrevFocus = document.activeElement;
    backdrop.style.display = 'block';
    modal.style.display    = 'block';
    backdrop.removeAttribute('aria-hidden');

    requestAnimationFrame(() => { document.getElementById('td-reject-msg')?.focus(); });
    modal.addEventListener('keydown', _rejectTrapFocus);
};

window.tdCloseRejectModal = function() {
    const modal    = document.getElementById('td-reject-modal');
    const backdrop = document.getElementById('td-reject-backdrop');
    if (!modal || !backdrop) return;

    modal.style.display    = 'none';
    backdrop.style.display = 'none';
    backdrop.setAttribute('aria-hidden', 'true');
    modal.removeEventListener('keydown', _rejectTrapFocus);

    (_rejectPrevFocus || document.getElementById('td-reject-open-btn'))?.focus();
    _rejectPrevFocus = null;
};

function _rejectTrapFocus(e) {
    if (e.key !== 'Tab' && e.key !== 'Escape') return;
    if (e.key === 'Escape') { e.preventDefault(); tdCloseRejectModal(); return; }

    const modal     = document.getElementById('td-reject-modal');
    const focusable = Array.from(modal.querySelectorAll(
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

window.tdRejectInput = function(ta) {
    const n   = ta.value.length;
    const cnt = document.getElementById('td-reject-count-label');
    if (cnt) {
        cnt.textContent = n + ' / 1000';
        cnt.style.color = n > 900 ? '#dc2626' : '#94a3b8';
    }
    ta.style.borderColor = '';
};

window.tdReject = function() {
    const ta  = document.getElementById('td-reject-msg');
    const btn = document.getElementById('td-reject-btn');
    const ok  = document.getElementById('td-reject-ok');
    const err = document.getElementById('td-reject-err');

    const reason = (ta ? ta.value : '').trim();
    if (!reason) {
        if (ta) { ta.style.borderColor = '#dc2626'; ta.setAttribute('aria-invalid', 'true'); ta.focus(); }
        return;
    }
    if (ta) { ta.style.borderColor = ''; ta.removeAttribute('aria-invalid'); }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Zapisuję…';
    ok?.classList.add('d-none');
    err?.classList.add('d-none');

    api('/tasks/api/task.php', {action:'reject', id:TID, reason:reason})
        .then(r => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Odrzuć wykonanie';
            if (r.ok) {
                if (ok) { ok.textContent = '✓ Wykonanie odrzucone — wykonawca otrzymał powiadomienie.'; ok.classList.remove('d-none'); }
                srAnnounce('Wykonanie zadania odrzucone.');
                if (typeof tkAjaxLoad === 'function') tkAjaxLoad();
                setTimeout(() => { tdCloseRejectModal(); openTask(TID); }, 1200);
            } else {
                if (err) { err.textContent = r.error || 'Błąd zapisu.'; err.classList.remove('d-none'); }
                ta?.focus();
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Odrzuć wykonanie';
            if (err) { err.textContent = 'Błąd połączenia z serwerem.'; err.classList.remove('d-none'); }
        });
};

</script>
