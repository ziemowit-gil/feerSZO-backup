<?php
/**
 * helpdesk/admin_email_templates.php — edytor szablonów e-mail helpdesku.
 *
 * Pozwala administratorowi skonfigurować treść powiadomień e-mail wysyłanych
 * automatycznie przez system (zmiana statusu, nowa wiadomość, przypisanie itp.).
 * Gdy szablon jest aktywny (is_active=1) i zawiera treść, zastępuje domyślny
 * kod PHP. W przeciwnym razie system korzysta z wbudowanych szablonów.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();
require_login();
if (!is_admin()) { flash_set('danger', 'Brak uprawnień.'); header('Location: ' . APP_URL . '/helpdesk/index.php'); exit; }

$u   = current_user();
$uid = (int)$u['id'];

// ── Akcje POST ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['_save'])) {
        $key      = trim($_POST['tpl_key'] ?? '');
        $subject  = trim($_POST['subject'] ?? '');
        $body     = trim($_POST['body_html'] ?? '');
        $active   = !empty($_POST['is_active']) ? 1 : 0;
        if ($key !== '') {
            db()->prepare(
                "UPDATE helpdesk_email_templates
                    SET subject=?, body_html=?, is_active=?, updated_by=?, updated_at=datetime('now')
                  WHERE key_=?"
            )->execute([$subject, $body, $active, $uid, $key]);
            flash_set('success', 'Szablon zapisany.');
        }
        header('Location: ' . APP_URL . '/helpdesk/admin_email_templates.php?key=' . urlencode($key)); exit;
    }

    if (isset($_POST['_reset'])) {
        $key = trim($_POST['tpl_key'] ?? '');
        if ($key !== '') {
            db()->prepare(
                "UPDATE helpdesk_email_templates
                    SET body_html='', is_active=0, updated_by=?, updated_at=datetime('now')
                  WHERE key_=?"
            )->execute([$uid, $key]);
            flash_set('success', 'Szablon zresetowany do domyślnego.');
        }
        header('Location: ' . APP_URL . '/helpdesk/admin_email_templates.php?key=' . urlencode($key)); exit;
    }
}

// ── Dane ───────────────────────────────────────────────────────────────────────
$templates = db_all(
    "SELECT * FROM helpdesk_email_templates ORDER BY id", []
);

$current_key = trim($_GET['key'] ?? '');
if (!$current_key && !empty($templates)) $current_key = $templates[0]['key_'];
$current = null;
foreach ($templates as $t) {
    if ($t['key_'] === $current_key) { $current = $t; break; }
}

// Opisy i opis placeholderów per typ
$tpl_info = [
    'status_change'    => ['icon' => 'bi-arrow-repeat',       'desc' => 'Wysyłany do zgłaszającego i operatora po zmianie statusu zgłoszenia.'],
    'new_msg_to_user'  => ['icon' => 'bi-reply-all',          'desc' => 'Wysyłany do zgłaszającego gdy operator doda odpowiedź.'],
    'new_msg_to_agent' => ['icon' => 'bi-person-lines-fill',  'desc' => 'Wysyłany do operatora gdy zgłaszający odpowie w wątku.'],
    'assigned'         => ['icon' => 'bi-person-check',       'desc' => 'Wysyłany do operatora gdy zostaje przypisany do zgłoszenia.'],
    'escalation_op'    => ['icon' => 'bi-exclamation-octagon','desc' => 'Wysyłany do operatora przy podbiciu zgłoszenia z powodu braku reakcji.'],
    'escalation_req'   => ['icon' => 'bi-shield-exclamation', 'desc' => 'Wysyłany do zgłaszającego jako potwierdzenie podbicia do 3. linii wsparcia.'],
    'shared_ticket'    => ['icon' => 'bi-share',              'desc' => 'Wysyłany do osoby, której udostępniono link do podglądu zgłoszenia.'],
];

// Domyślne treści szablonów (fallback podgląd gdy szablon jest pusty)
$defaults = [
    'status_change'    => _hd_default_tpl_preview('status_change'),
    'new_msg_to_user'  => _hd_default_tpl_preview('new_msg_to_user'),
    'new_msg_to_agent' => _hd_default_tpl_preview('new_msg_to_agent'),
    'assigned'         => _hd_default_tpl_preview('assigned'),
    'escalation_op'    => _hd_default_tpl_preview('escalation_op'),
    'escalation_req'   => _hd_default_tpl_preview('escalation_req'),
    'shared_ticket'    => _hd_default_tpl_preview('shared_ticket'),
];

function _hd_default_tpl_preview(string $key): string {
    $org = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
    switch ($key) {
        case 'status_change':
            return "<p>Witaj, <strong>{{requester_name}}</strong>!</p>"
                 . "<p>Status zgłoszenia <strong>#{{number}}</strong> — <em>{{title}}</em> uległ zmianie:</p>"
                 . "<p><strong>{{old_status}}</strong> &rarr; <strong>{{new_status}}</strong></p>"
                 . "<p>{{note}}</p>"
                 . "<p><a href=\"{{track_url}}\">Otwórz zgłoszenie →</a></p>"
                 . "<p style=\"color:#6c757d;font-size:.82em\">{$org} · Helpdesk IT</p>";
        case 'new_msg_to_user':
            return "<p>Witaj, <strong>{{requester_name}}</strong>!</p>"
                 . "<p>Masz nową odpowiedź na zgłoszenie <strong>#{{number}}</strong> — <em>{{title}}</em>:</p>"
                 . "<blockquote>{{message_body}}</blockquote>"
                 . "<p><a href=\"{{track_url}}\">Odpowiedz →</a></p>"
                 . "<p style=\"color:#6c757d;font-size:.82em\">{$org} · Helpdesk IT</p>";
        case 'new_msg_to_agent':
            return "<p>Witaj, <strong>{{agent_name}}</strong>!</p>"
                 . "<p>Zgłaszający odpowiedział na zgłoszenie <strong>#{{number}}</strong>:</p>"
                 . "<blockquote>{{message_body}}</blockquote>"
                 . "<p><a href=\"{{view_url}}\">Otwórz w panelu →</a></p>"
                 . "<p style=\"color:#6c757d;font-size:.82em\">{$org} · Helpdesk IT</p>";
        case 'assigned':
            return "<p>Witaj, <strong>{{agent_name}}</strong>!</p>"
                 . "<p>Przypisano Ci zgłoszenie <strong>#{{number}}</strong>: <em>{{title}}</em></p>"
                 . "<p><a href=\"{{view_url}}\">Otwórz zgłoszenie →</a></p>"
                 . "<p style=\"color:#6c757d;font-size:.82em\">{$org} · Helpdesk IT</p>";
        case 'escalation_op':
            return "<p>Zgłaszający zgłosił <strong>brak reakcji</strong> na zgłoszenie <strong>#{{number}}</strong> — <em>{{title}}</em>.</p>"
                 . "<p>Nr podbicia: <strong>{{escalation_number}}</strong> · Dni bez aktualizacji: <strong>{{days_waiting}}</strong></p>"
                 . "<p>Uzasadnienie: {{reason}}</p>"
                 . "<p><a href=\"{{view_url}}\">Otwórz zgłoszenie →</a></p>"
                 . "<p style=\"color:#6c757d;font-size:.82em\">{$org} · Helpdesk IT</p>";
        case 'escalation_req':
            return "<p>Witaj, <strong>{{requester_name}}</strong>!</p>"
                 . "<p>W związku z brakiem reakcji na zgłoszenie <strong>#{{number}}</strong> — <em>{{title}}</em>, "
                 . "zostało ono podbite (nr podbicia: <strong>{{escalation_number}}</strong>) i przechodzi na "
                 . "<strong>3. linię wsparcia</strong>.</p>"
                 . "<p><a href=\"{{track_url}}\">Otwórz zgłoszenie →</a></p>"
                 . "<p style=\"color:#6c757d;font-size:.82em\">{$org} · Helpdesk IT</p>";
        case 'shared_ticket':
            return "<p>Witaj, <strong>{{to_name}}</strong>!</p>"
                 . "<p><strong>{{by_name}}</strong> udostępnił(a) Ci zgłoszenie <strong>#{{number}}</strong> — <em>{{title}}</em>.</p>"
                 . "<p>{{note}}</p>"
                 . "<p><a href=\"{{track_url}}\">Otwórz zgłoszenie →</a></p>"
                 . "<p style=\"color:#6c757d;font-size:.82em\">{$org} · Helpdesk IT</p>";
        default: return '';
    }
}

$PAGE_TITLE = 'Szablony e-mail — Helpdesk IT';
include dirname(__DIR__) . '/includes/header.php';
?>
<style>
.hd-tpl-layout { display: grid; grid-template-columns: 260px 1fr; gap: 1.25rem; align-items: start; }
@media (max-width: 768px) { .hd-tpl-layout { grid-template-columns: 1fr; } }
.hd-tpl-list { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
.hd-tpl-item {
  display: flex; align-items: center; gap: .55rem;
  padding: .65rem .85rem; border-bottom: 1px solid #f1f5f9;
  cursor: pointer; text-decoration: none; color: #374151; font-size: .84rem;
  transition: background .1s;
}
.hd-tpl-item:last-child { border-bottom: none; }
.hd-tpl-item:hover { background: #f8fafc; }
.hd-tpl-item.active { background: #eff6ff; color: #1d4ed8; font-weight: 600; }
.hd-tpl-item i { font-size: 1rem; flex-shrink: 0; }
.hd-tpl-badge-on { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.hd-tpl-badge-off { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
.hd-tpl-badge { font-size: .62rem; font-weight: 700; padding: .12rem .4rem; border-radius: 999px; }
.hd-editor-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; }
.hd-ph-chip {
  display: inline-flex; align-items: center; gap: .3rem;
  background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px;
  padding: .18rem .5rem; font-size: .74rem; font-family: ui-monospace, SFMono-Regular, monospace;
  cursor: pointer; color: #475569; margin: 2px; transition: background .1s;
}
.hd-ph-chip:hover { background: #dbeafe; border-color: #93c5fd; color: #1d4ed8; }
.hd-ph-chip i { font-size: .8rem; }
.ql-toolbar.ql-snow { border-radius: .375rem .375rem 0 0; background: #f8fafc; }
.ql-container.ql-snow { border-radius: 0 0 .375rem .375rem; border-top: none; }
.ql-editor { min-height: 200px; font-size: .9rem; }
.hd-preview-box {
  border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-top: .75rem;
  font-family: sans-serif; max-width: 600px;
}
.hd-preview-header { background: #1e40af; padding: 14px 20px; color: #fff; font-weight: 600; font-size: .9rem; }
.hd-preview-body { padding: 20px 24px; font-size: .9rem; color: #212529; }
</style>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/helpdesk/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Helpdesk
  </a>
  <h1 class="h5 mb-0 fw-bold"><i class="bi bi-envelope-gear me-2 text-primary"></i>Szablony e-mail</h1>
  <span class="text-muted small">· Dostosuj automatyczne powiadomienia wysyłane przez system</span>
</div>

<?= flash_html() ?>

<!-- Informacja o działaniu -->
<div class="alert alert-info d-flex gap-2 py-2 mb-3" style="font-size:.84rem">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
  <div>
    <strong>Jak to działa?</strong> Gdy szablon jest <strong>aktywny</strong> i zawiera treść, zastępuje wbudowany domyślny
    kod HTML. Gdy jest wyłączony lub pusty — system korzysta z wbudowanego szablonu bez zmian.
    Użyj <code>{{zmienna}}</code> (podwójne nawiasy klamrowe) do wstawienia dynamicznych danych.
  </div>
</div>

<div class="hd-tpl-layout">

  <!-- Lista szablonów -->
  <div class="hd-tpl-list">
    <?php foreach ($templates as $tpl):
      $info = $tpl_info[$tpl['key_']] ?? ['icon' => 'bi-envelope', 'desc' => ''];
      $on   = !empty($tpl['is_active']) && $tpl['body_html'] !== '';
    ?>
    <a href="<?= APP_URL ?>/helpdesk/admin_email_templates.php?key=<?= urlencode($tpl['key_']) ?>"
       class="hd-tpl-item<?= $current_key === $tpl['key_'] ? ' active' : '' ?>">
      <i class="bi <?= $info['icon'] ?>"></i>
      <span class="flex-grow-1 text-truncate"><?= h($tpl['label']) ?></span>
      <span class="hd-tpl-badge <?= $on ? 'hd-tpl-badge-on' : 'hd-tpl-badge-off' ?>">
        <?= $on ? 'Aktywny' : 'Domyślny' ?>
      </span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Edytor -->
  <div>
    <?php if ($current): ?>
    <?php $info_c = $tpl_info[$current['key_']] ?? ['icon' => 'bi-envelope', 'desc' => '']; ?>
    <div class="hd-editor-card">
      <div class="d-flex align-items-start gap-2 mb-3">
        <i class="bi <?= $info_c['icon'] ?> text-primary" style="font-size:1.4rem;margin-top:.1rem"></i>
        <div>
          <h2 class="h6 fw-bold mb-0"><?= h($current['label']) ?></h2>
          <p class="text-muted small mb-0"><?= h($info_c['desc']) ?></p>
        </div>
      </div>

      <form method="post" action="<?= APP_URL ?>/helpdesk/admin_email_templates.php" id="tplForm">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="tpl_key" value="<?= h($current['key_']) ?>">
        <input type="hidden" name="body_html" id="bodyHtmlInput" value="<?= h($current['body_html']) ?>">

        <!-- Temat -->
        <div class="mb-3">
          <label for="tplSubject" class="form-label fw-semibold" style="font-size:.86rem">Temat wiadomości e-mail</label>
          <input type="text" id="tplSubject" name="subject" class="form-control form-control-sm"
                 value="<?= h($current['subject']) ?>"
                 placeholder="np. [{{org}}] Nowa odpowiedź na zgłoszenie #{{number}}">
          <div class="form-text">Użyj <code>{{zmienna}}</code> — dostępne zmienne poniżej.</div>
        </div>

        <!-- Zmienne / placeholdery -->
        <?php
        $phs_raw = $current['placeholders'] ?? '[]';
        $phs = json_decode($phs_raw, true) ?: [];
        // Parsuj {{klucz}} z listy
        $ph_names = [];
        foreach ($phs as $ph) {
            preg_match('/\{\{([^}]+)\}\}/', $ph, $m);
            if (!empty($m[1])) $ph_names[] = $m[1];
        }
        if (empty($ph_names)) {
            // Fallback — parsuj z domyślnej treści szablonu
            preg_match_all('/\{\{([^}]+)\}\}/', $defaults[$current['key_']] ?? '', $m2);
            $ph_names = array_unique($m2[1] ?? []);
        }
        ?>
        <?php if ($ph_names): ?>
        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:.86rem">Dostępne zmienne <span class="text-muted fw-normal">(kliknij by wstawić do edytora)</span></label>
          <div>
            <?php foreach ($ph_names as $ph): ?>
            <span class="hd-ph-chip" data-ph="<?= h($ph) ?>" title="Wstaw {{<?= h($ph) ?>}}" role="button" tabindex="0">
              <i class="bi bi-braces"></i>{{<?= h($ph) ?>}}
            </span>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Edytor HTML -->
        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:.86rem">Treść HTML</label>
          <div id="quillEditor" style="min-height:180px"><?= $current['body_html'] ?></div>
          <div class="form-text">Edytor WYSIWYG — formatowanie jest zachowywane jako HTML.</div>
        </div>

        <!-- Aktywacja -->
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" name="is_active" id="tplActive"
                 value="1" <?= !empty($current['is_active']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="tplActive" style="font-size:.86rem">
            Aktywuj ten szablon (zastąpi wbudowany domyślny)
          </label>
        </div>

        <?php if ($current['updated_at'] && $current['body_html'] !== ''): ?>
        <p class="text-muted small mb-3">
          <i class="bi bi-clock me-1"></i>Ostatnia zmiana: <?= date_pl($current['updated_at']) ?>
        </p>
        <?php endif; ?>

        <div class="d-flex gap-2 flex-wrap">
          <button type="submit" name="_save" class="btn btn-primary btn-sm">
            <i class="bi bi-floppy me-1"></i>Zapisz szablon
          </button>
          <?php if ($current['body_html'] !== ''): ?>
          <button type="submit" name="_reset" class="btn btn-outline-danger btn-sm"
                  onclick="return confirm('Zresetować szablon do domyślnego? Twoje zmiany zostaną usunięte.')">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć domyślny
          </button>
          <?php endif; ?>
          <button type="button" id="previewBtn" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-eye me-1"></i>Podgląd
          </button>
        </div>
      </form>

      <!-- Podgląd -->
      <div id="previewSection" class="d-none mt-4">
        <h3 class="h6 fw-semibold mb-2"><i class="bi bi-eye me-1"></i>Podgląd (przykładowe dane)</h3>
        <div class="hd-preview-box">
          <div class="hd-preview-header" id="previewSubject"></div>
          <div class="hd-preview-body" id="previewBody"></div>
        </div>
        <p class="text-muted small mt-2">
          <i class="bi bi-info-circle me-1"></i>Podgląd ze zmiennymi wypełnionymi przykładowymi danymi.
          Rzeczywiste wiadomości będą zawierały właściwe dane zgłoszenia.
        </p>
      </div>

      <!-- Domyślna treść (podgląd referencyjny) -->
      <?php if (!empty($defaults[$current['key_']])): ?>
      <details class="mt-4">
        <summary class="text-muted small" style="cursor:pointer">
          <i class="bi bi-code-slash me-1"></i>Pokaż wbudowany domyślny szablon (tylko do wglądu)
        </summary>
        <div class="mt-2 p-3 bg-light border rounded" style="font-size:.82rem">
          <p class="text-muted mb-2">To jest wbudowany szablon używany gdy niestandardowy nie jest aktywny:</p>
          <div style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden">
            <div style="background:#1e40af;padding:10px 16px;color:#fff;font-size:.82rem;font-weight:600">
              <?= h($current['subject']) ?>
            </div>
            <div style="padding:16px;background:#fff;font-size:.84rem;color:#212529">
              <?= $defaults[$current['key_']] ?>
            </div>
          </div>
        </div>
      </details>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="hd-editor-card text-center text-muted py-5">
      <i class="bi bi-envelope-gear" style="font-size:2.5rem;display:block;margin-bottom:.75rem;color:#cbd5e1"></i>
      <p>Wybierz szablon z listy po lewej stronie</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
(function () {
  var TOOLBAR = [
    [{ header: [false, 2, 3] }],
    ['bold','italic','underline'],
    [{ list:'ordered' }, { list:'bullet' }],
    ['blockquote','link'],
    ['clean']
  ];

  var quill = null;
  var bodyInput   = document.getElementById('bodyHtmlInput');
  var quillEl     = document.getElementById('quillEditor');
  var subjectInput= document.getElementById('tplSubject');
  var form        = document.getElementById('tplForm');

  if (quillEl) {
    quill = new Quill('#quillEditor', {
      theme: 'snow',
      modules: { toolbar: TOOLBAR },
      placeholder: 'Wpisz treść wiadomości…'
    });
    /* Sync przed wysłaniem */
    if (form) {
      form.addEventListener('submit', function () {
        if (quill && bodyInput) bodyInput.value = quill.root.innerHTML;
      });
    }
  }

  /* Wstawianie placeholderów do Quilla / subject */
  document.querySelectorAll('.hd-ph-chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var ph = '{{' + chip.dataset.ph + '}}';
      if (quill && document.activeElement !== subjectInput) {
        var range = quill.getSelection(true);
        quill.insertText(range ? range.index : quill.getLength(), ph);
      } else if (subjectInput) {
        var pos = subjectInput.selectionStart;
        var val = subjectInput.value;
        subjectInput.value = val.substring(0, pos) + ph + val.substring(subjectInput.selectionEnd);
        subjectInput.selectionStart = subjectInput.selectionEnd = pos + ph.length;
        subjectInput.focus();
      }
    });
    chip.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); chip.click(); }
    });
  });

  /* Podgląd */
  var previewBtn  = document.getElementById('previewBtn');
  var previewSec  = document.getElementById('previewSection');
  var previewSubj = document.getElementById('previewSubject');
  var previewBody = document.getElementById('previewBody');
  var SAMPLE = {
    requester_name: 'Jan Kowalski', agent_name: 'Anna Nowak', to_name: 'Firma ABC',
    by_name: 'Anna Nowak', from_name: 'Jan Kowalski',
    number: 'HD00042', title: 'Nie działa drukarka HP w sekretariacie',
    old_status: 'Nowe', new_status: 'W toku',
    org: <?= json_encode(defined('ORG_NAME') ? ORG_NAME : 'Helpdesk IT') ?>,
    track_url: '#podgląd', view_url: '#panel',
    note: 'Zidentyfikowaliśmy problem — sprawdzamy sterowniki.',
    message_body: 'Dziękuję za szybką odpowiedź. Czy problem jest już znany?',
    escalation_number: 'P-HD00042-070826-12XAB',
    days_waiting: '5', reason: 'Oczekuję na odpowiedź od 5 dni.',
  };

  function fillSample(tpl) {
    return tpl.replace(/\{\{([^}]+)\}\}/g, function (_, key) {
      return SAMPLE[key] !== undefined ? SAMPLE[key] : '{{' + key + '}}';
    });
  }

  if (previewBtn) {
    previewBtn.addEventListener('click', function () {
      var html    = quill ? quill.root.innerHTML : (bodyInput ? bodyInput.value : '');
      var subject = subjectInput ? subjectInput.value : '';
      previewSubj.textContent = fillSample(subject);
      previewBody.innerHTML   = fillSample(html);
      previewSec.classList.toggle('d-none');
      previewBtn.innerHTML = previewSec.classList.contains('d-none')
        ? '<i class="bi bi-eye me-1"></i>Podgląd'
        : '<i class="bi bi-eye-slash me-1"></i>Ukryj podgląd';
    });
  }
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
