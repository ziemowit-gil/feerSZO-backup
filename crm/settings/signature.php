<?php
/**
 * crm/settings/signature.php — wizualny edytor podpisu e-mail i SMS użytkownika CRM.
 * Każdy zalogowany użytkownik CRM może edytować swój własny podpis.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();
crm_require('settings', 'write');

// ── Migracja kolumn podpisu ───────────────────────────────────────────────────
foreach ([
    "ALTER TABLE users ADD COLUMN crm_job_title      TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE users ADD COLUMN crm_display_phone   TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE users ADD COLUMN crm_email_signature TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE users ADD COLUMN crm_sms_signature   TEXT NOT NULL DEFAULT ''",
] as $_sql) {
    try { db()->exec($_sql); } catch (\Throwable $e) {}
}

$PAGE_TITLE = 'CRM — Mój podpis';
$uid = (int)(current_user()['id'] ?? 0);

// ── POST: zapis ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $job_title      = trim($_POST['crm_job_title']      ?? '');
    $display_phone  = trim($_POST['crm_display_phone']  ?? '');
    $email_sig      = trim($_POST['crm_email_signature'] ?? '');
    $sms_sig        = mb_substr(trim($_POST['crm_sms_signature'] ?? ''), 0, 160);

    db()->prepare(
        "UPDATE users SET crm_job_title=?, crm_display_phone=?, crm_email_signature=?, crm_sms_signature=? WHERE id=?"
    )->execute([$job_title, $display_phone, $email_sig, $sms_sig, $uid]);

    flash_set('success', 'Podpis został zapisany.');
    header('Location: ' . APP_URL . '/crm/settings/signature.php');
    exit;
}

// ── Wczytaj dane ──────────────────────────────────────────────────────────────
$u    = db_one("SELECT name, first_name, last_name, email, crm_job_title, crm_display_phone, crm_email_signature, crm_sms_signature FROM users WHERE id=?", [$uid]);
$name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
if ($name === '') $name = $u['name'] ?? '';

// Domyślny podpis jeśli pusty
$default_email_sig = '';
if (empty($u['crm_email_signature'])) {
    $org  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $default_email_sig = '<p style="margin:0"><strong>' . h($name) . '</strong></p>'
        . (!empty($u['crm_job_title']) ? '<p style="margin:0;color:#555;font-size:.9em">' . h($u['crm_job_title']) . '</p>' : '')
        . ($org ? '<p style="margin:0;color:#555;font-size:.9em">' . h($org) . '</p>' : '')
        . (!empty($u['crm_display_phone']) ? '<p style="margin:0;font-size:.9em">' . h($u['crm_display_phone']) . '</p>' : '')
        . (!empty($u['email']) ? '<p style="margin:0;font-size:.9em"><a href="mailto:' . h($u['email']) . '">' . h($u['email']) . '</a></p>' : '');
}

include __DIR__ . '/../includes/header_crm.php';
require_once __DIR__ . '/_nav.php';
?>

<style>
/* ── layout ───────────────────────────────────────────────── */
.sig-split { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
@media (max-width: 900px) { .sig-split { grid-template-columns: 1fr; } }

/* ── toolbar ──────────────────────────────────────────────── */
.sig-toolbar {
    display: flex; flex-wrap: wrap; gap: .25rem;
    padding: .4rem .6rem;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-bottom: none;
    border-radius: .375rem .375rem 0 0;
}
.sig-toolbar button {
    min-width: 2rem; height: 2rem;
    padding: 0 .45rem;
    border: 1px solid #ced4da;
    background: #fff;
    border-radius: .25rem;
    font-size: .8rem;
    cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    transition: background .1s;
}
.sig-toolbar button:hover { background: #e9ecef; }
.sig-toolbar button.active { background: #2563eb; color: #fff; border-color: #2563eb; }
.sig-toolbar .sep { width: 1px; background: #dee2e6; align-self: stretch; margin: .2rem .1rem; }
.sig-toolbar select {
    height: 2rem; padding: 0 .4rem;
    border: 1px solid #ced4da; border-radius: .25rem;
    font-size: .8rem; background: #fff; cursor: pointer;
}

/* ── edytor contenteditable ───────────────────────────────── */
#email-sig-editor {
    min-height: 140px;
    padding: .75rem;
    border: 1px solid #dee2e6;
    border-radius: 0 0 .375rem .375rem;
    outline: none;
    font-size: .9rem;
    line-height: 1.6;
    background: #fff;
}
#email-sig-editor:focus { border-color: #86b7fe; box-shadow: 0 0 0 .2rem rgba(13,110,253,.25); }

/* ── podgląd ──────────────────────────────────────────────── */
.sig-preview-wrap {
    border: 1px solid #dee2e6;
    border-radius: .375rem;
    overflow: hidden;
    font-size: .9rem;
}
.sig-preview-header {
    background: #f1f3f5; padding: .5rem .85rem;
    font-size: .75rem; color: #6c757d;
    border-bottom: 1px solid #dee2e6;
    display: flex; align-items: center; gap: .5rem;
}
.sig-preview-body {
    padding: 1rem;
    background: #fff;
    color: #212529;
    line-height: 1.7;
}
.sig-preview-separator { border: none; border-top: 1px solid #dee2e6; margin: .85rem 0; }

/* ── SMS counter ─────────────────────────────────────────── */
.sms-counter { font-size: .75rem; }
.sms-counter.over { color: #dc3545; font-weight: 600; }

/* ── quick-build ──────────────────────────────────────────── */
.qb-tag {
    display: inline-flex; align-items: center; gap: .25rem;
    padding: .2rem .55rem;
    background: #e7f0fd; color: #1a56db;
    border-radius: 1rem; font-size: .78rem;
    cursor: pointer; user-select: none;
    border: 1px solid #c3d9fb;
    transition: background .1s;
}
.qb-tag:hover { background: #c3d9fb; }
</style>

<nav aria-label="Ścieżka nawigacji" class="mb-2">
  <ol class="breadcrumb mb-0" style="font-size:.82rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php"><i class="bi bi-diagram-2-fill me-1" style="color:var(--crm-primary)"></i>CRM</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia</a></li>
    <li class="breadcrumb-item active">Mój podpis</li>
  </ol>
</nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-pen-fill"></i></div>
  <div>
    <h1 class="crm-object-title">Mój podpis</h1>
    <div class="crm-object-count">Podpis dołączany do Twoich wiadomości e-mail i SMS w CRM</div>
  </div>
</div>

<?= flash_html() ?>

<form method="post" id="sig-form">
<input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
<!-- ukryte pole — wartość HTML edytora -->
<input type="hidden" name="crm_email_signature" id="crm_email_signature_hidden">

<!-- ── Dane nadawcy ────────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header fw-semibold bg-transparent border-bottom">
    <i class="bi bi-person-badge me-1 text-primary"></i> Dane nadawcy
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <label for="sig_name_display" class="form-label small fw-semibold">Imię i nazwisko</label>
        <input type="text" id="sig_name_display" class="form-control" value="<?= h($name) ?>" disabled>
        <div class="form-text">Zmień w <a href="<?= APP_URL ?>/user/profile.php">profilu użytkownika</a>.</div>
      </div>
      <div class="col-md-4">
        <label for="crm_job_title" class="form-label small fw-semibold">Stanowisko / rola</label>
        <input type="text" id="crm_job_title" name="crm_job_title"
               class="form-control" placeholder="np. Koordynator wolontariatu"
               maxlength="120"
               value="<?= h($u['crm_job_title'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label for="crm_display_phone" class="form-label small fw-semibold">Telefon do podpisu</label>
        <input type="text" id="crm_display_phone" name="crm_display_phone"
               class="form-control" placeholder="np. +48 123 456 789"
               maxlength="40"
               value="<?= h($u['crm_display_phone'] ?? '') ?>">
      </div>
    </div>

    <!-- Quick-build tags -->
    <div class="mt-3">
      <div class="small fw-semibold text-muted mb-2">Szybkie wstawianie do podpisu e-mail:</div>
      <div class="d-flex flex-wrap gap-2" id="qb-tags">
        <span class="qb-tag" data-insert="name" title="Wstaw imię i nazwisko">
          <i class="bi bi-person"></i> <?= h($name) ?>
        </span>
        <span class="qb-tag" data-insert="title" title="Wstaw stanowisko">
          <i class="bi bi-briefcase"></i> Stanowisko
        </span>
        <span class="qb-tag" data-insert="phone" title="Wstaw telefon">
          <i class="bi bi-telephone"></i> Telefon
        </span>
        <span class="qb-tag" data-insert="email" title="Wstaw e-mail">
          <i class="bi bi-envelope"></i> <?= h($u['email'] ?? '') ?>
        </span>
        <span class="qb-tag" data-insert="org" title="Wstaw nazwę organizacji">
          <i class="bi bi-building"></i> <?= h(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Organizacja')) ?>
        </span>
        <span class="qb-tag" data-insert="hr" title="Wstaw poziomą linię">
          <i class="bi bi-dash-lg"></i> Linia
        </span>
      </div>
    </div>
  </div>
</div>

<!-- ── Podpis e-mail ──────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header fw-semibold bg-transparent border-bottom">
    <i class="bi bi-envelope-at me-1 text-primary"></i> Podpis e-mail
    <span class="text-muted fw-normal small ms-2">— dołączany po treści każdej wiadomości e-mail wysyłanej przez Ciebie</span>
  </div>
  <div class="card-body">
    <div class="sig-split">
      <!-- Edytor -->
      <div>
        <label class="form-label small fw-semibold mb-1">Edytor</label>
        <!-- Toolbar -->
        <div class="sig-toolbar" role="toolbar" aria-label="Formatowanie podpisu">
          <button type="button" data-cmd="bold"        title="Pogrubienie (Ctrl+B)"><i class="bi bi-type-bold"></i></button>
          <button type="button" data-cmd="italic"      title="Kursywa (Ctrl+I)"><i class="bi bi-type-italic"></i></button>
          <button type="button" data-cmd="underline"   title="Podkreślenie (Ctrl+U)"><i class="bi bi-type-underline"></i></button>
          <div class="sep" aria-hidden="true"></div>
          <select data-cmd="fontSize" title="Rozmiar tekstu" aria-label="Rozmiar tekstu">
            <option value="">Rozmiar</option>
            <option value="1">XS</option>
            <option value="2">S</option>
            <option value="3" selected>M</option>
            <option value="4">L</option>
            <option value="5">XL</option>
          </select>
          <div class="sep" aria-hidden="true"></div>
          <button type="button" data-cmd="foreColor" data-value="#1a56db" title="Kolor niebieski" style="color:#1a56db"><i class="bi bi-type"></i></button>
          <button type="button" data-cmd="foreColor" data-value="#16a34a" title="Kolor zielony"  style="color:#16a34a"><i class="bi bi-type"></i></button>
          <button type="button" data-cmd="foreColor" data-value="#dc2626" title="Kolor czerwony" style="color:#dc2626"><i class="bi bi-type"></i></button>
          <button type="button" data-cmd="foreColor" data-value="#374151" title="Kolor szary"    style="color:#374151"><i class="bi bi-type"></i></button>
          <button type="button" id="btn-color-custom" title="Własny kolor"><i class="bi bi-palette"></i></button>
          <input type="color" id="color-picker" style="width:0;height:0;opacity:0;position:absolute" tabindex="-1">
          <div class="sep" aria-hidden="true"></div>
          <button type="button" data-cmd="createLink" title="Wstaw link"><i class="bi bi-link-45deg"></i></button>
          <button type="button" data-cmd="unlink"     title="Usuń link"><i class="bi bi-link"></i></button>
          <div class="sep" aria-hidden="true"></div>
          <button type="button" data-cmd="removeFormat" title="Wyczyść formatowanie"><i class="bi bi-eraser"></i></button>
          <div class="sep" aria-hidden="true"></div>
          <button type="button" id="btn-clear-editor" title="Wyczyść całą zawartość" class="text-danger"><i class="bi bi-trash3"></i></button>
        </div>
        <!-- Edytor -->
        <div id="email-sig-editor"
             contenteditable="true"
             role="textbox"
             aria-multiline="true"
             aria-label="Treść podpisu e-mail"
             spellcheck="true"><?= ($u['crm_email_signature'] !== '' && $u['crm_email_signature'] !== null)
                 ? $u['crm_email_signature']
                 : $default_email_sig ?></div>
        <div class="form-text mt-1">Obsługiwany HTML. Zmiany widać w podglądzie na żywo.</div>
      </div>

      <!-- Podgląd -->
      <div>
        <label class="form-label small fw-semibold mb-1">Podgląd wiadomości</label>
        <div class="sig-preview-wrap">
          <div class="sig-preview-header">
            <i class="bi bi-envelope"></i>
            <span>Od: <strong><?= h($name) ?></strong> &lt;<?= h($u['email'] ?? '') ?>&gt;</span>
          </div>
          <div class="sig-preview-body">
            <p class="text-muted fst-italic" style="font-size:.85rem;margin:0 0 .75rem">
              Treść Twojej wiadomości…
            </p>
            <hr class="sig-preview-separator">
            <div id="sig-preview-content"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Podpis SMS ─────────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold bg-transparent border-bottom">
    <i class="bi bi-chat-text me-1 text-primary"></i> Podpis SMS
    <span class="text-muted fw-normal small ms-2">— dołączany na końcu każdego SMS-a</span>
  </div>
  <div class="card-body">
    <div style="max-width:520px">
      <label for="crm_sms_signature" class="form-label small fw-semibold">Tekst podpisu SMS</label>
      <textarea id="crm_sms_signature" name="crm_sms_signature"
                class="form-control font-monospace"
                rows="3"
                maxlength="160"
                placeholder="np. Pozdrawiam, Jan Kowalski / FEER"><?= h($u['crm_sms_signature'] ?? '') ?></textarea>
      <div class="d-flex justify-content-between mt-1">
        <div class="form-text">Tylko tekst. Maksymalnie 160 znaków.</div>
        <div class="sms-counter" id="sms-counter">0 / 160</div>
      </div>

      <!-- Podgląd SMS -->
      <div class="mt-3">
        <div class="small fw-semibold text-muted mb-2">Podgląd:</div>
        <div style="
          background:#e9f5e9; border-radius:.5rem 0 .5rem .5rem;
          padding:.65rem .9rem; font-size:.875rem; max-width: 320px;
          box-shadow: 0 1px 3px rgba(0,0,0,.12);
          line-height: 1.5; white-space: pre-wrap; word-break: break-word;
        ">
          <span class="text-muted fst-italic">Treść wiadomości SMS…</span>
          <span id="sms-preview-sep"></span>
          <span id="sms-preview-sig" class="text-muted"></span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="d-flex gap-2 mb-5">
  <button type="submit" class="btn btn-crm-primary">
    <i class="bi bi-check-lg me-1"></i>Zapisz podpis
  </button>
  <a href="<?= APP_URL ?>/crm/settings/" class="btn btn-outline-secondary">Anuluj</a>
</div>

</form>

<script>
(function () {
'use strict';

/* ── Dane z PHP ──────────────────────────────────────────── */
const SENDER_NAME  = <?= json_encode($name) ?>;
const SENDER_EMAIL = <?= json_encode($u['email'] ?? '') ?>;
const ORG_NAME     = <?= json_encode(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '')) ?>;

const editor    = document.getElementById('email-sig-editor');
const preview   = document.getElementById('sig-preview-content');
const hiddenFld = document.getElementById('crm_email_signature_hidden');
const smsTa     = document.getElementById('crm_sms_signature');
const smsCtr    = document.getElementById('sms-counter');
const smsPreSig = document.getElementById('sms-preview-sig');
const smsPreSep = document.getElementById('sms-preview-sep');

/* ── Live preview e-mail ─────────────────────────────────── */
function syncPreview() {
  preview.innerHTML = editor.innerHTML;
  hiddenFld.value   = editor.innerHTML;
}
editor.addEventListener('input', syncPreview);
syncPreview(); // initial

/* ── Toolbar execCommand ─────────────────────────────────── */
document.querySelectorAll('.sig-toolbar button[data-cmd]').forEach(btn => {
  btn.addEventListener('mousedown', e => {
    e.preventDefault();
    const cmd = btn.dataset.cmd;
    const val = btn.dataset.value || null;
    if (cmd === 'createLink') {
      const url = prompt('Wpisz adres URL:', 'https://');
      if (url) document.execCommand('createLink', false, url);
    } else if (cmd === 'foreColor' && val) {
      document.execCommand('foreColor', false, val);
    } else {
      document.execCommand(cmd, false, val);
    }
    editor.focus();
    syncPreview();
  });
});

/* ── select fontSize ─────────────────────────────────────── */
document.querySelector('.sig-toolbar select[data-cmd]')?.addEventListener('change', function() {
  document.execCommand('fontSize', false, this.value);
  editor.focus();
  syncPreview();
  this.value = '';
});

/* ── Własny kolor ────────────────────────────────────────── */
const colorPicker = document.getElementById('color-picker');
document.getElementById('btn-color-custom').addEventListener('mousedown', e => {
  e.preventDefault();
  colorPicker.click();
});
colorPicker.addEventListener('input', () => {
  document.execCommand('foreColor', false, colorPicker.value);
  editor.focus();
  syncPreview();
});

/* ── Wyczyść ─────────────────────────────────────────────── */
document.getElementById('btn-clear-editor').addEventListener('click', () => {
  if (confirm('Wyczyścić całą treść podpisu?')) {
    editor.innerHTML = '';
    syncPreview();
    editor.focus();
  }
});

/* ── Quick-build tagi ────────────────────────────────────── */
const jobTitleInput  = document.getElementById('crm_job_title');
const phoneInput     = document.getElementById('crm_display_phone');

function wrapP(html) { return '<p style="margin:0">' + html + '</p>'; }
function wrapSmall(html) { return '<p style="margin:0;color:#555;font-size:.9em">' + html + '</p>'; }

const inserts = {
  name:  () => wrapP('<strong>' + escHtml(SENDER_NAME) + '</strong>'),
  title: () => { const t = jobTitleInput.value.trim(); return t ? wrapSmall(escHtml(t)) : ''; },
  phone: () => { const p = phoneInput.value.trim();    return p ? wrapSmall(escHtml(p)) : ''; },
  email: () => SENDER_EMAIL ? wrapSmall('<a href="mailto:' + escHtml(SENDER_EMAIL) + '">' + escHtml(SENDER_EMAIL) + '</a>') : '',
  org:   () => ORG_NAME ? wrapSmall(escHtml(ORG_NAME)) : '',
  hr:    () => '<hr style="border:none;border-top:1px solid #ccc;margin:.5rem 0">',
};

document.querySelectorAll('.qb-tag').forEach(tag => {
  tag.addEventListener('click', () => {
    const key = tag.dataset.insert;
    const html = inserts[key] ? inserts[key]() : '';
    if (!html) return;
    editor.focus();
    const sel = window.getSelection();
    if (sel && sel.rangeCount) {
      const range = sel.getRangeAt(0);
      range.collapse(false);
      const tpl = document.createElement('div');
      tpl.innerHTML = html;
      const frag = document.createDocumentFragment();
      let node;
      while ((node = tpl.firstChild)) frag.appendChild(node);
      range.insertNode(frag);
      range.collapse(false);
      sel.removeAllRanges();
      sel.addRange(range);
    } else {
      editor.insertAdjacentHTML('beforeend', html);
    }
    syncPreview();
  });
});

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── SMS counter + preview ───────────────────────────────── */
function updateSms() {
  const len = smsTa.value.length;
  smsCtr.textContent = len + ' / 160';
  smsCtr.classList.toggle('over', len > 160);
  if (smsTa.value.trim()) {
    smsPreSep.textContent = '\n';
    smsPreSig.textContent = smsTa.value;
  } else {
    smsPreSep.textContent = '';
    smsPreSig.textContent = '';
  }
}
smsTa.addEventListener('input', updateSms);
updateSms();

/* ── Submit: wstrzyknij HTML przed POST ──────────────────── */
document.getElementById('sig-form').addEventListener('submit', () => {
  hiddenFld.value = editor.innerHTML;
});

})();
</script>

<?php require_once __DIR__ . '/_nav_end.php'; ?>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
