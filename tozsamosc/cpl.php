<?php
/**
 * tozsamosc/cpl.php — „CPL — Centralny Portal Logowania” (okno logowania w układzie TETA Web).
 *
 * Wejście przed logowaniem do SZO i Systemu Tożsamości. Pola:
 *  - Nazwa użytkownika + Hasło  → POST do /auth/login.php (anty-brute, „tylko Office”, 2FA, bramka WebAuthn),
 *  - Logowanie domenowe          → logowanie kontem Microsoft 365 (SSO), bez hasła w tym oknie — od 13.11.2026 TYLKO administratorzy,
 *                                  domyślnie odznaczone (account_is_office_only; pozostali dostają komunikat w auth/microsoft.php),
 *  - Zaloguj przez logowanie jednokrotne → Logto (OpenID Connect, /sso/logowanie, modules/sso_logto/lib/LogtoOidc.php),
 *  - Profil / Rola               → moduł, do którego trafisz po zalogowaniu (adres docelowy, nie nadaje uprawnień —
 *                                  dostęp nadal rozstrzyga sam moduł).
 * Błędy wracają tutaj przez pole „from” (?err=). Rzadsze metody (SMS, kod, certyfikat) — /auth/login.php?pelne=1.
 *
 * WCAG 2.1 AA: etykiety pól, błąd w role=alert, widoczny fokus, obsługa klawiaturą, kontrast ≥ 4,5:1.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

auth_start();

$APP  = rtrim(APP_URL, '/');
$SELF = $APP . '/tozsamosc/cpl.php';
$e    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Profile / role: [etykieta, kod, adres docelowy]
$profiles = [
    // [etykieta, kod, adres docelowy, wymaga VPN]
    'szo'       => ['SZO — PANEL STARTOWY',        'SZO_ALL',      $APP . '/panel/launcher.php', false],
    'tozsamosc' => ['SYSTEM TOŻSAMOŚCI',           'TOZSAMOSC',    $APP . '/tozsamosc/index.php', false],
    'dydaktyk'  => ['TI — PANEL DYDAKTYKA',        'TI_DYD',       $APP . '/karty30/ti/dydaktyk/index.php', true],
    'crm'       => ['CRM — KONTAKTY I KAMPANIE',   'CRM_ALL',      $APP . '/crm/index.php', true],
    'kursant'   => ['TI — PANEL KURSANTA',         'TI_KURSANT',   $APP . '/karty30/ti/kursant/index.php', false],
    'wspolpr'   => ['PANEL WSPÓŁPRACOWNIKA — ZLECENIOBIORCA', 'ZLECENIE', $APP . '/panel/index.php', false],
    'ezd'       => ['EZD — ELEKTRONICZNE ZARZĄDZANIE DOKUMENTACJĄ', 'EZD_ALL', $APP . '/ezd/index.php', false],
    'helpdesk'  => ['HELPDESK IT',                 'HD_ALL',       $APP . '/helpdesk/index.php', false],
    'it'        => ['IT SZO — ADMINISTRACJA',      'IT_ADM',       $APP . '/it/index.php', false],
];

// Wskazany adres (?redirect=) w obrębie aplikacji: dopasuj do profilu albo dodaj jako osobną pozycję.
$raw = (string)($_GET['redirect'] ?? '');
$want = ($raw !== '' && str_starts_with($raw, $APP . '/')) ? $raw : '';
$sel = 'szo';
if ($want !== '') {
    $found = null;
    foreach ($profiles as $k => $p) if ($p[2] === $want) { $found = $k; break; }
    if ($found === null) { $profiles = ['wskazany' => ['WSKAZANA STRONA', 'REDIRECT', $want, false]] + $profiles; $found = 'wskazany'; }
    $sel = $found;
} elseif (str_contains((string)($_SERVER['HTTP_REFERER'] ?? ''), '/tozsamosc/')) {
    $sel = 'tozsamosc';
}

// Już zalogowany → od razu do wybranego profilu.
if (current_user()) { header('Location: ' . $profiles[$sel][2]); exit; }

$err   = trim((string)($_GET['err'] ?? ''));
$token = csrf_token();
$ms_ok = function_exists('ms_login_available') && ms_login_available() && function_exists('ms_auth_url');
$lt_ok = false;   // logowanie jednokrotne (Logto) — przycisk pod „Zaloguj”, cel wg wybranego profilu
try { require_once dirname(__DIR__) . '/modules/sso_logto/lib/LogtoOidc.php'; $lt_ok = LogtoOidc::enabled(); } catch (\Throwable $ex) {}
$urls  = [];   // per profil: formularz (hasło) i Microsoft (domenowe)
// ms_auth_url() zapisuje w sesji nowy „state” przy każdym wywołaniu — adres Microsoft powstaje więc dopiero po kliknięciu (?ms=profil).
if (isset($_GET['ms']) && $ms_ok && isset($profiles[(string)$_GET['ms']])) {
    header('Location: ' . ms_auth_url($profiles[(string)$_GET['ms']][2])); exit;
}
$vpnMap = [];
foreach ($profiles as $k => $p) {
    $vpnMap[$k] = (bool)$p[3];
    $urls[$k] = [
        'form' => $APP . '/auth/login.php?redirect=' . urlencode($p[2]),
        'ms'   => $ms_ok ? $SELF . '?ms=' . urlencode($k) . ($want !== '' ? '&redirect=' . urlencode($want) : '') : '',
        'sso'  => $lt_ok ? $APP . '/sso/logowanie?redirect=' . urlencode($p[2]) : '',
    ];
}
$from_url = $SELF . ($want !== '' ? '?redirect=' . urlencode($want) : '');

// Siatka kropek w nagłówku — stała (ziarno), żeby wygląd nie „migał” między odświeżeniami.
mt_srand(2610);
$dots = '';
$pal  = ['#6b2c91', '#0079c2', '#2e9b3f'];
for ($cx = 0; $cx < 24; $cx++) {
    for ($cy = 0; $cy < 9; $cy++) {
        if (mt_rand(0, 99) < 38 - $cy * 2) continue;
        $c = mt_rand(0, 99) < 22 ? $pal[mt_rand(0, 2)] : '#d4d4d8';
        $dots .= '<circle cx="' . (12 + $cx * 19) . '" cy="' . (10 + $cy * 15) . '" r="3" fill="' . $c . '"/>';
    }
}
header('Cache-Control: no-store');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CPL — Centralny Portal Logowania · Fundacja FEER</title>
<style>
  :root{--ink:#1f2937;--ink2:#4b5563;--brand:#2e2a7a;--blue:#0a6fb8;--blue-d:#075f9e;--green:#2e9b3f;--line:#c9ced6;--bg:#e9ebee}
  *{box-sizing:border-box}
  html,body{margin:0}
  body{background:var(--bg);color:var(--ink);font:14px/1.4 Tahoma,"Segoe UI",Verdana,Arial,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
  .cpl{width:100%;max-width:460px;background:#fff;box-shadow:0 0 0 1px #d6d9de;overflow:hidden}
  .cpl-top{position:relative;height:170px;background:linear-gradient(180deg,#fff 0%,#f4f5f6 70%,#eceeef 100%)}
  .cpl-top svg{position:absolute;inset:0;width:100%;height:100%}
  .cpl-logo{position:absolute;left:16px;bottom:22px;font-size:30px;font-weight:800;letter-spacing:-.5px;line-height:1}
  .cpl-logo b{color:var(--brand)}
  .cpl-logo span{background:linear-gradient(90deg,#0a7fd1,#2e9b3f);-webkit-background-clip:text;background-clip:text;color:transparent}
  .cpl-sub{position:absolute;left:16px;bottom:6px;font-size:11px;font-weight:700;color:var(--brand)}
  .cpl-body{background:#eceeef;padding:22px 26px 14px}
  .row{display:grid;grid-template-columns:120px 1fr;gap:6px 10px;align-items:center;margin-bottom:10px}
  .row>label{text-align:right;font-weight:700;color:#374151}
  .row.sub{margin-top:-4px}
  input[type=text],input[type=password],select{width:100%;height:30px;border:1px solid #8d95a1;background:#fff;padding:3px 7px;font:inherit;color:var(--ink);border-radius:2px}
  input:focus,select:focus,button:focus-visible,a:focus-visible,summary:focus-visible{outline:3px solid #f59e0b;outline-offset:1px}
  .chk{display:flex;align-items:center;gap:6px}
  .chk input{width:16px;height:16px;margin:0}
  .link{font-size:12px;color:#4b5563;text-align:right}
  .link a{color:#374151}
  .btn{display:block;margin:14px auto 6px;min-width:112px;height:34px;border:0;background:var(--blue);color:#fff;font:700 13px Tahoma,"Segoe UI",sans-serif;cursor:pointer;border-radius:2px;padding:0 18px}
  .btn:hover{background:var(--blue-d)}
  .btn--sso{width:max-content;max-width:100%;line-height:34px;text-align:center;text-decoration:none;background:#fff;color:var(--blue-d);border:1px solid var(--blue-d)}
  .btn--sso:hover{background:#e6f1fb;color:var(--blue-d)}
  .alert{margin:0 0 12px;padding:8px 10px;background:#fde8e8;border-left:5px solid #b42318;color:#7a1710;font-size:13px}
  .foot{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 10px 10px;background:#eceeef;font-size:12px;color:#374151;flex-wrap:wrap}
  .foot .lang{display:flex;align-items:center;gap:6px;font-weight:700}
  .more{padding:8px 26px 14px;background:#eceeef;font-size:12px}
  .more a{color:#1d4ed8;margin-right:12px}
  .vpn{margin:0;padding:9px 26px 11px;background:#fff7e0;border-top:1px solid #e6d9a8;border-bottom:1px solid #e6d9a8;color:#5c4400;font-size:12.5px}
  .vpn a{color:#7a4a00;font-weight:700}
  .hint{font-size:12px;color:var(--ink2);margin:0 0 10px;padding-left:130px}
  .vh{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
  [hidden]{display:none!important}
  @media(max-width:420px){.row{grid-template-columns:1fr}.row>label{text-align:left}.hint{padding-left:0}}
</style>
</head>
<body>
<main class="cpl" id="cpl" aria-labelledby="cpl-h">
  <div class="cpl-top">
    <svg viewBox="0 0 460 170" preserveAspectRatio="xMidYMin slice" aria-hidden="true" focusable="false"><?= $dots ?></svg>
    <div class="cpl-logo" id="cpl-h"><b>CPL</b> <span>Portal</span></div>
    <div class="cpl-sub">Centralny Portal Logowania · Fundacja FEER</div>
  </div>

  <form class="cpl-body" id="cpl-form" method="post" action="<?= $e($urls[$sel]['form']) ?>" novalidate autocomplete="on">
    <input type="hidden" name="_csrf" value="<?= $e($token) ?>">
    <input type="hidden" name="_method" value="local">
    <input type="hidden" name="from" value="<?= $e($from_url) ?>">

    <?php if ($err !== ''): ?>
    <div class="alert" role="alert" id="cpl-err"><?= $e($err) ?></div>
    <?php endif; ?>

    <div class="row" id="row-user">
      <label for="cpl-user">Nazwa użytkownika:</label>
      <input type="text" id="cpl-user" name="email" autocomplete="username" inputmode="email" autocapitalize="off" spellcheck="false"
             required <?= $err !== '' ? 'aria-invalid="true" aria-describedby="cpl-err"' : '' ?> autofocus>
    </div>
    <div class="row" id="row-pass">
      <label for="cpl-pass">Hasło:</label>
      <input type="password" id="cpl-pass" name="password" autocomplete="current-password" required <?= $err !== '' ? 'aria-invalid="true"' : '' ?>>
    </div>

    <div class="row sub">
      <span></span>
      <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center">
        <?php if ($ms_ok): ?>
        <label class="chk"><input type="checkbox" id="cpl-domain" aria-describedby="cpl-domain-h"> <span>Logowanie domenowe (administratorzy)</span></label>
        <?php else: ?><span></span><?php endif; ?>
        <span class="link"><a href="<?= $e($APP . '/user/verify_reset.php') ?>">Zapomniałem hasła</a></span>
      </div>
    </div>
    <?php if ($ms_ok): ?>
    <p class="hint" id="cpl-domain-h">Logowanie domenowe: Microsoft 365 — tylko konta <strong>administratorów</strong> @feer.org.pl. Pozostali logują się hasłem<?= $lt_ok ? ' albo logowaniem jednokrotnym' : '' ?>.</p>
    <?php endif; ?>

    <div class="row">
      <label for="cpl-profile">Profil / Rola:</label>
      <select id="cpl-profile" name="profil" aria-describedby="cpl-profile-h">
        <?php foreach ($profiles as $k => $p): ?>
        <option value="<?= $e($k) ?>"<?= $k === $sel ? ' selected' : '' ?>><?= $e($p[0]) ?> / <?= $e($p[1]) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <p class="hint" id="cpl-profile-h">Profil wskazuje, dokąd trafisz po zalogowaniu. Zakres dostępu nadają uprawnienia Twojego konta.</p>

    <button type="submit" class="btn" id="cpl-go">Zaloguj</button>
    <?php if ($lt_ok): ?>
    <a class="btn btn--sso" id="cpl-sso" href="<?= $e($urls[$sel]['sso']) ?>">Zaloguj przez logowanie jednokrotne</a>
    <p class="hint" style="padding-left:0;text-align:center">Jedno konto Fundacji (Logto) — to samo co do chmury plików.</p>
    <?php endif; ?>
  </form>

  <p class="vpn" id="cpl-vpn" role="note"<?= $profiles[$sel][3] ? '' : ' hidden' ?>><strong>Niektóre usługi są dostępne wyłącznie z VPN.</strong>
    Wybrany profil wymaga połączenia z VPN Fundacji — jeśli moduł nie otworzy się po zalogowaniu, połącz się z VPN i spróbuj ponownie. <a href="<?= $e($APP . '/vpn/') ?>">Dowiedz się więcej o VPN</a></p>

  <div class="foot">
    <label class="chk"><input type="checkbox" id="cpl-remember"> <span>Zapamiętaj profil i sposób logowania</span></label>
    <span class="lang" title="Interfejs dostępny w języku polskim"><span aria-hidden="true">🇵🇱</span> polski</span>
  </div>
</main>

<script>
(function () {
  var URLS = <?= json_encode($urls, JSON_UNESCAPED_SLASHES) ?>;
  var VPN = <?= json_encode($vpnMap) ?>;
  var HAS_MS = <?= $ms_ok ? 'true' : 'false' ?>;
  var form = document.getElementById('cpl-form'), prof = document.getElementById('cpl-profile'),
      dom = document.getElementById('cpl-domain'), user = document.getElementById('cpl-user'),
      pass = document.getElementById('cpl-pass'), rowUser = document.getElementById('row-user'),
      rowPass = document.getElementById('row-pass'),
      rem = document.getElementById('cpl-remember'), hasErr = <?= $err !== '' ? 'true' : 'false' ?>;
  var KEY = 'cpl.prefs.v2';   // v2 (13.11.2026): Microsoft 365 tylko dla administratorów — dawne „Logowanie domenowe” nie wraca samo
  function load() { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } }
  function save(v) { try { if (v) localStorage.setItem(KEY, JSON.stringify(v)); else localStorage.removeItem(KEY); } catch (e) {} }

  function apply() {
    var k = prof.value, domain = !!(dom && dom.checked);
    form.action = URLS[k].form;
    var vn = document.getElementById('cpl-vpn'); if (vn) vn.hidden = !VPN[k];
    var so = document.getElementById('cpl-sso'); if (so && URLS[k].sso) so.href = URLS[k].sso;
    rowPass.hidden = domain; rowUser.hidden = domain;
    pass.disabled = domain; user.disabled = domain;
    if (!domain) setTimeout(function () { try { user.focus(); } catch (e) {} }, 0);
  }
  prof.addEventListener('change', apply);
  if (dom) dom.addEventListener('change', apply);

  form.addEventListener('submit', function (ev) {
    var k = prof.value, domain = !!(dom && dom.checked);
    save(rem.checked ? { profil: k, domain: domain } : null);
    if (domain && HAS_MS && URLS[k].ms) { ev.preventDefault(); window.location.href = URLS[k].ms; return; }
    if (!user.value.trim() || !pass.value) {
      ev.preventDefault();
      (!user.value.trim() ? user : pass).focus();
    }
  });

  var p = load();
  if (p) {
    rem.checked = true;
    if (p.profil && URLS[p.profil] && !<?= $want !== '' ? 'true' : 'false' ?>) prof.value = p.profil;
    if (dom) dom.checked = !hasErr && !!p.domain;   // zapamiętany wybór wygrywa z domyślnym
  }
  apply();
})();
</script>
</body>
</html>
