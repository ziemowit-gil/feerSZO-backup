<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin');
$PAGE_TITLE = 'Ustawienia SMS';

// ── Załaduj bieżącą konfigurację ──────────────────────────────────────────────
$cfg = [
    'sms_enabled'      => sms_setting('sms_enabled'),
    'sms_provider'     => sms_provider(),           // 'smsapi', 'twilio' lub 'httprequest'
    // smsapi.pl
    'sms_api_token'    => sms_setting('sms_api_token'),
    'sms_sender_name'  => sms_setting('sms_sender_name'),
    // Twilio
    'sms_twilio_sid'   => sms_setting('sms_twilio_sid'),
    'sms_twilio_token' => sms_setting('sms_twilio_token'),
    'sms_twilio_from'  => sms_setting('sms_twilio_from'),
    // HTTP Request (webhook)
    'sms_http_url'     => sms_setting('sms_http_url'),
    'sms_http_method'  => sms_setting('sms_http_method') ?: 'GET',
];

$test_result = null;

// ── Obsługa POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        $provider = in_array($_POST['sms_provider'] ?? '', ['smsapi','twilio','httprequest'], true)
                    ? $_POST['sms_provider'] : 'smsapi';

        $save = [
            'sms_enabled'  => !empty($_POST['sms_enabled']) ? '1' : '0',
            'sms_provider' => $provider,
        ];

        // smsapi fields (keep existing if left blank)
        $api_token = trim($_POST['sms_api_token'] ?? '');
        $save['sms_api_token']   = $api_token ?: $cfg['sms_api_token'];
        $save['sms_sender_name'] = substr(trim($_POST['sms_sender_name'] ?? 'INFO'), 0, 11);

        // Twilio fields (keep existing if left blank)
        $twilio_token = trim($_POST['sms_twilio_token'] ?? '');
        $save['sms_twilio_sid']   = trim($_POST['sms_twilio_sid']   ?? '') ?: $cfg['sms_twilio_sid'];
        $save['sms_twilio_token'] = $twilio_token                           ?: $cfg['sms_twilio_token'];
        $save['sms_twilio_from']  = trim($_POST['sms_twilio_from']  ?? '') ?: $cfg['sms_twilio_from'];

        // HTTP Request fields
        $save['sms_http_url']    = trim($_POST['sms_http_url']    ?? '') ?: $cfg['sms_http_url'];
        $save['sms_http_method'] = in_array($_POST['sms_http_method'] ?? '', ['GET','POST'], true)
                                   ? $_POST['sms_http_method'] : 'GET';

        foreach ($save as $k => $v) {
            $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$k]);
            if ($exists) {
                db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$v, $k]);
            } else {
                db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$k, $v]);
            }
        }
        log_system_action((int)current_user()['id'], 'settings_save',
            'Ustawienia SMS — dostawca: ' . $provider);
        flash_set('success', 'Ustawienia SMS zapisane.');
        header('Location: sms_settings.php'); exit;
    }

    if ($action === 'test') {
        $phone    = trim($_POST['test_phone'] ?? '');
        $provider = $cfg['sms_provider'];

        if (!$phone) {
            $test_result = ['ok' => false, 'msg' => 'Podaj numer telefonu do testu.'];
        } elseif ($provider === 'smsapi' && !$cfg['sms_api_token']) {
            $test_result = ['ok' => false, 'msg' => 'Brak tokenu smsapi.pl — najpierw zapisz konfigurację.'];
        } elseif ($provider === 'twilio' && (!$cfg['sms_twilio_sid'] || !$cfg['sms_twilio_token'] || !$cfg['sms_twilio_from'])) {
            $test_result = ['ok' => false, 'msg' => 'Niekompletna konfiguracja Twilio — najpierw zapisz wszystkie pola.'];
        } elseif ($provider === 'httprequest' && !$cfg['sms_http_url']) {
            $test_result = ['ok' => false, 'msg' => 'Brak URL dla HTTP Request — najpierw zapisz konfigurację.'];
        } else {
            try {
                sms_send($phone, 'Test SMS z Rejestru Umów — ' . ORG_NAME . '. Zignoruj tę wiadomość.');
                $test_result = ['ok' => true, 'msg' => 'SMS wysłany pomyślnie na ' . $phone . ' przez ' . strtoupper($provider) . '.'];
            } catch (\Exception $e) {
                $test_result = ['ok' => false, 'msg' => $e->getMessage()];
            }
        }
    }
}

include dirname(__DIR__) . '/includes/header.php';

// Pomocnik: czy dana konfiguracja jest kompletna
$smsapi_ok = (bool)$cfg['sms_api_token'];
$twilio_ok = $cfg['sms_twilio_sid'] && $cfg['sms_twilio_token'] && $cfg['sms_twilio_from'];
$http_ok   = (bool)$cfg['sms_http_url'];
$active_ok = match($cfg['sms_provider']) {
    'twilio'      => $twilio_ok,
    'httprequest' => $http_ok,
    default       => $smsapi_ok,
};
$provider_label = match($cfg['sms_provider']) {
    'twilio'      => 'Twilio',
    'httprequest' => 'HTTP Request',
    default       => 'smsapi.pl',
};
?>

<style>
.provider-card {
    border: 2px solid #e2e8f0;
    border-radius: .6rem;
    padding: 1rem 1.1rem;
    cursor: pointer;
    transition: border-color .15s, background .15s;
}
.provider-card:has(input:checked) {
    border-color: #2563eb;
    background: #eff6ff;
}
.provider-card label { cursor: pointer; }
.provider-logo { width: 36px; height: 36px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
.logo-smsapi  { background: #e0f2fe; color: #0369a1; }
.logo-twilio  { background: #fce7f3; color: #be185d; }
.logo-http    { background: #f0fdf4; color: #15803d; }
</style>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-phone text-primary"></i> Ustawienia SMS</h4>
  <?php if ($cfg['sms_enabled'] === '1' && $active_ok): ?>
    <span class="badge bg-success">Aktywne — <?= $provider_label ?></span>
  <?php elseif ($cfg['sms_enabled'] === '1'): ?>
    <span class="badge bg-warning text-dark">Włączone — wymaga konfiguracji</span>
  <?php else: ?>
    <span class="badge bg-secondary">Wyłączone</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<div class="row g-3">
<div class="col-xl-7">

<form method="post" id="smsForm">
<input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
<input type="hidden" name="_action" value="save">

<!-- ── Aktywacja ──────────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-toggles"></i> Aktywacja</div>
<div class="card-body">
  <div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" role="switch"
           name="sms_enabled" id="sms_enabled" value="1"
           <?= $cfg['sms_enabled'] === '1' ? 'checked' : '' ?>>
    <label class="form-check-label fw-semibold" for="sms_enabled">
      Logowanie SMS aktywne
    </label>
  </div>
  <div class="form-text">Gdy włączone, na stronie logowania pojawi się zakładka <em>Kod SMS</em> dla wolontariuszy.</div>
</div>
</div>

<!-- ── Wybór dostawcy ─────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-diagram-3"></i> Dostawca SMS</div>
<div class="card-body">
  <div class="row g-2">

    <div class="col-md-4">
      <div class="provider-card">
        <input type="radio" name="sms_provider" id="prov_smsapi" value="smsapi"
               <?= $cfg['sms_provider'] === 'smsapi' ? 'checked' : '' ?>
               onchange="switchProvider('smsapi')" class="d-none">
        <label for="prov_smsapi" class="d-flex align-items-start gap-3 w-100 mb-0">
          <div class="provider-logo logo-smsapi"><i class="bi bi-chat-dots"></i></div>
          <div>
            <div class="fw-semibold">smsapi.pl</div>
            <div class="small text-muted">Polski dostawca, token OAuth.</div>
            <?php if ($smsapi_ok): ?>
            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle mt-1">
              <i class="bi bi-check-circle"></i> Skonfigurowany
            </span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary-emphasis border mt-1">Nie skonfigurowany</span>
            <?php endif; ?>
          </div>
        </label>
      </div>
    </div>

    <div class="col-md-4">
      <div class="provider-card">
        <input type="radio" name="sms_provider" id="prov_twilio" value="twilio"
               <?= $cfg['sms_provider'] === 'twilio' ? 'checked' : '' ?>
               onchange="switchProvider('twilio')" class="d-none">
        <label for="prov_twilio" class="d-flex align-items-start gap-3 w-100 mb-0">
          <div class="provider-logo logo-twilio"><i class="bi bi-telephone"></i></div>
          <div>
            <div class="fw-semibold">Twilio</div>
            <div class="small text-muted">Globalny dostawca, numery PL i zagraniczne.</div>
            <?php if ($twilio_ok): ?>
            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle mt-1">
              <i class="bi bi-check-circle"></i> Skonfigurowany
            </span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary-emphasis border mt-1">Nie skonfigurowany</span>
            <?php endif; ?>
          </div>
        </label>
      </div>
    </div>

    <div class="col-md-4">
      <div class="provider-card">
        <input type="radio" name="sms_provider" id="prov_http" value="httprequest"
               <?= $cfg['sms_provider'] === 'httprequest' ? 'checked' : '' ?>
               onchange="switchProvider('httprequest')" class="d-none">
        <label for="prov_http" class="d-flex align-items-start gap-3 w-100 mb-0">
          <div class="provider-logo logo-http"><i class="bi bi-globe2"></i></div>
          <div>
            <div class="fw-semibold">HTTP Request</div>
            <div class="small text-muted">Własny webhook GET/POST z URL.</div>
            <?php if ($http_ok): ?>
            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle mt-1">
              <i class="bi bi-check-circle"></i> Skonfigurowany
            </span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary-emphasis border mt-1">Nie skonfigurowany</span>
            <?php endif; ?>
          </div>
        </label>
      </div>
    </div>

  </div>
</div>
</div>

<!-- ── Konfiguracja smsapi.pl ─────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3" id="section-smsapi"
     <?= $cfg['sms_provider'] !== 'smsapi' ? 'style="display:none"' : '' ?>>
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <div class="provider-logo logo-smsapi" style="width:24px;height:24px;font-size:.85rem;border-radius:4px">
    <i class="bi bi-chat-dots"></i>
  </div>
  Konfiguracja smsapi.pl
</div>
<div class="card-body">
  <div class="mb-3">
    <label class="form-label fw-semibold small">Token OAuth <span class="text-danger">*</span></label>
    <input type="password" name="sms_api_token" class="form-control form-control-sm font-monospace"
           placeholder="<?= $cfg['sms_api_token'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'Wklej token OAuth z panelu smsapi.pl' ?>"
           autocomplete="new-password">
    <?php if ($cfg['sms_api_token']): ?>
    <div class="form-text text-success"><i class="bi bi-check-circle"></i> Token zapisany.</div>
    <?php else: ?>
    <div class="form-text">
      Wygeneruj w <a href="https://ssl.smsapi.com/sms_api/api_access" target="_blank">panelu smsapi.pl → API → Tokeny OAuth</a>.
      Uwaga: użyj tokenu OAuth, nie hasła API.
    </div>
    <?php endif; ?>
  </div>
  <div class="mb-0">
    <label class="form-label fw-semibold small">
      Nazwa nadawcy
      <span class="text-muted fw-normal">(maks. 11 znaków ASCII)</span>
    </label>
    <input type="text" name="sms_sender_name" class="form-control form-control-sm"
           value="<?= h($cfg['sms_sender_name']) ?>"
           maxlength="11" placeholder="INFO">
    <div class="form-text">Pojawi się zamiast numeru telefonu u odbiorcy wiadomości.</div>
  </div>
</div>
</div>

<!-- ── Konfiguracja Twilio ────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3" id="section-twilio"
     <?= $cfg['sms_provider'] !== 'twilio' ? 'style="display:none"' : '' ?>>
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <div class="provider-logo logo-twilio" style="width:24px;height:24px;font-size:.85rem;border-radius:4px">
    <i class="bi bi-telephone"></i>
  </div>
  Konfiguracja Twilio
</div>
<div class="card-body">
  <div class="alert alert-light border small p-2 mb-3">
    Dane znajdziesz na
    <a href="https://console.twilio.com" target="_blank">console.twilio.com</a>
    → główna strona projektu (Account Info).
  </div>

  <div class="row g-3">
    <div class="col-12">
      <label class="form-label fw-semibold small">Account SID <span class="text-danger">*</span></label>
      <input type="text" name="sms_twilio_sid" class="form-control form-control-sm font-monospace"
             value="<?= h($cfg['sms_twilio_sid']) ?>"
             placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
      <div class="form-text">Zaczyna się od <code>AC</code>, widoczny w konsoli Twilio.</div>
    </div>

    <div class="col-12">
      <label class="form-label fw-semibold small">Auth Token <span class="text-danger">*</span></label>
      <input type="password" name="sms_twilio_token" class="form-control form-control-sm font-monospace"
             placeholder="<?= $cfg['sms_twilio_token'] ? '(zapisany — zostaw puste by nie zmieniać)' : 'Auth Token z konsoli Twilio' ?>"
             autocomplete="new-password">
      <?php if ($cfg['sms_twilio_token']): ?>
      <div class="form-text text-success"><i class="bi bi-check-circle"></i> Auth Token zapisany.</div>
      <?php endif; ?>
    </div>

    <div class="col-12">
      <label class="form-label fw-semibold small">Numer nadawcy (From) <span class="text-danger">*</span></label>
      <input type="text" name="sms_twilio_from" class="form-control form-control-sm font-monospace"
             value="<?= h($cfg['sms_twilio_from']) ?>"
             placeholder="+48100200300">
      <div class="form-text">
        Numer zakupiony w Twilio lub zweryfikowany numer nadawcy — format E.164 (<code>+48XXXXXXXXX</code>).
        Sprawdź w <a href="https://console.twilio.com/us1/develop/phone-numbers/manage/incoming" target="_blank">Phone Numbers → Active numbers</a>.
      </div>
    </div>
  </div>
</div>
</div>

<!-- ── Konfiguracja HTTP Request ──────────────────────────────────────────── -->
<div class="card shadow-sm mb-3" id="section-httprequest"
     <?= $cfg['sms_provider'] !== 'httprequest' ? 'style="display:none"' : '' ?>>
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <div class="provider-logo logo-http" style="width:24px;height:24px;font-size:.85rem;border-radius:4px">
    <i class="bi bi-globe2"></i>
  </div>
  Konfiguracja HTTP Request (Webhook)
</div>
<div class="card-body">
  <div class="alert alert-light border small p-2 mb-3">
    Podaj adres URL do wywołania przy wysyłce SMS. Użyj placeholderów
    <code>{phone}</code> (numer telefonu, format <code>48XXXXXXXXX</code>) i
    <code>{message}</code> (treść SMS, urlencoded).<br>
    Przykład: <code>https://sms.brama.pl/send?token=ABC&amp;to={phone}&amp;text={message}</code>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small">URL szablonu <span class="text-danger">*</span></label>
    <input type="text" name="sms_http_url" class="form-control form-control-sm font-monospace"
           value="<?= h($cfg['sms_http_url']) ?>"
           placeholder="https://twojabrama.pl/api/send?key=XYZ&to={phone}&msg={message}">
    <?php if ($cfg['sms_http_url']): ?>
    <div class="form-text text-success"><i class="bi bi-check-circle"></i> URL zapisany.</div>
    <?php else: ?>
    <div class="form-text">Wklej URL swojego dostawcy SMS. Placeholdery zostaną zamienione automatycznie.</div>
    <?php endif; ?>
  </div>

  <div class="mb-0">
    <label class="form-label fw-semibold small">Metoda HTTP</label>
    <select name="sms_http_method" class="form-select form-select-sm" style="max-width:140px">
      <option value="GET"  <?= $cfg['sms_http_method'] === 'GET'  ? 'selected' : '' ?>>GET</option>
      <option value="POST" <?= $cfg['sms_http_method'] === 'POST' ? 'selected' : '' ?>>POST</option>
    </select>
    <div class="form-text">
      <strong>GET</strong>: parametry w URL (domyślnie). <strong>POST</strong>: system wyśle też <code>phone</code>, <code>message</code>, <code>text</code> w ciele żądania (form-urlencoded), URL nadal może zawierać placeholdery.
    </div>
  </div>
</div>
</div>

<!-- ── Zapisz ─────────────────────────────────────────────────────────────── -->
<div class="d-flex gap-2 mb-3">
  <button type="submit" class="btn btn-primary">
    <i class="bi bi-check-lg"></i> Zapisz konfigurację
  </button>
</div>

</form>

<!-- ── Test wysyłki ───────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-send"></i> Test wysyłki SMS</div>
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
      <input type="tel" name="test_phone" class="form-control phone-48"
             placeholder="123 456 789" required>
      <button type="submit" class="btn btn-outline-primary">Wyślij testowy SMS</button>
    </div>
    <div class="form-text">
      Używa aktualnie <strong>zapisanego</strong> dostawcy
      (<strong><?= $provider_label ?></strong>).
      Zapisz konfigurację przed testem.
    </div>
  </form>
</div>
</div>

</div><!-- /col-xl-7 -->

<!-- ── Panel informacyjny ──────────────────────────────────────────────────── -->
<div class="col-xl-5">

<!-- Status -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-shield-check"></i> Status</div>
<div class="card-body small">

  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted">Logowanie SMS</span>
    <span class="badge <?= $cfg['sms_enabled']==='1' ? 'bg-success' : 'bg-secondary' ?>">
      <?= $cfg['sms_enabled']==='1' ? 'Włączone' : 'Wyłączone' ?>
    </span>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted">Aktywny dostawca</span>
    <span class="fw-semibold"><?= $provider_label ?></span>
  </div>

  <hr class="my-2">

  <!-- smsapi status -->
  <div class="d-flex justify-content-between align-items-center mb-1">
    <span class="text-muted">smsapi.pl — token</span>
    <span class="badge <?= $smsapi_ok ? 'bg-success' : 'bg-danger' ?>">
      <?= $smsapi_ok ? 'OK' : 'Brak' ?>
    </span>
  </div>
  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted">smsapi.pl — nadawca</span>
    <span class="fw-semibold"><?= h($cfg['sms_sender_name'] ?: '—') ?></span>
  </div>

  <hr class="my-2">

  <!-- Twilio status -->
  <div class="d-flex justify-content-between align-items-center mb-1">
    <span class="text-muted">Twilio — Account SID</span>
    <span class="badge <?= $cfg['sms_twilio_sid'] ? 'bg-success' : 'bg-secondary' ?>">
      <?= $cfg['sms_twilio_sid'] ? h(substr($cfg['sms_twilio_sid'], 0, 8)) . '…' : 'Brak' ?>
    </span>
  </div>
  <div class="d-flex justify-content-between align-items-center mb-1">
    <span class="text-muted">Twilio — Auth Token</span>
    <span class="badge <?= $cfg['sms_twilio_token'] ? 'bg-success' : 'bg-secondary' ?>">
      <?= $cfg['sms_twilio_token'] ? 'Zapisany' : 'Brak' ?>
    </span>
  </div>
  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted">Twilio — numer nadawcy</span>
    <span class="fw-semibold font-monospace"><?= h($cfg['sms_twilio_from'] ?: '—') ?></span>
  </div>

  <hr class="my-2">

  <!-- HTTP Request status -->
  <div class="d-flex justify-content-between align-items-center mb-1">
    <span class="text-muted">HTTP — URL</span>
    <span class="badge <?= $http_ok ? 'bg-success' : 'bg-secondary' ?>">
      <?= $http_ok ? 'Ustawiony' : 'Brak' ?>
    </span>
  </div>
  <div class="d-flex justify-content-between align-items-center">
    <span class="text-muted">HTTP — metoda</span>
    <span class="fw-semibold"><?= h($cfg['sms_http_method'] ?: 'GET') ?></span>
  </div>

</div>
</div>

<!-- Jak to działa -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> Jak to działa</div>
<div class="card-body small">
  <p class="mb-2">Wolontariusz wpisuje numer telefonu → system wysyła 6-cyfrowy kod → wolontariusz wpisuje kod i jest zalogowany.</p>
  <p class="mb-1 fw-semibold">Wymagania:</p>
  <ul class="ps-3 mb-0">
    <li>Numer telefonu w umowie wolontariackiej</li>
    <li>Konto lokalne z tym samym e-mailem co w umowie</li>
    <li>Skonfigurowany jeden z dostawców SMS poniżej</li>
  </ul>
</div>
</div>

<!-- Porównanie dostawców -->
<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-table"></i> Porównanie dostawców</div>
<div class="card-body p-0">
<table class="table table-sm mb-0 small">
  <thead class="table-light">
    <tr><th></th><th class="text-center">smsapi.pl</th><th class="text-center">Twilio</th><th class="text-center">HTTP</th></tr>
  </thead>
  <tbody>
    <tr><td class="text-muted">Zasięg</td>
        <td class="text-center">Polska</td>
        <td class="text-center">Globalny</td>
        <td class="text-center">Własny</td></tr>
    <tr><td class="text-muted">Cena (SMS do PL)</td>
        <td class="text-center">~0,07 zł</td>
        <td class="text-center">~0,05 $</td>
        <td class="text-center">wg dostawcy</td></tr>
    <tr><td class="text-muted">Nazwa nadawcy</td>
        <td class="text-center"><i class="bi bi-check text-success"></i></td>
        <td class="text-center"><i class="bi bi-x text-muted"></i> nr tel</td>
        <td class="text-center">zależy</td></tr>
    <tr><td class="text-muted">Trial (darmowy)</td>
        <td class="text-center"><i class="bi bi-x text-muted"></i></td>
        <td class="text-center"><i class="bi bi-check text-success"></i></td>
        <td class="text-center">zależy</td></tr>
    <tr><td class="text-muted">Konfiguracja</td>
        <td class="text-center">1 token</td>
        <td class="text-center">SID + token + numer</td>
        <td class="text-center">URL szablonu</td></tr>
  </tbody>
</table>
</div>
</div>

</div><!-- /col-xl-5 -->
</div><!-- /row -->

<script>
function switchProvider(name) {
    document.getElementById('section-smsapi').style.display       = name === 'smsapi'       ? '' : 'none';
    document.getElementById('section-twilio').style.display       = name === 'twilio'       ? '' : 'none';
    document.getElementById('section-httprequest').style.display  = name === 'httprequest'  ? '' : 'none';
}
// Init on load (handles browser back-fill of radio)
document.addEventListener('DOMContentLoaded', function() {
    const checked = document.querySelector('[name="sms_provider"]:checked');
    if (checked) switchProvider(checked.value);
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
