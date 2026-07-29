<?php
/**
 * procedures/asystent.php — GUI mini-agenta AI przeszukującego bazę wiedzy SZO
 * (procedury i dokumentacja). Warstwa serwerowa tylko renderuje powłokę czatu;
 * rozmowa toczy się przez api/asystent_ai.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/asystent_ai.php';

require_login();
require_module_enabled('procedures_enabled', 'Moduł procedur');

$PAGE_TITLE = 'Asystent AI';
$ai_ready   = asai_enabled();

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.asai-wrap { max-width: 900px; margin: 0 auto; }
.asai-hero {
    background: linear-gradient(135deg,#eff6ff 0%,#f5f3ff 100%);
    border:1.5px solid #e2e8f0; border-radius:16px;
    padding:1.25rem 1.4rem; margin-bottom:1rem;
}
.asai-chat {
    background:#fff; border:1.5px solid #e2e8f0; border-radius:16px;
    display:flex; flex-direction:column; overflow:hidden;
}
.asai-log { padding:1.1rem 1.2rem; overflow-y:auto; min-height:320px; max-height:58vh; }
.asai-msg { display:flex; gap:.7rem; margin-bottom:1.1rem; }
.asai-msg .avatar {
    width:34px; height:34px; border-radius:9px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:1.05rem;
}
.asai-msg.user  { flex-direction:row-reverse; }
.asai-msg.user .avatar { background:#1e293b; color:#fff; }
.asai-msg.ai   .avatar { background:#ede9fe; color:#6d28d9; }
.asai-bubble {
    border-radius:12px; padding:.7rem .95rem; font-size:.9rem; line-height:1.6;
    max-width:80%; word-wrap:break-word;
}
.asai-msg.user .asai-bubble { background:#1e293b; color:#f8fafc; }
.asai-msg.ai   .asai-bubble { background:#f8fafc; border:1px solid #eef2f7; color:#1e293b; }
.asai-bubble > *:first-child { margin-top:0; }
.asai-bubble > *:last-child  { margin-bottom:0; }
.asai-bubble h1,.asai-bubble h2,.asai-bubble h3 { font-size:1rem; font-weight:700; margin:.7rem 0 .35rem; }
.asai-bubble ul,.asai-bubble ol { padding-left:1.25rem; margin:.4rem 0; }
.asai-bubble li { margin-bottom:.2rem; }
.asai-bubble code { background:#eef2f7; border-radius:4px; padding:.1em .35em; font-size:.85em; color:#be185d; }
.asai-bubble table { width:100%; border-collapse:collapse; margin:.5rem 0; font-size:.83rem; }
.asai-bubble th,.asai-bubble td { border:1px solid #e2e8f0; padding:.35rem .55rem; }

.asai-steps {
    font-size:.72rem; color:#64748b; background:#f8fafc;
    border:1px dashed #e2e8f0; border-radius:9px; padding:.5rem .7rem; margin-top:.6rem;
}
.asai-step { display:flex; align-items:center; gap:.4rem; padding:.12rem 0; }
.asai-step .bi { color:#7c3aed; }

.asai-sources { margin-top:.7rem; display:flex; flex-wrap:wrap; gap:.4rem; }
.asai-src {
    display:inline-flex; align-items:center; gap:.35rem; text-decoration:none;
    font-size:.74rem; padding:.28rem .6rem; border:1.5px solid #e2e8f0; border-radius:2rem;
    background:#fff; color:#334155; transition:border-color .12s, background .12s;
}
.asai-src:hover { border-color:#7c3aed; background:#f5f3ff; color:#6d28d9; }
.asai-src .badge { font-size:.6rem; }

.asai-input { border-top:1px solid #eef2f7; padding:.75rem .9rem; background:#fcfcfd; }
.asai-suggest { display:flex; flex-wrap:wrap; gap:.45rem; margin-top:.9rem; }
.asai-chip {
    font-size:.78rem; padding:.35rem .8rem; border:1.5px solid #e2e8f0; border-radius:2rem;
    background:#fff; color:#475569; cursor:pointer; transition:border-color .12s, background .12s;
}
.asai-chip:hover { border-color:#7c3aed; background:#f5f3ff; color:#6d28d9; }
.asai-typing span {
    display:inline-block; width:6px; height:6px; margin:0 1px; border-radius:50%;
    background:#a78bfa; animation:asaiBounce 1.2s infinite;
}
.asai-typing span:nth-child(2){ animation-delay:.15s; }
.asai-typing span:nth-child(3){ animation-delay:.3s; }
@keyframes asaiBounce { 0%,60%,100%{ transform:translateY(0); opacity:.5; } 30%{ transform:translateY(-4px); opacity:1; } }
</style>

<div class="asai-wrap">

  <div class="asai-hero d-flex align-items-start gap-3">
    <div style="width:46px;height:46px;border-radius:12px;background:#ede9fe;color:#6d28d9;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0">
      <i class="bi bi-robot"></i>
    </div>
    <div class="flex-grow-1">
      <h4 class="mb-1 fw-bold" style="color:#1e293b">Asystent AI — procedury i dokumentacja</h4>
      <div class="text-muted" style="font-size:.84rem">
        Zadaj pytanie po polsku. Agent samodzielnie przeszuka wewnętrzną bazę wiedzy —
        <strong>procedury</strong>, dokumenty organizacji, uchwały i zasady — i odpowie, wskazując źródła.
      </div>
    </div>
    <a href="<?= APP_URL ?>/procedures/index.php" class="btn btn-sm btn-outline-secondary flex-shrink-0">
      <i class="bi bi-journal-bookmark-fill me-1"></i>Procedury
    </a>
  </div>

  <?php if (!$ai_ready): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <div>
      Integracja AI nie jest skonfigurowana.
      <?php if (is_admin()): ?>
        Dodaj klucz w <a href="<?= APP_URL ?>/admin/ai_settings.php">Admin → Ustawienia AI</a>.
      <?php else: ?>
        Poproś administratora o skonfigurowanie klucza Anthropic API.
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="asai-chat">
    <div class="asai-log" id="asaiLog">
      <div class="asai-msg ai">
        <div class="avatar"><i class="bi bi-robot"></i></div>
        <div class="asai-bubble">
          Cześć! Jestem asystentem wiedzy organizacji. Zapytaj mnie o procedurę, dokument,
          uchwałę albo „jak coś załatwić" — przeszukam bazę i odpowiem z odnośnikami do źródeł.
          <div class="asai-suggest" id="asaiSuggest">
            <span class="asai-chip">Jak rozliczyć zwrot kosztów wolontariusza?</span>
            <span class="asai-chip">Procedura onboardingu nowej osoby</span>
            <span class="asai-chip">Jakie dokumenty przy rozwiązaniu umowy?</span>
            <span class="asai-chip">Zasady ochrony danych (RODO)</span>
          </div>
        </div>
      </div>
    </div>

    <div class="asai-input">
      <form id="asaiForm" class="d-flex gap-2 align-items-end">
        <textarea id="asaiInput" class="form-control" rows="1" placeholder="Napisz pytanie…"
                  style="resize:none;max-height:140px" <?= $ai_ready ? '' : 'disabled' ?>></textarea>
        <button type="submit" id="asaiSend" class="btn btn-primary" style="background:#7c3aed;border-color:#7c3aed" <?= $ai_ready ? '' : 'disabled' ?>>
          <i class="bi bi-send"></i>
        </button>
      </form>
      <div class="text-muted mt-1" style="font-size:.68rem">
        <i class="bi bi-shield-lock me-1"></i>Odpowiedzi generuje AI na podstawie bazy wiedzy — zweryfikuj przy decyzjach formalnych.
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked@9/marked.min.js"></script>
<script>
(function () {
  const CSRF     = <?= json_encode(csrf_token()) ?>;
  const ENDPOINT = <?= json_encode(APP_URL . '/api/asystent_ai.php') ?>;
  const log      = document.getElementById('asaiLog');
  const form     = document.getElementById('asaiForm');
  const input    = document.getElementById('asaiInput');
  const sendBtn  = document.getElementById('asaiSend');
  const history  = [];   // [{role,text}]
  let   busy     = false;

  if (typeof marked !== 'undefined') marked.setOptions({ breaks: true, gfm: true });
  const mdToHtml = (t) => (typeof marked !== 'undefined') ? marked.parse(t) : escapeHtml(t).replace(/\n/g, '<br>');

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  function scrollDown() { log.scrollTop = log.scrollHeight; }

  function addUser(text) {
    const el = document.createElement('div');
    el.className = 'asai-msg user';
    el.innerHTML = '<div class="avatar"><i class="bi bi-person-fill"></i></div>' +
                   '<div class="asai-bubble"></div>';
    el.querySelector('.asai-bubble').textContent = text;
    log.appendChild(el); scrollDown();
  }

  // Zwraca uchwyty do aktualizacji „myślącej" bańki AI.
  function addThinking() {
    const el = document.createElement('div');
    el.className = 'asai-msg ai';
    el.innerHTML = '<div class="avatar"><i class="bi bi-robot"></i></div>' +
      '<div class="asai-bubble">' +
        '<div class="asai-thinking text-muted" style="font-size:.82rem">' +
          '<i class="bi bi-search me-1"></i>Przeszukuję bazę wiedzy… ' +
          '<span class="asai-typing"><span></span><span></span><span></span></span>' +
        '</div>' +
        '<div class="asai-body" style="display:none"></div>' +
      '</div>';
    log.appendChild(el); scrollDown();
    return {
      root: el,
      thinking: el.querySelector('.asai-thinking'),
      body: el.querySelector('.asai-body'),
    };
  }

  function renderSources(container, sources) {
    if (!sources || !sources.length) return;
    const wrap = document.createElement('div');
    wrap.className = 'asai-sources';
    sources.forEach(s => {
      const a = document.createElement('a');
      a.className = 'asai-src';
      a.href = s.url; a.target = '_blank'; a.rel = 'noopener';
      a.innerHTML = '<i class="bi ' + escapeHtml(s.icon || 'bi-file-earmark') + '"></i>' +
                    '<span>' + escapeHtml(s.title) + '</span>' +
                    '<span class="badge bg-light text-secondary">' + escapeHtml(s.label || s.type) + '</span>';
      wrap.appendChild(a);
    });
    container.appendChild(wrap);
  }

  function renderSteps(container, trace) {
    if (!trace || !trace.length) return;
    const box = document.createElement('div');
    box.className = 'asai-steps';
    let html = '<div class="fw-semibold mb-1"><i class="bi bi-diagram-3 me-1"></i>Kroki agenta</div>';
    trace.forEach(t => {
      const icon = t.tool === 'otworz' ? 'bi-file-earmark-text' : 'bi-search';
      const verb = t.tool === 'otworz' ? 'Otworzył' : 'Szukał';
      html += '<div class="asai-step"><i class="bi ' + icon + '"></i>' +
              '<span>' + escapeHtml(verb) + ': „' + escapeHtml(t.input) + '" — ' + escapeHtml(t.summary) + '</span></div>';
    });
    box.innerHTML = html;
    container.appendChild(box);
  }

  async function ask(text) {
    if (busy) return;
    text = (text || '').trim();
    if (!text) return;

    const suggest = document.getElementById('asaiSuggest');
    if (suggest) suggest.remove();

    busy = true; sendBtn.disabled = true; input.disabled = true;
    addUser(text);
    history.push({ role: 'user', text });
    input.value = ''; autoGrow();

    const ui = addThinking();

    try {
      const resp = await fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ _csrf: CSRF, history }),
      });
      const data = await resp.json();

      ui.thinking.style.display = 'none';
      ui.body.style.display = '';

      if (!data.ok) {
        ui.body.innerHTML = '<div class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>' +
                            escapeHtml(data.error || 'Wystąpił błąd.') + '</div>';
        renderSteps(ui.body, data.trace);
      } else {
        ui.body.innerHTML = mdToHtml(data.answer || '(brak odpowiedzi)');
        renderSources(ui.body, data.sources);
        renderSteps(ui.body, data.trace);
        history.push({ role: 'assistant', text: data.answer || '' });
      }
    } catch (e) {
      ui.thinking.style.display = 'none';
      ui.body.style.display = '';
      ui.body.innerHTML = '<div class="text-danger"><i class="bi bi-wifi-off me-1"></i>Błąd połączenia. Spróbuj ponownie.</div>';
    } finally {
      busy = false; sendBtn.disabled = false; input.disabled = false;
      input.focus(); scrollDown();
    }
  }

  function autoGrow() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 140) + 'px';
  }

  form.addEventListener('submit', (e) => { e.preventDefault(); ask(input.value); });
  input.addEventListener('input', autoGrow);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(input.value); }
  });
  document.addEventListener('click', (e) => {
    const chip = e.target.closest('.asai-chip');
    if (chip) ask(chip.textContent);
  });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
