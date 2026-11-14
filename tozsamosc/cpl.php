<?php
/**
 * tozsamosc/cpl.php — „CPL — Centralny Portal Logowania” (pełne okno logowania z wyborem profilu).
 * Wygląd: jasna karta wspólna dla ekranów wejścia (includes/auth_screen.php, tryb 'light', inc/loginShell/authUi.php).
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
 * WCAG 2.1 AA: etykiety pól, błąd w role=alert przy polach (także brak loginu/hasła przed wysłaniem), widoczny fokus,
 * obsługa klawiaturą (zmiana profilu nie przenosi fokusu), kontrast ≥ 4,5:1.
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

header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/includes/auth_screen.php';
auth_screen_head([
    'title'           => 'CPL — Centralny Portal Logowania',
    'light'           => true,
    'width'           => 520,
    'main_id'         => 'cpl',
    'account_buttons' => [],
    'head_html'       => '<style>'
        . '.cpl-opts{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0 1rem;margin:-.4rem 0 .9rem}'
        . '.cpl-check{display:flex;align-items:center;gap:.6rem;min-height:44px}'
        . '.cpl-check input{flex-shrink:0;width:1.2rem;height:1.2rem;margin:0}'
        . '.cpl-check label{font-size:.95rem;cursor:pointer}'
        . '.cpl-opts>a{display:inline-flex;align-items:center;min-height:44px;font-weight:600}'
        . '#cpl-domain-h{margin:-.6rem 0 1.15rem}'
        . '#cpl-vpn{margin:-.35rem 0 1.25rem}'
        . '.cpl-sso-h{text-align:center}'
        . '.cpl-foot{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0 1rem;margin:1.5rem 0 0;padding-top:.75rem;border-top:1px solid var(--au-line)}'
        . '.cpl-lang{font-size:.9rem;font-weight:600;color:var(--au-muted)}'
        . '</style>',
]);
$errIds = $err !== '' ? ' cpl-err' : '';
?>
<p class="au-kicker">CPL</p>
<h1 class="ks-h1" id="cpl-h">Centralny Portal Logowania</h1>
<p class="ks-lead">Jedno logowanie do SZO i modułów Fundacji. Wybierz profil, a po zalogowaniu od razu tam trafisz.</p>

<form id="cpl-form" method="post" action="<?= $e($urls[$sel]['form']) ?>" novalidate autocomplete="on" aria-labelledby="cpl-h">
  <input type="hidden" name="_csrf" value="<?= $e($token) ?>">
  <input type="hidden" name="_method" value="local">
  <input type="hidden" name="from" value="<?= $e($from_url) ?>">

  <?php if ($err !== ''): ?>
  <div class="l-alert l-alert-danger" role="alert" id="cpl-err"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><span id="login-error-text"><?= $e($err) ?></span></div>
  <?php endif; ?>

  <div class="ks-field" id="row-user">
    <label for="cpl-user">Nazwa użytkownika</label>
    <input type="text" class="form-control" id="cpl-user" name="email" autocomplete="username" inputmode="email" autocapitalize="off" spellcheck="false"
           required aria-describedby="cpl-user-h cpl-user-err<?= $errIds ?>"<?= $err !== '' ? ' aria-invalid="true"' : '' ?> autofocus>
    <p class="ks-fieldhint" id="cpl-user-h">Adres e-mail, którym logujesz się do SZO.</p>
    <p class="au-ferr" id="cpl-user-err" role="alert"></p>
  </div>
  <div class="ks-field" id="row-pass">
    <label for="cpl-pass">Hasło</label>
    <div class="pass-wrap">
      <input type="password" class="form-control" id="cpl-pass" name="password" autocomplete="current-password"
             required aria-describedby="cpl-pass-err<?= $errIds ?>"<?= $err !== '' ? ' aria-invalid="true"' : '' ?>>
      <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false" aria-controls="cpl-pass" onclick="togglePass('cpl-pass', this)"><i class="bi bi-eye" aria-hidden="true"></i></button>
    </div>
    <p class="au-ferr" id="cpl-pass-err" role="alert"></p>
  </div>

  <div class="cpl-opts">
    <?php if ($ms_ok): ?>
    <div class="cpl-check"><input type="checkbox" id="cpl-domain" aria-describedby="cpl-domain-h"><label for="cpl-domain">Logowanie domenowe (administratorzy)</label></div>
    <?php else: ?><span></span><?php endif; ?>
    <a href="<?= $e($APP . '/user/verify_reset.php') ?>">Zapomniałem hasła</a>
  </div>
  <?php if ($ms_ok): ?>
  <p class="ks-fieldhint" id="cpl-domain-h">Logowanie domenowe to Microsoft 365, tylko dla kont <strong>administratorów</strong> @feer.org.pl. Pozostali logują się hasłem<?= $lt_ok ? ' albo logowaniem jednokrotnym' : '' ?>.</p>
  <?php endif; ?>

  <div class="ks-field">
    <label for="cpl-profile">Profil / Rola</label>
    <select class="form-control" id="cpl-profile" name="profil" aria-describedby="cpl-profile-h">
      <?php foreach ($profiles as $k => $p): ?>
      <option value="<?= $e($k) ?>"<?= $k === $sel ? ' selected' : '' ?>><?= $e($p[0]) ?> / <?= $e($p[1]) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="ks-fieldhint" id="cpl-profile-h">Profil wskazuje, dokąd trafisz po zalogowaniu. Zakres dostępu nadają uprawnienia Twojego konta.</p>
  </div>

  <div class="l-alert l-alert-warn" id="cpl-vpn" role="note"<?= $profiles[$sel][3] ? '' : ' hidden' ?>>
    <i class="bi bi-shield-lock" aria-hidden="true"></i>
    <span><strong>Niektóre usługi są dostępne wyłącznie z VPN.</strong>
    Wybrany profil wymaga połączenia z VPN Fundacji. Jeśli moduł nie otworzy się po zalogowaniu, połącz się z VPN i spróbuj ponownie.
    <a href="<?= $e($APP . '/vpn/') ?>">Dowiedz się więcej o VPN</a></span>
  </div>

  <button type="submit" class="ks-btn ks-btn--primary" id="cpl-go">Zaloguj</button>
  <?php if ($lt_ok): ?>
  <div class="ks-or">albo</div>
  <a class="ks-btn ks-btn--ghost" id="cpl-sso" href="<?= $e($urls[$sel]['sso']) ?>"><i class="bi bi-key" aria-hidden="true"></i> Zaloguj przez logowanie jednokrotne</a>
  <p class="ks-fieldhint cpl-sso-h">Jedno konto Fundacji (Logto), to samo co do chmury plików.</p>
  <?php endif; ?>
</form>

<div class="cpl-foot">
  <div class="cpl-check"><input type="checkbox" id="cpl-remember"><label for="cpl-remember">Zapamiętaj profil i sposób logowania</label></div>
  <span class="cpl-lang"><span aria-hidden="true">🇵🇱</span> polski<span class="sr"> (interfejs w języku polskim)</span></span>
</div>
<?php
$cpl_js = '<script>
(function () {
  var URLS = ' . json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';
  var VPN = ' . json_encode($vpnMap) . ';
  var HAS_MS = ' . ($ms_ok ? 'true' : 'false') . ';
  var WANT = ' . ($want !== '' ? 'true' : 'false') . ';
  var form = document.getElementById("cpl-form"), prof = document.getElementById("cpl-profile"),
      dom = document.getElementById("cpl-domain"), user = document.getElementById("cpl-user"),
      pass = document.getElementById("cpl-pass"), rowUser = document.getElementById("row-user"),
      rowPass = document.getElementById("row-pass"), rem = document.getElementById("cpl-remember"),
      hasErr = ' . ($err !== '' ? 'true' : 'false') . ';
  var KEY = "cpl.prefs.v2";   // v2 (13.11.2026): Microsoft 365 tylko dla administratorów; dawne „Logowanie domenowe” nie wraca samo
  function load() { try { return JSON.parse(localStorage.getItem(KEY) || "null"); } catch (e) { return null; } }
  function save(v) { try { if (v) localStorage.setItem(KEY, JSON.stringify(v)); else localStorage.removeItem(KEY); } catch (e) {} }

  // Błąd przy polu: tekst w role=alert pod polem, aria-invalid na polu.
  function fieldErr(inp, msg) {
    var box = document.getElementById(inp.id + "-err");
    if (box) box.innerHTML = msg ? "<i class=\"bi bi-exclamation-circle-fill\" aria-hidden=\"true\"></i><span></span>" : "";
    if (box && msg) box.lastChild.textContent = msg;
    if (msg) inp.setAttribute("aria-invalid", "true"); else if (!hasErr) inp.removeAttribute("aria-invalid");
  }
  // Zmiana profilu albo sposobu logowania nie przenosi fokusu (WCAG 3.2.2).
  function apply() {
    var k = prof.value, domain = !!(dom && dom.checked);
    form.action = URLS[k].form;
    var vn = document.getElementById("cpl-vpn"); if (vn) vn.hidden = !VPN[k];
    var so = document.getElementById("cpl-sso"); if (so && URLS[k].sso) so.href = URLS[k].sso;
    rowPass.hidden = domain; rowUser.hidden = domain;
    pass.disabled = domain; user.disabled = domain;
  }
  prof.addEventListener("change", apply);
  if (dom) dom.addEventListener("change", apply);
  [user, pass].forEach(function (i) { i.addEventListener("input", function () { if (i.value) fieldErr(i, ""); }); });

  form.addEventListener("submit", function (ev) {
    var k = prof.value, domain = !!(dom && dom.checked);
    save(rem.checked ? { profil: k, domain: domain } : null);
    if (domain && HAS_MS && URLS[k].ms) { ev.preventDefault(); window.location.href = URLS[k].ms; return; }
    var noUser = !user.value.trim(), noPass = !pass.value;
    fieldErr(user, noUser ? "Wpisz nazwę użytkownika (adres e-mail)." : "");
    fieldErr(pass, noPass ? "Wpisz hasło." : "");
    if (noUser || noPass) { ev.preventDefault(); (noUser ? user : pass).focus(); }
  });

  var p = load();
  if (p) {
    rem.checked = true;
    if (p.profil && URLS[p.profil] && !WANT) prof.value = p.profil;
    if (dom) dom.checked = !hasErr && !!p.domain;   // zapamiętany wybór wygrywa z domyślnym
  }
  apply();
})();
</script>';

auth_screen_foot([
    'no_new_tiles' => true,
    'extra_js'     => $cpl_js,
]);
