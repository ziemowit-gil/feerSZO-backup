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
require_once dirname(__DIR__) . '/includes/crm_perms.php';
crm_require('inbox', 'write');
if (!can_write('crm_mailing') && !is_admin()) {
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
require_once dirname(__DIR__) . '/includes/crm_attachments.php';
require_once dirname(__DIR__) . '/includes/crm_sender.php';
$m365_mail_configured = _mail_m365_configured();
$mail_channel = $m365_mail_configured ? 'Microsoft 365' : (
    _mail_setting('smtp_host') ? 'SMTP' : 'PHP mail()'
);
// Wysyłka z konta M365 zalogowanego użytkownika (do wyboru)
$_cu_now        = current_user();
$can_send_as_me = $m365_mail_configured && !empty($_cu_now['microsoft_id']) && !empty($_cu_now['email']);
$my_ms_email    = $can_send_as_me ? trim($_cu_now['email']) : '';
$sys_from_email = _mail_setting('m365_send_from_email');
// Konta, z których wolno wysyłać: systemowe, własna skrzynka M365 i skrzynki
// współdzielone z modułu Poczta (wg ACL). Wybór da się zapamiętać.
$sender_accounts = crm_sender_accounts($_cu_now);
$sender_default  = crm_sender_default($sender_accounts);

// ── POST: wyślij wiadomość ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $channel   = in_array($_POST['channel'] ?? '', ['sms','email','telefon','osobisty'], true) ? $_POST['channel'] : 'email';
    $subject   = trim($_POST['subject'] ?? '');
    $body      = trim($_POST['body'] ?? '');
    $tpl_name  = trim($_POST['template_name'] ?? '');
    $do_send   = !empty($_POST['do_send']);

    // Nadawca: system / własna skrzynka M365 / skrzynka współdzielona (mbox:ID)
    $send_as    = (string)($_POST['send_as'] ?? 'system');
    $from_email = $channel === 'email' ? crm_sender_email($send_as, $_cu_now) : '';
    if (!empty($_POST['send_as_remember'])) {
        user_pref_set('crm_send_as', $send_as);
    }

    // Obsługa załączników (tylko dla e-mail).
    // Widżet odkłada pliki (dysk + OneDrive) w poczekalni i przysyła same tokeny;
    // gałąź $_FILES zostaje jako zapas, gdy JS nie zadziała.
    $attachments = [];
    if ($channel === 'email' && !empty($_POST['crm_att_tokens'])) {
        $attachments = crm_att_resolve((array)$_POST['crm_att_tokens']);
    }
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
                $channel === 'email' ? $attachments : [],
                $from_email
            );
            if ($do_send && !$immediate && $channel === 'email' && !empty($contact['email'])) {
                // Wysyłka wsadowa — dodaj do kolejki bez natychmiastowego procesu
                $is_html   = strip_tags($rendered_body) !== $rendered_body;
                $html_body = $is_html ? $rendered_body : nl2br(htmlspecialchars($rendered_body));
                $crm_footer = trim(org_setting('crm_email_footer') ?? '');
                if ($crm_footer) $html_body .= "\n<hr>\n" . $crm_footer;
                mail_queue_add($contact['email'], $contact['imie_nazwisko'] ?? '', $rendered_subject ?: 'Wiadomość', $html_body, $rendered_body, 'crm', $cid, '', false, $attachments, $from_email);
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

<div class="crm-object-header comm-head mb-3">
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

<style>
/* ══ Widok wysyłki — układ „clear" ═══════════════════════════════════════
   Mniej ramek, mniej koloru, jedna miara odstępów. Kolor zostaje tam, gdzie
   niesie znaczenie: przycisk wysyłki i ostrzeżenia. Reszta ma nie krzyczeć. */
.comm-card {
  border: 1px solid #E5E7EB !important;
  border-radius: 12px;
  box-shadow: none !important;
  background: #fff;
}
.comm-card > .card-body { padding: 1.15rem 1.25rem; }
.comm-card--quiet { background: #FBFCFD; }

/* Nagłówek sekcji: numer jako cichy licznik, nie kolorowe kółko */
.comm-step { display: flex; align-items: baseline; gap: .55rem; margin-bottom: 1rem;
  padding-bottom: .7rem; border-bottom: 1px solid #F1F2F4; }
.comm-step__num {
  width: auto; height: auto; border-radius: 0; background: none;
  color: #C3C8D0; font-weight: 700; font-size: .8rem; font-variant-numeric: tabular-nums;
  flex-shrink: 0; display: inline; letter-spacing: .05em;
}
.comm-step__num::after { content: '.'; }
.comm-step__t { font-size: .92rem; font-weight: 700; color: #111827; line-height: 1.2; }
.comm-step__h { font-size: .76rem; color: #9CA3AF; margin-top: .1rem; }

/* Pola: jedna wysokość i jeden promień w całym formularzu */
#communicateForm .form-control,
#communicateForm .form-select {
  min-height: 36px; font-size: .86rem; color: #111827;
  border: 1px solid #E5E7EB; border-radius: 8px; box-shadow: none;
}
#communicateForm textarea.form-control { min-height: 120px; }
#communicateForm .form-control:focus,
#communicateForm .form-select:focus {
  border-color: var(--crm-primary); box-shadow: 0 0 0 3px rgba(1,118,211,.12);
}
#communicateForm .form-label { font-size: .76rem; font-weight: 600; color: #374151; margin-bottom: .3rem; }
#communicateForm .input-group-text { background: #fff; border-color: #E5E7EB; border-radius: 8px 0 0 8px; color: #9CA3AF; }
#communicateForm .input-group > .form-control { border-radius: 0 8px 8px 0; }

/* Pasek wysyłki: biały, oddzielony linią — kolor zostaje na przycisku */
.comm-send-bar {
  display: flex; flex-wrap: wrap; align-items: center; gap: .75rem 1rem;
  background: #fff; border: 0; border-top: 1px solid #F1F2F4;
  border-radius: 0; padding: .9rem 0 0;
}
.comm-send-bar .form-check-label { font-size: .82rem; color: #4B5563; }
.comm-send-bar .btn-send { font-size: .9rem; font-weight: 600; padding: .55rem 1.4rem; border-radius: 9px; }

/* Plakietki stanu kanałów — ciche, jednakowej wagi */
.comm-flags { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .75rem; }
.comm-flags .badge {
  font-weight: 500; font-size: .73rem !important; border-radius: 2rem; padding: .25rem .6rem;
}

/* Boczna kolumna */
.comm-side .crm-section-title { font-size: .7rem; letter-spacing: .08em; color: #9CA3AF; margin-bottom: .6rem; }
.comm-tpl-row { display: flex; align-items: center; gap: .5rem; padding: .35rem 0; border-bottom: 1px solid #F3F4F6; }
.comm-tpl-row:last-child { border-bottom: none; }
.comm-vars td { padding: .12rem .35rem; }
.comm-vars .font-monospace { color: #0F766E !important; font-size: .74rem; }

/* Nagłówek obiektu — lżejszy, bez cienia */
.crm-object-header.comm-head { box-shadow: none !important; border: 1px solid #E5E7EB; border-radius: 12px; }
</style>

<div class="row g-3">

  <!-- Formularz -->
  <div class="col-lg-8">
    <form method="post" enctype="multipart/form-data" id="communicateForm" novalidate aria-label="Formularz wysyłania wiadomości CRM">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

    <!-- Odbiorcy -->
    <div class="card comm-card mb-3">
      <div class="card-body">
        <div class="comm-step">
          <span class="comm-step__num" aria-hidden="true">1</span>
          <div>
            <div class="comm-step__t">Odbiorcy</div>
            <div class="comm-step__h">Wybierz, do kogo wysłać wiadomość</div>
          </div>
        </div>

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

          <!-- Masowo: wszyscy o wybranym statusie (np. cała baza darczyńców) -->
          <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
            <label class="form-label mb-0" for="comm-status-add" style="font-size:.76rem">Dodaj wszystkich ze statusem</label>
            <select id="comm-status-add" class="form-select form-select-sm" style="max-width:210px">
              <option value="">— wybierz status —</option>
              <?php foreach (crm_statuses() as $sk => $sv): ?>
              <option value="<?= h($sk) ?>"><?= h($sv['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="comm-status-btn">
              <i class="bi bi-people me-1"></i>Dodaj
            </button>
            <span id="comm-status-info" class="text-muted" style="font-size:.75rem"></span>
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
    <div class="card comm-card mb-3">
      <div class="card-body">
        <div class="comm-step">
          <span class="comm-step__num" aria-hidden="true">2</span>
          <div>
            <div class="comm-step__t">Kanał i szablon</div>
            <div class="comm-step__h">Jak wyślesz i z którego konta</div>
          </div>
        </div>

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

        <?php if (count($sender_accounts) > 1): ?>
        <div id="senderRow" class="mb-1" style="display:<?= $preselect_channel === 'email' ? '' : 'none' ?>">
          <label class="form-label" for="send_as"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>Konto nadawcy (e-mail)</label>
          <select name="send_as" id="send_as" class="form-select" aria-describedby="senderHelp">
            <?php foreach ($sender_accounts as $acc): ?>
            <option value="<?= h($acc['key']) ?>" data-hint="<?= h($acc['hint']) ?>"
                    <?= $acc['key'] === $sender_default ? 'selected' : '' ?>><?= h($acc['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-check mt-1">
            <input class="form-check-input" type="checkbox" name="send_as_remember" id="send_as_remember" value="1">
            <label class="form-check-label" for="send_as_remember" style="font-size:.76rem">
              Zapamiętaj jako moje domyślne konto wysyłki
            </label>
          </div>
          <div id="senderHelp" class="form-text" style="font-size:.74rem">
            <?= h($sender_accounts[0]['hint']) ?>
          </div>
        </div>
        <?php endif; ?>

        <input type="hidden" name="template_name" id="template_name_hidden" value="">
      </div>
    </div>

    <!-- Treść wiadomości -->
    <div class="card comm-card mb-3">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between" style="text-transform:none;letter-spacing:0;border:0;padding:0">
          <div class="comm-step mb-0">
            <span class="comm-step__num" aria-hidden="true">3</span>
            <div>
              <div class="comm-step__t">Treść wiadomości</div>
              <div class="comm-step__h">Wpisz treść lub użyj szablonu / AI</div>
            </div>
          </div>
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
        <div id="tplVarBadge" style="display:none;margin-top:.45rem"></div>
      </div>
    </div>

    <!-- Opcje wysyłki -->
    <div class="card comm-card">
      <div class="card-body">

        <!-- Załączniki (tylko e-mail) -->
        <div id="attachments-section" style="display:none;margin-bottom:1rem">
          <?php $ATT_UI = ['form' => true]; include __DIR__ . '/includes/attachments_ui.php'; ?>
        </div>

        <div class="comm-send-bar">
          <div class="form-check mb-0 flex-grow-1">
            <input type="checkbox" name="do_send" id="do_send"
                   class="form-check-input" value="1" checked>
            <label class="form-check-label" for="do_send">
              <strong>Wyślij teraz</strong> — odznacz, aby tylko zapisać w historii kontaktu (bez wysyłki)
            </label>
          </div>
          <button type="submit" class="btn btn-crm-primary btn-send">
            <i class="bi bi-send-fill me-1" aria-hidden="true"></i>Wyślij wiadomość
          </button>
        </div>
        <div class="comm-flags">
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
    <div class="card comm-card">
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
  <div class="col-lg-4 comm-side">

    <!-- Szablony istniejące -->
    <div class="card comm-card mb-3">
      <div class="card-body">
        <div class="crm-section-title d-flex align-items-center justify-content-between">
          Szablony
          <span class="d-flex align-items-center gap-2">
            <a href="<?= APP_URL ?>/crm/templates.php"
               style="font-size:.72rem;text-transform:none;letter-spacing:0;text-decoration:none;color:var(--crm-text-light)"
               title="Zarządzaj szablonami (edycja, usuwanie)">
              <i class="bi bi-gear me-1"></i>Zarządzaj
            </a>
            <button type="button" class="btn btn-sm btn-crm-outline py-0 px-2"
                    data-bs-toggle="modal" data-bs-target="#newTemplateModal"
                    style="font-size:.72rem;text-transform:none;letter-spacing:0">
              <i class="bi bi-plus me-1"></i>Nowy
            </button>
          </span>
        </div>
        <?php if ($templates): ?>
          <?php foreach ($templates as $tpl): ?>
          <div class="comm-tpl-row" style="font-size:.82rem">
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
    <div class="card comm-card comm-card--quiet">
      <div class="card-body" style="font-size:.8rem">
        <div class="crm-section-title">Zmienne szablonu</div>
        <table class="table table-sm table-borderless mb-0 comm-vars" style="font-size:.78rem">
          <tbody>
            <tr><td colspan="2" class="text-uppercase fw-bold" style="font-size:.66rem;letter-spacing:.05em;color:#5E6470">Odbiorca</td></tr>
            <tr><td class="font-monospace text-success">{imie}</td><td>Imię (pierwsze słowo)</td></tr>
            <tr><td class="font-monospace text-success">{imie_nazwisko}</td><td>Pełne imię i nazwisko</td></tr>
            <tr><td class="font-monospace text-success">{email}</td><td>Adres e-mail</td></tr>
            <tr><td class="font-monospace text-success">{organizacja}</td><td>Nazwa firmy / org.</td></tr>
            <tr><td class="font-monospace text-success">{stanowisko}</td><td>Stanowisko</td></tr>
            <tr><td class="font-monospace text-success">{data}</td><td>Dzisiejsza data</td></tr>
            <tr><td colspan="2" class="text-uppercase fw-bold pt-2" style="font-size:.66rem;letter-spacing:.05em;color:#5E6470">Nadawca (Ty)</td></tr>
            <tr><td class="font-monospace text-success">{nadawca_imie_nazwisko}</td><td>Twoje imię i nazwisko</td></tr>
            <tr><td class="font-monospace text-success">{nadawca_email}</td><td>Twój e-mail</td></tr>
            <tr><td class="font-monospace text-success">{nadawca_telefon}</td><td>Twój telefon</td></tr>
          </tbody>
        </table>
        <?php $cm_missing = CrmManager::senderMissing(); if ($cm_missing): ?>
        <div class="d-flex align-items-start gap-2 mt-2 p-2" style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:8px;font-size:.74rem;color:#92400E">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
          <span>Twoje dane nadawcy są niekompletne (brak: <strong><?= h(implode(', ', $cm_missing)) ?></strong>).
            <a href="<?= APP_URL ?>/panel/index.php" style="color:#92400E;font-weight:600">Uzupełnij →</a></span>
        </div>
        <?php endif; ?>
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
    var srow = document.getElementById('senderRow');
    if (srow) srow.style.display = ch === 'email' ? '' : 'none';
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

  // ── Auto-fill zmiennych szablonu ─────────────────────────────────────────
  var _currentTplId   = 0;   // id aktualnie załadowanego szablonu
  var _previewAbort   = null;
  var PREVIEW_URL     = '<?= APP_URL ?>/crm/api/template_preview.php';
  var PRESELECT_CID   = <?= $preselect_contact_id ?: 0 ?>;

  // Pomocnik: pobierz ID jedynego wybranego odbiorcy (0 = brak lub więcej niż jeden)
  function getSingleRecipientId() {
    // Tryb preselect — jeden kontakt z GET
    if (PRESELECT_CID) return PRESELECT_CID;
    // Tryb multi-search — CommRecip.selected (Map)
    if (typeof CommRecip === 'undefined') return 0;
    var sel = CommRecip.getSelected ? CommRecip.getSelected() : [];
    return sel.length === 1 ? sel[0].id : 0;
  }

  function getSelectedCount() {
    if (PRESELECT_CID) return 1;
    if (typeof CommRecip === 'undefined') return 0;
    var sel = CommRecip.getSelected ? CommRecip.getSelected() : [];
    return sel.length;
  }

  // Ustaw treść i temat w edytorze (bez zmiany kanału)
  function setEditorContent(subject, body, channel) {
    var subjectIn = document.getElementById('subject');
    if (subjectIn && subject !== null) subjectIn.value = subject;
    var isSms = (channel === 'sms');
    if (isSms) {
      document.getElementById('bodyPlain').value = body;
    } else {
      if (_mode === 'rich' && _quill) {
        if (/<[a-z][\s\S]*>/i.test(body)) { _quill.root.innerHTML = body; }
        else { _quill.setText(body); }
      } else {
        document.getElementById('bodyPlain').value = body;
      }
    }
    updateCharCount();
  }

  // Badge statusu zmiennych pod edytorem
  function setVarBadge(state, contactName) {
    var el = document.getElementById('tplVarBadge');
    if (!el) return;
    if (!state) { el.style.display = 'none'; el.innerHTML = ''; return; }
    var html = '';
    if (state === 'filled') {
      html = '<span class="badge text-bg-success fw-normal" style="font-size:.73rem">'
           + '<i class="bi bi-person-check-fill me-1"></i>Zmienne uzupełnione dla: '
           + esc(contactName) + '</span>';
    } else if (state === 'multi') {
      html = '<span class="badge text-bg-secondary fw-normal" style="font-size:.73rem">'
           + '<i class="bi bi-people-fill me-1"></i>Zmienne zostaną uzupełnione przy wysyłce (osobno dla każdego odbiorcy)</span>';
    } else if (state === 'none') {
      html = '<span class="badge text-bg-warning fw-normal" style="font-size:.73rem">'
           + '<i class="bi bi-exclamation-circle me-1"></i>Wybierz odbiorcę, aby uzupełnić zmienne</span>';
    } else if (state === 'loading') {
      html = '<span class="badge text-bg-light text-secondary fw-normal border" style="font-size:.73rem">'
           + '<span class="spinner-border spinner-border-sm me-1" style="width:.65rem;height:.65rem"></span>Uzupełnianie zmiennych…</span>';
    }
    el.innerHTML = html;
    el.style.display = html ? '' : 'none';
  }

  function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  // Główna funkcja: auto-fill szablonu dla bieżącego odbiorcy
  function tplAutoFill(rawBody, rawSubject, rawChannel) {
    if (!_currentTplId) { setVarBadge(null); return; }

    // Czy treść zawiera zmienne?
    var bodyToCheck = rawBody || '';
    var hasVars = /\{[a-z_]+\}/.test(bodyToCheck) || /\{[a-z_]+\}/.test(rawSubject || '');
    if (!hasVars) { setVarBadge(null); return; }

    var cid   = getSingleRecipientId();
    var count = getSelectedCount();

    if (count === 0) { setVarBadge('none'); return; }
    if (count > 1)   { setVarBadge('multi'); return; }

    // Dokładnie 1 odbiorca — fetch podglądu
    if (_previewAbort) _previewAbort.abort();
    _previewAbort = new AbortController();
    setVarBadge('loading');

    fetch(PREVIEW_URL + '?template_id=' + _currentTplId + '&contact_id=' + cid, { signal: _previewAbort.signal })
      .then(function(r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function(data) {
        setEditorContent(data.subject, data.body, data.channel);
        setVarBadge('filled', data.contact_name);
      })
      .catch(function(e) { if (e && e.name !== 'AbortError') setVarBadge(null); });
  }

  // Podepnij template_select dropdown
  var tplSelectEl = document.getElementById('template_select');
  if (tplSelectEl) {
    tplSelectEl.addEventListener('change', function() {
      var opt = this.options[this.selectedIndex];
      if (!this.value) {
        _currentTplId = 0;
        setVarBadge(null);
        return;
      }
      _currentTplId = parseInt(this.value, 10);
      var body    = opt.dataset.body    || '';
      var subject = opt.dataset.subject || '';
      var channel = opt.dataset.channel || 'email';
      var name    = opt.dataset.name    || '';
      // Ustaw kanał
      var chanSel = document.getElementById('channel');
      if (chanSel) chanSel.value = channel;
      // Ustaw hidden template_name
      var tplHidden = document.getElementById('template_name_hidden');
      if (tplHidden) tplHidden.value = name;
      // Załaduj surową treść, potem próbuj auto-fill
      setEditorContent(subject, body, channel);
      toggleSubject();
      tplAutoFill(body, subject, channel);
      // Szablon może mieć przypięte załączniki — dokładamy ich kopie
      if (channel === 'email' && window.CrmAtt) window.CrmAtt.fromTemplate(_currentTplId);
    });
  }

  // Nadpisz window.loadTemplate — po załadowaniu też próbuj auto-fill
  var _origLoadTemplate = window.loadTemplate;
  window.loadTemplate = function(id) {
    _origLoadTemplate(id);
    _currentTplId = id;
    var btn = document.querySelector('[onclick="loadTemplate(' + id + ')"]');
    if (btn) tplAutoFill(btn.dataset.body || '', btn.dataset.subject || '', btn.dataset.channel || 'email');
  };

  // Eksportuj hook dla CommRecip — zostanie wywołany po add/remove
  window._tplAutoFillHook = function() {
    if (!_currentTplId) return;
    // Pobierz surową treść z aktualnego edytora (zawiera zmienne, jeśli nie były jeszcze wypełnione)
    // lub po prostu refetchuj z serwera bazując na _currentTplId
    var count = getSelectedCount();
    if (count === 0) { setVarBadge('none'); return; }
    if (count > 1)   { setVarBadge('multi'); return; }
    // 1 odbiorca — refetch
    var cid = getSingleRecipientId();
    if (_previewAbort) _previewAbort.abort();
    _previewAbort = new AbortController();
    setVarBadge('loading');
    fetch(PREVIEW_URL + '?template_id=' + _currentTplId + '&contact_id=' + cid, { signal: _previewAbort.signal })
      .then(function(r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function(data) {
        setEditorContent(data.subject, data.body, data.channel);
        setVarBadge('filled', data.contact_name);
      })
      .catch(function(e) { if (e && e.name !== 'AbortError') setVarBadge(null); });
  };

  // ── Załączniki — pokazuj sekcję tylko dla e-maila ────────────────────────
  // Sam widżet (upload + OneDrive) obsługuje crm/includes/attachments_ui.php.
  (function() {
    var attSec    = document.getElementById('attachments-section');
    var channelEl = document.getElementById('channel');
    function refreshAttachSec() {
      if (!attSec || !channelEl) return;
      attSec.style.display = channelEl.value === 'email' ? '' : 'none';
    }

    // Podpowiedź pod wyborem konta mówi, co się stanie z wysyłką
    var sendAs = document.getElementById('send_as');
    var sendHelp = document.getElementById('senderHelp');
    if (sendAs && sendHelp) {
      var paintHint = function () {
        var o = sendAs.options[sendAs.selectedIndex];
        sendHelp.textContent = (o && o.dataset.hint) || '';
      };
      sendAs.addEventListener('change', paintHint);
      paintHint();
    }
    if (channelEl) channelEl.addEventListener('change', refreshAttachSec);
    refreshAttachSec();
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
    if (typeof window._tplAutoFillHook === 'function') window._tplAutoFillHook();
  }

  function remove(id) {
    selected.delete(id);
    render();
    if (typeof window._tplAutoFillHook === 'function') window._tplAutoFillHook();
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

  function getSelected() { return [...selected.values()]; }

  /* Masowe dodanie wszystkich kontaktów o danym statusie — do wysyłek typu
     „wszyscy darczyńcy". Limit po stronie API (500), żeby nie zawiesić okna. */
  function addByStatus(status, done) {
    if (!status) return;
    fetch(`<?= APP_URL ?>/crm/api/contacts_search.php?status=${encodeURIComponent(status)}&limit=500`)
      .then(r => r.json())
      .then(rows => {
        const before = selected.size;
        (rows || []).forEach(c => add(c.id, c.name, c.to_email || c.email || '',
                                      c.to_telefon || c.telefon || '', c.organizacja || ''));
        if (typeof done === 'function') done(rows ? rows.length : 0, selected.size - before);
      })
      .catch(() => { if (typeof done === 'function') done(-1, 0); });
  }

  return { add, remove, selectAll, getSelected, addByStatus };
})();

// Przycisk „Dodaj wszystkich ze statusem"
(function () {
  var btn = document.getElementById('comm-status-btn');
  var sel = document.getElementById('comm-status-add');
  var info = document.getElementById('comm-status-info');
  if (!btn || !sel) return;

  btn.addEventListener('click', function () {
    var st = sel.value;
    if (!st) { info.textContent = 'Najpierw wybierz status.'; return; }
    btn.disabled = true;
    info.textContent = 'Pobieram…';
    CommRecip.addByStatus(st, function (found, added) {
      btn.disabled = false;
      if (found < 0) { info.textContent = 'Nie udało się pobrać kontaktów.'; return; }
      info.textContent = found === 0
        ? 'Brak kontaktów o tym statusie.'
        : 'Znaleziono ' + found + ', dodano nowych: ' + added + '.';
    });
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
