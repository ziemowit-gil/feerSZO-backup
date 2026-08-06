<?php
/**
 * ezd/poczta/compose.php — Kompozytor wiadomości e-mail EZD.
 *
 * GET  ?sprawa_id=X&reply_to=Y   → formularz TinyMCE z pre-wypełnieniem
 * POST (JSON, X-Requested-With)  → wyślij; zwraca JSON {ok, comm_id, pismo_id, error}
 * POST (form, _ajax=0)           → redirect z flash
 *
 * Funkcje:
 *  - Edytor TinyMCE (spójny z resztą EZD)
 *  - Auto-wstrzyknięcie [EZD: ZNAK] do tematu
 *  - Wstawianie stopki użytkownika + stopki organizacyjnej (z CRM)
 *  - Tagi zmiennych: {{sprawa.znak}}, {{uzytkownik.imie_nazwisko}}, {{data}}
 *  - Załączniki (upload przez mail_queue_save_attachment)
 *  - DW i UDW
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_mail.php';
require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$user     = current_user();
$user_id  = (int)($user['id'] ?? 0);
$is_json  = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json');

// ── POST: wysyłka ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($is_json) {
        header('Content-Type: application/json; charset=utf-8');
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $data = $_POST;
        // Załączniki upload przez form
        if (!empty($_FILES['attachments']['tmp_name'])) {
            $atts = [];
            $names = (array)$_FILES['attachments']['name'];
            $tmps  = (array)$_FILES['attachments']['tmp_name'];
            $types = (array)$_FILES['attachments']['type'];
            $sizes = (array)$_FILES['attachments']['size'];
            foreach ($names as $i => $n) {
                if (!$tmps[$i]) continue;
                $saved = mail_queue_save_attachment([
                    'name'     => $n,
                    'tmp_name' => $tmps[$i],
                    'type'     => $types[$i],
                    'size'     => $sizes[$i],
                ]);
                if ($saved) $atts[] = $saved;
            }
            $data['attachments'] = $atts;
        }
    }

    if (($data['_csrf'] ?? '') !== csrf_token()) {
        $err = ['ok' => false, 'error' => 'Błąd CSRF.'];
        if ($is_json) { echo json_encode($err); exit; }
        flash_set('error', $err['error']);
        header('Location: ' . APP_URL . '/ezd/poczta/compose.php');
        exit;
    }

    $sprawa_id  = (int)($data['sprawa_id'] ?? 0);
    $to_email   = trim($data['to_email'] ?? '');
    $to_name    = trim($data['to_name']   ?? '');
    $subject    = trim($data['subject']   ?? '');
    $body_html  = $data['body_html'] ?? $data['body'] ?? '';
    $from_email = trim($data['from_email'] ?? '');
    $cc_raw     = trim($data['cc_emails'] ?? '');
    $bcc_raw    = trim($data['bcc_emails'] ?? '');
    $contact_id = (int)($data['contact_id'] ?? 0);
    $attachments = $data['attachments'] ?? [];

    // Sanityzacja HTML przez dopuszczone tagi (ochrona przed XSS)
    $allowed_tags = '<p><br><b><strong><i><em><u><s><h1><h2><h3><h4><ul><ol><li>'
        . '<a><img><table><thead><tbody><tr><th><td><blockquote><hr><span><div>'
        . '<style><pre><code>';
    $body_html = strip_tags($body_html, $allowed_tags);

    // Parsowanie DW / UDW
    $cc_emails  = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $cc_raw))));
    $bcc_emails = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $bcc_raw))));

    $errors = [];
    if (!$sprawa_id)                                    $errors[] = 'Wybierz sprawę EZD.';
    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL))  $errors[] = 'Nieprawidłowy adres e-mail odbiorcy.';
    if (!$subject)                                      $errors[] = 'Temat jest wymagany.';
    if (!$body_html)                                    $errors[] = 'Treść wiadomości jest wymagana.';
    foreach ($cc_emails  as $e) { if (!filter_var($e, FILTER_VALIDATE_EMAIL)) $errors[] = "Nieprawidłowy adres DW: {$e}"; }
    foreach ($bcc_emails as $e) { if (!filter_var($e, FILTER_VALIDATE_EMAIL)) $errors[] = "Nieprawidłowy adres UDW: {$e}"; }

    // Weryfikacja dostępu do sprawy
    if ($sprawa_id && !$errors) {
        $sprawa_chk = db_one("SELECT id FROM ezd_sprawy WHERE id=?", [$sprawa_id]);
        if (!$sprawa_chk) $errors[] = 'Sprawa nie istnieje lub brak dostępu.';
    }

    if ($errors) {
        $resp = ['ok' => false, 'error' => implode(' ', $errors)];
        if ($is_json) { echo json_encode($resp); exit; }
        flash_set('error', $resp['error']);
        header('Location: ' . APP_URL . '/ezd/poczta/compose.php?' . http_build_query(['sprawa_id' => $sprawa_id]));
        exit;
    }

    try {
        $svc = new EzdMailService();
        $res = $svc->compose([
            'sprawa_id'   => $sprawa_id,
            'to_email'    => $to_email,
            'to_name'     => $to_name,
            'subject'     => $subject,
            'body_html'   => $body_html,
            'from_email'  => $from_email,
            'cc_emails'   => $cc_emails,
            'bcc_emails'  => $bcc_emails,
            'attachments' => $attachments,
            'contact_id'  => $contact_id,
        ]);
        $resp = ['ok' => true, 'comm_id' => $res['comm_id'], 'pismo_id' => $res['pismo_id'],
                 'message' => 'Wiadomość wysłana i zarejestrowana w aktach sprawy.'];
    } catch (\Throwable $e) {
        $resp = ['ok' => false, 'error' => $e->getMessage()];
    }

    if ($is_json) { echo json_encode($resp); exit; }

    if ($resp['ok']) {
        flash_set('success', $resp['message']);
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $sprawa_id . '#tab-korespondencja');
    } else {
        flash_set('error', $resp['error']);
        header('Location: ' . APP_URL . '/ezd/poczta/compose.php?' . http_build_query(['sprawa_id' => $sprawa_id]));
    }
    exit;
}

// ── GET: formularz ─────────────────────────────────────────────────────────────
$sprawa_id = (int)($_GET['sprawa_id'] ?? 0);
$reply_to  = (int)($_GET['reply_to']  ?? 0);   // ID crm_communications do odpowiedzi

$sprawa = $sprawa_id ? db_one("SELECT * FROM ezd_sprawy WHERE id=?", [$sprawa_id]) : null;

// Dane do wstępnego wypełnienia przy odpowiedzi
$prefill = ['to_email'=>'','to_name'=>'','subject'=>'','thread_key'=>''];
if ($reply_to) {
    $orig = db_one("SELECT * FROM crm_communications WHERE id=?", [$reply_to]);
    if ($orig) {
        $prefill['to_email']   = $orig['from_email']  ?? '';
        $prefill['to_name']    = $orig['from_name']   ?? '';
        $prefill['subject']    = 'Re: ' . preg_replace('/^Re:\s*/i', '', $orig['subject'] ?? '');
        $prefill['thread_key'] = $orig['thread_key']  ?? '';
        if (!$sprawa_id && $orig['ezd_sprawa_id']) {
            $sprawa_id = (int)$orig['ezd_sprawa_id'];
            $sprawa    = db_one("SELECT * FROM ezd_sprawy WHERE id=?", [$sprawa_id]);
        }
    }
}

// Skrzynki wysyłkowe (M365 skrzynki powiązane z userem)
$user_accounts = db_all(
    "SELECT mailbox, display_name, is_default FROM ezd_mail_user_accounts WHERE user_id=? ORDER BY is_default DESC, mailbox",
    [$user_id]
);
// Fallback na globalną skrzynkę skanowania
if (!$user_accounts) {
    $user_accounts = db_all("SELECT mailbox, display_name, 1 AS is_default FROM poczta_mailboxes WHERE enabled=1 ORDER BY mailbox LIMIT 5");
}

// Stopki
$sigs = EzdMailService::fetchSignatures($user_id);

// Zmienne do TinyMCE
$vars_list = [
    '{{sprawa.znak}}'              => 'Znak sprawy',
    '{{sprawa.tytul}}'             => 'Tytuł sprawy',
    '{{uzytkownik.imie_nazwisko}}' => 'Imię i nazwisko (nadawca)',
    '{{uzytkownik.email}}'         => 'E-mail (nadawca)',
    '{{data}}'                     => 'Data (dzisiaj)',
    '{{data_czas}}'                => 'Data i czas',
];

$PAGE_TITLE = 'Nowa wiadomość' . ($sprawa ? ' — ' . h($sprawa['znak_sprawy']) : '');

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="container-fluid px-3 px-md-4" style="max-width:1060px">

  <!-- Breadcrumb -->
  <nav aria-label="breadcrumb" style="font-size:.82rem" class="mb-3">
    <ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/poczta/index.php">Poczta EZD</a></li>
      <?php if ($sprawa): ?>
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>"><?= h($sprawa['znak_sprawy']) ?></a></li>
      <?php endif; ?>
      <li class="breadcrumb-item active">Nowa wiadomość</li>
    </ol>
  </nav>

  <div class="card shadow-sm border-0">
    <div class="card-header bg-white d-flex align-items-center gap-2 py-2">
      <i class="bi bi-pencil-square text-primary"></i>
      <span class="fw-semibold">Nowa wiadomość e-mail</span>
      <?php if ($sprawa): ?>
      <span class="ms-auto badge rounded-pill text-bg-info" style="font-size:.72rem">
        <i class="bi bi-folder2-open me-1"></i><?= h($sprawa['znak_sprawy']) ?>
      </span>
      <?php endif; ?>
    </div>

    <div class="card-body p-3">
      <form id="composeForm" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

        <!-- Sprawa EZD ──────────────────────────────────────────────────────── -->
        <div class="row g-2 mb-2">
          <div class="col-12 col-md-6">
            <label class="form-label small fw-semibold mb-1">
              Sprawa EZD <span class="text-danger">*</span>
            </label>
            <?php if ($sprawa): ?>
            <input type="hidden" name="sprawa_id" value="<?= $sprawa_id ?>">
            <div class="form-control form-control-sm bg-light" style="cursor:default">
              <i class="bi bi-folder2-open me-1 text-info"></i>
              <strong><?= h($sprawa['znak_sprawy']) ?></strong> — <?= h(mb_substr($sprawa['title'],0,60)) ?>
              <a href="#" id="changeSprawaBtn" class="ms-2 small text-muted">zmień</a>
            </div>
            <?php else: ?>
            <input type="hidden" name="sprawa_id" id="sprzawaIdInput" value="">
            <div class="input-group input-group-sm">
              <input type="text" id="sprawaSearchInput" class="form-control" placeholder="Wpisz znak lub tytuł sprawy…" autocomplete="off">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
            </div>
            <div id="sprawaSearchResults" class="list-group mt-1" style="display:none;position:absolute;z-index:999;width:100%;max-height:200px;overflow-y:auto"></div>
            <div id="sprawaSelectedInfo" class="alert alert-info py-1 mt-1 small" style="display:none">
              <i class="bi bi-check-circle me-1"></i><span id="sprawaSelectedText"></span>
            </div>
            <?php endif; ?>
          </div>

          <!-- Skrzynka nadawcy ───────────────────────────────────────────── -->
          <div class="col-12 col-md-6">
            <label class="form-label small fw-semibold mb-1">Skrzynka nadawcy</label>
            <?php if (count($user_accounts) === 1): ?>
            <input type="hidden" name="from_email" value="<?= h($user_accounts[0]['mailbox']) ?>">
            <input type="text" class="form-control form-control-sm bg-light" value="<?= h($user_accounts[0]['display_name'] ?: $user_accounts[0]['mailbox']) ?>" readonly>
            <?php elseif ($user_accounts): ?>
            <select name="from_email" class="form-select form-select-sm">
              <?php foreach ($user_accounts as $acct): ?>
              <option value="<?= h($acct['mailbox']) ?>" <?= $acct['is_default'] ? 'selected' : '' ?>>
                <?= h($acct['display_name'] ?: $acct['mailbox']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="text" name="from_email" class="form-control form-control-sm" placeholder="adres@feer.org.pl (opcjonalne)">
            <?php endif; ?>
          </div>
        </div>

        <!-- Odbiorca ─────────────────────────────────────────────────────────── -->
        <div class="row g-2 mb-2">
          <div class="col-12 col-md-8">
            <label class="form-label small fw-semibold mb-1">Do <span class="text-danger">*</span></label>
            <input type="email" name="to_email" id="toEmail" class="form-control form-control-sm"
                   placeholder="odbiorca@domena.pl" value="<?= h($prefill['to_email']) ?>" required autocomplete="email">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label small fw-semibold mb-1">Nazwa odbiorcy</label>
            <input type="text" name="to_name" class="form-control form-control-sm"
                   placeholder="Jan Kowalski" value="<?= h($prefill['to_name']) ?>">
          </div>
        </div>

        <!-- DW / UDW (zwijane) ──────────────────────────────────────────────── -->
        <div class="mb-2">
          <a class="small text-muted text-decoration-none" data-bs-toggle="collapse" href="#ccBccPanel">
            <i class="bi bi-chevron-down"></i> DW / UDW
          </a>
          <div class="collapse" id="ccBccPanel">
            <div class="row g-2 mt-1">
              <div class="col-12 col-md-6">
                <label class="form-label small fw-semibold mb-1">DW (Do Wiadomości)</label>
                <input type="text" name="cc_emails" class="form-control form-control-sm"
                       placeholder="adres1@d.pl, adres2@d.pl">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label small fw-semibold mb-1">UDW (Ukryta DW)</label>
                <input type="text" name="bcc_emails" class="form-control form-control-sm"
                       placeholder="adres1@d.pl, adres2@d.pl">
              </div>
            </div>
          </div>
        </div>

        <!-- Temat ─────────────────────────────────────────────────────────────── -->
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1">Temat <span class="text-danger">*</span></label>
          <div class="input-group input-group-sm">
            <input type="text" name="subject" id="subjectInput" class="form-control"
                   placeholder="Temat wiadomości" value="<?= h($prefill['subject']) ?>" required>
            <?php if ($sprawa): ?>
            <span class="input-group-text text-muted" style="font-size:.75rem" title="Tag EZD zostanie dodany automatycznie">
              <i class="bi bi-tag-fill text-info me-1"></i>[EZD: <?= h($sprawa['znak_sprawy']) ?>]
            </span>
            <?php endif; ?>
          </div>
          <div class="form-text text-muted" style="font-size:.72rem">
            <i class="bi bi-info-circle me-1"></i>Numer sprawy <code>[EZD: <?= $sprawa ? h($sprawa['znak_sprawy']) : 'ZNAK' ?>]</code> zostanie automatycznie dołączony do tematu.
          </div>
        </div>

        <!-- Pasek narzędzi kompozytora ──────────────────────────────────────── -->
        <div class="d-flex flex-wrap gap-1 mb-1 align-items-center" style="font-size:.8rem">
          <span class="text-muted me-1">Wstaw:</span>
          <?php foreach ($vars_list as $var => $label): ?>
          <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 btn-insert-var" data-var="<?= h($var) ?>">
            <?= h($label) ?>
          </button>
          <?php endforeach; ?>
          <div class="ms-auto d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-info py-0 px-2" id="btnInsertUserSig" title="Wstaw mój podpis">
              <i class="bi bi-person-badge me-1"></i>Mój podpis
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="btnInsertOrgSig" title="Wstaw stopkę organizacji">
              <i class="bi bi-building me-1"></i>Stopka org.
            </button>
          </div>
        </div>

        <!-- TinyMCE editor ──────────────────────────────────────────────────── -->
        <div class="mb-3">
          <textarea id="bodyEditor" name="body_html" style="display:none"></textarea>
        </div>

        <!-- Załączniki ─────────────────────────────────────────────────────── -->
        <div class="mb-3">
          <label class="form-label small fw-semibold mb-1"><i class="bi bi-paperclip me-1"></i>Załączniki</label>
          <input type="file" name="attachments[]" id="attachmentsInput" class="form-control form-control-sm" multiple>
          <div class="form-text text-muted" style="font-size:.72rem">
            Maksymalnie <?= ini_get('upload_max_filesize') ?> na plik. Akceptowane: PDF, DOCX, XLSX, JPG, PNG, ZIP.
          </div>
        </div>

        <!-- Przyciski ─────────────────────────────────────────────────────── -->
        <div class="d-flex gap-2 justify-content-end">
          <a href="<?= $sprawa_id ? APP_URL . '/ezd/sprawy/view.php?id=' . $sprawa_id : APP_URL . '/ezd/poczta/index.php' ?>"
             class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-x-lg me-1"></i>Anuluj
          </a>
          <button type="submit" class="btn btn-sm btn-primary" id="btnSend" <?= !$sprawa_id ? 'disabled' : '' ?>>
            <i class="bi bi-send me-1"></i>Wyślij
          </button>
        </div>

      </form>
    </div>
  </div>

  <!-- Alert (JS) -->
  <div id="composeAlert" class="alert mt-3" style="display:none"></div>

</div>

<!-- TinyMCE 7 (CDN, spójny z ezd/zaswiadczenia) -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
const _CSRF    = <?= json_encode(csrf_token()) ?>;
const _API     = <?= json_encode(APP_URL . '/ezd/poczta/api.php') ?>;
const _APP_URL = <?= json_encode(APP_URL) ?>;
const _SIG_USER = <?= json_encode($sigs['user']) ?>;
const _SIG_ORG  = <?= json_encode($sigs['org']) ?>;
const _SPRAWA_ID = <?= $sprawa_id ?: 'null' ?>;

// ── TinyMCE init ──────────────────────────────────────────────────────────────
tinymce.init({
  selector: '#bodyEditor',
  license_key: 'gpl',
  language: 'pl',
  language_url: _APP_URL + '/assets/js/tinymce/langs/pl.js',
  promotion: false,
  branding: false,
  menubar: 'edit format insert table',
  toolbar: 'undo redo | blocks | bold italic underline strikethrough | forecolor backcolor | '
         + 'alignleft aligncenter alignright | bullist numlist | link image | removeformat',
  plugins: 'lists link image table code paste',
  height: 360,
  content_style: [
    'body { font-family: system-ui, -apple-system, sans-serif; font-size: 14px; line-height: 1.6; color: #1f2937; padding: 12px 16px; }',
    'a { color: #2563eb; } blockquote { border-left: 3px solid #e5e7eb; padding-left: 1rem; color: #6b7280; margin: 0 0 1rem; }'
  ].join(' '),
  entity_encoding: 'raw',
  setup: function(editor) {
    // Synchronizuj z <textarea> przy submicie
    editor.on('change', () => editor.save());
  }
});

// ── Wstaw zmienną szablonu ────────────────────────────────────────────────────
document.querySelectorAll('.btn-insert-var').forEach(btn => {
  btn.addEventListener('click', () => {
    const ed = tinymce.get('bodyEditor');
    if (ed) ed.insertContent(btn.dataset.var);
  });
});

// ── Wstaw podpis użytkownika ──────────────────────────────────────────────────
document.getElementById('btnInsertUserSig')?.addEventListener('click', () => {
  const ed = tinymce.get('bodyEditor');
  if (!ed) return;
  if (_SIG_USER) {
    ed.insertContent('<hr style="border:none;border-top:1px solid #e5e7eb;margin:1rem 0">' + _SIG_USER);
  } else {
    alert('Nie masz ustawionego podpisu e-mail. Skonfiguruj go w Ustawieniach konta → Podpis CRM.');
  }
});

// ── Wstaw stopkę organizacji ──────────────────────────────────────────────────
document.getElementById('btnInsertOrgSig')?.addEventListener('click', () => {
  const ed = tinymce.get('bodyEditor');
  if (!ed) return;
  if (_SIG_ORG) {
    ed.insertContent('<hr style="border:none;border-top:1px solid #e5e7eb;margin:1rem 0">' + _SIG_ORG);
  } else {
    alert('Stopka organizacji nie jest ustawiona. Skonfiguruj ją w Ustawieniach CRM.');
  }
});

// ── Wyszukiwarka spraw (gdy brak kontekstu) ────────────────────────────────────
<?php if (!$sprawa_id): ?>
let _selectedSprawaId = null;
let _sprawaSearchTimer;
const sprawaIn  = document.getElementById('sprawaSearchInput');
const sprawaRes = document.getElementById('sprawaSearchResults');
const sprawaHid = document.getElementById('sprzawaIdInput');
const btnSend   = document.getElementById('btnSend');

sprawaIn?.addEventListener('input', function() {
  clearTimeout(_sprawaSearchTimer);
  const q = this.value.trim();
  if (q.length < 2) { sprawaRes.style.display='none'; return; }
  _sprawaSearchTimer = setTimeout(() => {
    fetch(_API + '?action=search_sprawa&q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(data => {
        sprawaRes.innerHTML = '';
        if (!data.rows?.length) {
          sprawaRes.innerHTML = '<div class="list-group-item text-muted small py-1">Brak wyników</div>';
        } else {
          data.rows.forEach(s => {
            const a = document.createElement('a');
            a.className = 'list-group-item list-group-item-action py-1 small';
            a.href = '#';
            a.innerHTML = `<strong>${s.znak_sprawy}</strong> — ${s.title}`;
            a.addEventListener('click', e => {
              e.preventDefault();
              _selectedSprawaId = s.id;
              sprawaHid.value = s.id;
              document.getElementById('sprawaSelectedText').textContent = s.znak_sprawy + ' — ' + s.title;
              document.getElementById('sprawaSelectedInfo').style.display = '';
              sprawaRes.style.display = 'none';
              btnSend.disabled = false;
            });
            sprawaRes.appendChild(a);
          });
        }
        sprawaRes.style.display = '';
      });
  }, 280);
});
document.addEventListener('click', e => {
  if (!sprawaIn?.contains(e.target)) sprawaRes.style.display='none';
});
<?php else: ?>
document.getElementById('changeSprawaBtn')?.addEventListener('click', e => {
  e.preventDefault();
  window.location = _APP_URL + '/ezd/poczta/compose.php';
});
<?php endif; ?>

// ── Wysyłka formularza (AJAX) ──────────────────────────────────────────────────
document.getElementById('composeForm')?.addEventListener('submit', async function(e) {
  e.preventDefault();

  const ed = tinymce.get('bodyEditor');
  if (ed) ed.save();

  const btn = document.getElementById('btnSend');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Wysyłanie…';

  try {
    const formData = new FormData(this);
    const resp = await fetch(window.location.pathname, {
      method: 'POST',
      headers: {'X-Requested-With': 'XMLHttpRequest'},
      body: formData
    });
    const data = await resp.json();

    const alertEl = document.getElementById('composeAlert');
    if (data.ok) {
      alertEl.className = 'alert alert-success mt-3';
      alertEl.innerHTML = '<i class="bi bi-check-circle me-1"></i>' + data.message;
      alertEl.style.display = '';
      this.reset();
      ed?.setContent('');
      setTimeout(() => {
        if (_SPRAWA_ID) {
          window.location = _APP_URL + '/ezd/sprawy/view.php?id=' + _SPRAWA_ID + '#tab-korespondencja';
        } else {
          window.location = _APP_URL + '/ezd/poczta/index.php?status=sent';
        }
      }, 1400);
    } else {
      alertEl.className = 'alert alert-danger mt-3';
      alertEl.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>' + (data.error || 'Nieznany błąd.');
      alertEl.style.display = '';
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-send me-1"></i>Wyślij';
    }
  } catch (err) {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-send me-1"></i>Wyślij';
    alert('Błąd sieci: ' + err.message);
  }
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
