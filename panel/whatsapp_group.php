<?php
/**
 * panel/whatsapp_group.php — Podstrona "WhatsApp — grupa" w panelu współpracownika.
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
                    sms_send($user_phone, "Link do grupy WhatsApp FEER: {$link}\n\n{$rules_text}", 'whatsapp_group');
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

<?php
require_once dirname(__DIR__) . '/modules/panel_metro/lib/panelMetro.php';
panelMetroAssets($_vol_color ?? '#0f6fa8');
?>
<style>
.pvm .wa-cols{display:grid;gap:1.25rem;grid-template-columns:1fr;align-items:start}
@media(min-width:992px){.pvm .wa-cols{grid-template-columns:minmax(0,7fr) minmax(0,5fr)}}
.pvm .wa-join{display:flex;align-items:center;gap:1rem;background:#075e54;color:#fff;padding:1.25rem 1.4rem;text-decoration:none;margin-bottom:8px}
.pvm .wa-join:hover{filter:brightness(1.12);color:#fff}
.pvm .wa-join .ic{font-size:2.6rem;line-height:1}
.pvm .wa-join b{display:block;font-size:1.35rem;font-weight:600}
.pvm .wa-join span{font-size:.85rem;opacity:.9}
.pvm .wa-qr{background:#fff;display:inline-flex;padding:12px;margin-bottom:.75rem}
</style>

<div class="pvm">

  <div class="mt-head">
    <div>
      <div class="mt-kicker">Panel współpracownika · Komunikacja</div>
      <h1 class="mt-title">WhatsApp — grupa</h1>
      <p class="mt-lead">Dołącz do grupy organizacji na WhatsApp.</p>
    </div>
    <a href="<?= APP_URL ?>/panel/" class="mt-btn mt-btn--ghost"><i class="bi bi-arrow-left" aria-hidden="true"></i>Pulpit</a>
  </div>

  <?= flash_html() ?>

  <?php if (!$link): ?>

    <div class="mt-empty mb-3" role="status" aria-live="polite">
      <i class="bi bi-whatsapp" style="font-size:2rem;display:block;margin-bottom:.5rem" aria-hidden="true"></i>
      <p class="fw-semibold mb-1" style="color:var(--pvm-ink)">Link do grupy nie został jeszcze udostępniony</p>
      <p class="small mb-0">Administrator jeszcze nie dodał zaproszenia do grupy WhatsApp.</p>
    </div>
    <div class="mt-svc" style="--c:var(--m-orange)" role="note" aria-label="Zasady grupy WhatsApp">
      <i class="bi bi-exclamation-triangle ic" aria-hidden="true"></i>
      <div class="mt-svc__bd">
        <h3>Zasady grupy</h3>
        <ul class="mb-0 mt-1 ps-3" style="font-size:.88rem">
          <li>Grupa służy tylko do szybkich rozmów i luźnych spraw.</li>
          <li>Zadania ogarniamy w zadaniach w SZO — <a href="https://zadania.feer.org.pl" target="_blank" rel="noopener" style="color:inherit">zadania.feer.org.pl</a>.</li>
          <li>Nie wysyłamy danych wrażliwych.</li>
        </ul>
      </div>
    </div>

  <?php else: ?>

    <div class="wa-cols">
      <div>
        <a href="<?= h($link) ?>" target="_blank" rel="noopener" class="wa-join"
           aria-label="Dołącz do grupy WhatsApp organizacji (otwiera aplikację WhatsApp)">
          <i class="bi bi-whatsapp ic" aria-hidden="true"></i>
          <span><b>Dołącz do grupy</b>otwiera zaproszenie w aplikacji WhatsApp</span>
        </a>

        <?php if (trim($info) !== ''): ?>
        <div class="mt-group" style="margin-bottom:8px">
          <h2 class="mt-h2">Informacje o <b>grupie</b></h2>
          <p class="mb-0" style="line-height:1.6;white-space:pre-wrap;word-break:break-word"><?= nl2br(h($info)) ?></p>
        </div>
        <?php endif; ?>

        <div class="mt-svc" style="--c:var(--m-orange)" role="note" aria-label="Zasady grupy WhatsApp">
      <i class="bi bi-exclamation-triangle ic" aria-hidden="true"></i>
      <div class="mt-svc__bd">
        <h3>Zasady grupy</h3>
        <ul class="mb-0 mt-1 ps-3" style="font-size:.88rem">
          <li>Grupa służy tylko do szybkich rozmów i luźnych spraw.</li>
          <li>Zadania ogarniamy w zadaniach w SZO — <a href="https://zadania.feer.org.pl" target="_blank" rel="noopener" style="color:inherit">zadania.feer.org.pl</a>.</li>
          <li>Nie wysyłamy danych wrażliwych.</li>
        </ul>
      </div>
    </div>
      </div>

      <div>
        <div class="mt-group text-center">
          <h2 class="mt-h2">Zeskanuj <b>telefonem</b></h2>
          <div class="wa-qr"><div id="waGroupQr" role="img" aria-label="Kod QR do dołączenia do grupy WhatsApp"></div></div>
          <p class="mb-0 small mt-no">Zeskanuj kod aparatem telefonu, aby otworzyć zaproszenie bezpośrednio w aplikacji WhatsApp.</p>
        </div>

        <div class="mt-group mt-2" style="margin-top:8px">
          <h2 class="mt-h2">Wyślij mi <b>link</b></h2>
          <form method="post" aria-label="Formularz wysyłki linku do grupy WhatsApp">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="send_link">
            <p class="mb-3 small mt-no">Wyślij sobie link do grupy, żeby mieć go pod ręką na telefonie.</p>
            <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
              <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="via_email" id="waViaEmail" value="1"
                       <?= $user_email ? 'checked' : 'disabled' ?> aria-describedby="waViaEmailHint">
                <label class="form-check-label" for="waViaEmail"><i class="bi bi-envelope me-1" aria-hidden="true"></i>e-mailem</label>
                <?php if (!$user_email): ?><span id="waViaEmailHint" class="mt-badge ms-1">brak adresu</span><?php endif; ?>
              </div>
              <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="via_sms" id="waViaSms" value="1"
                       <?= $user_phone ? '' : 'disabled' ?> aria-describedby="waViaSmsHint">
                <label class="form-check-label" for="waViaSms"><i class="bi bi-phone me-1" aria-hidden="true"></i>SMS-em</label>
                <?php if (!$user_phone): ?><span id="waViaSmsHint" class="mt-badge ms-1">brak numeru</span><?php endif; ?>
              </div>
            </div>
            <button type="submit" class="mt-btn" <?= (!$user_email && !$user_phone) ? 'disabled aria-disabled="true"' : '' ?>>
              <i class="bi bi-send" aria-hidden="true"></i>Wyślij
            </button>
          </form>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var container = document.getElementById('waGroupQr');
        if (!container) return;
        var url = <?= json_encode($link) ?>;
        try {
            new QRCode(container, { text: url, width: 180, height: 180, colorDark: '#075e54', colorLight: '#ffffff' });
        } catch (e) {
            container.innerHTML = '<a href="' + url + '" target="_blank" rel="noopener" class="mt-btn mt-btn--ghost">' + url + '</a>';
        }
    });
    </script>

  <?php endif; ?>

</div><!-- /.pvm -->

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
