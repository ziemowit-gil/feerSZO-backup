<?php
/**
 * panel/whatsapp_group.php — Podstrona "WhatsApp — grupa" w panelu wolontariusza.
 * Treść (opis + link zaproszenia) zarządzana przez administratora
 * w admin/whatsapp_group.php (settings: whatsapp_group_*).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/includes/sms.php';

require_login();
require_module_enabled('whatsapp_group_enabled', 'Podstrona WhatsApp — grupa');

$link = org_setting('whatsapp_group_link');
$info = org_setting('whatsapp_group_info');

$user       = current_user();
$fresh_user = db_one("SELECT email, name, phone_number, twofa_phone FROM users WHERE id=?", [(int)$user['id']]);
$user_email = trim($fresh_user['email'] ?? ($user['email'] ?? ''));
$user_name  = $fresh_user['name'] ?? ($user['name'] ?? '');
$user_phone = trim($fresh_user['phone_number'] ?? '') ?: trim($fresh_user['twofa_phone'] ?? '');
if (!$user_phone && $user_email) {
    $c = db_one("SELECT telefon FROM umowy_wolontariat WHERE email=? AND telefon <> '' ORDER BY created_at DESC LIMIT 1", [$user_email]);
    $user_phone = trim($c['telefon'] ?? '');
}

// ── Wyślij mi link (e-mail / SMS) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'send_link') {
    csrf_check();
    $via_email = !empty($_POST['via_email']);
    $via_sms   = !empty($_POST['via_sms']);
    $sent      = [];
    $send_errors = [];

    if (!$link) {
        $send_errors[] = 'Link do grupy nie został jeszcze udostępniony.';
    } elseif (!$via_email && !$via_sms) {
        $send_errors[] = 'Wybierz co najmniej jeden sposób wysyłki.';
    } else {
        $rules_text = "Zasady grupy:\n"
                    . "- tylko do szybkich rozmów i luźnych spraw\n"
                    . "- zadania ogarniamy w zadaniach w SZO — zadania.feer.org.pl\n"
                    . "- nie wysyłamy danych wrażliwych";

        if ($via_email) {
            if ($user_email) {
                $body_html = '<p>Link do grupy WhatsApp organizacji:</p>'
                           . '<p><a href="' . h($link) . '">' . h($link) . '</a></p>'
                           . '<p><strong>Zasady grupy:</strong></p>'
                           . '<ul>'
                           . '<li>tylko do szybkich rozmów i luźnych spraw</li>'
                           . '<li>zadania ogarniamy w zadaniach w SZO — <a href="https://zadania.feer.org.pl">zadania.feer.org.pl</a></li>'
                           . '<li>nie wysyłamy danych wrażliwych</li>'
                           . '</ul>';
                mail_queue_add($user_email, $user_name, 'Link do grupy WhatsApp — FEER', $body_html, '', 'whatsapp_group', null, '', true);
                $sent[] = 'e-mailem';
            } else {
                $send_errors[] = 'Nie znaleziono adresu e-mail na Twoim koncie.';
            }
        }

        if ($via_sms) {
            if ($user_phone) {
                try {
                    sms_send($user_phone, "Link do grupy WhatsApp FEER: {$link}\n\n{$rules_text}");
                    $sent[] = 'SMS-em';
                } catch (\Throwable $e) {
                    $send_errors[] = 'Nie udało się wysłać SMS: ' . $e->getMessage();
                }
            } else {
                $send_errors[] = 'Nie znaleziono numeru telefonu na Twoim koncie ani w umowie.';
            }
        }
    }

    if ($sent) {
        flash_set($send_errors ? 'warning' : 'success', 'Link wysłano ' . implode(' i ', $sent) . '.' . ($send_errors ? ' ' . implode(' ', $send_errors) : ''));
    } elseif ($send_errors) {
        flash_set('danger', implode(' ', $send_errors));
    }
    header('Location: ' . APP_URL . '/panel/whatsapp_group.php'); exit;
}

$PAGE_TITLE = 'WhatsApp — grupa';
include __DIR__ . '/includes/header_panel.php';
?>

<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-whatsapp me-2" aria-hidden="true"></i>WhatsApp — grupa</h1>
  <p class="pv-page-sub">Dołącz do grupy organizacji na WhatsApp</p>
</div>

<?= flash_html() ?>

<div class="row g-3">
<div class="<?= $link ? 'col-lg-7' : 'col-12' ?>">
  <div class="vol-detail-card h-100">
    <div class="vol-detail-body">
      <?php if (trim($info) !== ''): ?>
      <div class="mb-3" style="line-height:1.6;white-space:pre-wrap;word-break:break-word"><?= nl2br(h($info)) ?></div>
      <?php else: ?>
      <p class="text-muted mb-3">Brak dodatkowych informacji o grupie.</p>
      <?php endif; ?>

      <div class="alert alert-warning mb-3">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Zasady grupy</div>
        <ul class="mb-0 ps-3">
          <li>Grupa służy tylko do szybkich rozmów i luźnych spraw.</li>
          <li>Zadania ogarniamy w zadaniach w SZO — <a href="https://zadania.feer.org.pl" target="_blank" rel="noopener">zadania.feer.org.pl</a>.</li>
          <li>Nie wysyłamy danych wrażliwych.</li>
        </ul>
      </div>

      <?php if ($link): ?>
      <a href="<?= h($link) ?>" target="_blank" rel="noopener" class="btn btn-success">
        <i class="bi bi-whatsapp me-1" aria-hidden="true"></i>Dołącz do grupy
      </a>

      <form method="post" class="mt-3 pt-3 border-top d-flex flex-wrap align-items-center gap-2">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="send_link">
        <span class="text-muted small me-1">Wyślij mi link:</span>
        <div class="form-check form-check-inline mb-0">
          <input class="form-check-input" type="checkbox" name="via_email" id="waViaEmail" value="1" <?= $user_email ? 'checked' : 'disabled' ?>>
          <label class="form-check-label small" for="waViaEmail">e-mailem<?= $user_email ? '' : ' (brak adresu)' ?></label>
        </div>
        <div class="form-check form-check-inline mb-0">
          <input class="form-check-input" type="checkbox" name="via_sms" id="waViaSms" value="1" <?= $user_phone ? '' : 'disabled' ?>>
          <label class="form-check-label small" for="waViaSms">SMS-em<?= $user_phone ? '' : ' (brak numeru telefonu)' ?></label>
        </div>
        <button type="submit" class="btn btn-outline-secondary btn-sm" <?= (!$user_email && !$user_phone) ? 'disabled' : '' ?>>
          <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij
        </button>
      </form>
      <?php else: ?>
      <div class="alert alert-light border mb-0 small py-2">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Link do grupy nie został jeszcze udostępniony.
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($link): ?>
<div class="col-lg-5">
  <div class="vol-detail-card h-100">
    <div class="vol-detail-header"><i class="bi bi-qr-code me-2" aria-hidden="true"></i>Zeskanuj telefonem</div>
    <div class="vol-detail-body text-center">
      <div id="waGroupQr" class="mb-2 d-flex justify-content-center" role="img" aria-label="Kod QR do dołączenia do grupy WhatsApp"></div>
      <p class="text-muted small mb-0">Zeskanuj kod aparatem telefonu, aby otworzyć zaproszenie bezpośrednio w aplikacji WhatsApp.</p>
    </div>
  </div>
</div>
<?php endif; ?>
</div>

<?php if ($link): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var container = document.getElementById('waGroupQr');
    if (!container) return;
    var url = <?= json_encode($link) ?>;
    try {
        new QRCode(container, { text: url, width: 180, height: 180, colorDark: '#128C7E', colorLight: '#ffffff' });
    } catch (e) {
        container.innerHTML = '<a href="' + url + '" target="_blank" class="btn btn-outline-success btn-sm">' + url + '</a>';
    }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
