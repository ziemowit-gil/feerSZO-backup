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
require_once dirname(__DIR__) . '/includes/nozbe.php';

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

    // Obsługa załączników (tylko dla e-mail)
    $attachments = [];
    if ($channel === 'email' && !empty($_FILES['crm_attachments']['name'][0])) {
        $files = $_FILES['crm_attachments'];
        $count = count($files['name']);
        for ($fi = 0; $fi < $count; $fi++) {
            if ($files['error'][$fi] !== UPLOAD_ERR_OK) continue;
            $att = mail_queue_save_attachment([
                'name'     => $files['name'][$fi],
                'tmp_name' => $files['tmp_name'][$fi],
                'error'    => $files['error'][$fi],
                'size'     => $files['size'][$fi],
            ]);
            if ($att) $attachments[] = $att;
        }
    }

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
        // < 10 odbiorców → natychmiast; >= 10 → kolejka CRON
        $immediate = count($recipient_ids) < 10;
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
                $do_send && $immediate,
                $channel === 'email' ? $attachments : []
            );
            if ($do_send && !$immediate && $channel === 'email' && !empty($contact['email'])) {
                // Wysyłka wsadowa — dodaj do kolejki bez natychmiastowego procesu
                $is_html   = strip_tags($rendered_body) !== $rendered_body;
                $html_body = $is_html ? $rendered_body : nl2br(htmlspecialchars($rendered_body));
                $crm_footer = trim(org_setting('crm_email_footer') ?? '');
                if ($crm_footer) $html_body .= "\n<hr>\n" . $crm_footer;
                mail_queue_add($contact['email'], $contact['imie_nazwisko'] ?? '', $rendered_subject ?: 'Wiadomość', $html_body, $rendered_body, 'crm', $cid, '', false, $attachments);
            }
            $sent_count++;
        }
        if ($do_send && !$immediate) {
            $msg = "Zakolejkowano {$sent_count} wiadomości — zostaną wysłane przez harmonogram (CRON).";
        } else {
            $msg = "Wiadomość " . ($do_send ? 'wysłana' : 'zalogowana') . " do {$sent_count} odbiorców.";
        }
        // ── Nozbe follow-up task ────────────────────────────────────────────
        if (!empty($_POST['nozbe_task']) && nozbe_setting('nozbe_enabled') === '1') {
            try {
                $nozbe      = NozbeAPI::from_settings();
                $project_id = trim($_POST['nozbe_project_id'] ?? nozbe_setting('nozbe_default_project_id'));
                $due        = trim($_POST['nozbe_due_date'] ?? '');
                $task_name  = trim($_POST['nozbe_task_name'] ?? '') ?: 'Follow-up: ' . implode(', ', array_map(function($cid) {
                    $c = db_one("SELECT imie_nazwisko FROM crm_contacts WHERE id=?", [(int)$cid]);
                    return $c['imie_nazwisko'] ?? "#$cid";
                }, array_slice($recipient_ids, 0, 3))) . (count($recipient_ids) > 3 ? ' +'.( count($recipient_ids)-3).' więcej' : '');

                if ($nozbe->is_configured() && $project_id) {
                    $desc = "Wysłano wiadomość CRM (" . date('d.m.Y H:i') . ")\n"
                          . "Temat: $subject\n"
                          . "Odbiorców: " . count($recipient_ids);
                    $task = $nozbe->create_task($task_name, $project_id, $desc, $due ?: null);
                    flash_set('success', $msg . " · Zadanie Nozbe utworzone: <strong>" . h($task_name) . "</strong>");
                } else {
                    flash_set('success', $msg);
                }
            } catch (\Throwable $e) {
                flash_set('success', $msg);
                flash_set('warning', 'Nozbe: ' . $e->getMessage());
            }
        } else {
            flash_set('success', $msg);
        }
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
    <form method="post" enctype="multipart/form-data" id="communicateForm" novalidate aria-label="Formularz wysyłania wiadomości CRM">
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
        <!-- Live search odbiorców -->
        <div id="comm-recipient-wrap">
          <div class="position-relative mb-2">
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
              <input type="text" id="comm-search" class="form-control"
                     placeholder="Szukaj po imieniu, e-mailu lub organizacji…"
                     autocomplete="off">
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="CommRecip.selectAll()" title="Zaznacz wszystkich z bieżącego wyszukiwania">
                <i class="bi bi-check-all"></i>
              </button>
            </div>
            <div id="comm-dropdown"
                 style="display:none;position:absolute;z-index:1050;background:#fff;border:1px solid #E5E7EB;
                        border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);width:100%;
                        max-height:220px;overflow-y:auto;top:calc(100%+4px)">
            </div>
          </div>

          <!-- Wybrani odbiorcy jako chips + hidden inputs -->
          <div id="comm-chips" class="d-flex flex-wrap gap-1 mb-1"></div>

          <!-- Licznik -->
          <div class="text-muted" id="comm-count" style="font-size:.75rem"></div>
        </div>
        <div id="comm-hidden-inputs"></div>
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
          <div class="d-flex align-items-center gap-2">
            <!-- AI -->
            <button type="button" class="btn btn-sm btn-outline-primary"
                    style="font-size:.78rem;padding:.2rem .65rem"
                    data-bs-toggle="modal" data-bs-target="#aiModal"
                    title="Wygeneruj treść przez AI">
              <i class="bi bi-stars me-1"></i>Wygeneruj AI
            </button>
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

        <!-- Załączniki (tylko e-mail) -->
        <div id="attachments-section" style="display:none;margin-bottom:1rem">
          <label class="form-label fw-semibold small mb-1">
            <i class="bi bi-paperclip me-1"></i>Załączniki
            <span class="text-muted fw-normal">(max 15 MB każdy, razem max 5 plików)</span>
          </label>
          <input type="file" name="crm_attachments[]" id="crm_attachments"
                 class="form-control form-control-sm"
                 multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.zip,.rar,.odt,.ods">
          <div id="attachments-preview" class="d-flex flex-wrap gap-2 mt-2"></div>
          <div class="form-text">Dozwolone: PDF, Word, Excel, obrazy, archiwa ZIP.</div>
        </div>

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

    <?php if (nozbe_setting('nozbe_enabled') === '1' && nozbe_setting('nozbe_api_token')): ?>
    <!-- Nozbe follow-up -->
    <div class="card border-0 shadow-sm">
      <div class="card-body py-2">
        <div class="form-check mb-2">
          <input type="checkbox" class="form-check-input" name="nozbe_task" id="nozbeTaskChk"
                 value="1" onchange="document.getElementById('nozbeTaskDetails').style.display=this.checked?'':'none'">
          <label class="form-check-label small fw-semibold" for="nozbeTaskChk">
            <img src="https://nozbe.com/favicon.ico" style="width:14px;height:14px;margin-right:4px;vertical-align:middle" alt="">
            Utwórz zadanie follow-up w Nozbe
          </label>
        </div>
        <div id="nozbeTaskDetails" style="display:none">
          <?php
          $nozbe_projects = [];
          try {
              $nz = NozbeAPI::from_settings();
              if ($nz->is_configured()) $nozbe_projects = $nz->get_projects();
          } catch (\Throwable $e) {}
          $default_project = nozbe_setting('nozbe_default_project_id');
          ?>
          <div class="row g-2">
            <div class="col-md-5">
              <label class="form-label small mb-1">Nazwa zadania</label>
              <input type="text" name="nozbe_task_name" class="form-control form-control-sm"
                     placeholder="np. Follow-up po kampanii…">
            </div>
            <div class="col-md-4">
              <label class="form-label small mb-1">Projekt</label>
              <select name="nozbe_project_id" class="form-select form-select-sm">
                <option value="">— domyślny —</option>
                <?php foreach ($nozbe_projects as $p): ?>
                <option value="<?= h($p['id']) ?>" <?= $default_project===$p['id']?'selected':'' ?>>
                  <?= h($p['name'] ?? $p['id']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small mb-1">Termin</label>
              <input type="date" name="nozbe_due_date" class="form-control form-control-sm"
                     value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

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
/* Chip odbiorcy */
.comm-chip {
  display:inline-flex;align-items:center;gap:.3rem;
  background:#EFF7ED;border:1px solid #A7F3D0;border-radius:2rem;
  padding:.2rem .5rem .2rem .65rem;font-size:.76rem;color:#065F46;
}
.comm-chip button {
  background:none;border:none;color:#6B7280;padding:0 .1rem;
  line-height:1;font-size:.95rem;cursor:pointer;
}
.comm-chip button:hover { color:#DC2626; }
/* Dropdown item */
.comm-di {
  display:flex;flex-direction:column;padding:.45rem .75rem;
  cursor:pointer;border-bottom:1px solid #F3F4F6;
}
.comm-di:last-child { border-bottom:none; }
.comm-di:hover { background:#F9FAFB; }
.comm-di-name { font-size:.82rem;font-weight:600;color:#111827; }
.comm-di-sub  { font-size:.72rem;color:#6B7280; }
.comm-di.already  { opacity:.45;cursor:default;pointer-events:none; }
.comm-di.disabled { opacity:.55;cursor:not-allowed;background:#FEF3E2; }
.comm-di.disabled .comm-di-name { color:#92400E; }
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
    // Udostępnij dla modułu AI
    document.getElementById('quillEditor').__quill = _quill;
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
      // Pokaż/ukryj załączniki
      var attSec = document.getElementById('attachments-section');
      if (attSec) attSec.style.display = (channelSel.value === 'email') ? '' : 'none';
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

  // ── Załączniki — init ────────────────────────────────────────────────────
  (function() {
    var attSec    = document.getElementById('attachments-section');
    var attInput  = document.getElementById('crm_attachments');
    var attPrev   = document.getElementById('attachments-preview');
    var channelEl = document.getElementById('channel');

    function refreshAttachSec() {
      if (!attSec || !channelEl) return;
      attSec.style.display = channelEl.value === 'email' ? '' : 'none';
    }
    refreshAttachSec();

    if (!attInput || !attPrev) return;

    attInput.addEventListener('change', function() {
      attPrev.innerHTML = '';
      var files = Array.from(attInput.files);
      if (files.length > 5) {
        attPrev.innerHTML = '<span class="text-danger small">Maksymalnie 5 załączników.</span>';
        attInput.value = '';
        return;
      }
      files.forEach(function(f) {
        var size = f.size > 1048576
          ? (f.size / 1048576).toFixed(1) + ' MB'
          : Math.round(f.size / 1024) + ' KB';
        var chip = document.createElement('span');
        chip.style.cssText = 'background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:.25rem .6rem;font-size:.8rem;display:inline-flex;align-items:center;gap:.3rem';
        chip.innerHTML = '<i class="bi bi-paperclip" style="font-size:.75rem"></i>'
          + esc(f.name)
          + ' <span style="color:#94a3b8">(' + size + ')</span>';
        attPrev.appendChild(chip);
      });
    });

    function esc(s) { var d=document.createElement('div');d.textContent=s;return d.innerHTML; }
  })();
})();
</script>

<!-- ══ Modal: Generuj treść przez AI ═══════════════════════════════════════ -->
<div class="modal fade" id="aiModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold">
          <i class="bi bi-stars text-primary me-1"></i>Asystent AI — wygeneruj treść
        </h6>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label small fw-semibold">Temat / instrukcja <span class="text-danger">*</span></label>
          <textarea id="ai-prompt" class="form-control" rows="3"
                    placeholder="np. Zaproszenie na spotkanie podsumowujące projekt, nieformalne, z podziękowaniem za zaangażowanie"></textarea>
        </div>
        <div class="row g-2">
          <div class="col-4">
            <label class="form-label small fw-semibold">Ton</label>
            <select id="ai-tone" class="form-select form-select-sm">
              <option value="profesjonalny">Profesjonalny</option>
              <option value="przyjazny">Przyjazny</option>
              <option value="formalny">Formalny</option>
              <option value="nieformalny">Nieformalny</option>
              <option value="motywujący">Motywujący</option>
            </select>
          </div>
          <div class="col-4">
            <label class="form-label small fw-semibold">Model AI</label>
            <select id="ai-model" class="form-select form-select-sm">
              <option value="claude-haiku-4-5-20251001">Haiku — szybki</option>
              <option value="claude-sonnet-4-6">Sonnet — lepszy</option>
            </select>
          </div>
          <div class="col-4">
            <label class="form-label small fw-semibold">Działanie</label>
            <select id="ai-insert" class="form-select form-select-sm">
              <option value="replace">Zastąp treść</option>
              <option value="append">Dodaj na końcu</option>
            </select>
          </div>
        </div>
        <div class="mb-0 mt-2">
          <label class="form-label small fw-semibold">Dodatkowy kontekst (opcjonalnie)</label>
          <input type="text" id="ai-context" class="form-control form-control-sm"
                 placeholder="np. Dotyczy projektu Lato 2025, grupę odbiorców: wolontariusze">
        </div>
        <div id="ai-error" class="alert alert-danger py-2 small mt-3 d-none"></div>
        <div id="ai-result" class="border rounded bg-light p-2 mt-3 small d-none" style="max-height:160px;overflow-y:auto"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-primary btn-sm" id="ai-submit" onclick="aiGenerate()">
          <i class="bi bi-stars me-1"></i>Generuj
        </button>
        <button type="button" class="btn btn-success btn-sm d-none" id="ai-apply" onclick="aiApply()">
          <i class="bi bi-check2 me-1"></i>Wstaw do edytora
        </button>
      </div>
    </div>
  </div>
</div>

<script>
var _aiGeneratedHtml  = '';
var _aiGeneratedPlain = '';

function aiGenerate() {
    const prompt = document.getElementById('ai-prompt').value.trim();
    if (!prompt) { document.getElementById('ai-prompt').focus(); return; }

    const btn = document.getElementById('ai-submit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generuję…';
    document.getElementById('ai-error').classList.add('d-none');
    document.getElementById('ai-result').classList.add('d-none');
    document.getElementById('ai-apply').classList.add('d-none');

    const channel = document.querySelector('[name="channel"]')?.value || 'email';

    fetch('<?= APP_URL ?>/crm/api/ai_generate.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            _csrf:   '<?= csrf_token() ?>',
            prompt:  prompt,
            tone:    document.getElementById('ai-tone').value,
            model:   document.getElementById('ai-model').value,
            channel: channel,
            context: document.getElementById('ai-context').value,
        }),
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-stars me-1"></i>Generuj ponownie';
        if (data.ok) {
            _aiGeneratedHtml  = data.html  || '';
            _aiGeneratedPlain = data.plain || '';
            const preview = document.getElementById('ai-result');
            preview.innerHTML = _aiGeneratedHtml || _aiGeneratedPlain.replace(/\n/g, '<br>');
            preview.classList.remove('d-none');
            document.getElementById('ai-apply').classList.remove('d-none');
        } else {
            const err = document.getElementById('ai-error');
            err.textContent = data.error || 'Nieznany błąd.';
            err.classList.remove('d-none');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-stars me-1"></i>Generuj';
        const err = document.getElementById('ai-error');
        err.textContent = 'Błąd połączenia.';
        err.classList.remove('d-none');
    });
}

function aiApply() {
    const insertMode = document.getElementById('ai-insert').value;
    const channel    = document.querySelector('[name="channel"]')?.value || 'email';
    const isRich     = !document.getElementById('plainWrapper') ||
                       document.getElementById('plainWrapper').style.display === 'none';

    const qlEditor = document.getElementById('quillEditor');
    if (isRich && qlEditor && qlEditor.__quill) {
        const q = qlEditor.__quill;
        if (insertMode === 'replace') {
            q.root.innerHTML = _aiGeneratedHtml;
        } else {
            q.clipboard.dangerouslyPasteHTML(q.getLength() - 1, _aiGeneratedHtml);
        }
    } else if (isRich && qlEditor) {
        // fallback: wstaw bezpośrednio do DOM edytora Quill
        if (insertMode === 'replace') qlEditor.innerHTML = _aiGeneratedHtml;
        else qlEditor.innerHTML += _aiGeneratedHtml;
    } else {
        const ta = document.getElementById('bodyPlain');
        if (ta) {
            if (insertMode === 'replace') ta.value = _aiGeneratedPlain;
            else ta.value = (ta.value ? ta.value + '\n\n' : '') + _aiGeneratedPlain;
        }
    }

    bootstrap.Modal.getInstance(document.getElementById('aiModal')).hide();
}
</script>

<?php if (!$preselect_contact_id && !$preselect_group): ?>
<script>
/* ── Live search odbiorców w communicate.php ────────────────────────────── */
const CommRecip = (function () {
  const SEARCH_URL = '<?= APP_URL ?>/crm/api/contacts_search.php';
  const selected   = new Map(); // id → {id, name, email, telefon, org}
  let   timer      = null;

  const inp      = document.getElementById('comm-search');
  const dropdown = document.getElementById('comm-dropdown');
  const chips    = document.getElementById('comm-chips');
  const hiddens  = document.getElementById('comm-hidden-inputs');
  const counter  = document.getElementById('comm-count');
  const chanSel  = document.getElementById('channel');

  if (!inp) return {};

  // Aktualny kanał
  function getChannel() { return chanSel ? chanSel.value : 'email'; }

  // Czy kontakt ma wymagane pole dla danego kanału
  function contactOk(c, ch) {
    if (ch === 'email')    return !!c.email;
    if (ch === 'sms')      return !!c.telefon;
    if (ch === 'telefon')  return !!c.telefon;
    return true; // osobisty, inne — zawsze ok
  }

  function missingField(ch) {
    if (ch === 'email')   return 'brak adresu e-mail';
    if (ch === 'sms')     return 'brak numeru telefonu';
    if (ch === 'telefon') return 'brak numeru telefonu';
    return '';
  }

  inp.addEventListener('input', () => {
    clearTimeout(timer);
    const q = inp.value.trim();
    if (!q) { dropdown.style.display = 'none'; return; }
    timer = setTimeout(() => search(q), 200);
  });

  // Przelicz dropdown gdy zmienia się kanał
  if (chanSel) chanSel.addEventListener('change', () => {
    if (dropdown.style.display !== 'none') renderDropdown(_lastRows);
    // Odśwież chipy — wyszarz te które nie pasują do nowego kanału
    renderChips();
  });

  let _lastRows = [];

  document.addEventListener('click', e => {
    if (!e.target.closest('#comm-search') && !e.target.closest('#comm-dropdown'))
      dropdown.style.display = 'none';
  });

  function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function search(q) {
    const excl = [...selected.keys()].join(',');
    fetch(`${SEARCH_URL}?q=${encodeURIComponent(q)}&limit=25&exclude=${excl}`)
      .then(r => r.json())
      .then(rows => {
        _lastRows = rows;
        renderDropdown(rows);
      });
  }

  function renderDropdown(rows) {
    if (!rows.length) { dropdown.style.display = 'none'; return; }
    const ch = getChannel();
    dropdown.innerHTML = rows.map(c => {
      const ok      = contactOk(c, ch);
      const missing = ok ? '' : missingField(ch);
      const contact = c.email || c.telefon ? (c.email || '') + (c.telefon ? (c.email?' · ':'')+c.telefon : '') : 'brak danych';
      return `
        <div class="comm-di${ok ? '' : ' disabled'}"
             data-id="${c.id}" data-name="${esc(c.name)}"
             data-email="${esc(c.email||'')}" data-telefon="${esc(c.telefon||'')}"
             data-org="${esc(c.organizacja||'')}"
             ${ok ? `onclick="CommRecip.add(${c.id},'${esc(c.name)}','${esc(c.email||'')}','${esc(c.telefon||'')}','${esc(c.organizacja||'')}')"` : ''}
             title="${ok ? '' : 'Nie można wybrać — '+missing}">
          <div class="comm-di-name">
            ${esc(c.name)}
            ${c.organizacja ? `<span style="font-weight:400;color:#9CA3AF"> · ${esc(c.organizacja)}</span>` : ''}
            <span class="badge bg-light text-dark border ms-1" style="font-size:.62rem">${c.type==='organizacja'?'org':'os.'}</span>
            ${!ok ? `<span class="badge bg-warning text-dark ms-1" style="font-size:.6rem"><i class="bi bi-exclamation-triangle-fill me-1"></i>${missing}</span>` : ''}
          </div>
          <div class="comm-di-sub" style="${ok?'':'color:#D97706'}">
            ${ok ? esc(contact) : '<i>'+esc(missing)+'</i>'}
          </div>
        </div>`;
    }).join('');
    dropdown.style.display = 'block';
  }

  function add(id, name, email, telefon, org) {
    if (selected.has(id)) return;
    // Sprawdź czy kontakt pasuje do aktualnego kanału
    const ch = getChannel();
    if (!contactOk({email, telefon}, ch)) return;
    selected.set(id, {id, name, email, telefon, org});
    inp.value = '';
    dropdown.style.display = 'none';
    render();
  }

  function remove(id) {
    selected.delete(id);
    render();
  }

  function renderChips() {
    chips.innerHTML = '';
    const ch = getChannel();
    selected.forEach(({id, name, email, telefon}) => {
      const ok = contactOk({email, telefon}, ch);
      const ch_el = document.createElement('span');
      ch_el.className = 'comm-chip' + (ok ? '' : ' opacity-50');
      ch_el.title = ok ? '' : 'Ten kontakt nie ma ' + missingField(ch) + ' — nie zostanie wysłany';
      ch_el.innerHTML = `<i class="bi bi-person-fill" style="font-size:.75rem"></i>`
        + `<span>${esc(name)}</span>`
        + (ch === 'email' && email ? `<span class="text-muted" style="font-size:.7rem">&lt;${esc(email)}&gt;</span>` : '')
        + (ch !== 'email' && telefon ? `<span class="text-muted" style="font-size:.7rem">${esc(telefon)}</span>` : '')
        + (!ok ? `<i class="bi bi-exclamation-triangle-fill text-warning ms-1" style="font-size:.7rem"></i>` : '')
        + `<button type="button" onclick="CommRecip.remove(${id})"><i class="bi bi-x"></i></button>`;
      chips.appendChild(ch_el);
    });
  }

  function render() {
    renderChips();

    // Hidden inputs — tylko kontakty pasujące do kanału
    const ch = getChannel();
    hiddens.innerHTML = '';
    selected.forEach(({id, email, telefon}) => {
      if (!contactOk({email, telefon}, ch)) return;
      const inp = document.createElement('input');
      inp.type = 'hidden'; inp.name = 'recipient_ids[]'; inp.value = id;
      hiddens.appendChild(inp);
    });

    // Licznik — tylko pasujących
    const valid = [...selected.values()].filter(c => contactOk(c, ch)).length;
    const total = selected.size;
    if (total === 0) { counter.textContent = ''; return; }
    counter.textContent = `Wybrano ${valid} z ${total} kontaktów`
      + (valid < total ? ` (${total-valid} bez wymaganego pola — zostaną pominięci)` : '');
    counter.style.color = valid < total ? '#D97706' : '';
  }

  function selectAll() {
    const ch = getChannel();
    dropdown.querySelectorAll('.comm-di:not(.disabled)').forEach(el => {
      add(parseInt(el.dataset.id), el.dataset.name, el.dataset.email, el.dataset.telefon, el.dataset.org);
    });
  }

  return { add, remove, selectAll };
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
