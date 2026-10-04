<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/modules/sms_senders/logic/smsSenders.php';

require_role('admin');
$PAGE_TITLE = 'Ustawienia SMS';

// ── Załaduj bieżącą konfigurację ──────────────────────────────────────────────
$cfg = [
    'sms_enabled'      => sms_setting('sms_enabled'),
    'sms_provider'     => sms_provider(),           // klucz z sms_providers()
    'sms_fallback_provider' => sms_provider_fallback(), // '' = brak, lub jw.
    // smsapi.pl
    'sms_api_login'    => sms_setting('sms_api_login'),
    'sms_api_password' => sms_setting('sms_api_password'),
    'sms_sender_name'  => sms_setting('sms_sender_name'),
    // SerwerSMS.pl (token i hasło to sekrety — w formularzu nie wracają, zostają zapisane, gdy pole jest puste)
    'sms_serwersms_login'    => sms_setting('sms_serwersms_login'),
    'sms_serwersms_password' => sms_setting('sms_serwersms_password'),
    'sms_serwersms_sender'   => sms_setting('sms_serwersms_sender'),
    'sms_serwersms_channel'  => sms_serwersms_channel(),
    'sms_serwersms_api_base' => sms_setting('sms_serwersms_api_base'),
    // Twilio
    'sms_twilio_sid'   => sms_setting('sms_twilio_sid'),
    'sms_twilio_token' => sms_setting('sms_twilio_token'),
    'sms_twilio_from'  => sms_setting('sms_twilio_from'),
    // HTTP Request (webhook)
    'sms_http_url'     => sms_setting('sms_http_url'),
    'sms_http_method'  => sms_setting('sms_http_method') ?: 'GET',
    'sms_http_ok_text' => sms_setting('sms_http_ok_text'),
];

$test_result = null;

// ── Obsługa POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        $provider = in_array($_POST['sms_provider'] ?? '', array_keys(sms_providers()), true)
                    ? $_POST['sms_provider'] : 'smsapi';

        // Dostawca zapasowy (fallback) — '' = brak; nie może być taki sam jak główny
        $fallback = in_array($_POST['sms_fallback_provider'] ?? '', array_keys(sms_providers()), true)
                    ? $_POST['sms_fallback_provider'] : '';
        if ($fallback === $provider) $fallback = '';

        $save = [
            'sms_enabled'           => !empty($_POST['sms_enabled']) ? '1' : '0',
            'sms_provider'          => $provider,
            'sms_fallback_provider' => $fallback,
        ];

        // smsapi fields (keep existing if left blank)
        $api_token = trim($_POST['sms_api_token']    ?? '');
        $api_login = trim($_POST['sms_api_login']    ?? '');
        $api_pass  = trim($_POST['sms_api_password'] ?? '');
        $save['sms_api_token']    = $api_token ?: sms_setting('sms_api_token');
        $save['sms_api_login']    = $api_login ?: $cfg['sms_api_login'];
        $save['sms_api_password'] = $api_pass  ?: $cfg['sms_api_password'];
        $save['sms_sender_name']  = substr(trim($_POST['sms_sender_name'] ?? 'INFO'), 0, 11);

        // SerwerSMS.pl: token i hasło zostają, gdy pole puste (jak w smsapi); nadawcę i adres API wolno wyczyścić —
        // pusty nadawca = domyślny „tte” (kanał normalny FULL), pusty adres = domyślny https://api2.serwersms.pl.
        // Kanał: full (normalny, z nazwą nadawcy) albo eco (bez nazwy nadawcy); nazwa nadawcy zostaje zapamiętana także przy ECO
        $save['sms_serwersms_channel']  = ($_POST['sms_serwersms_channel'] ?? '') === 'eco' ? 'eco' : 'full';
        $save['sms_serwersms_token']    = trim($_POST['sms_serwersms_token']    ?? '') ?: sms_setting('sms_serwersms_token');
        $save['sms_serwersms_login']    = trim($_POST['sms_serwersms_login']    ?? '') ?: $cfg['sms_serwersms_login'];
        $save['sms_serwersms_password'] = trim($_POST['sms_serwersms_password'] ?? '') ?: $cfg['sms_serwersms_password'];
        $save['sms_serwersms_sender']   = substr((string)preg_replace('/[^A-Za-z0-9 ._-]/', '', trim((string)($_POST['sms_serwersms_sender'] ?? ''))), 0, 11);
        $api_base = trim((string)($_POST['sms_serwersms_api_base'] ?? ''));
        $api_base_bad = $api_base !== '' && !preg_match('~^https://[A-Za-z0-9.-]+(:\d+)?(/\S*)?$~', $api_base);
        $save['sms_serwersms_api_base'] = $api_base_bad ? '' : rtrim($api_base, '/');

        // Twilio fields (keep existing if left blank)
        $twilio_token = trim($_POST['sms_twilio_token'] ?? '');
        $save['sms_twilio_sid']   = trim($_POST['sms_twilio_sid']   ?? '') ?: $cfg['sms_twilio_sid'];
        $save['sms_twilio_token'] = $twilio_token                           ?: $cfg['sms_twilio_token'];
        $save['sms_twilio_from']  = trim($_POST['sms_twilio_from']  ?? '') ?: $cfg['sms_twilio_from'];

        // HTTP Request fields
        $save['sms_http_url']    = trim($_POST['sms_http_url']    ?? '') ?: $cfg['sms_http_url'];
        $save['sms_http_method'] = in_array($_POST['sms_http_method'] ?? '', ['GET','POST'], true)
                                   ? $_POST['sms_http_method'] : 'GET';
        $save['sms_http_ok_text'] = mb_substr(trim($_POST['sms_http_ok_text'] ?? ''), 0, 100);   // opcjonalny; pusty = nie sprawdzamy treści odpowiedzi

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
        if ($api_base_bad) flash_set('warning', 'Ustawienia SMS zapisane, ale adres API SerwerSMS pominięto — musi zaczynać się od https://.');
        else               flash_set('success', 'Ustawienia SMS zapisane.');
        header('Location: sms_settings.php'); exit;
    }

    if ($action === 'save_kind_senders') {
        $in  = $_POST['kind_sender'] ?? [];
        $res = SmsSenders::save(is_array($in) ? $in : [], (int)current_user()['id']);
        $n   = count($res['changed']);
        if ($n) log_system_action((int)current_user()['id'], 'settings_save', 'Ustawienia SMS — nadawca wg rodzaju SMS: ' . $n . ' zmian');
        $kinds = SmsSenders::kinds();
        if ($res['adjusted']) {
            $names = array_map(fn($k) => '„' . $kinds[$k]['label'] . '”', $res['adjusted']);
            flash_set('warning', 'Nadawców zapisano (zmian: ' . $n . '), ale poprawiono wpisy dla: ' . implode(', ', $names)
                . ' — dozwolone są litery, cyfry, spacja, kropka, myślnik i podkreślnik, maks. ' . SmsSenders::NAME_MAX . ' znaków.');
        } else {
            flash_set('success', $n ? 'Nadawcy wg rodzaju SMS zapisani (zmian: ' . $n . ').' : 'Bez zmian — nadawcy wg rodzaju SMS są już zapisani w tej postaci.');
        }
        header('Location: sms_settings.php#kind-senders'); exit;
    }

    if ($action === 'test') {
        $phone    = trim($_POST['test_phone'] ?? '');
        $kind     = (string)($_POST['kind'] ?? '');
        if (!SmsSenders::exists($kind)) $kind = '';                  // tylko rodzaje z katalogu; pusty = domyślny nadawca
        $via      = in_array($_POST['via'] ?? '', ['primary', 'fallback', 'chain'], true) ? $_POST['via'] : 'chain';
        $primary  = sms_provider();
        $fallback = sms_provider_fallback();
        $text     = 'Test SMS z Rejestru Umów — ' . ORG_NAME . '. Zignoruj tę wiadomość.';

        if (strlen((string)preg_replace('/\D/', '', $phone)) < 9) {
            $test_result = ['ok' => false, 'msg' => 'Podaj numer telefonu do testu (9 cyfr).'];
        } elseif ($via === 'fallback' && ($fallback === '' || $fallback === $primary)) {
            $test_result = ['ok' => false, 'msg' => 'Dostawca zapasowy nie jest wybrany (albo jest taki sam jak główny) — wybierz innego i zapisz konfigurację.'];
        } else {
            try {
                if ($via === 'chain') {
                    // tak jak w produkcji: główny → zapasowy; komunikat mówi, kto naprawdę wysłał
                    $used = sms_send($phone, $text, $kind);
                    $last = sms_last_send();
                    if ($used === '') {
                        $test_result = ['ok' => true, 'msg' => 'SMS pominięty — numer należy do kursanta wirtualnego.'];
                    } else {
                        $msg = 'SMS wysłany na ' . $phone . ' przez ' . sms_provider_label($used) . '.';
                        if (!empty($last['fallback'])) {
                            $msg .= ' Główny dostawca (' . sms_provider_label($primary) . ') zawiódł: ' . ($last['errors'][$primary] ?? 'błąd')
                                  . ' — zadziałał fallback.';
                        }
                        $test_result = ['ok' => true, 'msg' => $msg, 'fallback' => !empty($last['fallback'])];
                    }
                } else {
                    // jeden dostawca, bez łańcucha — sprawdza, czy właśnie ten operator działa
                    $prov = $via === 'primary' ? $primary : $fallback;
                    sms_send_via($prov, $phone, $text, $kind);
                    $test_result = ['ok' => true, 'msg' => 'SMS wysłany na ' . $phone . ' przez ' . sms_provider_label($prov)
                                    . ' (' . ($via === 'primary' ? 'główny' : 'zapasowy') . ', bez łańcucha).'];
                }
            } catch (\Throwable $e) {
                $test_result = ['ok' => false, 'msg' => $e->getMessage()];
            }
        }
    }
}

include dirname(__DIR__) . '/includes/header.php';

// Pomocnik: czy dana konfiguracja jest kompletna
$smsapi_ok = (bool)sms_setting('sms_api_token')
          || ((bool)$cfg['sms_api_login'] && (bool)$cfg['sms_api_password']);
$serwersms_token = sms_setting('sms_serwersms_token');
$serwersms_ok    = (bool)$serwersms_token || ((bool)$cfg['sms_serwersms_login'] && (bool)$cfg['sms_serwersms_password']);
$twilio_ok = $cfg['sms_twilio_sid'] && $cfg['sms_twilio_token'] && $cfg['sms_twilio_from'];
$http_ok   = (bool)$cfg['sms_http_url'];
$active_ok = match($cfg['sms_provider']) {
    'serwersms'   => $serwersms_ok,
    'twilio'      => $twilio_ok,
    'httprequest' => $http_ok,
    default       => $smsapi_ok,
};
$provider_label = match($cfg['sms_provider']) {
    'serwersms'   => 'SerwerSMS.pl',
    'twilio'      => 'Twilio',
    'httprequest' => 'HTTP Request',
    default       => 'smsapi.pl',
};

// Główny i zapasowy dostawca: pola konfiguracji pokazujemy dla OBU (dawniej tylko dla głównego — zapasowego nie dało się skonfigurować)
$fb        = $cfg['sms_fallback_provider'];
$labels    = sms_providers();
$ready_of  = ['smsapi' => $smsapi_ok, 'serwersms' => $serwersms_ok, 'twilio' => (bool)$twilio_ok, 'httprequest' => $http_ok];
$show      = fn(string $p): bool => $cfg['sms_provider'] === $p || $fb === $p;
$fb_valid  = $fb !== '' && $fb !== $cfg['sms_provider'];
$fb_ready  = $fb_valid && !empty($ready_of[$fb]);
$chain     = sms_provider_chain();
$events    = [];
try {
    require_once dirname(__DIR__) . '/modules/audit_logs/logic/audit_logs.php';
    audit_logs_migrate();
    $events = db_all("SELECT action, details, created_at FROM audit_logs WHERE action LIKE 'sms.%' ORDER BY id DESC LIMIT 10");
} catch (\Throwable $e) {}
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
.logo-serwersms { background: #fef3c7; color: #b45309; }
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

<?php if (!$chain): ?>
<div class="alert alert-danger small">
  <strong>Żaden dostawca SMS nie jest skonfigurowany</strong> — powiadomienia i kody SMS nie będą wysyłane.
  Uzupełnij konfigurację głównego dostawcy (<?= h($labels[$cfg['sms_provider']] ?? '') ?>) poniżej.
</div>
<?php elseif (!$active_ok): ?>
<div class="alert alert-warning small">
  <strong>Główny dostawca (<?= h($provider_label) ?>) nie ma kompletnej konfiguracji.</strong>
  SMS-y idą wyłącznie przez zapasowego (<?= h($labels[$chain[0]] ?? $chain[0]) ?>) — uzupełnij głównego, żeby przywrócić normalny tryb.
</div>
<?php endif; ?>
<?php if ($fb_valid && !$fb_ready): ?>
<div class="alert alert-warning small">
  <strong>Dostawca zapasowy (<?= h($labels[$fb] ?? $fb) ?>) nie ma kompletnej konfiguracji</strong> — fallback nie zadziała.
  Uzupełnij jego pola w sekcji „Konfiguracja <?= h($labels[$fb] ?? $fb) ?>” poniżej i zapisz.
</div>
<?php endif; ?>

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
               onchange="refreshProviders()" class="d-none">
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
        <input type="radio" name="sms_provider" id="prov_serwersms" value="serwersms"
               <?= $cfg['sms_provider'] === 'serwersms' ? 'checked' : '' ?>
               onchange="refreshProviders()" class="d-none">
        <label for="prov_serwersms" class="d-flex align-items-start gap-3 w-100 mb-0">
          <div class="provider-logo logo-serwersms"><i class="bi bi-send"></i></div>
          <div>
            <div class="fw-semibold">SerwerSMS.pl</div>
            <div class="small text-muted">Polski dostawca, API v2, kanały FULL i ECO.</div>
            <?php if ($serwersms_ok): ?>
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
               onchange="refreshProviders()" class="d-none">
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
               onchange="refreshProviders()" class="d-none">
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

<!-- ── Dostawca zapasowy (fallback) ───────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-arrow-repeat"></i> Dostawca zapasowy — inny operator (fallback)</div>
<div class="card-body">
  <p class="small text-muted mb-2">
    Gdy <strong>główny</strong> operator zwróci błąd albo nie odpowie w ciągu kilkunastu sekund, system automatycznie
    ponowi wysyłkę przez operatora zapasowego. Dla kodów logowania łańcuch to:
    <strong>główny SMS → zapasowy SMS → e-mail</strong>. Każde przełączenie zapisuje się w „Ostatnich zdarzeniach” obok.
    Zalecane u nas: główny <strong>smsapi.pl</strong>, zapasowy <strong>SerwerSMS.pl</strong>, <strong>Twilio</strong> albo <strong>HTTP Request</strong> (brama innego operatora).
  </p>
  <label class="form-label fw-semibold small" for="sms_fallback_provider">Użyj jako zapasowego</label>
  <select name="sms_fallback_provider" id="sms_fallback_provider" class="form-select form-select-sm" style="max-width:280px"
          onchange="refreshProviders()" aria-describedby="fb-help fb-note">
    <option value="">— brak (bez fallbacku) —</option>
    <option value="smsapi"      <?= $fb==='smsapi'      ? 'selected':'' ?>>smsapi.pl</option>
    <option value="serwersms"   <?= $fb==='serwersms'   ? 'selected':'' ?>>SerwerSMS.pl</option>
    <option value="twilio"      <?= $fb==='twilio'      ? 'selected':'' ?>>Twilio</option>
    <option value="httprequest" <?= $fb==='httprequest' ? 'selected':'' ?>>HTTP Request</option>
  </select>
  <div class="form-text" id="fb-help">
    Musi być <strong>inny</strong> niż dostawca główny. Po wyborze poniżej pojawia się jego konfiguracja —
    uzupełnij ją tutaj, nie trzeba przełączać dostawcy głównego.
  </div>
  <div class="form-text fw-semibold" id="fb-note"></div>
</div>
</div>

<!-- ── Nazwa nadawcy (wspólna) ────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-person-badge"></i> Nazwa nadawcy</div>
<div class="card-body">
  <label class="form-label fw-semibold small" for="sms_sender_name">
    Nazwa nadawcy <span class="text-muted fw-normal">(maks. 11 znaków ASCII)</span>
  </label>
  <input type="text" id="sms_sender_name" name="sms_sender_name" class="form-control form-control-sm" style="max-width:280px"
         value="<?= h($cfg['sms_sender_name']) ?>" maxlength="11" placeholder="INFO" aria-describedby="sender-help">
  <div class="form-text" id="sender-help">
    Pojawi się zamiast numeru telefonu u odbiorcy (smsapi.pl) i w placeholderze <code>{sender}</code> bramy HTTP.
    Twilio wysyła z numeru nadawcy z konfiguracji Twilio. SerwerSMS ma własne pole nadawcy w sekcji „Konfiguracja SerwerSMS.pl”
    (nazwa musi być tam zarejestrowana). Nadawcę dla poszczególnych rodzajów SMS (kody, powiadomienia TI…) ustawisz osobno
    w sekcji <a href="#kind-senders">„Nadawca wg rodzaju SMS”</a> pod formularzem.
  </div>
</div>
</div>

<!-- ── Konfiguracja smsapi.pl ─────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3" id="section-smsapi"
     <?= $show('smsapi') ? '' : 'style="display:none"' ?>>
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <div class="provider-logo logo-smsapi" style="width:24px;height:24px;font-size:.85rem;border-radius:4px">
    <i class="bi bi-chat-dots"></i>
  </div>
  Konfiguracja smsapi.pl
  <span class="badge ms-auto fw-normal role-badge" data-provider="smsapi"></span>
</div>
<div class="card-body">
  <!-- Token OAuth (nowa metoda — zalecana) -->
  <?php $smsapi_token = sms_setting('sms_api_token'); ?>
  <div class="mb-3">
    <label class="form-label fw-semibold small">
      Token OAuth
      <span class="badge bg-success ms-1 fw-normal" style="font-size:.65rem">Zalecane</span>
    </label>
    <input type="password" name="sms_api_token" class="form-control form-control-sm font-monospace"
           placeholder="<?= $smsapi_token ? '(zapisany — zostaw puste by nie zmieniać)' : 'Token OAuth z panelu smsapi.pl → API → Tokeny' ?>"
           autocomplete="new-password">
    <?php if ($smsapi_token): ?>
    <div class="form-text text-success"><i class="bi bi-check-circle"></i> Token OAuth zapisany — używa oficjalnego SDK smsapi/php-client.</div>
    <?php else: ?>
    <div class="form-text">
      Pobierz token w panelu smsapi.pl: <strong>API → Tokeny dostępu</strong>.
      Jeśli brak tokenu, system użyje loginu i hasła jako fallback.
    </div>
    <?php endif; ?>
  </div>

  <details class="mb-3">
    <summary class="text-muted small" style="cursor:pointer">
      <i class="bi bi-chevron-right me-1"></i>Fallback: login + hasło (stara metoda)
    </summary>
    <div class="mt-2">
  <div class="mb-3">
    <label class="form-label fw-semibold small">Login</label>
    <input type="text" name="sms_api_login" class="form-control form-control-sm font-monospace"
           value="<?= h($cfg['sms_api_login']) ?>"
           placeholder="Login do konta smsapi.pl" autocomplete="off">
    <?php if ($cfg['sms_api_login']): ?>
    <div class="form-text text-success"><i class="bi bi-check-circle"></i> Login zapisany.</div>
    <?php endif; ?>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold small">Hasło</label>
    <input type="password" name="sms_api_password" class="form-control form-control-sm font-monospace"
           placeholder="<?= $cfg['sms_api_password'] ? '(zapisane — zostaw puste by nie zmieniać)' : 'Hasło do konta smsapi.pl' ?>"
           autocomplete="new-password">
    <?php if ($cfg['sms_api_password']): ?>
    <div class="form-text text-success"><i class="bi bi-check-circle"></i> Hasło zapisane.</div>
    <?php else: ?>
    <div class="form-text">Hasło wysyłane jako MD5 (stare API).</div>
    <?php endif; ?>
  </div>
    </div>
  </details>
</div>
</div>

<!-- ── Konfiguracja SerwerSMS.pl ──────────────────────────────────────────── -->
<div class="card shadow-sm mb-3" id="section-serwersms"
     <?= $show('serwersms') ? '' : 'style="display:none"' ?>>
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <div class="provider-logo logo-serwersms" style="width:24px;height:24px;font-size:.85rem;border-radius:4px">
    <i class="bi bi-send"></i>
  </div>
  Konfiguracja SerwerSMS.pl
  <span class="badge ms-auto fw-normal role-badge" data-provider="serwersms"></span>
</div>
<div class="card-body">
  <div class="alert alert-light border small p-2 mb-3">
    HTTPS API v2 (<a href="https://dev.serwersms.pl/https-api-v2/wprowadzenie" target="_blank" rel="noopener">dokumentacja</a>).
    Token wygenerujesz w panelu SerwerSMS: <strong>Ustawienia interfejsów → HTTPS API → Tokeny API</strong>.
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small" for="sms_serwersms_token">
      Token API
      <span class="badge bg-success ms-1 fw-normal" style="font-size:.65rem">Zalecane</span>
    </label>
    <input type="password" id="sms_serwersms_token" name="sms_serwersms_token" class="form-control form-control-sm font-monospace"
           placeholder="<?= $serwersms_token ? '(zapisany — zostaw puste by nie zmieniać)' : 'Token API z panelu SerwerSMS' ?>"
           autocomplete="new-password">
    <?php if ($serwersms_token): ?>
    <div class="form-text text-success"><i class="bi bi-check-circle"></i> Token API zapisany — używany w nagłówku <code>Authorization: Bearer</code>.</div>
    <?php else: ?>
    <div class="form-text">Bez tokenu system użyje loginu i hasła użytkownika API (poniżej).</div>
    <?php endif; ?>
  </div>

  <details class="mb-3">
    <summary class="text-muted small" style="cursor:pointer">
      <i class="bi bi-chevron-right me-1"></i>Alternatywa: użytkownik API (login + hasło)
    </summary>
    <div class="mt-2">
      <div class="mb-3">
        <label class="form-label fw-semibold small" for="sms_serwersms_login">Login użytkownika API</label>
        <input type="text" id="sms_serwersms_login" name="sms_serwersms_login" class="form-control form-control-sm font-monospace"
               value="<?= h($cfg['sms_serwersms_login']) ?>" placeholder="np. webapi_firma" autocomplete="off">
        <?php if ($cfg['sms_serwersms_login']): ?>
        <div class="form-text text-success"><i class="bi bi-check-circle"></i> Login zapisany.</div>
        <?php endif; ?>
      </div>
      <div class="mb-0">
        <label class="form-label fw-semibold small" for="sms_serwersms_password">Hasło użytkownika API</label>
        <input type="password" id="sms_serwersms_password" name="sms_serwersms_password" class="form-control form-control-sm font-monospace"
               placeholder="<?= $cfg['sms_serwersms_password'] ? '(zapisane — zostaw puste by nie zmieniać)' : 'Hasło użytkownika API' ?>"
               autocomplete="new-password">
        <?php if ($cfg['sms_serwersms_password']): ?>
        <div class="form-text text-success"><i class="bi bi-check-circle"></i> Hasło zapisane.</div>
        <?php else: ?>
        <div class="form-text">Użytkownika API tworzysz w panelu: <strong>Ustawienia interfejsów → HTTPS API → Użytkownicy API</strong>. To nie jest hasło do panelu.</div>
        <?php endif; ?>
      </div>
    </div>
  </details>

  <div class="mb-3">
    <label class="form-label fw-semibold small" for="sms_serwersms_channel">Kanał wysyłki</label>
    <select id="sms_serwersms_channel" name="sms_serwersms_channel" class="form-select form-select-sm" style="max-width:340px"
            aria-describedby="serwersms-channel-help">
      <option value="full" <?= $cfg['sms_serwersms_channel'] === 'full' ? 'selected' : '' ?>>Normalny (FULL) — z nazwą nadawcy</option>
      <option value="eco"  <?= $cfg['sms_serwersms_channel'] === 'eco'  ? 'selected' : '' ?>>ECO — bez własnej nazwy nadawcy</option>
    </select>
    <div class="form-text" id="serwersms-channel-help">
      <strong>Normalny (FULL)</strong> — SMS z nazwą nadawcy (poniżej), polskie znaki w UTF (70 znaków w SMS-ie, bez nich 160).
      <strong>ECO</strong> — tańszy kanał bez własnej nazwy nadawcy; nie obsługuje UTF, więc system zamienia polskie znaki na ASCII.
      To ustawienie domyślne dla wszystkich SMS-ów; nadawcę wybranego rodzaju SMS można ustawić osobno.
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold small" for="sms_serwersms_sender">
      Nazwa nadawcy w SerwerSMS <span class="text-muted fw-normal">(kanał normalny; maks. 11 znaków; puste = <code>tte</code>)</span>
    </label>
    <input type="text" id="sms_serwersms_sender" name="sms_serwersms_sender" class="form-control form-control-sm" style="max-width:280px"
           value="<?= h($cfg['sms_serwersms_sender']) ?>" maxlength="11" placeholder="tte" aria-describedby="serwersms-sender-help">
    <div class="form-text" id="serwersms-sender-help">
      Używana w kanale normalnym (FULL) — domyślnie <code>tte</code>. Nazwa musi być wcześniej zarejestrowana i zatwierdzona
      w panelu SerwerSMS, inaczej serwis odrzuci wiadomość (błąd 4402). Nie używa wspólnej „Nazwy nadawcy” z góry strony.
    </div>
  </div>

  <details>
    <summary class="text-muted small" style="cursor:pointer">
      <i class="bi bi-chevron-right me-1"></i>Zaawansowane: adres API
    </summary>
    <div class="mt-2">
      <label class="form-label fw-semibold small" for="sms_serwersms_api_base">Adres API <span class="text-muted fw-normal">(puste = domyślny)</span></label>
      <input type="text" id="sms_serwersms_api_base" name="sms_serwersms_api_base" class="form-control form-control-sm font-monospace"
             value="<?= h($cfg['sms_serwersms_api_base']) ?>" placeholder="https://api2.serwersms.pl" aria-describedby="serwersms-base-help">
      <div class="form-text" id="serwersms-base-help">
        Domyślnie <code>https://api2.serwersms.pl</code>. Dokumentacja podaje też zapasowy host <code>https://s1api2.serwersms.pl</code>.
        Adres musi zaczynać się od <code>https://</code>.
      </div>
    </div>
  </details>
</div>
</div>

<!-- ── Konfiguracja Twilio ────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3" id="section-twilio"
     <?= $show('twilio') ? '' : 'style="display:none"' ?>>
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <div class="provider-logo logo-twilio" style="width:24px;height:24px;font-size:.85rem;border-radius:4px">
    <i class="bi bi-telephone"></i>
  </div>
  Konfiguracja Twilio
  <span class="badge ms-auto fw-normal role-badge" data-provider="twilio"></span>
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
     <?= $show('httprequest') ? '' : 'style="display:none"' ?>>
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <div class="provider-logo logo-http" style="width:24px;height:24px;font-size:.85rem;border-radius:4px">
    <i class="bi bi-globe2"></i>
  </div>
  Konfiguracja HTTP Request (Webhook)
  <span class="badge ms-auto fw-normal role-badge" data-provider="httprequest"></span>
</div>
<div class="card-body">
  <div class="alert alert-light border small p-2 mb-3">
    Podaj adres URL do wywołania przy wysyłce SMS. Placeholdery (url-encodowane):
    <code>{phone}</code> — numer <code>48XXXXXXXXX</code>, <code>{phone9}</code> — same 9 cyfr,
    <code>{phone_plus}</code> — <code>+48XXXXXXXXX</code>, <code>{message}</code> — treść SMS,
    <code>{sender}</code> — nazwa nadawcy z ustawień.<br>
    Przykład: <code>https://sms.brama.pl/send?apikey=ABC&amp;to={phone}&amp;from={sender}&amp;text={message}</code>
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

  <div class="mt-3">
    <label class="form-label fw-semibold small" for="sms_http_ok_text">Tekst oznaczający sukces w odpowiedzi <span class="text-muted fw-normal">(opcjonalnie)</span></label>
    <input type="text" id="sms_http_ok_text" name="sms_http_ok_text" class="form-control form-control-sm font-monospace" style="max-width:280px"
           value="<?= h($cfg['sms_http_ok_text']) ?>" maxlength="100" placeholder="np. OK albo &quot;status&quot;:&quot;ok&quot;" aria-describedby="okt-help">
    <div class="form-text" id="okt-help">
      Sukcesem jest odpowiedź HTTP 2xx. Niektóre bramy zwracają 200 także przy błędzie (np. „ERROR: brak środków”) —
      wpisz tekst, który występuje w odpowiedzi tylko przy powodzeniu, a odpowiedź bez niego zostanie uznana za porażkę
      i system przejdzie na dostawcę zapasowego. Puste = treści nie sprawdzamy (system nie zgaduje, by nie wysłać SMS-a dwa razy).
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

<?php
// Nadawca wg rodzaju SMS: dostawcy łańcucha (główny, zapasowy) — kolumna „Obowiązuje teraz” pokazuje wynik dla każdego z nich
$ks_groups    = SmsSenders::groups();
$ks_stored    = SmsSenders::all();
$ks_providers = array_values(array_unique(array_filter([sms_provider(), sms_provider_fallback()])));
$ks_labels    = sms_providers();
$ks_suggest   = array_values(array_unique(array_filter([
    trim((string)$cfg['sms_sender_name']), trim((string)$cfg['sms_serwersms_sender']), sms_serwersms_sender(),
])));
include dirname(__DIR__) . '/modules/sms_senders/views/kindSenders.php';
?>

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
    <label class="form-label small fw-semibold mb-1" for="test_phone">Numer telefonu</label>
    <div class="input-group input-group-sm mb-2" style="max-width:340px">
      <span class="input-group-text fw-semibold text-muted">+48</span>
      <input type="tel" id="test_phone" name="test_phone" class="form-control phone-48" placeholder="123 456 789" required>
    </div>
    <label class="form-label small fw-semibold mb-1" for="test_kind">Rodzaj SMS <span class="text-muted fw-normal">(jakiego nadawcy użyć)</span></label>
    <select id="test_kind" name="kind" class="form-select form-select-sm mb-2" style="max-width:340px">
      <option value="">— bez rodzaju (domyślny nadawca) —</option>
      <?php foreach ($ks_groups as $grp => $grp_kinds): ?>
      <optgroup label="<?= h($grp) ?>">
        <?php foreach ($grp_kinds as $k => $info): ?><option value="<?= h($k) ?>"><?= h($info['label']) ?></option><?php endforeach; ?>
      </optgroup>
      <?php endforeach; ?>
    </select>
    <div class="d-flex flex-wrap gap-2">
      <button type="submit" name="via" value="chain" class="btn btn-sm btn-primary">
        <i class="bi bi-diagram-3"></i> Wyślij jak w produkcji (łańcuch)
      </button>
      <button type="submit" name="via" value="primary" class="btn btn-sm btn-outline-primary">
        Tylko przez głównego (<?= h($provider_label) ?>)
      </button>
      <button type="submit" name="via" value="fallback" class="btn btn-sm btn-outline-primary"<?= $fb_valid ? '' : ' disabled aria-disabled="true"' ?>>
        Tylko przez zapasowego (<?= $fb_valid ? h($labels[$fb] ?? $fb) : 'nie wybrano' ?>)
      </button>
    </div>
    <div class="form-text mt-2">
      Test używa <strong>zapisanej</strong> konfiguracji — zapisz ją przed testem. „Łańcuch” działa jak prawdziwa wysyłka i podaje,
      kto faktycznie wysłał; „tylko głównego/zapasowego” sprawdza jednego operatora bez przechodzenia na drugiego —
      tym sprawdzisz, czy zapasowy w ogóle działa, bez psucia głównego.
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

  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted">Dostawca zapasowy</span>
    <span class="badge <?= $fb_valid ? ($fb_ready ? 'bg-info-subtle text-info-emphasis border border-info-subtle' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle') : 'bg-secondary' ?>">
      <?= $fb_valid ? h($labels[$fb] ?? $fb) . ($fb_ready ? '' : ' — niekompletny') : 'brak' ?>
    </span>
  </div>
  <div class="d-flex justify-content-between align-items-start mb-2">
    <span class="text-muted">Łańcuch wysyłki</span>
    <span class="fw-semibold text-end">
      <?php if ($chain): ?>
        <?= h(implode(' → ', array_map(fn($p) => $labels[$p] ?? $p, $chain))) ?><?= ' → e-mail (kody)' ?>
      <?php else: ?>
        <span class="text-danger">brak działającego dostawcy</span>
      <?php endif; ?>
    </span>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted">Rodzaje SMS z własnym nadawcą</span>
    <span class="fw-semibold"><a href="#kind-senders"><?= (int)count($ks_stored) ?></a> z <?= (int)count(SmsSenders::kinds()) ?></span>
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

  <!-- SerwerSMS status -->
  <div class="d-flex justify-content-between align-items-center mb-1">
    <span class="text-muted">SerwerSMS.pl — dostęp API</span>
    <span class="badge <?= $serwersms_ok ? 'bg-success' : 'bg-secondary' ?>">
      <?= $serwersms_token ? 'Token' : ($serwersms_ok ? 'Login + hasło' : 'Brak') ?>
    </span>
  </div>
  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted">SerwerSMS.pl — nadawca</span>
    <span class="fw-semibold"><?php if (sms_serwersms_sender() === ''): ?>ECO <span class="text-muted fw-normal">(bez nazwy)</span><?php else: ?><?= h(sms_serwersms_sender()) ?> <span class="text-muted fw-normal">(FULL)</span><?php endif; ?></span>
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

<!-- Ostatnie zdarzenia -->
<div class="card shadow-sm mb-3">
<div class="card-header fw-semibold"><i class="bi bi-clock-history"></i> Ostatnie zdarzenia wysyłki</div>
<div class="card-body small">
  <?php if (!$events): ?>
    <p class="text-muted mb-0">Brak zdarzeń — główny dostawca nie zawodził (albo nic jeszcze nie wysyłano).</p>
  <?php else: ?>
  <p class="text-muted mb-2">Przełączenia na zapasowego i całkowite porażki wysyłki (numer zamaskowany).</p>
  <ul class="list-unstyled mb-0">
    <?php foreach ($events as $ev):
      $d = json_decode((string)$ev['details'], true) ?: [];
      $is_fb = $ev['action'] === 'sms.fallback';
      $err   = $d['errors'] ?? []; ?>
    <li class="mb-2">
      <span class="badge <?= $is_fb ? 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' : 'bg-danger-subtle text-danger-emphasis border border-danger-subtle' ?>">
        <?= $is_fb ? 'przełączono na zapasowego' : 'wysyłka nieudana' ?>
      </span>
      <span class="text-muted"><?= h((string)$ev['created_at']) ?> · do <?= h((string)($d['to'] ?? '')) ?></span>
      <?php if ($is_fb && !empty($d['used'])): ?><span> · wysłał <?= h($labels[$d['used']] ?? $d['used']) ?></span><?php endif; ?>
      <?php foreach ($err as $prov => $msg): ?>
        <div class="text-muted ps-2">— <?= h($labels[$prov] ?? $prov) ?>: <?= h(mb_substr((string)$msg, 0, 160)) ?></div>
      <?php endforeach; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
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
    <tr><th></th><th class="text-center">smsapi.pl</th><th class="text-center">SerwerSMS</th><th class="text-center">Twilio</th><th class="text-center">HTTP</th></tr>
  </thead>
  <tbody>
    <tr><td class="text-muted">Zasięg</td>
        <td class="text-center">Polska</td>
        <td class="text-center">Polska</td>
        <td class="text-center">Globalny</td>
        <td class="text-center">Własny</td></tr>
    <tr><td class="text-muted">Cena (SMS do PL)</td>
        <td class="text-center">~0,07 zł</td>
        <td class="text-center">wg cennika</td>
        <td class="text-center">~0,05 $</td>
        <td class="text-center">wg dostawcy</td></tr>
    <tr><td class="text-muted">Nazwa nadawcy</td>
        <td class="text-center"><i class="bi bi-check text-success"></i></td>
        <td class="text-center"><i class="bi bi-check text-success"></i> FULL / ECO</td>
        <td class="text-center"><i class="bi bi-x text-muted"></i> nr tel</td>
        <td class="text-center">zależy</td></tr>
    <tr><td class="text-muted">Trial (darmowy)</td>
        <td class="text-center"><i class="bi bi-x text-muted"></i></td>
        <td class="text-center">zależy</td>
        <td class="text-center"><i class="bi bi-check text-success"></i></td>
        <td class="text-center">zależy</td></tr>
    <tr><td class="text-muted">Konfiguracja</td>
        <td class="text-center">1 token</td>
        <td class="text-center">1 token</td>
        <td class="text-center">SID + token + numer</td>
        <td class="text-center">URL szablonu</td></tr>
  </tbody>
</table>
</div>
</div>

<!-- Konfiguracja pod Dydaktyka 3 / Dydaktyka -->
<div class="card shadow-sm mt-3">
<div class="card-header fw-semibold"><i class="bi bi-card-checklist text-primary"></i> Konfiguracja SMS dla modułu „Dydaktyka" (Dydaktyka 3)</div>
<div class="card-body small">
  <p class="mb-2">
    Ustawienia SMS są <strong>wspólne dla całego Systemu Obsługi Organizacji</strong> — moduł
    Dydaktyka 3 korzysta z tej samej konfiguracji. Fallback (np. Twilio) działa automatycznie
    we wszystkich miejscach poniżej.
  </p>

  <p class="fw-semibold mb-1">Zalecana konfiguracja (główny + zapasowy):</p>
  <ol class="ps-3 mb-2">
    <li>Włącz przełącznik <em>Logowanie SMS aktywne</em>.</li>
    <li><strong>Dostawca SMS = smsapi.pl</strong> → wpisz <em>Token OAuth</em> i <em>Nazwę nadawcy</em>.</li>
    <li><strong>Dostawca zapasowy = Twilio</strong> (sekcja „Dostawca zapasowy”) — zaraz pod nią pojawi się konfiguracja Twilio:
        Account SID, Auth Token, numer From (E.164, <code>+48…</code>). Zamiast Twilio może to być SerwerSMS.pl (token API) albo brama innego operatora (HTTP Request).</li>
    <li>Zapisz i sprawdź <em>testem</em>: „Tylko przez zapasowego” pokazuje, czy drugi operator działa, a „łańcuch” — kto wysłał jak w produkcji.
        Nie trzeba niczego „psuć” w głównym.</li>
  </ol>

  <p class="fw-semibold mb-1">Gdzie Dydaktyka 3 wysyła SMS:</p>
  <ul class="ps-3 mb-2">
    <li>Powiadomienia kursantów: lekcje, oceny, wiadomości (TI → Komunikacja).</li>
    <li>Powiadomienia o rozliczeniach (TI → Rozliczenia).</li>
    <li>Logowanie kursanta / portal PFRON (kody jednorazowe).</li>
    <li>Lista oczekujących — SMS do beneficjenta.</li>
  </ul>

  <div class="alert alert-light border mb-0 py-2">
    <i class="bi bi-info-circle text-primary"></i>
    Dla <strong>kodów jednorazowych</strong> łańcuch awaryjny to:
    <strong>główny SMS → zapasowy SMS → e-mail</strong> (gdy konto ma adres e-mail).
  </div>
</div>
</div>

</div><!-- /col-xl-5 -->
</div><!-- /row -->

<script>
// Sekcje konfiguracji widać dla dostawcy głównego i zapasowego; plakietki mówią, kim jest który.
function refreshProviders() {
    var main = (document.querySelector('[name="sms_provider"]:checked') || {}).value || '';
    var fbSel = document.getElementById('sms_fallback_provider');
    var fb = fbSel ? fbSel.value : '';
    var names = {smsapi: 'smsapi.pl', serwersms: 'SerwerSMS.pl', twilio: 'Twilio', httprequest: 'HTTP Request'};
    if (fbSel) {
        Array.prototype.forEach.call(fbSel.options, function (o) { o.disabled = (o.value !== '' && o.value === main); });
        if (fb === main) { fbSel.value = ''; fb = ''; }
    }
    ['smsapi', 'serwersms', 'twilio', 'httprequest'].forEach(function (p) {
        var sec = document.getElementById('section-' + p);
        if (sec) sec.style.display = (p === main || p === fb) ? '' : 'none';
    });
    document.querySelectorAll('.role-badge').forEach(function (b) {
        var p = b.getAttribute('data-provider');
        var role = p === main ? 'Główny' : (p === fb ? 'Zapasowy' : '');
        b.textContent = role;
        b.className = 'badge ms-auto fw-normal role-badge ' + (p === main ? 'bg-primary' : 'bg-info-subtle text-info-emphasis border border-info-subtle');
        b.style.display = role ? '' : 'none';
    });
    var note = document.getElementById('fb-note');
    if (note) note.textContent = fb ? 'Zapasowy: ' + names[fb] + ' — uzupełnij jego konfigurację w sekcji poniżej.' : 'Bez fallbacku: porażka głównego dostawcy oznacza niewysłany SMS.';
}
document.addEventListener('DOMContentLoaded', refreshProviders);
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
