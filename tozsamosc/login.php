<?php
/**
 * tozsamosc/login.php — dedykowane okno logowania do Systemu Tożsamości (dawny wygląd pod ?stary=1).
 * Wygląd: jasna karta wspólna dla ekranów wejścia (includes/auth_screen.php, tryb 'light', przebudowa 2026-11-14).
 *
 * Kolumna obok karty wyjaśnia, czym jest System Tożsamości i SSO. Prawa: logowanie jednokrotne (Logto), Microsoft 365 (tylko administratorzy) albo e-mail i hasło.
 * Formularz NIE ma własnej kryptografii — wysyła dane do /auth/login.php (anty-brute, „tylko Office”, 2FA,
 * bramka WebAuthn, wymuszona zmiana hasła). Pole „from” sprawia, że po błędzie wracamy tutaj z ?err=.
 * Rzadsze metody (kod jednorazowy, SMS, certyfikat X.509) — przez pełny ekran /auth/login.php (?pelne=1).
 *
 * WCAG 2.1 AA: etykiety pól, błąd w role=alert przy polach, widoczny fokus, obsługa klawiaturą, cele ≥ 44 px.
 */
// CPL — Centralny Portal Logowania (układ TETA Web) jest teraz domyślnym oknem logowania Tożsamości.
// Poprzedni wygląd (METRO) zostaje pod ?stary=1.
if (!isset($_GET['stary'])) { require __DIR__ . '/cpl.php'; exit; }
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth_screen.php';

auth_start();

$APP      = rtrim(APP_URL, '/');
$SELF     = $APP . '/tozsamosc/login.php';
$index    = $APP . '/tozsamosc/index.php';
// Dokąd po zalogowaniu — tylko adresy w obrębie Tożsamości.
$raw      = (string)($_GET['redirect'] ?? '');
$redirect = ($raw !== '' && str_starts_with($raw, $APP . '/tozsamosc/')) ? $raw : $index;

// Już zalogowany → prosto do portalu tożsamości.
if (current_user()) { header('Location: ' . $redirect); exit; }

$err      = trim((string)($_GET['err'] ?? ''));
$token    = csrf_token();
$self_url = $SELF . ($redirect !== $index ? '?redirect=' . urlencode($redirect) : '');
$action   = $APP . '/auth/login.php?redirect=' . urlencode($redirect);
$full_url = $APP . '/auth/login.php?pelne=1&redirect=' . urlencode($redirect);   // kod / SMS / certyfikat
$ms_ok    = function_exists('ms_login_available') && ms_login_available();
$ms_url   = ($ms_ok && function_exists('ms_auth_url')) ? ms_auth_url($redirect) : '';
// Logowanie jednokrotne (Logto) — od 13.11.2026 główna metoda; Microsoft 365 tylko dla administratorów.
$lt_ok    = false;
try { require_once dirname(__DIR__) . '/modules/sso_logto/lib/LogtoOidc.php'; $lt_ok = LogtoOidc::enabled(); } catch (\Throwable $ex) {}
$lt_url   = $APP . '/sso/logowanie?redirect=' . urlencode($redirect);
$sso_url  = $APP . '/karty30/ti/sso.php';
$e        = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Lewa kolumna: czym jest System Tożsamości (krótko, zwykłym językiem)
$side = '<aside class="tzt-aside" aria-labelledby="tzt-aside-h"><h2 id="tzt-aside-h" class="tzt-aside-h">Czym jest System Tożsamości?</h2>'
      . '<p class="tzt-lead">Jedno konto i jedno logowanie do systemów Fundacji FEER.</p>'
      . '<ul class="tzt-lista">'
      . '<li><i class="bi bi-key" aria-hidden="true"></i><span><strong>Jedno logowanie (SSO).</strong> Logujesz się raz, a SZO, panel TI, CRM, poczta i połączone aplikacje otwierają się bez ponownego wpisywania hasła.</span></li>'
      . '<li><i class="bi bi-shield-lock" aria-hidden="true"></i><span><strong>Twoje bezpieczeństwo.</strong> Tu zmienisz hasło, telefon do kodów SMS, logowanie dwuetapowe i klucze bezpieczeństwa (passkey).</span></li>'
      . '<li><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i><span><strong>Dostępy i role.</strong> Widzisz, do których modułów i aplikacji masz dostęp i w jakiej roli.</span></li>'
      . '<li><i class="bi bi-file-earmark-lock" aria-hidden="true"></i><span><strong>Twoje dane (RODO).</strong> Sprawdzisz, co dzieje się z Twoimi danymi, i pobierzesz ich kopię.</span></li>'
      . '</ul>'
      . '<p class="tzt-uwaga"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>'
      . '<span>Hasło wpisuj tylko na stronach <strong>' . $e(parse_url($APP, PHP_URL_HOST) ?: 'szo.feer.org.pl') . '</strong>. '
      . 'Fundacja nigdy nie prosi o hasło ani kod SMS przez telefon czy e-mail. '
      . '<a href="' . $e($sso_url) . '">Więcej o SSO</a></span></p>'
      . '</aside>';

$_err_attr = $err !== '' ? ' aria-invalid="true"' : '';
$_err_ids  = $err !== '' ? ' tzt-err' : '';
auth_screen_head([
    'title'           => 'Logowanie — System Tożsamości',
    'light'           => true,
    'width'           => 480,
    'main_id'         => 'tzt-main',
    'account_buttons' => [],
    'aside_html'      => $side,
    'head_html'       => '<style>'
        . '.au .d-none{display:none!important}'
        . '.tzt-aside{padding:1.6rem 1.75rem;background:#fff;border:1px solid var(--au-line);border-radius:var(--au-r);color:var(--au-ink)}'
        . '.tzt-aside-h{margin:0 0 .35rem;font-size:1.35rem;font-weight:700}'
        . '.tzt-lead{margin:0 0 1.25rem;color:var(--au-ink2)}'
        . '.tzt-lista{list-style:none;display:grid;gap:.85rem;margin:0;padding:0}'
        . '.tzt-lista li{display:flex;gap:.75rem;align-items:flex-start;font-size:.95rem;line-height:1.5}'
        . '.tzt-lista .bi{flex:0 0 2.25rem;display:flex;align-items:center;justify-content:center;height:2.25rem;border-radius:8px;background:var(--au-acc-soft);color:var(--au-acc);font-size:1.1rem}'
        . '.tzt-uwaga{display:flex;gap:.6rem;align-items:flex-start;margin:1.5rem 0 0;padding:.8rem 1rem;border-left:4px solid var(--au-warn);border-radius:var(--au-rs);background:var(--au-warn-bg);color:var(--au-warn-ink);font-size:.9rem}'
        . '.tzt-uwaga .bi{font-size:1.1rem;color:var(--au-warn-ink)}'
        . '.tzt-aside a{font-weight:600;color:var(--au-acc);text-decoration:underline}'
        . '.tzt-sso-h{margin:.45rem 0 0;font-size:.875rem;text-align:center;color:var(--au-muted)}'
        . '.tzt-ms svg{flex-shrink:0}'
        . '.tzt-inne-h{margin:1.75rem 0 .25rem;font-size:.95rem;font-weight:700}'
        . '.tzt-inne{display:grid;gap:.25rem;margin:0 0 .5rem}'
        . '.tzt-inne a{display:flex;align-items:center;gap:.5rem;min-height:44px;font-weight:600}'
        . '.tzt-inne a .bi{color:var(--au-acc);font-size:1.1rem}'
        . '@media(max-width:520px){.tzt-aside{padding:1.25rem}}'
        . '</style>',
]);
?>
<div class="l-alert l-alert-info" role="note">
  <i class="bi bi-calendar-check" aria-hidden="true"></i>
  <span><strong>Od 10.10.2026 obowiązuje nowy system zarządzania tożsamością.</strong>
  Konta założone wcześniej logują się jak za pierwszym razem — dotychczasowych haseł nie dało się przenieść.</span>
</div>
<h1 class="ks-h1">Zaloguj się</h1>
<p class="ks-lead">do Systemu Tożsamości Fundacji FEER</p>

<?php if ($lt_ok): ?>
<a href="<?= $e($lt_url) ?>" class="ks-btn ks-btn--primary">
  <i class="bi bi-key" aria-hidden="true"></i> Zaloguj przez logowanie jednokrotne
</a>
<p class="tzt-sso-h">Jedno konto Fundacji — to samo co do chmury plików.</p>
<?php endif; ?>
<?php if ($ms_ok): ?>
<a href="<?= $e($ms_url) ?>" class="ks-btn ks-btn--ghost tzt-ms"<?= $lt_ok ? ' style="margin-top:1rem"' : '' ?>
   aria-label="Microsoft 365 — tylko administratorzy; zostaniesz przekierowany do Microsoft">
  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 23 23" aria-hidden="true" focusable="false">
    <path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/>
    <path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/>
  </svg>
  Microsoft 365 — administratorzy
</a>
<p class="tzt-sso-h">Tylko konta administratorów <strong>@feer.org.pl</strong>.</p>
<?php endif; ?>
<?php if ($lt_ok || $ms_ok): ?>
<div class="ks-or">lub e-mailem i hasłem</div>
<?php endif; ?>

<form method="post" action="<?= $e($action) ?>" novalidate autocomplete="on" aria-label="Logowanie e-mailem i hasłem">
  <input type="hidden" name="_csrf" value="<?= $e($token) ?>">
  <input type="hidden" name="_method" value="local">
  <input type="hidden" name="from" value="<?= $e($self_url) ?>">

  <?php if ($err !== ''): ?>
  <div class="l-alert l-alert-danger" role="alert" id="tzt-err"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><span id="login-error-text"><?= $e($err) ?></span></div>
  <?php endif; ?>
  <div class="ks-field">
    <label for="tzt-email">Adres e-mail</label>
    <input type="email" class="form-control" id="tzt-email" name="email"
           autocomplete="username" inputmode="email" required<?= ($ms_ok || $lt_ok) && $err === '' ? '' : ' autofocus' ?>
           aria-describedby="tzt-email-err<?= $_err_ids ?>"<?= $_err_attr ?>>
    <p class="au-ferr" id="tzt-email-err" role="alert"></p>
  </div>
  <div class="ks-field">
    <label for="tzt-pass">Hasło</label>
    <div class="pass-wrap">
      <input type="password" class="form-control" id="tzt-pass" name="password"
             autocomplete="current-password" required aria-describedby="tzt-pass-err<?= $_err_ids ?>"<?= $_err_attr ?>>
      <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false" aria-controls="tzt-pass" onclick="togglePass('tzt-pass', this)">
        <i class="bi bi-eye" aria-hidden="true"></i>
      </button>
    </div>
    <p class="au-ferr" id="tzt-pass-err" role="alert"></p>
  </div>
  <p class="ks-note" style="margin:-.25rem 0 1.25rem"><a href="<?= $e($APP . '/user/verify_reset.php') ?>">Nie pamiętasz hasła?</a></p>

  <button type="submit" class="ks-btn ks-btn--primary">Zaloguj do Tożsamości</button>
</form>

<?php /* Logowanie kluczem bezpieczeństwa (WebAuthn) — logika w tozsamosc/klucz_login.php */ ?>
<div class="ks-or">lub kluczem bezpieczeństwa</div>
<button type="button" class="ks-btn ks-btn--ghost" id="tzt-klucz" aria-describedby="tzt-klucz-hint">
  <i class="bi bi-usb-drive" aria-hidden="true"></i>Zaloguj kluczem bezpieczeństwa
</button>
<p class="ks-fieldhint" id="tzt-klucz-hint">YubiKey, passkey w telefonie, Windows Hello lub Touch ID.
  Masz YubiKey? Najpierw wpisz adres e-mail powyżej. Klucz dodasz w Tożsamości: <strong>Bezpieczeństwo</strong>.</p>
<div class="l-alert l-alert-danger d-none" id="tzt-klucz-err" role="alert" style="margin-top:.75rem"></div>
<div class="sr" role="status" aria-live="polite" id="tzt-klucz-live"></div>

<h2 class="tzt-inne-h">Inne sposoby logowania</h2>
<div class="tzt-inne">
  <a href="<?= $e($full_url) ?>"><i class="bi bi-phone" aria-hidden="true"></i>Kod SMS, kod jednorazowy lub certyfikat</a>
  <a href="<?= $e($APP . '/tozsamosc/kursant.php') ?>"><i class="bi bi-mortarboard" aria-hidden="true"></i>Jestem kursantem TI</a>
  <a href="<?= $e($APP . '/auth/report_login_issue.php') ?>"><i class="bi bi-life-preserver" aria-hidden="true"></i>Problem z logowaniem</a>
</div>
<p class="ks-fieldhint">Logowanie chronione: logowanie dwuetapowe i klucze bezpieczeństwa działają tak jak w całym systemie.</p>
<?php
$_klucz_js = '<script>(function () {'
    . 'var btn = document.getElementById("tzt-klucz"), err = document.getElementById("tzt-klucz-err"), live = document.getElementById("tzt-klucz-live");'
    . 'if (!btn) return;'
    . 'if (!window.PublicKeyCredential || !navigator.credentials) { btn.disabled = true; btn.setAttribute("aria-disabled", "true");'
    . '  document.getElementById("tzt-klucz-hint").textContent = "Ta przeglądarka nie obsługuje kluczy bezpieczeństwa."; return; }'
    . 'var api = ' . json_encode($APP . '/tozsamosc/klucz_login.php?api=') . ', tok = ' . json_encode($token) . ', ret = ' . json_encode($redirect) . ';'
    . 'function b2(s){s=s.replace(/-/g,"+").replace(/_/g,"/");while(s.length%4)s+="=";var b=atob(s),a=new Uint8Array(b.length);for(var i=0;i<b.length;i++)a[i]=b.charCodeAt(i);return a.buffer;}'
    . 'function u2(buf){var u=new Uint8Array(buf),s="";for(var i=0;i<u.length;i++)s+=String.fromCharCode(u[i]);return btoa(s).replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/,"");}'
    . 'function blad(t){err.textContent=t;err.classList.remove("d-none");btn.disabled=false;live.textContent="";}'
    . 'btn.addEventListener("click", async function () {'
    . '  err.classList.add("d-none"); btn.disabled = true; live.textContent = "Dotknij klucza bezpieczeństwa albo potwierdź w urządzeniu.";'
    . '  try {'
    . '    var em = (document.getElementById("tzt-email").value || "").trim();'
    . '    var r = await fetch(api + "begin" + (em ? "&email=" + encodeURIComponent(em) : ""), { credentials: "same-origin", cache: "no-store" });'
    . '    var j = await r.json(); if (!r.ok) throw new Error(j.error || "Nie udało się rozpocząć logowania kluczem.");'
    . '    var o = j.data;'
    . '    var c = await navigator.credentials.get({ publicKey: { challenge: b2(o.challenge), rpId: o.rpId, timeout: o.timeout || 60000,'
    . '      userVerification: o.userVerification || "preferred",'
    . '      allowCredentials: (o.allowCredentials || []).map(function (a) { return { type: "public-key", id: b2(a.id), transports: a.transports }; }) } });'
    . '    var resp = { id: c.id, rawId: u2(c.rawId), type: c.type, clientDataJSON: u2(c.response.clientDataJSON),'
    . '      authenticatorData: u2(c.response.authenticatorData), signature: u2(c.response.signature) };'
    . '    var r2 = await fetch(api + "complete", { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json" },'
    . '      body: JSON.stringify({ _csrf: tok, response: resp, redirect: ret }) });'
    . '    var j2 = await r2.json(); if (!r2.ok || !j2.ok) throw new Error(j2.error || "Nie udało się zalogować kluczem.");'
    . '    live.textContent = "Zalogowano. Przechodzę dalej."; window.location = j2.redirect;'
    . '  } catch (e) {'
    . '    blad(e && e.name === "NotAllowedError" ? "Logowanie kluczem przerwane albo minął czas. Spróbuj ponownie." : (e && e.message ? e.message : "Nie udało się zalogować kluczem."));'
    . '  }'
    . '});'
    . '})();</script>';

// Puste pole e-mail / hasło: komunikat przy polu (role=alert) zamiast wysłania pustego formularza.
$_pola_js = '<script>(function () {'
    . 'var em = document.getElementById("tzt-email"), pw = document.getElementById("tzt-pass"); if (!em || !pw) return;'
    . 'function err(i, t) { var b = document.getElementById(i.id + "-err"); if (!b) return;'
    . '  b.innerHTML = t ? "<i class=\"bi bi-exclamation-circle-fill\" aria-hidden=\"true\"></i><span></span>" : "";'
    . '  if (t) { b.lastChild.textContent = t; i.setAttribute("aria-invalid", "true"); } }'
    . '[em, pw].forEach(function (i) { i.addEventListener("input", function () { if (i.value) err(i, ""); }); });'
    . 'em.form.addEventListener("submit", function (ev) { var a = !em.value.trim(), b = !pw.value;'
    . '  err(em, a ? "Wpisz adres e-mail." : ""); err(pw, b ? "Wpisz hasło." : "");'
    . '  if (a || b) { ev.preventDefault(); (a ? em : pw).focus(); } });'
    . '})();</script>';

auth_screen_foot([
    'no_new_tiles' => true,
    'extra_js'     => $_klucz_js . $_pola_js,
]);
