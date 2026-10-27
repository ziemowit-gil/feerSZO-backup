<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/modules/sms_senders/logic/smsSenders.php';

require_role('admin');
$PAGE_TITLE = 'Ustawienia SMS';   // samodzielna strona Metro — bez includes/header.php (menu SZO)

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
$TABS = ['dostawca', 'zapas', 'nadawca', 'test', 'status'];
$TAB  = in_array($_GET['tab'] ?? '', $TABS, true) ? $_GET['tab'] : 'dostawca';

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

        // Sendly: token zostaje, gdy pole puste; adres API wolno wyczyścić (domyślny https://api.sendly.link)
        $save['sms_sendly_token'] = trim($_POST['sms_sendly_token'] ?? '') ?: sms_setting('sms_sendly_token');
        $sl_base = trim((string)($_POST['sms_sendly_api_base'] ?? ''));
        $save['sms_sendly_api_base'] = ($sl_base !== '' && preg_match('~^https://[A-Za-z0-9.-]+(:\d+)?(/\S*)?$~', $sl_base)) ? rtrim($sl_base, '/') : '';

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
        $back_tab = in_array($_POST['_tab'] ?? '', ['dostawca', 'zapas'], true) ? $_POST['_tab'] : 'dostawca';
        header('Location: sms_settings.php?tab=' . $back_tab); exit;
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
        header('Location: sms_settings.php?tab=nadawca'); exit;
    }

    if ($action === 'test') {
        $TAB = 'test';
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

$smsapi_ok = (bool)sms_setting('sms_api_token')
          || ((bool)$cfg['sms_api_login'] && (bool)$cfg['sms_api_password']);
$serwersms_token = sms_setting('sms_serwersms_token');
$serwersms_ok    = (bool)$serwersms_token || ((bool)$cfg['sms_serwersms_login'] && (bool)$cfg['sms_serwersms_password']);
$sendly_token = sms_setting('sms_sendly_token');
$sendly_ok    = (bool)$sendly_token;
$twilio_ok = $cfg['sms_twilio_sid'] && $cfg['sms_twilio_token'] && $cfg['sms_twilio_from'];
$http_ok   = (bool)$cfg['sms_http_url'];
$active_ok = match($cfg['sms_provider']) {
    'serwersms'   => $serwersms_ok,
    'sendly'      => $sendly_ok,
    'twilio'      => $twilio_ok,
    'httprequest' => $http_ok,
    default       => $smsapi_ok,
};
$provider_label = match($cfg['sms_provider']) {
    'serwersms'   => 'SerwerSMS.pl',
    'sendly'      => 'Sendly',
    'twilio'      => 'Twilio',
    'httprequest' => 'HTTP Request',
    default       => 'smsapi.pl',
};

// Główny i zapasowy dostawca: pola konfiguracji pokazujemy dla OBU (dawniej tylko dla głównego — zapasowego nie dało się skonfigurować)
$fb        = $cfg['sms_fallback_provider'];
$labels    = sms_providers();
$ready_of  = ['smsapi' => $smsapi_ok, 'serwersms' => $serwersms_ok, 'sendly' => $sendly_ok, 'twilio' => (bool)$twilio_ok, 'httprequest' => $http_ok];
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

// Nadawca wg rodzaju SMS: dostawcy łańcucha (główny, zapasowy) — kolumna „Obowiązuje teraz” pokazuje wynik dla każdego z nich
$ks_groups    = SmsSenders::groups();
$ks_stored    = SmsSenders::all();
$ks_providers = array_values(array_unique(array_filter([sms_provider(), sms_provider_fallback()])));
$ks_labels    = sms_providers();
$ks_suggest   = array_values(array_unique(array_filter([
    trim((string)$cfg['sms_sender_name']), trim((string)$cfg['sms_serwersms_sender']), sms_serwersms_sender(),
])));

?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ustawienia SMS — <?= h(defined('ORG_NAME') ? ORG_NAME : 'SZO') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* METRO: ciemny pasek, płaskie szare grupy, zero zaokrągleń i cieni (jak ekrany MFA i Tożsamość) */
:root{--m-acc:#0f6fa8;--m-dark:#1d1d1d;--m-green:#007a00;--m-red:#a20025;--m-orange:#c25100;--m-light:#f2f2f2;--m-ink:#111827;--m-muted:#5f5f5f}
html,body{background:#fff;color:var(--m-ink);font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,Arial,sans-serif;-webkit-font-smoothing:antialiased}
*:focus-visible{outline:3px solid #fbbf24!important;outline-offset:2px}
.skip{position:absolute;left:-999px}.skip:focus{left:1rem;top:1rem;z-index:2000;background:#fff;padding:.5rem 1rem;font-weight:600}
.sbar{background:var(--m-dark);color:#fff}
.sbar .in{max-width:1100px;margin:0 auto;padding:.45rem 1rem;display:flex;align-items:center;gap:.7rem}
.sbar .mark{width:34px;height:34px;background:var(--m-acc);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sbar b{font-weight:700;font-size:.95rem;line-height:1.05}.sbar small{display:block;font-size:.65rem;color:rgba(255,255,255,.65);font-weight:500}
.sbar a{color:#fff;text-decoration:none;font-size:.85rem;margin-left:auto;display:inline-flex;gap:.4rem;align-items:center}.sbar a:hover{text-decoration:underline}
.smain{max-width:1100px;margin:0 auto;padding:1.75rem 1rem 3rem}
.kick{font-size:.74rem;font-weight:600;text-transform:uppercase;letter-spacing:.18em;color:var(--m-muted);margin-bottom:.35rem}
.stitle{margin:0;font-size:clamp(1.8rem,4vw,2.4rem);font-weight:300;letter-spacing:-.02em;line-height:1.05;display:flex;flex-wrap:wrap;align-items:center;gap:.8rem}
.slead{margin:.5rem 0 1.25rem;font-weight:300;color:var(--m-muted);max-width:75ch}
.stabs{display:flex;flex-wrap:wrap;gap:2px;margin:0 0 1.25rem;border-bottom:3px solid var(--m-dark)}
.stabs button{display:flex;align-items:center;gap:.5rem;background:var(--m-light);border:0;border-radius:0;padding:.7rem 1.1rem;font:inherit;font-weight:600;font-size:.92rem;color:var(--m-ink);cursor:pointer;min-height:46px}
.stabs button:hover{background:#e4e4e4}
.stabs button[aria-selected="true"]{background:var(--m-dark);color:#fff}
.stabs .dot{width:10px;height:10px;display:inline-block;background:#8a8a8a}.stabs .dot.ok{background:var(--m-green)}.stabs .dot.warn{background:var(--m-orange)}.stabs .dot.bad{background:var(--m-red)}
.stabs button[aria-selected="true"] .dot.ok{background:#4ade80}.stabs button[aria-selected="true"] .dot.warn{background:#fdba74}.stabs button[aria-selected="true"] .dot.bad{background:#fca5a5}
.spanel[hidden],#cfg-store[hidden]{display:none}
.card{border:0!important;border-radius:0!important;box-shadow:none!important;background:var(--m-light)}
.card-header{background:transparent!important;border:0!important;font-size:1.2rem;font-weight:300!important;padding:1rem 1.25rem .25rem}
.card-body{padding:.75rem 1.25rem 1.25rem}
.form-control,.form-select,.input-group-text{border-radius:0!important;border:2px solid #8a8a8a!important}
.form-control:focus,.form-select:focus{border-color:var(--m-acc)!important;box-shadow:none!important}
.btn{border-radius:0!important;font-weight:600;min-height:40px}
.btn-primary{background:var(--m-acc);border-color:var(--m-acc)}.btn-primary:hover{background:var(--m-dark);border-color:var(--m-dark)}
.badge{border-radius:0!important}
.alert{border-radius:0!important;border:0;border-left:6px solid}
.alert-danger{background:#fde8ec;border-color:var(--m-red)!important}.alert-warning{background:#fff1e0;border-color:var(--m-orange)!important}
.alert-success{background:#e3f4e3;border-color:var(--m-green)!important}.alert-light{background:#fff}
.provider-card{border:2px solid #c8c8c8;border-radius:0;padding:1rem 1.1rem;cursor:pointer;background:#fff}
.provider-card:has(input:checked){border-color:var(--m-acc);background:#e8f3fa}
.provider-card:has(input:focus-visible){outline:3px solid #fbbf24}
.provider-card label{cursor:pointer}
.provider-logo{width:36px;height:36px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.logo-smsapi{background:#e0f2fe;color:#0369a1}.logo-twilio{background:#fce7f3;color:#be185d}.logo-serwersms{background:#fef3c7;color:#b45309}.logo-sendly{background:#dcfce7;color:#15803d}.logo-http{background:#f0fdf4;color:#15803d}
.sflash{padding:.8rem 1.1rem;color:#fff;margin:0 0 1rem;display:flex;gap:.6rem;align-items:flex-start}
.sflash.success{background:var(--m-green)}.sflash.danger,.sflash.error{background:var(--m-red)}.sflash.warning{background:var(--m-orange)}.sflash.info{background:var(--m-acc)}
.slot-empty{background:#fff;border-left:6px solid #8a8a8a;padding:.8rem 1rem;font-size:.9rem}
</style>
</head>
<body>
<a href="#sms-main" class="skip">Przejdź do treści</a>
<header class="sbar"><div class="in">
  <span class="mark" aria-hidden="true"><i class="bi bi-phone-fill"></i></span>
  <span><b><?= h(defined('ORG_NAME') ? ORG_NAME : 'SZO') ?></b><small>Administracja · Bramka SMS</small></span>
  <a href="<?= APP_URL ?>/modules/whatsapp/router.php"><i class="bi bi-whatsapp" aria-hidden="true"></i>WhatsApp</a>
  <a href="<?= APP_URL ?>/admin/index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i>Panel admina</a>
</div></header>

<main class="smain" id="sms-main">
  <div class="kick">Administracja · integracje</div>
  <h1 class="stitle">Ustawienia SMS
  <?php if ($cfg['sms_enabled'] === '1' && $active_ok): ?><span class="badge bg-success fs-6">Aktywne — <?= h($provider_label) ?></span>
  <?php elseif ($cfg['sms_enabled'] === '1'): ?><span class="badge bg-warning text-dark fs-6">Włączone — wymaga konfiguracji</span>
  <?php else: ?><span class="badge bg-secondary fs-6">Wyłączone</span><?php endif; ?>
  </h1>
  <p class="slead">Dostawca główny i zapasowy, nadawca wg rodzaju wiadomości, test wysyłki i stan bramki. Ustawienia są wspólne dla całego systemu.</p>

  <?php if ($__f = flash_get()): ?>
  <div class="sflash <?= h($__f['type']) ?>" role="<?= in_array($__f['type'], ['danger', 'error'], true) ? 'alert' : 'status' ?>"><i class="bi bi-info-circle-fill" aria-hidden="true"></i><div><?= h($__f['msg'] ?? '') ?></div></div>
  <?php endif; ?>

<?php if (!$chain): ?>
<div class="alert alert-danger small">
  <strong>Żaden dostawca SMS nie jest skonfigurowany</strong> — powiadomienia i kody SMS nie będą wysyłane.
  Uzupełnij konfigurację głównego dostawcy (<?= h($labels[$cfg['sms_provider']] ?? '') ?>) w zakładce „Dostawca”.
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
<?php
$tab_state = [
    'dostawca' => ['Dostawca', $active_ok ? 'ok' : 'warn'],
    'zapas'    => ['Zapas (fallback)', $fb_valid ? ($fb_ready ? 'ok' : 'warn') : ''],
    'nadawca'  => ['Nadawca wg rodzaju', count($ks_stored) ? 'ok' : ''],
    'test'     => ['Test wysyłki', ''],
    'status'   => ['Status', $chain ? ($events ? 'warn' : 'ok') : 'bad'],
];
?>
<div class="stabs" role="tablist" aria-label="Sekcje ustawień SMS" id="sms-tabs">
<?php foreach ($tab_state as $k => [$lbl, $st]): ?>
  <button type="button" role="tab" id="tab-<?= $k ?>" data-tab="<?= $k ?>" aria-controls="panel-<?= $k ?>" aria-selected="<?= $TAB === $k ? 'true' : 'false' ?>" tabindex="<?= $TAB === $k ? '0' : '-1' ?>">
    <?php if ($st): ?><span class="dot <?= $st ?>" aria-hidden="true"></span><?php endif; ?><?= h($lbl) ?>
    <?php if ($st === 'warn'): ?><span class="visually-hidden"> — wymaga uwagi</span><?php elseif ($st === 'bad'): ?><span class="visually-hidden"> — problem</span><?php elseif ($st === 'ok'): ?><span class="visually-hidden"> — w porządku</span><?php endif; ?>
  </button>
<?php endforeach; ?>
</div>

<form method="post" id="smsForm">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="_action" value="save">
<input type="hidden" name="_tab" id="smsTab" value="dostawca">
<section class="spanel" id="panel-dostawca" role="tabpanel" aria-labelledby="tab-dostawca"<?= $TAB === 'dostawca' ? '' : ' hidden' ?>>
<div class="card mb-3">
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
<div class="card mb-3">
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
        <input type="radio" name="sms_provider" id="prov_sendly" value="sendly"
               <?= $cfg['sms_provider'] === 'sendly' ? 'checked' : '' ?>
               onchange="refreshProviders()" class="d-none">
        <label for="prov_sendly" class="d-flex align-items-start gap-3 w-100 mb-0">
          <div class="provider-logo logo-sendly"><i class="bi bi-chat-dots"></i></div>
          <div>
            <div class="fw-semibold">Sendly</div>
            <div class="small text-muted">Bramka SMS (sendly.link), API JSON z tokenem.</div>
            <?php if ($sendly_ok): ?>
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
<div class="card mb-3">
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
    w sekcji <a href="?tab=nadawca" data-tab-link="nadawca">„Nadawca wg rodzaju SMS”</a> pod formularzem.
  </div>
</div>
</div>
<div id="slot-main"></div>
<div class="d-flex gap-2 mb-3"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Zapisz konfigurację</button></div>
</section>
<section class="spanel" id="panel-zapas" role="tabpanel" aria-labelledby="tab-zapas"<?= $TAB === 'zapas' ? '' : ' hidden' ?>>
<div class="card mb-3">
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
    <option value="sendly"      <?= $fb==='sendly'      ? 'selected':'' ?>>Sendly</option>
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
<div id="slot-fb"></div>
<div class="slot-empty mb-3" id="fb-empty" hidden>Wybierz dostawcę zapasowego powyżej — pojawi się jego konfiguracja.</div>
<div class="d-flex gap-2 mb-3"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Zapisz konfigurację</button></div>
</section>
<div id="cfg-store" hidden>
<!-- ── Konfiguracja smsapi.pl ─────────────────────────────────────────────── -->
<div class="card mb-3 cfg-sec" id="section-smsapi">
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
<div class="card mb-3 cfg-sec" id="section-serwersms">
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
<div class="card mb-3 cfg-sec" id="section-sendly">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <div class="provider-logo logo-sendly" style="width:24px;height:24px;font-size:.85rem;border-radius:4px">
    <i class="bi bi-chat-dots"></i>
  </div>
  Konfiguracja Sendly
  <span class="badge ms-auto fw-normal role-badge" data-provider="sendly"></span>
</div>
<div class="card-body">
  <div class="alert alert-light border small p-2 mb-3">
    API: <code>POST https://api.sendly.link/api/sms</code> (<code>to</code>, <code>body</code>), autoryzacja tokenem (<code>Authorization: Bearer</code>).
    Token skopiujesz w panelu <a href="https://app.sendly.link" target="_blank" rel="noopener">app.sendly.link</a> po rejestracji.
    Nazwa nadawcy (Sender ID do 11 znaków, weryfikacja w UKE) jest przypisana do usługi w panelu Sendly — nie ustawia się jej tutaj.
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold small" for="sms_sendly_token">Token API</label>
    <input type="password" id="sms_sendly_token" name="sms_sendly_token" class="form-control form-control-sm font-monospace"
           placeholder="<?= $sendly_token ? '(zapisany — zostaw puste by nie zmieniać)' : 'Token API z panelu Sendly' ?>" autocomplete="new-password">
  </div>
  <div class="mb-0">
    <label class="form-label fw-semibold small" for="sms_sendly_api_base">Adres API <span class="text-muted fw-normal">(puste = domyślny)</span></label>
    <input type="text" id="sms_sendly_api_base" name="sms_sendly_api_base" class="form-control form-control-sm font-monospace" style="max-width:420px"
           value="<?= h(sms_setting('sms_sendly_api_base')) ?>" placeholder="https://api.sendly.link">
  </div>
  <?php require_once dirname(__DIR__) . '/modules/sms_delivery/logic/sendlyDelivery.php';
        $sl_stats = sendlyStats(); $sl_alive = sendlyWebhookAlive(); ?>
  <div class="border rounded p-2 mt-3 small">
    <div class="fw-semibold mb-1"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Raporty doręczeń i ponowna wysyłka</div>
    <p class="mb-2">Sendly przyjmuje SMS od razu, a o niedoręczeniu informuje dopiero raportem. Wklej ten adres w panelu Sendly
      (Bramka SMS → Ustawienia usługi → raporty doręczeń, metoda POST): przy <code>ERROR</code> albo braku potwierdzenia po
      <?= (int)(org_setting('sms_sendly_confirm_minutes') ?: 4) ?> min ten sam SMS pójdzie przez innego operatora (smsapi albo zapasowego).</p>
    <label class="visually-hidden" for="sl_webhook">Adres webhooka Sendly</label>
    <input id="sl_webhook" class="form-control form-control-sm font-monospace mb-2" readonly value="<?= h(sendlyWebhookUrl()) ?>" onclick="this.select()">
    <div><?= $sl_alive ? '<span class="badge text-bg-success">webhook działa</span> (ostatni raport: ' . h((string)org_setting('sms_sendly_webhook_last')) . ')'
                       : '<span class="badge text-bg-warning">webhook jeszcze nie przysłał raportu</span> — do tego czasu działa tylko ponowna wysyłka po raporcie ERROR' ?></div>
    <?php if ($sl_stats): ?>
    <div class="mt-1 text-muted">Ostatnie 7 dni: <?= h(implode(' · ', array_map(fn($k, $v) => ['sent' => 'czeka na raport', 'delivered' => 'doręczone', 'error' => 'błąd', 'resent' => 'wysłane ponownie innym operatorem', 'resend_failed' => 'ponowna wysyłka nieudana', 'expired' => 'bez raportu (wygaszone)'][$k] . ': ' . $v, array_keys($sl_stats), $sl_stats))) ?></div>
    <?php endif; ?>
  </div>
</div>
</div>

<div class="card mb-3 cfg-sec" id="section-twilio">
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
<div class="card mb-3 cfg-sec" id="section-httprequest">
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
</div>
</form>
<section class="spanel" id="panel-nadawca" role="tabpanel" aria-labelledby="tab-nadawca"<?= $TAB === 'nadawca' ? '' : ' hidden' ?>>
<?php include dirname(__DIR__) . '/modules/sms_senders/views/kindSenders.php'; ?>
</section>
<section class="spanel" id="panel-test" role="tabpanel" aria-labelledby="tab-test"<?= $TAB === 'test' ? '' : ' hidden' ?>>
<div class="card mb-3">
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
</section>
<section class="spanel" id="panel-status" role="tabpanel" aria-labelledby="tab-status"<?= $TAB === 'status' ? '' : ' hidden' ?>>
<div class="row g-3"><div class="col-lg-6">
<!-- Status -->
<div class="card mb-3">
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
    <span class="fw-semibold"><a href="?tab=nadawca" data-tab-link="nadawca"><?= (int)count($ks_stored) ?></a> z <?= (int)count(SmsSenders::kinds()) ?></span>
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
<div class="card mb-3">
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

</div><div class="col-lg-6"><!-- Jak to działa -->
<div class="card mb-3">
<div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> Jak to działa</div>
<div class="card-body small">
  <p class="mb-2">Wolontariusz wpisuje numer telefonu → system wysyła 6-cyfrowy kod → wolontariusz wpisuje kod i jest zalogowany.</p>
  <p class="mb-1 fw-semibold">Wymagania:</p>
  <ul class="ps-3 mb-0">
    <li>Numer telefonu w umowie wolontariackiej</li>
    <li>Konto lokalne z tym samym e-mailem co w umowie</li>
    <li>Skonfigurowany jeden z dostawców SMS (zakładka „Dostawca”)</li>
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
<div class="card mt-3">
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
</div></div>
</section>
</main>

<script>
// ── Zakładki (WAI-ARIA): klik, strzałki, Home/End; adres ?tab= zapamiętuje wybór ──
(function () {
  var tabs = Array.prototype.slice.call(document.querySelectorAll('#sms-tabs [role="tab"]'));
  function show(name, focus) {
    tabs.forEach(function (t) {
      var on = t.dataset.tab === name;
      t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1;
      var p = document.getElementById('panel-' + t.dataset.tab); if (p) p.hidden = !on;
      if (on && focus) t.focus();
    });
    var h = document.getElementById('smsTab'); if (h && (name === 'dostawca' || name === 'zapas')) h.value = name;
    try { var u = new URL(location.href); u.searchParams.set('tab', name); history.replaceState(null, '', u); } catch (e) {}
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { show(t.dataset.tab, false); });
    t.addEventListener('keydown', function (e) {
      var j = e.key === 'ArrowRight' ? (i + 1) % tabs.length : e.key === 'ArrowLeft' ? (i - 1 + tabs.length) % tabs.length : e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : -1;
      if (j < 0) return; e.preventDefault(); show(tabs[j].dataset.tab, true);
    });
  });
  document.querySelectorAll('[data-tab-link]').forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); show(a.dataset.tabLink, true); window.scrollTo(0, 0); });
  });
  var cur = tabs.filter(function (t) { return t.getAttribute('aria-selected') === 'true'; })[0];
  var hid = document.getElementById('smsTab'); if (hid && cur && (cur.dataset.tab === 'dostawca' || cur.dataset.tab === 'zapas')) hid.value = cur.dataset.tab;
})();

// ── Dostawcy: konfiguracja głównego ląduje w zakładce „Dostawca”, zapasowego w „Zapas” ──
function refreshProviders() {
    var main = (document.querySelector('[name="sms_provider"]:checked') || {}).value || '';
    var fbSel = document.getElementById('sms_fallback_provider');
    var fb = fbSel ? fbSel.value : '';
    var names = {smsapi: 'smsapi.pl', serwersms: 'SerwerSMS.pl', sendly: 'Sendly', twilio: 'Twilio', httprequest: 'HTTP Request'};
    if (fbSel) {
        Array.prototype.forEach.call(fbSel.options, function (o) { o.disabled = (o.value !== '' && o.value === main); });
        if (fb === main) { fbSel.value = ''; fb = ''; }
    }
    var store = document.getElementById('cfg-store'), slotMain = document.getElementById('slot-main'), slotFb = document.getElementById('slot-fb');
    ['smsapi', 'serwersms', 'sendly', 'twilio', 'httprequest'].forEach(function (p) {
        var sec = document.getElementById('section-' + p);
        if (!sec) return;
        var dest = p === main ? slotMain : (p === fb ? slotFb : store);
        if (dest && sec.parentNode !== dest) dest.appendChild(sec);
    });
    var empty = document.getElementById('fb-empty'); if (empty) empty.hidden = !!fb;
    document.querySelectorAll('.role-badge').forEach(function (b) {
        var p = b.getAttribute('data-provider');
        var role = p === main ? 'Główny' : (p === fb ? 'Zapasowy' : '');
        b.textContent = role;
        b.className = 'badge ms-auto fw-normal role-badge ' + (p === main ? 'bg-primary' : 'bg-info-subtle text-info-emphasis border border-info-subtle');
        b.style.display = role ? '' : 'none';
    });
    var note = document.getElementById('fb-note');
    if (note) note.textContent = fb ? 'Zapasowy: ' + names[fb] + ' — jego konfiguracja jest poniżej.' : 'Bez fallbacku: porażka głównego dostawcy oznacza niewysłany SMS.';
}
document.addEventListener('DOMContentLoaded', refreshProviders);

// ── Prefiks +48 dla pól .phone-48 (jak w stopce SZO) ──
(function () {
  function strip48(v) { var d = v.replace(/\D/g, ''); if (d.length === 11 && d.indexOf('48') === 0) return d.slice(2); return d.length === 9 ? d : v; }
  document.querySelectorAll('.phone-48').forEach(function (input) {
    input.value = strip48(input.value);
    var frm = input.form || input.closest('form');
    if (frm) frm.addEventListener('submit', function () { var d = input.value.replace(/\D/g, ''); if (d.length === 9) input.value = '48' + d; });
  });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
