<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin', 'editor');
require_once __DIR__ . '/../includes/messages.php';

// ── ensure messages table ──────────────────────────────────────────
db()->exec("CREATE TABLE IF NOT EXISTS onboarding_messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    volunteer_id INTEGER NOT NULL,
    subject     TEXT NOT NULL DEFAULT '',
    body        TEXT NOT NULL DEFAULT '',
    sent_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_by     INTEGER
)");

$id = intval($_GET['id'] ?? 0);
$vol = $id ? db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$id]) : null;
if (!$vol) {
    http_response_code(404);
    $PAGE_TITLE = 'Nie znaleziono';
    include __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">Zgłoszenie nie zostało znalezione.</div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $now = date('Y-m-d H:i:s');

    if ($action === 'verify') {
        db()->prepare("UPDATE onboarding_volunteers SET status='verified', updated_at=? WHERE id=?")
            ->execute([$now, $id]);
        flash_set('success', 'Oznaczono jako zweryfikowane.');
        header('Location: ' . APP_URL . '/onboarding/view.php?id=' . $id);
        exit;
    }

    if ($action === 'reject') {
        $note = trim($_POST['admin_note'] ?? '');
        db()->prepare("UPDATE onboarding_volunteers SET status='rejected', admin_note=?, updated_at=? WHERE id=?")
            ->execute([$note, $now, $id]);
        flash_set('warning', 'Zgłoszenie odrzucone.');
        header('Location: ' . APP_URL . '/onboarding/view.php?id=' . $id);
        exit;
    }

    if ($action === 'convert') {
        auth_start();
        $_SESSION['ob_prefill'] = [
            'imie_nazwisko'  => $vol['imie_nazwisko'],
            'pesel'          => $vol['pesel'],
            'data_urodzenia' => $vol['data_urodzenia'],
            'adres'          => $vol['adres'],
            'telefon'        => $vol['telefon'],
            'email'          => $vol['email'],
        ];
        db()->prepare("UPDATE onboarding_volunteers SET status='converted', updated_at=? WHERE id=?")
            ->execute([$now, $id]);
        header('Location: ' . APP_URL . '/contracts/wolontariat/add.php');
        exit;
    }

    if ($action === 'restore') {
        db()->prepare("UPDATE onboarding_volunteers SET status='pending', updated_at=? WHERE id=?")
            ->execute([$now, $id]);
        flash_set('info', 'Zgłoszenie przywrócone do rozpatrzenia.');
        header('Location: ' . APP_URL . '/onboarding/view.php?id=' . $id);
        exit;
    }

    // ── Wiadomość z CKEditor ───────────────────────────────────────────────────
    if (isset($_POST['_msg_send']) || $action === 'send_message') {
        $body = $_POST['msg_body'] ?? '';
        // CKEditor przesyła HTML; plain-textarea przesyła text — normalizuj
        $body_trimmed = trim(strip_tags($body));
        if ($body_trimmed !== '' && $vol['email'] !== '') {
            $u    = current_user();
            $subj = trim($_POST['msg_subject'] ?? '') ?: 'Wiadomość dotycząca Twojego zgłoszenia';
            pmsg_send('onboarding', $id, '', 'admin', (int)$u['id'], $u['name'], $body);
            // E-mail z pełnym HTML (CKEditor lub plain → nl2br)
            $is_html   = ($body !== strip_tags($body));
            $mail_body = $is_html ? $body : nl2br(h($body));
            $html_email = '<!DOCTYPE html><html><body style="font-family:sans-serif;color:#1e293b;max-width:620px;margin:0 auto;padding:24px">'
                        . '<div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden">'
                        . '<div style="background:#1e293b;padding:18px 24px">'
                        . '<span style="color:#fff;font-weight:700;font-size:1rem">' . h(defined('ORG_NAME') ? ORG_NAME : '') . '</span>'
                        . '</div>'
                        . '<div style="padding:24px">' . $mail_body . '</div>'
                        . '<div style="background:#f8fafc;padding:14px 24px;border-top:1px solid #e2e8f0;font-size:.82rem;color:#64748b">'
                        . 'Wiadomość od administracji — nie odpowiadaj bezpośrednio na ten e-mail.'
                        . '</div></div></body></html>';
            require_once __DIR__ . '/../includes/approval.php';
            approval_send_email($vol['email'], $subj, $html_email);
            flash_set('success', 'Wiadomość wysłana do ' . h($vol['email']) . '.');
        } else {
            flash_set('warning', 'Treść wiadomości nie może być pusta.');
        }
        header('Location: ' . APP_URL . '/onboarding/view.php?id=' . $id);
        exit;
    }
}

// Reload after any potential redirect
$vol = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$id]);

function ob_status_badge_view(string $status): string {
    $map = [
        'new'       => ['label' => 'Nowy',                    'class' => 'bg-secondary'],
        'pending'   => ['label' => 'Nowy — niezweryfikowany',  'class' => 'bg-warning text-dark'],
        'verified'  => ['label' => 'Zweryfikowany',            'class' => 'bg-success'],
        'converted' => ['label' => 'Umowa utworzona',          'class' => 'bg-primary'],
        'rejected'  => ['label' => 'Odrzucony',                'class' => 'bg-danger'],
    ];
    $s = $map[$status] ?? ['label' => $status, 'class' => 'bg-secondary'];
    return '<span class="badge ' . $s['class'] . ' fs-6">' . htmlspecialchars($s['label']) . '</span>';
}

function mask_pesel_view(?string $pesel): string {
    if (!$pesel || strlen($pesel) < 11) return '—';
    return substr($pesel, 0, 2) . '***' . substr($pesel, 5, 1) . '***' . substr($pesel, 9, 2);
}

$messages = [];
try {
    $stmt = db()->prepare("SELECT m.*, u.name AS sender_name FROM onboarding_messages m LEFT JOIN users u ON u.id = m.sent_by WHERE m.volunteer_id = ? ORDER BY m.sent_at DESC");
    $stmt->execute([$id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(\Exception $e) { $messages = []; }

$PAGE_TITLE = 'Zgłoszenie: ' . ($vol['imie_nazwisko'] ?: '#' . $id);
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <a href="<?= APP_URL ?>/onboarding/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Zgłoszenia
  </a>
  <h4 class="mb-0">
    <i class="bi bi-person-badge text-primary"></i>
    <?= h($vol['imie_nazwisko'] ?: '(brak imienia)') ?>
  </h4>
  <?php
  // Link do źródłowego zgłoszenia rekrutacyjnego
  $src_app_id = (int)($vol['source_application_id'] ?? 0);
  if ($src_app_id):
    try {
      $src_app = db_one(
        "SELECT va.id, va.volunteer_offer_id, vo.title AS offer_title
         FROM volunteer_applications va
         JOIN volunteer_offers vo ON vo.id = va.volunteer_offer_id
         WHERE va.id = ?", [$src_app_id]
      );
    } catch (\Throwable $e) { $src_app = null; }
    if ($src_app):
  ?>
  <span class="ms-auto">
    <a href="<?= APP_URL ?>/contracts/rekrutacja/view.php?id=<?= (int)$src_app['volunteer_offer_id'] ?>"
       class="btn btn-sm btn-outline-info"
       title="Ogłoszenie: <?= h($src_app['offer_title']) ?>">
      <i class="bi bi-megaphone me-1"></i>Rekrutacja: <?= h($src_app['offer_title']) ?>
    </a>
  </span>
  <?php endif; endif; ?>
</div>

<?= flash_html() ?>

<div class="row g-4">

  <!-- LEFT: Dane zgłoszenia -->
  <div class="col-lg-5">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-semibold">
        <i class="bi bi-person-lines-fill"></i> Dane zgłoszenia
      </div>
      <div class="card-body">

        <div class="mb-3">
          <?= ob_status_badge_view($vol['status']) ?>
        </div>

        <ul class="list-unstyled mb-3">
          <li class="mb-2">
            <small class="text-muted d-block">PESEL</small>
            <?php if ($vol['pesel'] && strlen($vol['pesel']) >= 11): ?>
              <span id="pesel-value"
                    data-masked="<?= h(mask_pesel_view($vol['pesel'])) ?>"
                    data-full="<?= h($vol['pesel']) ?>"
                    data-showing="0"><?= h(mask_pesel_view($vol['pesel'])) ?></span>
              <button type="button" class="btn btn-sm btn-link p-0 ms-1" id="pesel-toggle"
                      onclick="togglePesel()">
                <i class="bi bi-eye"></i>
              </button>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </li>
          <li class="mb-2">
            <small class="text-muted d-block">Data urodzenia</small>
            <?= h($vol['data_urodzenia'] ? date_pl($vol['data_urodzenia']) : '—') ?>
          </li>
          <li class="mb-2">
            <small class="text-muted d-block">Adres</small>
            <?= h($vol['adres'] ?: '—') ?>
          </li>
          <li class="mb-2">
            <small class="text-muted d-block">Telefon</small>
            <?= h($vol['telefon'] ?: '—') ?>
          </li>
          <li class="mb-2">
            <small class="text-muted d-block">E-mail</small>
            <?= h($vol['email'] ?: '—') ?>
          </li>
        </ul>

        <div class="mb-3">
          <div class="mb-1">
            <?php if ($vol['phone_verified']): ?>
              <span class="text-success"><i class="bi bi-check-circle-fill"></i> Telefon: zweryfikowany</span>
            <?php else: ?>
              <span class="text-muted"><i class="bi bi-x-circle"></i> Telefon: niezweryfikowany</span>
            <?php endif; ?>
          </div>
          <div class="mb-1">
            <?php if ($vol['email_verified']): ?>
              <span class="text-success"><i class="bi bi-check-circle-fill"></i> E-mail: zweryfikowany</span>
            <?php else: ?>
              <span class="text-muted"><i class="bi bi-x-circle"></i> E-mail: niezweryfikowany</span>
            <?php endif; ?>
          </div>
          <?php if ($vol['klauzula_accepted']): ?>
          <div>
            <span class="text-success"><i class="bi bi-check-circle-fill"></i> Klauzula: zaakceptowana</span>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($vol['ip_address']): ?>
        <div class="mb-2">
          <small class="text-muted">IP: <?= h($vol['ip_address']) ?></small>
        </div>
        <?php endif; ?>

        <div class="text-muted" style="font-size:.85em">
          Zgłoszono: <?= h(date_pl($vol['created_at'])) ?><br>
          Zaktualizowano: <?= h(date_pl($vol['updated_at'])) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- RIGHT: Akcje -->
  <div class="col-lg-7">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold">
        <i class="bi bi-gear"></i> Akcje
      </div>
      <div class="card-body">

        <?php $status = $vol['status']; ?>

        <?php if (in_array($status, ['pending', 'new'])): ?>

          <!-- Verify -->
          <form method="post" class="d-inline me-2">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="verify">
            <button type="submit" class="btn btn-success">
              <i class="bi bi-check-lg"></i> Zweryfikuj
            </button>
          </form>

          <!-- Reject (collapsible) -->
          <button class="btn btn-danger" type="button" data-bs-toggle="collapse"
                  data-bs-target="#rejectForm" aria-expanded="false">
            <i class="bi bi-x-lg"></i> Odrzuć
          </button>
          <div class="collapse mt-3" id="rejectForm">
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="reject">
              <div class="mb-2">
                <label class="form-label fw-semibold">Powód odrzucenia</label>
                <textarea name="admin_note" rows="3" class="form-control"
                          placeholder="Opcjonalny komentarz..."><?= h($vol['admin_note']) ?></textarea>
              </div>
              <button type="submit" class="btn btn-danger btn-sm">Potwierdź odrzucenie</button>
            </form>
          </div>

        <?php elseif ($status === 'verified'): ?>

          <!-- Convert -->
          <form method="post" class="d-inline me-2">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="convert">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-file-earmark-plus"></i> Utwórz umowę wolontariacką
            </button>
          </form>
          <p class="text-muted mt-2 mb-3" style="font-size:.9em">
            Dane zostaną przeniesione do formularza nowej umowy.
          </p>

          <!-- Reject -->
          <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse"
                  data-bs-target="#rejectForm" aria-expanded="false">
            <i class="bi bi-x-lg"></i> Odrzuć
          </button>
          <div class="collapse mt-3" id="rejectForm">
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="reject">
              <div class="mb-2">
                <label class="form-label fw-semibold">Powód odrzucenia</label>
                <textarea name="admin_note" rows="3" class="form-control"
                          placeholder="Opcjonalny komentarz..."><?= h($vol['admin_note']) ?></textarea>
              </div>
              <button type="submit" class="btn btn-danger btn-sm">Potwierdź odrzucenie</button>
            </form>
          </div>

        <?php elseif ($status === 'converted'): ?>

          <div class="alert alert-success mb-0">
            <i class="bi bi-check-circle-fill"></i>
            Umowa wolontariacka została już utworzona z tych danych.
          </div>

        <?php elseif ($status === 'rejected'): ?>

          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="restore">
            <button type="submit" class="btn btn-outline-secondary">
              <i class="bi bi-arrow-counterclockwise"></i> Przywróć do rozpatrzenia
            </button>
          </form>

          <?php if ($vol['admin_note']): ?>
          <div class="mt-3">
            <strong>Powód odrzucenia:</strong>
            <div class="text-muted"><?= h($vol['admin_note']) ?></div>
          </div>
          <?php endif; ?>

        <?php endif; ?>

        <?php if ($vol['admin_note'] && !in_array($status, ['rejected'])): ?>
        <hr>
        <div>
          <strong>Notatka admina:</strong>
          <div class="text-muted"><?= h($vol['admin_note']) ?></div>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<!-- Full data table -->
<div class="card shadow-sm mt-4">
  <div class="card-header fw-semibold">
    <i class="bi bi-table"></i> Pełny widok danych
  </div>
  <div class="table-responsive">
    <table class="table table-striped table-sm mb-0">
      <tbody>
        <tr><th style="width:200px">Imię i nazwisko</th><td><?= h($vol['imie_nazwisko'] ?: '—') ?></td></tr>
        <tr>
          <th>PESEL</th>
          <td>
            <?php if ($vol['pesel'] && strlen($vol['pesel']) >= 11): ?>
              <span id="pesel-value-2"
                    data-masked="<?= h(mask_pesel_view($vol['pesel'])) ?>"
                    data-full="<?= h($vol['pesel']) ?>"
                    data-showing="0"><?= h(mask_pesel_view($vol['pesel'])) ?></span>
              <button type="button" class="btn btn-sm btn-link p-0 ms-1" id="pesel-toggle-2"
                      onclick="togglePesel2()">
                <i class="bi bi-eye"></i>
              </button>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <tr><th>Data urodzenia</th><td><?= h($vol['data_urodzenia'] ? date_pl($vol['data_urodzenia']) : '—') ?></td></tr>
        <tr><th>Adres</th><td><?= h($vol['adres'] ?: '—') ?></td></tr>
        <tr><th>Telefon</th><td><?= h($vol['telefon'] ?: '—') ?></td></tr>
        <tr><th>E-mail</th><td><?= h($vol['email'] ?: '—') ?></td></tr>

        <tr><th>Status</th><td><?= ob_status_badge_view($vol['status']) ?></td></tr>
        <tr><th>IP</th><td><span class="text-muted"><?= h($vol['ip_address'] ?: '—') ?></span></td></tr>
        <tr><th>Data zgłoszenia</th><td><?= h(date_pl($vol['created_at'])) ?></td></tr>
        <tr><th>Ostatnia aktualizacja</th><td><?= h(date_pl($vol['updated_at'])) ?></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Communication card -->
<div class="card shadow-sm mt-4" id="ob-comm-card">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-chat-dots text-primary"></i> Wiadomości z kandydatem
    <?php if ($vol['email']): ?>
    <small class="text-muted fw-normal ms-1"><?= h($vol['email']) ?></small>
    <?php
      $_ob_unread = msg_unread_thread('onboarding', $id, 'admin');
      if ($_ob_unread): ?>
    <span class="badge bg-danger ms-auto"><?= $_ob_unread ?> nowe</span>
    <?php endif; ?>
    <?php endif; /* $vol['email'] w nagłówku */ ?>
  </div>
  <?php if (!$vol['email']): ?>
  <div class="card-body text-muted small">
    <i class="bi bi-info-circle"></i> Brak adresu e-mail w zgłoszeniu.
  </div>
  <?php else: ?>

  <!-- Wątek wiadomości (widget bez formularza) -->
  <div class="border-bottom" style="background:#f8fafc">
    <?php
      $u = current_user();
      $msg_ctx_type      = 'onboarding';
      $msg_ctx_id        = $id;
      $msg_contract_type = '';
      $msg_viewer        = 'admin';
      $msg_viewer_name   = $u['name'];
      $msg_viewer_id     = (int)$u['id'];
      $msg_post_url      = APP_URL . '/onboarding/view.php?id=' . $id;
      $msg_hide_form     = true;   // formularz zastąpiony przez CKEditor poniżej
      include __DIR__ . '/../includes/messages_widget.php';
    ?>
  </div>

  <!-- CKEditor — formularz wysyłki -->
  <div class="p-3">
    <form method="post" action="<?= APP_URL ?>/onboarding/view.php?id=<?= $id ?>"
          id="ob-cke-form">
      <input type="hidden" name="_csrf"            value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_msg_send"         value="1">
      <input type="hidden" name="msg_ctx_type"      value="onboarding">
      <input type="hidden" name="msg_ctx_id"        value="<?= $id ?>">
      <input type="hidden" name="msg_contract_type" value="">
      <!-- Treść (wypełniana przez CKEditor przy submit) -->
      <textarea name="msg_body" id="ob-cke-body" style="display:none"></textarea>

      <!-- Temat e-mail -->
      <div class="mb-2">
        <div class="input-group input-group-sm">
          <span class="input-group-text text-muted"><i class="bi bi-envelope"></i></span>
          <input type="text" class="form-control" id="ob-msg-subject-input"
                 name="msg_subject"
                 value="Wiadomość dotycząca Twojego zgłoszenia"
                 placeholder="Temat e-maila do kandydata">
        </div>
        <div class="form-text">Temat pojawi się w e-mailu wysłanym do kandydata.</div>
      </div>

      <!-- Edytor CKEditor -->
      <div id="ob-cke-container" class="mb-3"></div>

      <!-- Pasek akcji -->
      <div class="d-flex justify-content-between align-items-center gap-2">
        <span class="small text-muted">

        <button type="submit" class="btn btn-primary" id="ob-cke-submit">
          <i class="bi bi-send-fill me-1"></i> Wyślij wiadomość
        </button>
      </div>
    </form>
  </div>

  <?php endif; ?>
</div>

<script>
function togglePesel() {
    var el = document.getElementById('pesel-value');
    var btn = document.getElementById('pesel-toggle');
    var showing = el.dataset.showing === '1';
    el.textContent = showing ? el.dataset.masked : el.dataset.full;
    el.dataset.showing = showing ? '0' : '1';
    btn.innerHTML = showing ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
}
function togglePesel2() {
    var el = document.getElementById('pesel-value-2');
    var btn = document.getElementById('pesel-toggle-2');
    var showing = el.dataset.showing === '1';
    el.textContent = showing ? el.dataset.masked : el.dataset.full;
    el.dataset.showing = showing ? '0' : '1';
    btn.innerHTML = showing ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
}
</script>

<?php if ($vol['email']): ?>
<!-- ── CKEditor 5 ──────────────────────────────────────────────────────────── -->
<link rel="stylesheet"
      href="https://cdn.ckeditor.com/ckeditor5/44.3.0/ckeditor5.css"
      crossorigin>
<style>
  /* Dopasowanie edytora do layoutu Bootstrap */
  #ob-cke-container .ck-editor__editable {
    min-height: 160px;
    max-height: 400px;
    font-size: .9375rem;
    line-height: 1.6;
  }
  #ob-cke-container .ck.ck-toolbar {
    border-radius: .375rem .375rem 0 0 !important;
    border-color: #ced4da !important;
    background: #f8f9fa;
  }
  #ob-cke-container .ck.ck-editor__main > .ck-editor__editable {
    border-color: #ced4da !important;
    border-radius: 0 0 .375rem .375rem !important;
  }
  #ob-cke-container .ck.ck-editor__main > .ck-editor__editable.ck-focused {
    border-color: #86b7fe !important;
    box-shadow: 0 0 0 .25rem rgba(13,110,253,.25) !important;
  }
  /* Blokowanie resize na dole edytora */
  #ob-cke-container { resize: vertical; overflow: auto; }
</style>
<script type="importmap">
{
  "imports": {
    "ckeditor5": "https://cdn.ckeditor.com/ckeditor5/44.3.0/ckeditor5.js",
    "ckeditor5/": "https://cdn.ckeditor.com/ckeditor5/44.3.0/"
  }
}
</script>
<script type="module">
import {
  ClassicEditor,
  Autoformat,
  Bold, Italic, Underline, Strikethrough,
  BlockQuote,
  Essentials,
  Heading,
  HorizontalLine,
  Image, ImageCaption, ImageStyle, ImageToolbar, ImageUpload,
  Indent, IndentBlock,
  Link,
  List, ListProperties,
  Paragraph,
  Table, TableToolbar,
  TextTransformation,
  Undo
} from 'ckeditor5';

const editorEl = document.getElementById('ob-cke-container');
if (editorEl) {
  ClassicEditor.create(editorEl, {
    plugins: [
      Autoformat, Bold, Italic, Underline, Strikethrough,
      BlockQuote, Essentials, Heading, HorizontalLine,
      Indent, IndentBlock, Link,
      List, ListProperties,
      Paragraph, Table, TableToolbar,
      TextTransformation, Undo
    ],
    toolbar: {
      items: [
        'undo', 'redo', '|',
        'heading', '|',
        'bold', 'italic', 'underline', 'strikethrough', '|',
        'link', 'blockQuote', 'horizontalLine', '|',
        'bulletedList', 'numberedList', 'outdent', 'indent', '|',
        'insertTable'
      ],
      shouldNotGroupWhenFull: false
    },
    table: {
      contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells']
    },
    language: 'pl',
    placeholder: 'Napisz wiadomość do kandydata…'
  }).then(editor => {
    window._ckeObMsg = editor;

    // Walidacja i sync przy submit
    document.getElementById('ob-cke-form').addEventListener('submit', function(e) {
      const data = editor.getData().trim();
      const stripped = data.replace(/<[^>]*>/g, '').trim();
      if (!stripped) {
        e.preventDefault();
        editor.editing.view.focus();
        // Podświetl edytor
        const editable = editorEl.querySelector('.ck-editor__editable');
        if (editable) {
          editable.style.borderColor = '#dc3545';
          setTimeout(() => { editable.style.borderColor = ''; }, 2500);
        }
        return;
      }
      document.getElementById('ob-cke-body').value = data;
    });

    // Enter w polu tematu → focus na edytor
    document.getElementById('ob-msg-subject-input').addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); editor.editing.view.focus(); }
    });
  }).catch(console.error);
}
</script>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
