<?php
/**
 * karty30/ti/dydaktyk/yk_gate.php — obowiązkowy klucz sprzętowy (YubiKey, WebAuthn) przy wejściu do panelu
 * dla administratora, kierownika i zastępcy (dyd_yk_required()).
 *
 * Trafia się tu po TOTP (totp_gate.php → dyd_2fa_finish()), a administrator — od razu po haśle albo Office
 * (TOTP go nie dotyczy). Sesję panelu zakłada dopiero poprawny podpis klucza; profil dostaje wtedy yk_ok,
 * a dyd_require() odsyła tu każdą sesję bez tego znacznika.
 *
 * Konto bez żadnego klucza (tabela webauthn_credentials — ta sama co w SZO, więc klucz dodany w SZO działa
 * i tu, o ile adres jest ten sam) rejestruje pierwszy klucz tutaj; kolejne klucze dodaje się już po
 * zalogowaniu (moj_certyfikat.php). Zgubiony klucz usuwa administrator w SZO.
 *   GET  ?action=begin     → opcje dla navigator.credentials.get() albo .create() (pierwszy klucz)
 *   POST ?action=complete  (JSON: odpowiedź z przeglądarki) → zakłada sesję panelu
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/webauthn.php';

karty30_migrate();
webauthn_migrate();

// Już w pełni zalogowany (np. otwarta druga karta) → prosto do panelu.
if (dyd_current()) { header('Location: index.php'); exit; }

$profile = dyd_2fa_pending();
if (!$profile) { header('Location: login.php'); exit; }

$uid = (int)$profile['user_id'];
$u   = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [$uid]);
if (!$u) { dyd_2fa_clear_pending(); header('Location: login.php'); exit; }

// Konto, którego bramka nie dotyczy, nie ma tu czego szukać; konto z TOTP musi najpierw podać kod.
if (!dyd_yk_required($profile)) dyd_2fa_finish($profile);
if (($profile['role'] ?? '') !== 'admin' && empty($_SESSION[DYD_2FA_KEY]['totp_ok'])) {
    header('Location: totp_gate.php'); exit;
}

$has_key = webauthn_user_has_keys($uid);
$action  = (string)($_GET['action'] ?? '');

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $out = function (array $d, int $code = 200): void {
        http_response_code($code);
        echo json_encode($d, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if ($action === 'begin' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $out($has_key
            ? ['mode' => 'auth', 'data' => webauthn_begin_auth($uid)]
            : ['mode' => 'register', 'data' => webauthn_begin_register($uid, (string)$u['email'], (string)($u['name'] ?: $u['email']))]);
    }

    if ($action === 'complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        dyd_token_check();
        $in = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($in)) $out(['error' => 'Nieprawidłowe dane.'], 422);
        try {
            if ($has_key) {
                if (webauthn_complete_auth($in) !== $uid) $out(['error' => 'Ten klucz należy do innego konta.'], 422);
                $what = 'klucz sprzętowy';
            } else {
                // Pierwszy klucz konta — tylko gdy nie ma żadnego (inaczej trzeba się nim zalogować).
                if (($_SESSION['webauthn_reg_uid'] ?? 0) !== $uid) $out(['error' => 'Sesja rejestracji wygasła. Spróbuj ponownie.'], 422);
                webauthn_complete_register($in, 'YubiKey ' . date('d.m.Y'));
                $what = 'nowy klucz sprzętowy (pierwsza rejestracja)';
                if (function_exists('log_user_action')) log_user_action($uid, $uid, 'webauthn_registered', 'Zarejestrowano klucz sprzętowy przy logowaniu do panelu dydaktyka');
            }
        } catch (\Throwable $e) {
            $out(['error' => $e->getMessage()], 422);
        }
        if (function_exists('log_user_action')) log_user_action($uid, $uid, 'dyd_login_yk', 'Wejście do panelu dydaktyka: ' . $what);
        $go = dyd_2fa_next();
        $profile['yk_ok'] = 1;
        dyd_login_user($profile);
        dyd_2fa_clear_pending();
        $out(['go' => $go]);
    }

    $out(['error' => 'Nieznana akcja.'], 404);
}

$rola = ($profile['role'] ?? '') === 'admin' ? 'administratora'
      : (($profile['panel_role'] ?? '') === 'zastepca' ? 'zastępcy kierownika' : 'kierownika');

$KP_TITLE      = 'Klucz sprzętowy — Panel dydaktyka';
$KP_BODY_CLASS = 'kp-login-split-page';
include __DIR__ . '/../kursant/_layout_head.php';
?>
<style>
*, *::before, *::after { box-sizing: border-box; }
:root {
  --bg: #eef0f4; --card-bg: #ffffff; --text: #1a1a1a; --muted: #5f5f66;
  --rule: #d0d0d8; --accent: #1e3a5f; --accent-h: #15293f;
}
@media (prefers-color-scheme: dark) {
  :root { --bg:#15151b; --card-bg:#1e1e26; --text:#ededed; --muted:#9a9aa4; --rule:#34343f; --accent:#5680b3; --accent-h:#6f97c6; }
}
html, body.kp-login-split-page { min-height: 100%; margin: 0; padding: 0 !important; }
body.kp-login-split-page { background: var(--bg); color: var(--text); font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; }
.tg-wrap { min-height: 100vh; width: 100%; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
.tg-card {
  width: 100%; max-width: 460px; background: var(--card-bg); border: 1px solid var(--rule);
  border-radius: 2px; box-shadow: 0 1px 4px rgba(0,0,0,.09); padding: 1.75rem;
}
.tg-h { font-size: 1.3rem; font-weight: 700; margin: 0 0 .25rem; display:flex; align-items:center; gap:.5rem; }
.tg-sub { color: var(--muted); font-size: .85rem; margin: 0 0 1.25rem; }
.tg-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
  background: var(--accent); border: 1px solid var(--accent); color: #fff; border-radius: 2px;
  padding: .55rem 1rem; font-weight: 600; width: 100%; margin-top: .9rem; cursor: pointer;
}
.tg-btn:hover { background: var(--accent-h); border-color: var(--accent-h); }
.tg-btn[disabled] { opacity: .65; cursor: wait; }
</style>
<div class="tg-wrap">
<div class="tg-card">
  <div class="alert alert-danger py-2 mb-3 d-none" role="alert" id="ykErr"></div>
  <p class="visually-hidden" role="status" aria-live="polite" id="ykStatus"></p>

<?php if ($has_key): ?>
  <h1 class="tg-h"><i class="bi bi-usb-drive text-primary" aria-hidden="true"></i>Potwierdź kluczem</h1>
  <p class="tg-sub">
    Konto <?= h($rola) ?> wymaga przy logowaniu klucza sprzętowego (YubiKey).
    Włóż klucz, naciśnij przycisk poniżej i dotknij klucza, gdy zacznie migać.
  </p>
  <button type="button" class="tg-btn" id="ykBtn"><i class="bi bi-key" aria-hidden="true"></i>Użyj klucza</button>
<?php else: ?>
  <h1 class="tg-h"><i class="bi bi-usb-drive text-primary" aria-hidden="true"></i>Dodaj klucz sprzętowy</h1>
  <p class="tg-sub">
    Konto <?= h($rola) ?> wymaga przy logowaniu klucza sprzętowego (YubiKey), a Twoje konto nie ma jeszcze żadnego.
    To jednorazowy krok: włóż klucz, naciśnij przycisk i dotknij klucza. Od następnego logowania wystarczy go dotknąć.
    Najlepiej zarejestruj potem drugi, zapasowy klucz (Mój certyfikat → Klucz YubiKey).
  </p>
  <button type="button" class="tg-btn" id="ykBtn"><i class="bi bi-plus-circle" aria-hidden="true"></i>Zarejestruj klucz i zaloguj się</button>
<?php endif; ?>

<p class="text-center mt-3 mb-0"><a href="logout.php" style="color:var(--muted);font-size:.8rem">Anuluj i wróć do logowania</a></p>
</div>
</div>
<script>
(function () {
  var btn = document.getElementById('ykBtn');
  var err = document.getElementById('ykErr');
  var st  = document.getElementById('ykStatus');
  var tok = <?= json_encode(dyd_token()) ?>;
  function b64uToBuf(s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '=';
    var bin = atob(s), a = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i); return a.buffer; }
  function bufToB64u(buf) { var u = new Uint8Array(buf), s = ''; for (var i = 0; i < u.length; i++) s += String.fromCharCode(u[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function blad(t) { err.textContent = t; err.classList.remove('d-none'); err.setAttribute('tabindex', '-1'); err.focus(); }
  if (!window.PublicKeyCredential) {
    blad('Ta przeglądarka nie obsługuje kluczy sprzętowych. Użyj aktualnej wersji Chrome, Edge, Firefox albo Safari.');
    btn.disabled = true; return;
  }
  btn.addEventListener('click', async function () {
    err.classList.add('d-none'); btn.disabled = true; st.textContent = 'Czekam na dotknięcie klucza…';
    try {
      var r = await fetch('yk_gate.php?action=begin', { credentials: 'same-origin' });
      var j = await r.json(); if (!r.ok) throw new Error(j.error || 'Nie udało się rozpocząć.');
      var o = j.data, resp, cred;
      if (j.mode === 'auth') {
        cred = await navigator.credentials.get({ publicKey: {
          challenge: b64uToBuf(o.challenge), rpId: o.rpId, timeout: o.timeout || 60000,
          userVerification: o.userVerification || 'preferred',
          allowCredentials: (o.allowCredentials || []).map(function (c) { return { type: 'public-key', id: b64uToBuf(c.id), transports: c.transports }; }) } });
        resp = { id: bufToB64u(cred.rawId), rawId: bufToB64u(cred.rawId), type: cred.type, response: {
          clientDataJSON: bufToB64u(cred.response.clientDataJSON),
          authenticatorData: bufToB64u(cred.response.authenticatorData),
          signature: bufToB64u(cred.response.signature) } };
      } else {
        cred = await navigator.credentials.create({ publicKey: {
          challenge: b64uToBuf(o.challenge), rp: o.rp,
          user: { id: b64uToBuf(o.user.id), name: o.user.name, displayName: o.user.displayName },
          pubKeyCredParams: o.pubKeyCredParams, timeout: o.timeout || 60000, attestation: o.attestation || 'none',
          excludeCredentials: (o.excludeCredentials || []).map(function (c) { return { type: 'public-key', id: b64uToBuf(c.id) }; }),
          authenticatorSelection: o.authenticatorSelection || { userVerification: 'preferred' } } });
        resp = { id: bufToB64u(cred.rawId), rawId: bufToB64u(cred.rawId), type: cred.type, response: {
          clientDataJSON: bufToB64u(cred.response.clientDataJSON),
          attestationObject: bufToB64u(cred.response.attestationObject) } };
      }
      var r2 = await fetch('yk_gate.php?action=complete', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok }, body: JSON.stringify(resp) });
      var j2 = await r2.json(); if (!r2.ok) throw new Error(j2.error || 'Klucz nie został przyjęty.');
      st.textContent = 'Klucz przyjęty, otwieram panel…';
      location.href = j2.go || 'index.php';
    } catch (e) {
      st.textContent = '';
      blad(e && e.name === 'NotAllowedError'
        ? 'Nie dotknięto klucza albo anulowano. Spróbuj ponownie.'
        : (e.message || 'Nie udało się potwierdzić klucza.'));
      btn.disabled = false;
    }
  });
})();
</script>
<?php include __DIR__ . '/../kursant/_layout_foot.php'; ?>
