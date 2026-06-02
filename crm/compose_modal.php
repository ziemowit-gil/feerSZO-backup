<?php
/**
 * crm/compose_modal.php
 * Endpoint dla modalnego okna kompozytora wiadomości.
 *
 * GET  ?contact_id=X&channel=email|sms  → zwraca HTML fragmentu (ładowany do modala)
 * POST (JSON, X-Requested-With)          → wysyła i zwraca JSON {ok, message, error}
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';

header('X-Frame-Options: SAMEORIGIN');

if (!current_user()) { http_response_code(401); exit; }
if (!can_write('crm') && !is_admin()) { http_response_code(403); exit; }
crm_migrate();

// ── POST — wyślij wiadomość ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? [];

    if (($data['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Błąd CSRF.']); exit;
    }

    $contact_id = (int)($data['contact_id'] ?? 0);
    $channel    = in_array($data['channel'] ?? '', ['email','sms','telefon','osobisty'], true)
                  ? $data['channel'] : 'email';
    $subject    = trim($data['subject'] ?? '');
    $body       = trim($data['body']    ?? '');
    $tpl_name   = trim($data['template_name'] ?? '');
    $do_send    = !empty($data['do_send']);

    // Walidacja
    $errors = [];
    if (!$contact_id)               $errors[] = 'Brak kontaktu.';
    if (!$body)                     $errors[] = 'Treść nie może być pusta.';
    if ($channel === 'email' && !$subject) $errors[] = 'Temat jest wymagany dla e-maila.';
    if ($contact_id && !crm_can_access_contact($contact_id)) $errors[] = 'Brak dostępu do kontaktu.';

    if ($errors) { echo json_encode(['ok' => false, 'error' => implode(' ', $errors)]); exit; }

    $contact = db_one("SELECT * FROM crm_contacts WHERE id=?", [$contact_id]);
    if (!$contact) { echo json_encode(['ok' => false, 'error' => 'Kontakt nie istnieje.']); exit; }

    $rendered_body    = CrmManager::renderTemplate($body, $contact);
    $rendered_subject = CrmManager::renderTemplate($subject, $contact);

    CrmManager::sendAndLog($contact_id, $channel, $rendered_body, $rendered_subject, $tpl_name, $do_send);

    $verb = $do_send ? 'Wysłano' : 'Zalogowano';
    echo json_encode(['ok' => true, 'message' => "{$verb} wiadomość do " . h($contact['imie_nazwisko']) . "."]);
    exit;
}

// ── GET — zwróć fragment HTML ──────────────────────────────────────────────
$contact_id = (int)($_GET['contact_id'] ?? 0);
$channel    = in_array($_GET['channel'] ?? '', ['email','sms'], true) ? $_GET['channel'] : 'email';

$contact = $contact_id
    ? db_one("SELECT id, imie_nazwisko, email, telefon FROM crm_contacts WHERE id=? AND crm_active=1", [$contact_id])
    : null;

if ($contact_id && !$contact) {
    echo '<div class="alert alert-danger m-3">Kontakt nie istnieje lub jest nieaktywny.</div>';
    exit;
}

try {
    require_once dirname(__DIR__) . '/includes/sms.php';
    $sms_available = sms_is_enabled();
} catch (\Throwable $e) { $sms_available = false; }

$m365_ok = _mail_m365_configured();
$smtp_ok = (bool)_mail_setting('smtp_host');

$templates = db_all("SELECT * FROM crm_templates WHERE is_active=1 ORDER BY channel, name");
$csrf      = csrf_token();
?>
<style>
#cm-quill-wrapper .ql-toolbar.ql-snow {
  border:1px solid #E5E7EB;border-bottom:none;border-radius:.375rem .375rem 0 0;
  background:#F9FAFB;padding:.3rem .5rem;
}
#cm-quill-wrapper .ql-container.ql-snow {
  border:1px solid #E5E7EB;border-radius:0 0 .375rem .375rem;
  font-size:.91rem;
}
#cm-quill-wrapper .ql-editor { min-height:180px; }
#cm-quill-wrapper .ql-editor.ql-blank::before { color:#9CA3AF;font-style:normal; }
.cm-var-btn {
  font-size:.68rem;font-family:monospace;padding:.05rem .3rem;
  border:1px solid #d1d5db;background:#f8fafc;border-radius:3px;cursor:pointer;
  transition:background .1s;
}
.cm-var-btn:hover { background:#dbeafe; }
</style>

<div id="cm-root" data-contact-id="<?= $contact_id ?>" data-channel="<?= h($channel) ?>">

<!-- Odbiorca -->
<div class="mb-3">
  <?php if ($contact): ?>
  <div class="d-flex align-items-center gap-2 px-3 py-2 rounded"
       style="background:#eff6ff;border:1px solid #bfdbfe">
    <div class="crm-avatar sm"><?= h(CrmManager::makeInitials($contact['imie_nazwisko'])) ?></div>
    <div>
      <div class="fw-semibold small"><?= h($contact['imie_nazwisko']) ?></div>
      <div class="text-muted" style="font-size:.73rem">
        <?= h($contact['email']) ?>
        <?= $contact['telefon'] ? ' · ' . h($contact['telefon']) : '' ?>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="alert alert-warning py-2 small">Brak kontaktu — otwórz modal z widoku kontaktu.</div>
  <?php endif; ?>
</div>

<!-- Kanał + szablon -->
<div class="row g-2 mb-3">
  <div class="col-5">
    <label class="form-label small fw-semibold" for="cm-channel">Kanał</label>
    <select id="cm-channel" class="form-select form-select-sm">
      <option value="email"    <?= $channel === 'email'    ? 'selected' : '' ?>>E-mail</option>
      <?php if ($sms_available): ?>
      <option value="sms"      <?= $channel === 'sms'      ? 'selected' : '' ?>>SMS</option>
      <?php endif; ?>
      <option value="telefon"  <?= $channel === 'telefon'  ? 'selected' : '' ?>>Telefon (zaloguj)</option>
      <option value="osobisty">Spotkanie</option>
    </select>
  </div>
  <div class="col-7">
    <label class="form-label small fw-semibold" for="cm-tpl">Szablon</label>
    <select id="cm-tpl" class="form-select form-select-sm">
      <option value="">— Bez szablonu —</option>
      <?php foreach ($templates as $t): ?>
      <option value="<?= $t['id'] ?>"
              data-ch="<?= h($t['channel']) ?>"
              data-subj="<?= h($t['subject'] ?? '') ?>"
              data-body="<?= h($t['body']) ?>"
              data-name="<?= h($t['name']) ?>">
        [<?= strtoupper(h($t['channel'])) ?>] <?= h($t['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<!-- Temat -->
<div id="cm-subject-row" class="mb-2" style="display:<?= $channel === 'email' ? '' : 'none' ?>">
  <label class="form-label small fw-semibold" for="cm-subject">Temat</label>
  <input type="text" id="cm-subject" class="form-control form-control-sm" placeholder="Temat e-maila…">
</div>

<!-- Zmienne -->
<div class="mb-2 d-flex flex-wrap gap-1 align-items-center">
  <span class="text-muted" style="font-size:.7rem">Wstaw:</span>
  <?php foreach (['{imie}','{imie_nazwisko}','{email}','{organizacja}','{data}'] as $v): ?>
  <button type="button" class="cm-var-btn" onclick="CM.insertVar('<?= $v ?>')"><?= $v ?></button>
  <?php endforeach; ?>
  <button type="button" class="btn btn-sm ms-auto"
          style="font-size:.72rem;padding:.15rem .5rem;border:1px solid #c7d2fe;background:#eef2ff;color:#4f46e5"
          data-bs-toggle="modal" data-bs-target="#cm-ai-modal">
    <i class="bi bi-stars me-1"></i>AI
  </button>
</div>

<!-- Edytor -->
<div id="cm-mode-bar" class="btn-group btn-group-sm mb-1">
  <button type="button" id="cm-btn-rich" class="btn btn-outline-secondary active btn-sm" onclick="CM.setMode('rich')">
    <i class="bi bi-type-bold"></i> Rich
  </button>
  <button type="button" id="cm-btn-plain" class="btn btn-outline-secondary btn-sm" onclick="CM.setMode('plain')">
    <i class="bi bi-code"></i> Zwykły
  </button>
</div>

<div id="cm-quill-wrapper">
  <div id="cm-quill-editor"></div>
</div>
<div id="cm-plain-wrapper" style="display:none">
  <textarea id="cm-plain" class="form-control" rows="6" placeholder="Treść…"></textarea>
</div>
<div class="d-flex justify-content-between mt-1 mb-3">
  <span id="cm-char" class="text-muted" style="font-size:.72rem"></span>
</div>

<!-- Opcje -->
<div class="form-check mb-3">
  <input type="checkbox" class="form-check-input" id="cm-do-send" value="1" checked>
  <label class="form-check-label small" for="cm-do-send">
    Faktycznie wyślij (odznacz = tylko zaloguj w historii)
  </label>
</div>

<!-- Status wysyłki -->
<div id="cm-mail-info" class="d-flex flex-wrap gap-1 mb-2" style="font-size:.72rem">
  <?php if ($m365_ok): ?>
  <span class="badge bg-success-subtle text-success border border-success-subtle">
    <i class="bi bi-microsoft me-1"></i>Microsoft 365
  </span>
  <?php elseif ($smtp_ok): ?>
  <span class="badge bg-info-subtle text-info border border-info-subtle">
    <i class="bi bi-envelope me-1"></i>SMTP
  </span>
  <?php else: ?>
  <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
    <i class="bi bi-exclamation-triangle me-1"></i>PHP mail()
  </span>
  <?php endif; ?>
</div>

<!-- Błąd -->
<div id="cm-error" class="alert alert-danger py-2 small d-none"></div>
</div>

<!-- Przycisk Wyślij — wstrzyknięty do modal-footer przez JS -->
<template id="cm-footer-tpl">
  <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
  <button type="button" class="btn btn-crm-primary btn-sm" id="cm-submit-btn" onclick="CM.send()">
    <i class="bi bi-send-fill me-1"></i>Wyślij
  </button>
</template>

<!-- Modal AI -->
<div class="modal fade" id="cm-ai-modal" tabindex="-1" style="z-index:1060">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-stars text-primary me-1"></i>Asystent AI</h6>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label small fw-semibold">Temat / instrukcja <span class="text-danger">*</span></label>
          <textarea id="cm-ai-prompt" class="form-control form-control-sm" rows="3"
                    placeholder="np. Zaproszenie na wolontariat, przyjazny ton"></textarea>
        </div>
        <div class="row g-2">
          <div class="col-4">
            <label class="form-label small fw-semibold">Ton</label>
            <select id="cm-ai-tone" class="form-select form-select-sm">
              <option value="profesjonalny">Profesjonalny</option>
              <option value="przyjazny">Przyjazny</option>
              <option value="formalny">Formalny</option>
              <option value="nieformalny">Nieformalny</option>
            </select>
          </div>
          <div class="col-4">
            <label class="form-label small fw-semibold">Model</label>
            <select id="cm-ai-model" class="form-select form-select-sm">
              <option value="claude-haiku-4-5-20251001">Haiku — szybki</option>
              <option value="claude-sonnet-4-6">Sonnet — lepszy</option>
            </select>
          </div>
          <div class="col-4">
            <label class="form-label small fw-semibold">Działanie</label>
            <select id="cm-ai-insert" class="form-select form-select-sm">
              <option value="replace">Zastąp</option>
              <option value="append">Dodaj</option>
            </select>
          </div>
        </div>
        <div id="cm-ai-error" class="alert alert-danger py-2 small mt-2 d-none"></div>
        <div id="cm-ai-preview" class="border rounded bg-light p-2 mt-2 small d-none" style="max-height:120px;overflow-y:auto"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-primary btn-sm" id="cm-ai-btn" onclick="CM.aiGenerate()">
          <i class="bi bi-stars me-1"></i>Generuj
        </button>
        <button type="button" class="btn btn-success btn-sm d-none" id="cm-ai-apply" onclick="CM.aiApply()">
          <i class="bi bi-check2 me-1"></i>Wstaw
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
'use strict';

var CSRF       = <?= json_encode($csrf) ?>;
var BASE       = <?= json_encode(rtrim(APP_URL,'/')) ?>;
var CONTACT_ID = <?= (int)$contact_id ?>;

var _quill = null;
var _mode  = 'rich';
var _aiHtml = '', _aiPlain = '';

window.CM = {};

/* ── Init ─────────────────────────────────────────────────────────────── */
CM.init = function() {
    _quill = new Quill('#cm-quill-editor', {
        theme: 'snow',
        placeholder: 'Treść wiadomości…',
        modules: { toolbar: [
            [{ header: [1,2,3,false] }],
            ['bold','italic','underline'],
            [{ list:'ordered' },{ list:'bullet' }],
            ['link','blockquote'],
            ['clean'],
        ]}
    });
    _quill.on('text-change', CM.updateChar);

    // Wstrzyknij przyciski do modal-footer
    var tpl  = document.getElementById('cm-footer-tpl');
    var foot = document.getElementById('crmComposeModalFooter');
    if (tpl && foot) {
        foot.innerHTML = '';
        foot.appendChild(tpl.content.cloneNode(true));
    }

    // Zdarzenia
    var ch = document.getElementById('cm-channel');
    if (ch) ch.addEventListener('change', function() {
        CM.onChannelChange(this.value);
    });

    var tplSel = document.getElementById('cm-tpl');
    if (tplSel) tplSel.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        if (!opt.value) return;
        var ch2 = document.getElementById('cm-channel');
        if (ch2 && opt.dataset.ch) ch2.value = opt.dataset.ch;
        var subj = document.getElementById('cm-subject');
        if (subj) subj.value = opt.dataset.subj || '';
        var body = opt.dataset.body || '';
        if (opt.dataset.ch === 'sms') {
            CM.setMode('plain');
            document.getElementById('cm-plain').value = body;
        } else {
            CM.setMode('rich');
            if (_quill) {
                if (/<[a-z]/i.test(body)) _quill.root.innerHTML = body;
                else _quill.setText(body);
            }
        }
        CM.onChannelChange(opt.dataset.ch || 'email');
        CM.updateChar();
    });

    CM.onChannelChange(<?= json_encode($channel) ?>);
    CM.updateChar();
};

CM.onChannelChange = function(ch) {
    var subjRow = document.getElementById('cm-subject-row');
    if (subjRow) subjRow.style.display = ch === 'email' ? '' : 'none';
    if (ch === 'sms') CM.setMode('plain');
};

CM.setMode = function(mode) {
    _mode = mode;
    var r = mode === 'rich';
    document.getElementById('cm-quill-wrapper').style.display = r ? '' : 'none';
    document.getElementById('cm-plain-wrapper').style.display  = r ? 'none' : '';
    document.getElementById('cm-btn-rich').classList.toggle('active',  r);
    document.getElementById('cm-btn-plain').classList.toggle('active', !r);
    if (!r && _quill) {
        var tmp = document.createElement('div');
        tmp.innerHTML = _quill.root.innerHTML;
        document.getElementById('cm-plain').value = (tmp.textContent || '').trim();
    } else if (r) {
        var plain = document.getElementById('cm-plain').value;
        if (_quill && plain) _quill.setText(plain);
    }
    CM.updateChar();
};

CM.insertVar = function(v) {
    if (_mode === 'plain') {
        var ta = document.getElementById('cm-plain');
        var s = ta.selectionStart;
        ta.value = ta.value.slice(0, s) + v + ta.value.slice(ta.selectionEnd);
        ta.selectionStart = ta.selectionEnd = s + v.length;
        ta.focus();
    } else if (_quill) {
        var range = _quill.getSelection(true);
        _quill.insertText(range ? range.index : _quill.getLength(), v, 'user');
    }
};

CM.updateChar = function() {
    var cc  = document.getElementById('cm-char');
    if (!cc) return;
    var ch  = (document.getElementById('cm-channel') || {}).value || 'email';
    var len = _mode === 'rich' && _quill
        ? _quill.getText().replace(/\n$/, '').length
        : (document.getElementById('cm-plain')?.value || '').length;
    if (ch === 'sms') {
        var msgs = Math.ceil(len / 160) || 1;
        cc.textContent = len + ' zn. / ' + msgs + ' SMS';
    } else {
        cc.textContent = len > 0 ? len + ' znaków' : '';
    }
};

/* ── Wyślij ───────────────────────────────────────────────────────────── */
CM.send = function() {
    var btn = document.getElementById('cm-submit-btn');
    var err = document.getElementById('cm-error');
    err.classList.add('d-none');

    var body = _mode === 'rich' && _quill ? _quill.root.innerHTML : document.getElementById('cm-plain').value;
    var ch   = document.getElementById('cm-channel').value;

    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Wysyłam…'; }

    fetch(BASE + '/crm/compose_modal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            _csrf:         CSRF,
            contact_id:    CONTACT_ID,
            channel:       ch,
            subject:       (document.getElementById('cm-subject')?.value || ''),
            body:          body,
            template_name: (document.getElementById('cm-tpl')?.options[document.getElementById('cm-tpl').selectedIndex]?.dataset.name || ''),
            do_send:       document.getElementById('cm-do-send')?.checked,
        }),
    })
    .then(r => r.json())
    .then(function(data) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-send-fill me-1"></i>Wyślij'; }
        if (data.ok) {
            // Zamknij modal i pokaż toast
            var modal = bootstrap.Modal.getInstance(document.getElementById('crmComposeModal'));
            if (modal) modal.hide();
            // Flash success — jeśli istnieje globalny mechanizm
            if (typeof showToast === 'function') showToast(data.message, 'success');
            else alert(data.message);
            // Odśwież historię kontaktu jeśli element istnieje
            if (typeof refreshContactHistory === 'function') refreshContactHistory();
        } else {
            err.textContent = data.error || 'Błąd wysyłki.';
            err.classList.remove('d-none');
        }
    })
    .catch(function() {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-send-fill me-1"></i>Wyślij'; }
        err.textContent = 'Błąd połączenia.';
        err.classList.remove('d-none');
    });
};

/* ── AI ───────────────────────────────────────────────────────────────── */
CM.aiGenerate = function() {
    var prompt = document.getElementById('cm-ai-prompt').value.trim();
    if (!prompt) { document.getElementById('cm-ai-prompt').focus(); return; }

    var btn = document.getElementById('cm-ai-btn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    document.getElementById('cm-ai-error').classList.add('d-none');
    document.getElementById('cm-ai-preview').classList.add('d-none');
    document.getElementById('cm-ai-apply').classList.add('d-none');

    fetch(BASE + '/crm/api/ai_generate.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            _csrf:   CSRF,
            prompt:  prompt,
            tone:    document.getElementById('cm-ai-tone').value,
            model:   document.getElementById('cm-ai-model').value,
            channel: document.getElementById('cm-channel').value,
        }),
    })
    .then(r => r.json())
    .then(function(d) {
        btn.disabled = false; btn.innerHTML = '<i class="bi bi-stars me-1"></i>Generuj ponownie';
        if (d.ok) {
            _aiHtml = d.html || ''; _aiPlain = d.plain || '';
            var prev = document.getElementById('cm-ai-preview');
            prev.innerHTML = _aiHtml || (_aiPlain.replace(/\n/g,'<br>'));
            prev.classList.remove('d-none');
            document.getElementById('cm-ai-apply').classList.remove('d-none');
        } else {
            var e = document.getElementById('cm-ai-error');
            e.textContent = d.error || 'Błąd AI.'; e.classList.remove('d-none');
        }
    })
    .catch(function() {
        btn.disabled = false; btn.innerHTML = '<i class="bi bi-stars me-1"></i>Generuj';
        var e = document.getElementById('cm-ai-error');
        e.textContent = 'Błąd połączenia.'; e.classList.remove('d-none');
    });
};

CM.aiApply = function() {
    var ins = document.getElementById('cm-ai-insert').value;
    if (_mode === 'rich' && _quill) {
        if (ins === 'replace') _quill.root.innerHTML = _aiHtml;
        else _quill.clipboard.dangerouslyPasteHTML(_quill.getLength()-1, _aiHtml);
    } else {
        var ta = document.getElementById('cm-plain');
        ta.value = ins === 'replace' ? _aiPlain : (ta.value ? ta.value + '\n\n' + _aiPlain : _aiPlain);
    }
    bootstrap.Modal.getInstance(document.getElementById('cm-ai-modal'))?.hide();
    CM.updateChar();
};

/* ── Start ────────────────────────────────────────────────────────────── */
CM.init();

})();
</script>
