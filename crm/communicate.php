<?php
/**
 * crm/communicate.php — Moduł komunikacji CRM.
 *
 * Wysyłanie SMS/email do kontaktów z obsługą szablonów i logowaniem historii.
 * GET ?contact_id=X&channel=email|sms → prefill formularza
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!can_write('crm') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do wysyłania wiadomości.');
    header('Location: ' . APP_URL . '/crm/index.php');
    exit;
}
crm_migrate();

$PAGE_TITLE  = 'CRM — Wyślij wiadomość';
$errors      = [];
$sent_count  = 0;

// Prefill z GET
$preselect_contact_id = (int)($_GET['contact_id'] ?? 0);
$preselect_channel    = in_array($_GET['channel'] ?? '', ['sms','email'], true) ? $_GET['channel'] : 'email';
$preselect_group_id   = (int)($_GET['group_id'] ?? 0);
$preselect_group      = $preselect_group_id ? CrmManager::getGroup($preselect_group_id) : null;

// IDs kontaktów preselektowanych przez grupę
$preselect_group_ids = $preselect_group
    ? array_column($preselect_group['members'], 'id')
    : [];

// Aktywne kontakty — filtrowane po dostępie grupowym
$_acc_ids = crm_accessible_group_ids();
if ($_acc_ids === null) {
    $contacts = db_all("SELECT id, imie_nazwisko, email, telefon, organizacja FROM crm_contacts WHERE crm_active=1 ORDER BY imie_nazwisko");
} elseif (empty($_acc_ids)) {
    $contacts = [];
} else {
    $_ph = implode(',', array_fill(0, count($_acc_ids), '?'));
    $contacts = db_all(
        "SELECT DISTINCT c.id, c.imie_nazwisko, c.email, c.telefon, c.organizacja
         FROM crm_contacts c
         JOIN crm_group_members gm ON gm.contact_id=c.id AND gm.group_id IN ($_ph)
         WHERE c.crm_active=1
         ORDER BY c.imie_nazwisko",
        $_acc_ids
    );
}
$templates = db_all("SELECT * FROM crm_templates WHERE is_active=1 ORDER BY channel, name");

// Sprawdź dostępność kanałów
$sms_available   = false;
$email_available = true;
try {
    require_once dirname(__DIR__) . '/includes/sms.php';
    $sms_available = sms_is_enabled();
} catch (\Throwable $e) {}

require_once dirname(__DIR__) . '/includes/mail_queue.php';
$m365_mail_configured = _mail_m365_configured();
$mail_channel = $m365_mail_configured ? 'Microsoft 365' : (
    _mail_setting('smtp_host') ? 'SMTP' : 'PHP mail()'
);

// ── POST: wyślij wiadomość ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $channel   = in_array($_POST['channel'] ?? '', ['sms','email','telefon','osobisty'], true) ? $_POST['channel'] : 'email';
    $subject   = trim($_POST['subject'] ?? '');
    $body      = trim($_POST['body'] ?? '');
    $tpl_name  = trim($_POST['template_name'] ?? '');
    $do_send   = !empty($_POST['do_send']);

    // Odbiorcy: jeden lub wielu (checkbox list)
    $recipient_ids = array_map('intval', (array)($_POST['recipient_ids'] ?? []));
    // Opcjonalnie: pojedynczy contact_id
    if (empty($recipient_ids) && !empty($_POST['contact_id'])) {
        $recipient_ids = [(int)$_POST['contact_id']];
    }

    // Walidacja
    if (empty($recipient_ids)) $errors[] = 'Wybierz co najmniej jednego odbiorcę.';
    if ($body === '')           $errors[] = 'Treść wiadomości nie może być pusta.';
    if ($channel === 'email' && $subject === '') $errors[] = 'Temat wiadomości jest wymagany dla e-maila.';

    if (!$errors) {
        $user_id = (int)(current_user()['id'] ?? 0);
        foreach ($recipient_ids as $cid) {
            if (!crm_can_access_contact($cid)) continue;
            $contact = db_one("SELECT * FROM crm_contacts WHERE id=?", [$cid]);
            if (!$contact) continue;
            $rendered_body    = CrmManager::renderTemplate($body, $contact);
            $rendered_subject = CrmManager::renderTemplate($subject, $contact);

            CrmManager::sendAndLog(
                $cid,
                $channel,
                $rendered_body,
                $rendered_subject,
                $tpl_name,
                $do_send
            );
            $sent_count++;
        }
        flash_set('success', "Wiadomość " . ($do_send ? 'wysłana' : 'zalogowana') . " do {$sent_count} odbiorców.");
        header('Location: ' . APP_URL . '/crm/communicate.php');
        exit;
    }
}

include __DIR__ . '/includes/header_crm.php';
?>

<!-- Breadcrumb -->
<nav aria-label="Ścieżka nawigacji" class="mb-2">
  <ol class="breadcrumb mb-0" style="font-size:.82rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/index.php"><i class="bi bi-diagram-2-fill me-1" style="color:var(--crm-primary)"></i>CRM</a></li>
    <li class="breadcrumb-item active" aria-current="page">Wyślij wiadomość</li>
  </ol>
</nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon" aria-hidden="true"
       <?php if ($preselect_group): ?>style="background:<?= h($preselect_group['color']) ?>"<?php endif; ?>>
    <i class="bi <?= $preselect_group ? h($preselect_group['icon']) : 'bi-send-fill' ?>"></i>
  </div>
  <div>
    <h1 class="crm-object-title">Wyślij wiadomość</h1>
    <div class="crm-object-count">
      <?php if ($preselect_group): ?>
        Do grupy: <strong><?= h($preselect_group['name']) ?></strong>
        · <?= count($preselect_group_ids) ?> odbiorców
      <?php else: ?>
        SMS, e-mail lub inny kanał — z obsługą szablonów
      <?php endif; ?>
    </div>
  </div>
  <div class="crm-object-actions">
    <?php if ($preselect_group): ?>
    <a href="<?= APP_URL ?>/crm/group/view.php?id=<?= $preselect_group_id ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Wróć do grupy
    </a>
    <?php else: ?>
    <a href="<?= APP_URL ?>/crm/index.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Lista kontaktów
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger d-flex align-items-start gap-2 mb-3" role="alert">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
  <ul class="mb-0 ps-2">
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="row g-3">

  <!-- Formularz -->
  <div class="col-lg-8">
    <form method="post" id="communicateForm" novalidate aria-label="Formularz wysyłania wiadomości CRM">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

    <!-- Odbiorcy -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title">Odbiorcy</div>

        <?php if ($preselect_contact_id): ?>
        <!-- Jeden odbiorca (prefill z contact view) -->
        <?php
          $pre_c = db_one("SELECT id, imie_nazwisko, email, telefon FROM crm_contacts WHERE id=?", [$preselect_contact_id]);
        ?>
        <?php if ($pre_c): ?>
        <input type="hidden" name="contact_id" value="<?= $preselect_contact_id ?>">
        <div class="d-flex align-items-center gap-2 p-2 border rounded" style="background:var(--crm-primary-bg);border-color:var(--crm-primary)!important">
          <div class="crm-avatar sm"><?= h(CrmManager::makeInitials($pre_c['imie_nazwisko'])) ?></div>
          <div>
            <div class="fw-semibold small"><?= h($pre_c['imie_nazwisko']) ?></div>
            <div class="text-muted" style="font-size:.75rem">
              <?= h($pre_c['email']) ?> <?= $pre_c['telefon'] ? '· '.h($pre_c['telefon']) : '' ?>
            </div>
          </div>
          <a href="<?= APP_URL ?>/crm/communicate.php" class="ms-auto text-muted" style="font-size:.75rem">
            <i class="bi bi-x"></i> Zmień
          </a>
        </div>
        <?php endif; ?>

        <?php elseif ($preselect_group): ?>
        <!-- Cała grupa (prefill z group/view.php) -->
        <div class="p-2 border rounded mb-2"
             style="background:<?= h($preselect_group['color']) ?>15;border-color:<?= h($preselect_group['color']) ?>55!important">
          <div class="d-flex align-items-center gap-2">
            <i class="bi <?= h($preselect_group['icon']) ?>" style="color:<?= h($preselect_group['color']) ?>;font-size:1.1rem" aria-hidden="true"></i>
            <div>
              <div class="fw-semibold small" style="color:<?= h($preselect_group['color']) ?>">
                Grupa: <?= h($preselect_group['name']) ?>
              </div>
              <div class="text-muted" style="font-size:.75rem">
                <?= count($preselect_group_ids) ?> odbiorców
              </div>
            </div>
            <a href="<?= APP_URL ?>/crm/communicate.php" class="ms-auto text-muted" style="font-size:.75rem">
              <i class="bi bi-x"></i> Zmień
            </a>
          </div>
        </div>
        <?php foreach ($preselect_group_ids as $pgid): ?>
        <input type="hidden" name="recipient_ids[]" value="<?= (int)$pgid ?>">
        <?php endforeach; ?>

        <!-- Pokaż listę do podglądu (read-only) -->
        <div class="mt-1" style="max-height:140px;overflow-y:auto;background:#f9f9f9;border:1px solid var(--crm-border);border-radius:.4rem;padding:.5rem">
          <?php foreach ($preselect_group['members'] as $gm): ?>
          <div style="font-size:.78rem;padding:.15rem 0">
            <?= h($gm['imie_nazwisko']) ?>
            <?php if ($gm['email']): ?><span class="text-muted"> · <?= h($gm['email']) ?></span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <?php else: ?>
        <!-- Multi-select -->
        <label class="form-label visually-hidden" for="recipient_ids">Wybierz odbiorców</label>
        <select name="recipient_ids[]" id="recipient_ids"
                class="form-select"
                multiple
                size="8"
                aria-label="Wybierz odbiorców (możesz zaznaczyć wielu)">
          <?php foreach ($contacts as $c):
            $label = h($c['imie_nazwisko']) . ($c['organizacja'] ? ' (' . h($c['organizacja']) . ')' : '');
          ?>
          <option value="<?= (int)$c['id'] ?>"><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text" style="font-size:.75rem">
          Przytrzymaj Ctrl/Cmd, aby zaznaczyć wielu odbiorców.
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Kanał + szablon -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title">Kanał i szablon</div>

        <div class="row g-3 mb-3">
          <div class="col-sm-4">
            <label class="form-label" for="channel">Kanał komunikacji</label>
            <select name="channel" id="channel" class="form-select" aria-label="Kanał komunikacji">
              <option value="email"    <?= $preselect_channel === 'email'    ? 'selected' : '' ?>>E-mail</option>
              <?php if ($sms_available): ?>
              <option value="sms"      <?= $preselect_channel === 'sms'      ? 'selected' : '' ?>>SMS</option>
              <?php endif; ?>
              <option value="telefon"  <?= $preselect_channel === 'telefon'  ? 'selected' : '' ?>>Telefon (zaloguj)</option>
              <option value="osobisty" <?= $preselect_channel === 'osobisty' ? 'selected' : '' ?>>Spotkanie osobiste</option>
            </select>
          </div>
          <div class="col-sm-8">
            <label class="form-label" for="template_select">Użyj szablonu</label>
            <select id="template_select" class="form-select" aria-label="Wybierz szablon wiadomości">
              <option value="">— Bez szablonu —</option>
              <?php foreach ($templates as $tpl): ?>
              <option value="<?= (int)$tpl['id'] ?>"
                      data-channel="<?= h($tpl['channel']) ?>"
                      data-subject="<?= h($tpl['subject'] ?? '') ?>"
                      data-body="<?= h($tpl['body']) ?>"
                      data-name="<?= h($tpl['name']) ?>">
                [<?= strtoupper(h($tpl['channel'])) ?>] <?= h($tpl['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <input type="hidden" name="template_name" id="template_name_hidden" value="">
      </div>
    </div>

    <!-- Treść wiadomości -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between">
          <span>Treść wiadomości</span>
          <!-- Przełącznik trybu edytora -->
          <div class="btn-group btn-group-sm" id="editorModeGroup" role="group">
            <button type="button" id="btnModeRich" class="btn btn-outline-secondary active" onclick="Comm.setMode('rich')" title="Edytor wizualny (WYSIWYG)">
              <i class="bi bi-type-bold me-1"></i>Rich Text
            </button>
            <button type="button" id="btnModePlain" class="btn btn-outline-secondary" onclick="Comm.setMode('plain')" title="Zwykły tekst (SMS, plain)">
              <i class="bi bi-code me-1"></i>Zwykły
            </button>
          </div>
        </div>

        <!-- Temat (tylko email) -->
        <div id="subjectRow" class="mb-3" style="display:<?= $preselect_channel === 'email' ? '' : 'none' ?>">
          <label class="form-label" for="subject">Temat e-maila</label>
          <input type="text" name="subject" id="subject"
                 class="form-control"
                 value="<?= h($_POST['subject'] ?? '') ?>"
                 aria-label="Temat wiadomości e-mail">
        </div>

        <!-- Zmienne szablonu -->
        <div class="mb-2 d-flex align-items-center flex-wrap gap-1">
          <span class="text-muted small me-1">Wstaw zmienną:</span>
          <?php foreach (['{imie}','{imie_nazwisko}','{email}','{organizacja}','{stanowisko}','{data}'] as $var): ?>
          <button type="button" class="btn btn-outline-secondary py-0 px-1"
                  style="font-size:.7rem;font-family:monospace;line-height:1.6"
                  onclick="Comm.insertVar('<?= $var ?>')">
            <?= h($var) ?>
          </button>
          <?php endforeach; ?>
        </div>

        <!-- WYSIWYG Quill — widoczny dla email/rich text -->
        <div id="quillWrapper">
          <div id="quillEditor" style="min-height:200px;font-size:.92rem;border-radius:0 0 .375rem .375rem"></div>
        </div>

        <!-- Textarea plain — dla SMS / plain text -->
        <div id="plainWrapper" style="display:none">
          <textarea name="body_plain" id="bodyPlain"
                    class="form-control" rows="7"
                    placeholder="Treść wiadomości… Możesz używać zmiennych np. {imie}"><?= h($_POST['body'] ?? '') ?></textarea>
        </div>

        <!-- Ukryte pole przekazywane do POST -->
        <input type="hidden" name="body" id="bodyHidden" value="<?= h($_POST['body'] ?? '') ?>">

        <div class="d-flex justify-content-between align-items-center mt-1">
          <div class="form-text" style="font-size:.75rem" id="charCount"></div>
          <div class="form-text text-muted" style="font-size:.72rem" id="editorHint">
            <i class="bi bi-info-circle me-1"></i>Email wysyłany jako HTML
          </div>
        </div>
      </div>
    </div>

    <!-- Opcje wysyłki -->
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="row g-3 align-items-center">
          <div class="col">
            <div class="form-check">
              <input type="checkbox" name="do_send" id="do_send"
                     class="form-check-input" value="1" checked>
              <label class="form-check-label" for="do_send">
                Faktycznie wyślij wiadomość (odznacz = tylko zaloguj w historii)
              </label>
            </div>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-crm-primary">
              <i class="bi bi-send-fill me-1"></i>Wyślij
            </button>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <?php if ($m365_mail_configured): ?>
          <div class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.75rem">
            <i class="bi bi-microsoft me-1"></i>E-mail przez Microsoft 365 (<?= h(_mail_setting('m365_send_from_email')) ?>)
          </div>
          <?php elseif (_mail_setting('smtp_host')): ?>
          <div class="badge bg-info-subtle text-info border border-info-subtle" style="font-size:.75rem">
            <i class="bi bi-envelope me-1"></i>E-mail przez SMTP (<?= h(_mail_setting('smtp_host')) ?>)
          </div>
          <?php else: ?>
          <div class="badge bg-warning-subtle text-warning border border-warning-subtle" style="font-size:.75rem">
            <i class="bi bi-exclamation-triangle me-1"></i>E-mail przez PHP mail() —
            <a href="<?= APP_URL ?>/admin/m365_settings.php" class="ms-1">skonfiguruj M365</a>
          </div>
          <?php endif; ?>
          <?php if (!$sms_available): ?>
          <div class="badge bg-secondary-subtle text-secondary border" style="font-size:.75rem">
            <i class="bi bi-phone-x me-1"></i>SMS niekonfigurowany —
            <a href="<?= APP_URL ?>/admin/sms_settings.php">konfiguruj</a>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    </form>
  </div><!-- /col-8 -->

  <!-- Prawa: szablony + pomoc -->
  <div class="col-lg-4">

    <!-- Szablony istniejące -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between">
          Szablony
          <button type="button" class="btn btn-sm btn-crm-outline py-0 px-2"
                  data-bs-toggle="modal" data-bs-target="#newTemplateModal"
                  style="font-size:.72rem;text-transform:none;letter-spacing:0">
            <i class="bi bi-plus me-1"></i>Nowy
          </button>
        </div>
        <?php if ($templates): ?>
          <?php foreach ($templates as $tpl): ?>
          <div class="d-flex align-items-center gap-2 py-1 border-bottom" style="font-size:.82rem">
            <span class="badge bg-light text-dark border" style="font-size:.65rem"><?= strtoupper(h($tpl['channel'])) ?></span>
            <button type="button"
                    class="btn btn-link p-0 text-start crm-name-link"
                    style="font-size:.82rem"
                    onclick="loadTemplate(<?= (int)$tpl['id'] ?>)"
                    data-channel="<?= h($tpl['channel']) ?>"
                    data-subject="<?= h($tpl['subject'] ?? '') ?>"
                    data-body="<?= h($tpl['body']) ?>"
                    data-name="<?= h($tpl['name']) ?>"
                    aria-label="Użyj szablonu: <?= h($tpl['name']) ?>">
              <?= h($tpl['name']) ?>
            </button>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
        <p class="text-muted small">Brak zapisanych szablonów.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Pomoc z zmiennymi -->
    <div class="card border-0 shadow-sm" style="background:var(--crm-primary-bg);border-color:var(--crm-primary-light)!important">
      <div class="card-body" style="font-size:.8rem">
        <div class="crm-section-title">Zmienne szablonu</div>
        <table class="table table-sm table-borderless mb-0" style="font-size:.78rem">
          <tbody>
            <tr><td class="font-monospace text-success">{imie}</td><td>Imię (pierwsze słowo)</td></tr>
            <tr><td class="font-monospace text-success">{imie_nazwisko}</td><td>Pełne imię i nazwisko</td></tr>
            <tr><td class="font-monospace text-success">{email}</td><td>Adres e-mail</td></tr>
            <tr><td class="font-monospace text-success">{organizacja}</td><td>Nazwa firmy / org.</td></tr>
            <tr><td class="font-monospace text-success">{stanowisko}</td><td>Stanowisko</td></tr>
            <tr><td class="font-monospace text-success">{data}</td><td>Dzisiejsza data</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /col-4 -->

</div><!-- /row -->

<!-- ── Modal: nowy szablon ─────────────────────────────────────────────────── -->
<div class="modal fade" id="newTemplateModal" tabindex="-1" aria-labelledby="newTemplateModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= APP_URL ?>/crm/api/save_template.php" aria-label="Nowy szablon wiadomości">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="newTemplateModalLabel">
            <i class="bi bi-file-text me-2 text-success"></i>Nowy szablon wiadomości
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-sm-8">
              <label class="form-label fw-semibold" for="tpl_name">Nazwa szablonu</label>
              <input type="text" name="name" id="tpl_name" class="form-control"
                     placeholder="np. Powitanie wolontariusza" required maxlength="120">
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-semibold" for="tpl_channel">Kanał</label>
              <select name="channel" id="tpl_channel" class="form-select">
                <option value="email">E-mail</option>
                <option value="sms">SMS</option>
              </select>
            </div>
          </div>
          <div class="mt-3" id="tpl_subject_row">
            <label class="form-label" for="tpl_subject">Temat (dla e-maila)</label>
            <input type="text" name="subject" id="tpl_subject" class="form-control"
                   placeholder="Temat e-maila…">
          </div>
          <div class="mt-3">
            <label class="form-label fw-semibold" for="tpl_body">Treść szablonu</label>
            <textarea name="body" id="tpl_body" class="form-control" rows="7"
                      placeholder="Treść z {imie}, {organizacja}…" required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-crm-primary">
            <i class="bi bi-floppy me-1"></i>Zapisz szablon
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Quill WYSIWYG -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

<style>
/* Quill customizacja */
#quillWrapper .ql-toolbar.ql-snow {
  border: 1px solid #E5E7EB; border-bottom: none;
  border-radius: .375rem .375rem 0 0;
  background: #F9FAFB; padding: .35rem .5rem;
}
#quillWrapper .ql-container.ql-snow {
  border: 1px solid #E5E7EB;
  border-radius: 0 0 .375rem .375rem;
  font-family: system-ui, -apple-system, sans-serif;
  font-size: .92rem;
}
#quillWrapper .ql-editor { min-height: 200px; }
#quillWrapper .ql-editor.ql-blank::before { color: #9CA3AF; font-style: normal; }
.ql-toolbar .ql-formats { margin-right: 6px; }
</style>

<script>
(function () {
  'use strict';

  // ── Quill init ─────────────────────────────────────────────────────────────
  var _quill = null;
  var _mode  = 'rich'; // 'rich' | 'plain'

  function initQuill() {
    if (_quill) return;
    _quill = new Quill('#quillEditor', {
      theme: 'snow',
      placeholder: 'Treść wiadomości… Możesz używać zmiennych np. {imie}',
      modules: {
        toolbar: [
          [{ header: [1, 2, 3, false] }],
          ['bold', 'italic', 'underline', 'strike'],
          [{ color: [] }, { background: [] }],
          [{ list: 'ordered' }, { list: 'bullet' }],
          [{ indent: '-1' }, { indent: '+1' }],
          ['link', 'blockquote', 'code-block'],
          ['clean'],
        ]
      }
    });
    // Ustaw wartość początkową (np. po walidacji serwera)
    var initial = document.getElementById('bodyHidden').value;
    if (initial) {
      // Jeśli jest HTML, wstaw jako HTML; jeśli plain, wstaw jako tekst
      if (/<[a-z][\s\S]*>/i.test(initial)) {
        _quill.root.innerHTML = initial;
      } else {
        _quill.setText(initial);
      }
    }
    _quill.on('text-change', function() { updateCharCount(); });
  }

  // Uruchom Quill od razu
  initQuill();

  // ── Tryb edytora ──────────────────────────────────────────────────────────
  var Comm = window.Comm = {};

  Comm.setMode = function(mode) {
    _mode = mode;
    var isRich = mode === 'rich';
    document.getElementById('quillWrapper').style.display = isRich ? '' : 'none';
    document.getElementById('plainWrapper').style.display  = isRich ? 'none' : '';
    document.getElementById('btnModeRich').classList.toggle('active', isRich);
    document.getElementById('btnModePlain').classList.toggle('active', !isRich);
    document.getElementById('editorHint').innerHTML = isRich
      ? '<i class="bi bi-info-circle me-1"></i>Email wysyłany jako HTML'
      : '<i class="bi bi-info-circle me-1"></i>Tekst plain — zalecany dla SMS';

    // Synchronizuj treść między trybami
    if (!isRich) {
      var html = _quill ? _quill.root.innerHTML : '';
      // Usuń HTML → plain przy przejściu
      var tmp = document.createElement('div');
      tmp.innerHTML = html;
      document.getElementById('bodyPlain').value = (tmp.textContent || '').trim();
    } else {
      var plain = document.getElementById('bodyPlain').value;
      if (_quill && plain) _quill.setText(plain);
    }
    updateCharCount();
  };

  // SMS → auto przełącz na plain
  var channelSel = document.getElementById('channel');
  if (channelSel) {
    channelSel.addEventListener('change', function() {
      if (this.value === 'sms') {
        Comm.setMode('plain');
        document.getElementById('editorModeGroup').style.display = 'none';
      } else {
        document.getElementById('editorModeGroup').style.display = '';
        Comm.setMode('rich');
      }
      toggleSubject();
      updateCharCount();
    });
  }

  // ── Wstaw zmienną ─────────────────────────────────────────────────────────
  Comm.insertVar = function(v) {
    if (_mode === 'plain') {
      var ta = document.getElementById('bodyPlain');
      if (!ta) return;
      var s = ta.selectionStart, e = ta.selectionEnd;
      ta.value = ta.value.slice(0, s) + v + ta.value.slice(e);
      ta.selectionStart = ta.selectionEnd = s + v.length;
      ta.focus();
    } else {
      if (!_quill) return;
      var range = _quill.getSelection(true);
      _quill.insertText(range ? range.index : _quill.getLength(), v, 'user');
    }
    updateCharCount();
  };

  // ── Synchronizacja body przed submitem ────────────────────────────────────
  document.getElementById('communicateForm')?.addEventListener('submit', function() {
    var hidden = document.getElementById('bodyHidden');
    if (_mode === 'rich' && _quill) {
      hidden.value = _quill.root.innerHTML;
    } else {
      hidden.value = document.getElementById('bodyPlain').value;
    }
  });

  // ── Wczytaj szablon ───────────────────────────────────────────────────────
  window.loadTemplate = function(id) {
    var btn = document.querySelector('[onclick="loadTemplate(' + id + ')"]');
    if (!btn) return;
    var channelSel2 = document.getElementById('channel');
    var subjectIn   = document.getElementById('subject');
    var tplHidden   = document.getElementById('template_name_hidden');
    var body        = btn.dataset.body || '';

    if (channelSel2) channelSel2.value = btn.dataset.channel || 'email';
    if (subjectIn)   subjectIn.value   = btn.dataset.subject || '';
    if (tplHidden)   tplHidden.value   = btn.dataset.name    || '';

    var isSms = (btn.dataset.channel === 'sms');
    if (isSms) {
      Comm.setMode('plain');
      document.getElementById('editorModeGroup').style.display = 'none';
      document.getElementById('bodyPlain').value = body;
    } else {
      document.getElementById('editorModeGroup').style.display = '';
      Comm.setMode('rich');
      if (_quill) {
        if (/<[a-z][\s\S]*>/i.test(body)) { _quill.root.innerHTML = body; }
        else { _quill.setText(body); }
      }
    }
    toggleSubject();
    updateCharCount();
  };

  // ── Helpers ───────────────────────────────────────────────────────────────
  function toggleSubject() {
    var ch  = (document.getElementById('channel') || {}).value || 'email';
    var row = document.getElementById('subjectRow');
    if (row) row.style.display = ch === 'email' ? '' : 'none';
  }

  function updateCharCount() {
    var cc  = document.getElementById('charCount');
    if (!cc) return;
    var ch  = (document.getElementById('channel') || {}).value || 'email';
    var len;
    if (_mode === 'rich' && _quill) {
      len = _quill.getText().replace(/\n$/, '').length;
    } else {
      len = (document.getElementById('bodyPlain')?.value || '').length;
    }
    if (ch === 'sms') {
      var msgs = Math.ceil(len / 160) || 1;
      cc.textContent = len + ' znaków (' + msgs + ' SMS' + (msgs > 1 ? '-y' : '') + ')';
      cc.className = len > 160 ? 'form-text text-warning' : 'form-text text-muted';
    } else {
      cc.textContent = len > 0 ? len + ' znaków' : '';
      cc.className = 'form-text text-muted';
    }
  }

  // Modal szablonu: ukryj temat dla SMS
  var tplCh    = document.getElementById('tpl_channel');
  var tplSubRow= document.getElementById('tpl_subject_row');
  if (tplCh) tplCh.addEventListener('change', function() {
    if (tplSubRow) tplSubRow.style.display = this.value === 'sms' ? 'none' : '';
  });

  toggleSubject();
  updateCharCount();
})();
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
