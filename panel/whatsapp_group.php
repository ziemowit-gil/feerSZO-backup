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

<div class="pv-wrap">

  <div class="pv-page-header">
    <div>
      <a href="<?= APP_URL ?>/panel/" class="pv-page-back">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>Panel
      </a>
      <h1 class="pv-page-title">
        <i class="bi bi-whatsapp" aria-hidden="true"></i>WhatsApp — grupa
      </h1>
      <p class="pv-page-sub">Dołącz do grupy organizacji na WhatsApp</p>
    </div>
  </div>

  <?= flash_html() ?>

  <?php if (!$link): ?>

    <div class="pv-empty" role="status" aria-live="polite">
      <i class="bi bi-whatsapp" aria-hidden="true"></i>
      <div class="pv-empty-title">Link do grupy nie został jeszcze udostępniony</div>
      <p class="pv-empty-sub">Administrator jeszcze nie dodał zaproszenia do grupy WhatsApp.</p>
    </div>

    <div class="tz-note" role="note" aria-label="Zasady grupy WhatsApp">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <div>
        <strong>Zasady grupy</strong>
        <ul class="mb-0 mt-1 ps-3">
          <li>Grupa służy tylko do szybkich rozmów i luźnych spraw.</li>
          <li>Zadania ogarniamy w zadaniach w SZO — <a href="https://zadania.feer.org.pl" target="_blank" rel="noopener">zadania.feer.org.pl</a>.</li>
          <li>Nie wysyłamy danych wrażliwych.</li>
        </ul>
      </div>
    </div>

  <?php else: ?>

    <div class="row g-3 mb-3">

      <div class="col-lg-7">
        <div class="tz-card h-100">
          <div class="tz-card__hd">
            <i class="bi bi-whatsapp" aria-hidden="true"></i>Informacje o grupie
          </div>
          <div class="tz-card__bd">

            <?php if (trim($info) !== ''): ?>
            <p class="mb-3" style="line-height:1.6;white-space:pre-wrap;word-break:break-word"><?= nl2br(h($info)) ?></p>
            <?php endif; ?>

            <div class="tz-note mb-3" role="note" aria-label="Zasady grupy WhatsApp">
              <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
              <div>
                <strong>Zasady grupy</strong>
                <ul class="mb-0 mt-1 ps-3">
                  <li>Grupa służy tylko do szybkich rozmów i luźnych spraw.</li>
                  <li>Zadania ogarniamy w zadaniach w SZO — <a href="https://zadania.feer.org.pl" target="_blank" rel="noopener">zadania.feer.org.pl</a>.</li>
                  <li>Nie wysyłamy danych wrażliwych.</li>
                </ul>
              </div>
            </div>

            <a href="<?= h($link) ?>" target="_blank" rel="noopener"
               class="tz-btn"
               aria-label="Dołącz do grupy WhatsApp organizacji (otwiera aplikację WhatsApp)">
              <i class="bi bi-whatsapp" aria-hidden="true"></i>Dołącz do grupy
            </a>

          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="tz-card h-100">
          <div class="tz-card__hd">
            <i class="bi bi-qr-code" aria-hidden="true"></i>Zeskanuj telefonem
          </div>
          <div class="tz-card__bd text-center">
            <div id="waGroupQr" class="mb-2 d-flex justify-content-center"
                 role="img"
                 aria-label="Kod QR do dołączenia do grupy WhatsApp"></div>
            <p class="mb-0 small" style="color:var(--tz-muted)">Zeskanuj kod aparatem telefonu, aby otworzyć zaproszenie bezpośrednio w aplikacji WhatsApp.</p>
          </div>
        </div>
      </div>

    </div>

    <div class="tz-card">
      <div class="tz-card__hd">
        <i class="bi bi-send" aria-hidden="true"></i>Wyślij mi link
      </div>
      <div class="tz-card__bd">
        <form method="post" aria-label="Formularz wysyłki linku do grupy WhatsApp">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="send_link">
          <p class="mb-3 small" style="color:var(--tz-muted)">Wyślij sobie link do grupy, żeby mieć go pod ręką na telefonie.</p>
          <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <div class="form-check mb-0">
              <input class="form-check-input" type="checkbox"
                     name="via_email" id="waViaEmail" value="1"
                     <?= $user_email ? 'checked' : 'disabled' ?>
                     aria-describedby="waViaEmailHint">
              <label class="form-check-label" for="waViaEmail">
                <i class="bi bi-envelope me-1" aria-hidden="true"></i>e-mailem
              </label>
              <?php if (!$user_email): ?>
              <span id="waViaEmailHint" class="tz-badge tz-badge--off ms-1">brak adresu</span>
              <?php endif; ?>
            </div>
            <div class="form-check mb-0">
              <input class="form-check-input" type="checkbox"
                     name="via_sms" id="waViaSms" value="1"
                     <?= $user_phone ? '' : 'disabled' ?>
                     aria-describedby="waViaSmsHint">
              <label class="form-check-label" for="waViaSms">
                <i class="bi bi-phone me-1" aria-hidden="true"></i>SMS-em
              </label>
              <?php if (!$user_phone): ?>
              <span id="waViaSmsHint" class="tz-badge tz-badge--off ms-1">brak numeru</span>
              <?php endif; ?>
            </div>
          </div>
          <button type="submit"
                  class="tz-btn tz-btn--ghost"
                  <?= (!$user_email && !$user_phone) ? 'disabled aria-disabled="true"' : '' ?>>
            <i class="bi bi-send" aria-hidden="true"></i>Wyślij
          </button>
        </form>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var container = document.getElementById('waGroupQr');
        if (!container) return;
        var url = <?= json_encode($link) ?>;
        try {
            new QRCode(container, { text: url, width: 180, height: 180, colorDark: '#128C7E', colorLight: '#ffffff' });
        } catch (e) {
            container.innerHTML = '<a href="' + url + '" target="_blank" rel="noopener" class="tz-btn tz-btn--ghost">' + url + '</a>';
        }
    });
    </script>

  <?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
