<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/whatsapp.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin');
$PAGE_TITLE = 'WhatsApp';

$cfg = [
    'wa_enabled'      => wa_setting('wa_enabled'),
    'wa_smsapi_token' => wa_setting('wa_smsapi_token'),
    'wa_phone_number' => wa_setting('wa_phone_number'),
];

$sms_token = '';
try {
    $r = db_one("SELECT value FROM settings WHERE key_='sms_api_token'");
    $sms_token = $r['value'] ?? '';
} catch (\Throwable $e) {}

$test_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        $token = trim($_POST['wa_smsapi_token'] ?? '');
        if (!$token && !empty($_POST['wa_inherit_sms_token']) && $sms_token) {
            $token = $sms_token;
        }
        $save = [
            'wa_enabled'      => !empty($_POST['wa_enabled']) ? '1' : '0',
            'wa_smsapi_token' => $token ?: $cfg['wa_smsapi_token'],
            'wa_phone_number' => preg_replace('/\D/', '', trim($_POST['wa_phone_number'] ?? '')),
        ];
        foreach ($save as $k => $v) {
            $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$k]);
            if ($exists) {
                db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$v, $k]);
            } else {
                db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$k, $v]);
            }
            $cfg[$k] = $v;
        }
        log_system_action((int)current_user()['id'], 'settings_save', 'Ustawienia WhatsApp zapisane.');
        flash_set('success', 'Ustawienia WhatsApp zapisane.');
        header('Location: whatsapp_settings.php'); exit;
    }

    if ($action === 'test') {
        $phone = trim($_POST['test_phone'] ?? '');
        if (!$phone) {
            $test_result = ['ok' => false, 'msg' => 'Podaj numer telefonu.'];
        } elseif (!$cfg['wa_smsapi_token']) {
            $test_result = ['ok' => false, 'msg' => 'Brak tokenu SMSAPI.pl — najpierw zapisz konfigurację.'];
        } elseif (!$cfg['wa_phone_number']) {
            $test_result = ['ok' => false, 'msg' => 'Brak numeru WhatsApp Business.'];
        } else {
            try {
                wa_send_message($phone, 'Test WhatsApp z Rejestru Umów — ' . ORG_NAME . '. Zignoruj tę wiadomość.');
                $test_result = ['ok' => true, 'msg' => 'Wiadomość WhatsApp wysłana pomyślnie na +' . preg_replace('/\D/', '', $phone)];
            } catch (\Exception $e) {
                $test_result = ['ok' => false, 'msg' => $e->getMessage()];
            }
        }
    }
}

include dirname(__DIR__) . '/includes/header.php';

$wa_ok    = (bool)$cfg['wa_smsapi_token'] && (bool)$cfg['wa_phone_number'];
$chat_url = wa_chat_url();
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-whatsapp text-success"></i> WhatsApp</h4>
  <?php if ($cfg['wa_enabled'] === '1' && $wa_ok): ?>
    <span class="badge bg-success">Aktywny</span>
  <?php elseif ($cfg['wa_enabled'] === '1'): ?>
    <span class="badge bg-warning text-dark">Włączony — wymaga konfiguracji</span>
  <?php else: ?>
    <span class="badge bg-secondary">Wyłączony</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<div class="row g-3">
<div class="col-xl-7">

<form method="post" id="waForm">
<input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
<input type="hidden" name="_action" value="save">

<!-- Aktywacja -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-toggles"></i> Aktywacja</div>
<div class="card-body">
  <div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" role="switch"
           name="wa_enabled" id="wa_enabled" value="1"
           <?= $cfg['wa_enabled'] === '1' ? 'checked' : '' ?>>
    <label class="form-check-label fw-semibold" for="wa_enabled">Moduł WhatsApp aktywny</label>
  </div>
  <div class="form-text">Gdy włączony, admini mogą wysyłać wiadomości WhatsApp do wolontariuszy z poziomu umów.</div>
</div>
</div>

<!-- Token SMSAPI -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <i class="bi bi-key"></i> Token SMSAPI.pl
</div>
<div class="card-body">
  <?php if ($sms_token && !$cfg['wa_smsapi_token']): ?>
  <div class="alert alert-info py-2 small mb-3">
    <i class="bi bi-info-circle"></i>
    Wykryto token SMS z SMSAPI.pl — możesz go odziedzieczyć, jeśli korzystasz z tego samego konta.
  </div>
  <div class="form-check mb-3">
    <input class="form-check-input" type="checkbox" name="wa_inherit_sms_token" id="wa_inherit" value="1">
    <label class="form-check-label small" for="wa_inherit">
      Użyj tego samego tokenu co dla SMS (smsapi.pl)
    </label>
  </div>
  <?php endif; ?>

  <label class="form-label fw-semibold small">Token OAuth <span class="text-danger">*</span></label>
  <input type="password" name="wa_smsapi_token" class="form-control form-control-sm font-monospace"
         placeholder="<?= $cfg['wa_smsapi_token'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'Wklej token OAuth z panelu smsapi.pl' ?>"
         autocomplete="new-password">
  <?php if ($cfg['wa_smsapi_token']): ?>
  <div class="form-text text-success"><i class="bi bi-check-circle"></i> Token zapisany.</div>
  <?php else: ?>
  <div class="form-text">
    Wygeneruj w <a href="https://ssl.smsapi.com/sms_api/api_access" target="_blank">panelu smsapi.pl → API → Tokeny OAuth</a>.
    Ten sam token może działać dla SMS i WhatsApp.
  </div>
  <?php endif; ?>
</div>
</div>

<!-- Numer WhatsApp Business -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-telephone-plus"></i> Numer WhatsApp Business</div>
<div class="card-body">
  <label class="form-label fw-semibold small">Numer telefonu (format E.164) <span class="text-danger">*</span></label>
  <div class="input-group input-group-sm" style="max-width:280px">
    <span class="input-group-text">+</span>
    <input type="text" name="wa_phone_number" class="form-control font-monospace"
           value="<?= h($cfg['wa_phone_number']) ?>" placeholder="48123456789" maxlength="20"
           id="waPhoneInput" oninput="updateQr()">
  </div>
  <div class="form-text">
    Numer zarejestrowany w <a href="https://business.facebook.com/wa/manage/phone-numbers/" target="_blank">Meta Business</a>
    dla WhatsApp Business API. Format: <code>48XXXXXXXXX</code> (bez plusa).
  </div>
</div>
</div>

<div class="d-flex gap-2 mb-3">
  <button type="submit" class="btn btn-success">
    <i class="bi bi-check-lg"></i> Zapisz konfigurację
  </button>
</div>
</form>

<!-- Test wysyłki -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-send"></i> Test wysyłki</div>
<div class="card-body">
  <?php if ($test_result !== null): ?>
  <div class="alert alert-<?= $test_result['ok'] ? 'success' : 'danger' ?> py-2 small mb-3 d-flex align-items-center gap-2">
    <i class="bi bi-<?= $test_result['ok'] ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
    <?= h($test_result['msg']) ?>
  </div>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="test">
    <div class="input-group input-group-sm mb-1">
      <span class="input-group-text fw-semibold text-muted">+48</span>
      <input type="tel" name="test_phone" class="form-control"
             placeholder="123 456 789" required>
      <button type="submit" class="btn btn-outline-success">Wyślij testową WA</button>
    </div>
    <div class="form-text">
      Wysyła wiadomość testową na podany numer przez WhatsApp Business API (SMSAPI.pl).
      Odbiorca musi mieć WhatsApp.
    </div>
  </form>
</div>
</div>

</div><!-- /col-xl-7 -->

<!-- Panel boczny -->
<div class="col-xl-5">

<!-- QR Code -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-qr-code"></i> Kod QR — chat z fundacją</div>
<div class="card-body text-center">
  <?php if ($chat_url): ?>
  <div id="qrContainer" class="mb-3"></div>
  <p class="small text-muted mb-1">Zeskanuj, aby otworzyć chat WhatsApp z fundacją.</p>
  <a href="<?= h($chat_url) ?>" target="_blank" class="btn btn-sm btn-outline-success">
    <i class="bi bi-box-arrow-up-right"></i> <?= h($chat_url) ?>
  </a>
  <?php else: ?>
  <div id="qrContainer" class="mb-2 text-muted small">
    <i class="bi bi-qr-code display-4 text-muted opacity-25"></i><br>
    Podaj numer WhatsApp Business, aby wygenerować kod QR.
  </div>
  <?php endif; ?>
</div>
</div>

<!-- Status -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-shield-check"></i> Status</div>
<div class="card-body small">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted">Moduł</span>
    <span class="badge <?= $cfg['wa_enabled'] === '1' ? 'bg-success' : 'bg-secondary' ?>">
      <?= $cfg['wa_enabled'] === '1' ? 'Włączony' : 'Wyłączony' ?>
    </span>
  </div>
  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted">Token SMSAPI</span>
    <span class="badge <?= $cfg['wa_smsapi_token'] ? 'bg-success' : 'bg-danger' ?>">
      <?= $cfg['wa_smsapi_token'] ? 'Zapisany' : 'Brak' ?>
    </span>
  </div>
  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted">Numer WA Business</span>
    <span class="fw-semibold font-monospace">
      <?= $cfg['wa_phone_number'] ? '+' . h($cfg['wa_phone_number']) : '—' ?>
    </span>
  </div>
  <div class="d-flex justify-content-between align-items-center">
    <span class="text-muted">Gotowość</span>
    <span class="badge <?= $wa_ok ? 'bg-success' : 'bg-warning text-dark' ?>">
      <?= $wa_ok ? 'OK' : 'Wymaga konfiguracji' ?>
    </span>
  </div>
</div>
</div>

<!-- Jak to działa -->
<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> Jak to działa</div>
<div class="card-body small">
  <p class="mb-2">
    Integracja używa <strong>SMSAPI.pl WhatsApp Business API</strong> — tego samego konta co SMS.
  </p>
  <ol class="ps-3 mb-2">
    <li>Zarejestruj numer w <a href="https://smsapi.pl" target="_blank">SMSAPI.pl</a> jako WhatsApp Business</li>
    <li>Wpisz token OAuth (można reużyć tokenu SMS)</li>
    <li>Podaj numer WA Business (format <code>48XXXXXXXXX</code>)</li>
    <li>Z poziomu umowy wolontariackiej kliknij <em>Wyślij na WhatsApp</em></li>
  </ol>
  <div class="alert alert-warning py-2 mb-0 small">
    <i class="bi bi-exclamation-triangle"></i>
    WhatsApp wymaga, by odbiorca najpierw zainicjował kontakt LUB by korzystać z zatwierdzonych <strong>szablonów wiadomości</strong> (templates). Wiadomości tekstowe działają tylko w oknie 24h od ostatniej wiadomości od odbiorcy.
  </div>
</div>
</div>

</div><!-- /col-xl-5 -->
</div><!-- /row -->

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
var _waQrInstance = null;

function buildQr(phone) {
    var container = document.getElementById('qrContainer');
    if (!container) return;
    if (!phone) { container.innerHTML = '<span class="text-muted small"><i class="bi bi-qr-code display-4 opacity-25"></i><br>Podaj numer WA Business.</span>'; return; }
    var url = 'https://wa.me/' + phone.replace(/\D/g, '');
    container.innerHTML = '';
    try {
        _waQrInstance = new QRCode(container, { text: url, width: 180, height: 180, colorDark: '#128C7E', colorLight: '#ffffff' });
    } catch(e) { container.innerHTML = '<a href="' + url + '" target="_blank" class="btn btn-outline-success btn-sm">' + url + '</a>'; }
}

function updateQr() {
    var val = document.getElementById('waPhoneInput');
    if (val) buildQr(val.value);
}

document.addEventListener('DOMContentLoaded', function() {
    var phone = '<?= h($cfg['wa_phone_number']) ?>';
    if (phone) buildQr(phone);
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
