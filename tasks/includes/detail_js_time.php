<script>
/* tasks/includes/detail_js_time.php — wydzielone z tasks/detail.php.
   Czas pracy: formatowanie, timer live, log ręczny, usuwanie wpisu.
   UWAGA: ten fragment jest wstrzykiwany do offcanvas przez fetch()+createContextualFragment()
   (nie pełne przeładowanie strony) — dlatego BEZ IIFE per plik: deklaracje top-level
   (const/let/function) współdzielą jeden "script scope" ze wszystkimi innymi
   detail_js_*.php tego fragmentu, doładowanymi jako kolejne <script> w tym samym
   dokumencie, dokładnie tak jak wcześniej działało jedno wspólne IIFE. */
// ── Czas pracy — timer i log ──────────────────────────────────────────────
let _timerInterval = null;
let _timerStart    = null;
let _timerLogId    = null;

function fmtSec(s) {
    const h = Math.floor(s/3600), m = Math.floor((s%3600)/60), sec = s%60;
    return (h?h+'h ':'') + (m?m+'min ':'') + ((!h&&!m)||sec?sec+'s':'');
}
function fmtDur(s) {
    const h=Math.floor(s/3600),m=Math.floor((s%3600)/60);
    return h?`${h}h ${m}min`:`${m}min`;
}
function pad2(n){return String(n).padStart(2,'0');}

(function loadTimeLog(){
    fetch(BASE + '/tasks/api/time.php?task_id=' + TID)
        .then(r=>r.json())
        .then(r=>{
            if (!r.ok) return;
            renderTimeLog(r.data);
            if (r.data.active) startTimerDisplay(r.data.active.started_at, r.data.active.id);
        })
        .catch(()=>{
            const l=document.getElementById('td-time-loading');
            if(l) l.textContent='Błąd ładowania logów.';
        });
})();

function renderTimeLog(data) {
    const container = document.getElementById('td-time-log');
    const totalEl   = document.getElementById('td-time-total');
    if (!container) return;

    const total = data.total_seconds || 0;
    if (totalEl) totalEl.textContent = total ? '— łącznie: ' + fmtDur(total) : '';

    const logs = data.logs || [];
    if (!logs.length) {
        container.innerHTML = '<p class="text-muted small mb-0" style="font-size:.78rem">Brak wpisów czasu.</p>';
        return;
    }
    container.innerHTML = logs.map(l => {
        const dur  = l.duration_seconds ? fmtDur(parseInt(l.duration_seconds)) : '(aktywny)';
        const time = (l.started_at||'').substring(0,16);
        return `<div class="d-flex align-items-center gap-2 py-1 border-bottom" style="font-size:.78rem" role="listitem">
          <i class="bi bi-clock text-muted flex-shrink-0"></i>
          <span class="fw-semibold" style="min-width:55px">${escHtml(dur)}</span>
          <span class="text-muted">${escHtml(time)}</span>
          <span class="text-muted flex-grow-1 text-truncate">${l.note ? escHtml(l.note) : ''}</span>
          <span class="text-muted" style="font-size:.7rem">${escHtml(l.user_name||'')}</span>
          <button onclick="tdDeleteTimeLog(${l.id})" class="btn-close" style="font-size:.5rem;opacity:.4"
                  aria-label="Usuń wpis"></button>
        </div>`;
    }).join('');
}

function startTimerDisplay(startedAt, logId) {
    _timerStart  = new Date(startedAt).getTime();
    _timerLogId  = logId;
    const btn    = document.getElementById('td-timer-btn');
    const icon   = document.getElementById('td-timer-icon');
    const label  = document.getElementById('td-timer-label');
    const runDiv = document.getElementById('td-timer-running');

    if (btn)    btn.style.cssText    = 'background:#dc2626;color:#fff;border:none;font-size:.74rem';
    if (icon)   icon.className       = 'bi bi-stop-fill me-1';
    if (label)  label.textContent    = 'Stop';
    if (runDiv) { runDiv.style.display='flex'; runDiv.classList.remove('d-none'); }

    clearInterval(_timerInterval);
    _timerInterval = setInterval(() => {
        const el = document.getElementById('td-timer-elapsed');
        if (!el) return;
        const elapsed = Math.floor((Date.now() - _timerStart) / 1000);
        const h=Math.floor(elapsed/3600), m=Math.floor((elapsed%3600)/60), s=elapsed%60;
        el.textContent = (h?pad2(h)+':':'') + pad2(m) + ':' + pad2(s);
    }, 1000);
}

function stopTimerDisplay() {
    clearInterval(_timerInterval);
    _timerStart = _timerLogId = null;
    const btn    = document.getElementById('td-timer-btn');
    const icon   = document.getElementById('td-timer-icon');
    const label  = document.getElementById('td-timer-label');
    const runDiv = document.getElementById('td-timer-running');
    if (btn)    btn.style.cssText = '';
    if (icon)   icon.className    = 'bi bi-play-fill me-1';
    if (label)  label.textContent = 'Start';
    if (runDiv) runDiv.style.display = 'none';
}

window.tdTimerToggle = function() {
    const btn = document.getElementById('td-timer-btn');
    btn.disabled = true;

    if (_timerLogId) {
        // Stop
        const note = document.getElementById('td-timer-note')?.value?.trim() || '';
        api('/tasks/api/time.php', {action:'stop', task_id:TID, note})
            .then(r => {
                btn.disabled = false;
                if (r.ok) { stopTimerDisplay(); openTask(TID); }
                else alert(r.error);
            });
    } else {
        // Start
        api('/tasks/api/time.php', {action:'start', task_id:TID})
            .then(r => {
                btn.disabled = false;
                if (r.ok) {
                    startTimerDisplay(r.data.started_at, r.data.log_id);
                    srAnnounce('Timer uruchomiony.');
                } else alert(r.error);
            });
    }
};

window.tdDeleteTimeLog = function(logId) {
    if (!confirm('Usunąć ten wpis czasu?')) return;
    api('/tasks/api/time.php', {action:'delete', task_id:TID, log_id:logId})
        .then(r => {
            if (r.ok) openTask(TID);
            else alert(r.error);
        });
};

window.tdManualTime = function() {
    const start = document.getElementById('td-mt-start')?.value;
    const end   = document.getElementById('td-mt-end')?.value;
    const note  = document.getElementById('td-mt-note')?.value?.trim() || '';
    if (!start || !end) { alert('Podaj czas rozpoczęcia i zakończenia.'); return; }
    if (new Date(end) <= new Date(start)) { alert('Czas zakończenia musi być późniejszy niż rozpoczęcia.'); return; }
    api('/tasks/api/time.php', {action:'manual', task_id:TID,
        started_at: start.replace('T',' '), ended_at: end.replace('T',' '), note})
        .then(r => {
            if (r.ok) { openTask(TID); srAnnounce('Wpis czasu dodany.'); }
            else alert(r.error);
        });
};

</script>
