<?php
/**
 * karty30/ti/dydaktyk/totp_gate.php — obowiązkowe 2FA (TOTP) dla panelu dydaktyka.
 *
 * Jedyne miejsce, które woła dyd_login_user() po świeżym logowaniu (hasłem —
 * login.php, albo Office SSO — office_enter.php): oba zapisują profil przez
 * dyd_2fa_stash() i przekierowują tutaj. Konto bez totp_confirmed przechodzi
 * najpierw zakładanie (QR + potwierdzenie kodem), konto z aktywnym TOTP —
 * od razu weryfikację kodu (albo kodu zapasowego). Dopiero wtedy zakładana
 * jest pełna sesja panelu (dyd_login_user()) — ciche wznowienia sesji
 * („zapamiętaj mnie”, odświeżenie z dyd_current()) tej bramki już nie widzą,
 * tak jak logowanie Microsoft 365 nie powtarza się przy każdym wejściu.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/totp.php';

karty30_migrate();

// Już w pełni zalogowany (np. otwarta druga karta) → prosto do panelu.
if (dyd_current()) { header('Location: index.php'); exit; }

$profile = dyd_2fa_pending();
if (!$profile) { header('Location: login.php'); exit; }

$uid = (int)$profile['user_id'];
$u   = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [$uid]);
if (!$u) { dyd_2fa_clear_pending(); header('Location: login.php'); exit; }

$enrolled = !empty($u['totp_confirmed']) && !empty($u['totp_secret']);
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $action = $_POST['_action'] ?? '';

    // ── Konto BEZ TOTP: potwierdzenie kodu z aplikacji kończy zakładanie ──
    if ($action === 'setup_confirm' && !$enrolled) {
        $secret = (string)($_SESSION['k30_dyd_2fa_setup_secret'] ?? '');
        $code   = trim($_POST['code'] ?? '');
        if (!$secret) {
            $error = 'Sesja zakładania wygasła. Odśwież stronę i zacznij od nowa.';
        } elseif (!TOTP::verify($secret, $code)) {
            $error = 'Kod nieprawidłowy. Sprawdź czas systemowy telefonu i spróbuj ponownie.';
        } else {
            $backup = [];
            for ($i = 0; $i < 8; $i++) {
                $backup[] = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
            }
            db()->prepare(
                "UPDATE users SET totp_secret=?, totp_confirmed=1, twofa_method='totp', totp_backup_codes=? WHERE id=?"
            )->execute([$secret, json_encode($backup), $uid]);
            unset($_SESSION['k30_dyd_2fa_setup_secret']);
            $_SESSION['k30_dyd_2fa_backup_show'] = $backup;
            if (function_exists('log_user_action')) log_user_action($uid, $uid, '2fa_enabled', 'Włączono 2FA TOTP (panel dydaktyka, obowiązkowe)');
            $enrolled = true;
            $u['totp_confirmed'] = 1; // odśwież lokalnie do renderowania poniżej
        }
    }

    // ── Krok końcowy: kody zapasowe pokazane, zakładamy pełną sesję ───────
    elseif ($action === 'finish' && $enrolled) {
        dyd_login_user($profile);
        dyd_2fa_clear_pending();
        header('Location: index.php'); exit;
    }

    // ── Konto Z TOTP: weryfikacja kodu (albo kodu zapasowego) ─────────────
    elseif ($action === 'verify' && $enrolled) {
        $code = trim($_POST['code'] ?? '');
        $ok   = TOTP::verify((string)$u['totp_secret'], $code);

        if (!$ok && !empty($u['totp_backup_codes'])) {
            $backup     = json_decode((string)$u['totp_backup_codes'], true) ?? [];
            $normalized = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $code));
            foreach ($backup as $idx => $bc) {
                $bc_norm = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)$bc));
                if ($normalized !== '' && hash_equals($bc_norm, $normalized)) {
                    array_splice($backup, $idx, 1);
                    db()->prepare("UPDATE users SET totp_backup_codes=? WHERE id=?")
                        ->execute([json_encode(array_values($backup)), $uid]);
                    $ok = true;
                    break;
                }
            }
        }

        if ($ok) {
            dyd_login_user($profile);
            dyd_2fa_clear_pending();
            header('Location: index.php'); exit;
        }
        $error = 'Nieprawidłowy kod. Spróbuj ponownie.';
    }
}

// Sekret do zakładania — generowany raz, trzymany w sesji do potwierdzenia.
$pending_secret = '';
if (!$enrolled) {
    $pending_secret = (string)($_SESSION['k30_dyd_2fa_setup_secret'] ?? '');
    if ($pending_secret === '') {
        $pending_secret = TOTP::generate_secret();
        $_SESSION['k30_dyd_2fa_setup_secret'] = $pending_secret;
    }
}
$totp_uri     = $pending_secret !== '' ? TOTP::get_qr_uri($pending_secret, (string)$u['email'], ORG_NAME) : '';
$backup_show  = $_SESSION['k30_dyd_2fa_backup_show'] ?? null;

$KP_TITLE      = 'Weryfikacja dwuetapowa — Panel dydaktyka';
$KP_BODY_CLASS = 'kp-login-split-page';
include __DIR__ . '/../kursant/_layout_head.php';
?>
<style>
*, *::before, *::after { box-sizing: border-box; }
:root {
  --bg: #eef0f4; --card-bg: #ffffff; --text: #1a1a1a; --muted: #5f5f66;
  --rule: #d0d0d8; --accent: #1e3a5f; --accent-h: #15293f; --hover-bg: #e7e9ef;
}
@media (prefers-color-scheme: dark) {
  :root { --bg:#15151b; --card-bg:#1e1e26; --text:#ededed; --muted:#9a9aa4; --rule:#34343f; --accent:#5680b3; --accent-h:#6f97c6; --hover-bg:#292933; }
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
.tg-code {
  font-size: 1.5rem; letter-spacing: .4rem; text-align: center; font-weight: 700;
  border: 1px solid var(--rule); border-radius: 2px; background: var(--card-bg); color: var(--text);
  padding: .5rem; width: 100%;
}
.tg-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
  background: var(--accent); border: 1px solid var(--accent); color: #fff; border-radius: 2px;
  padding: .55rem 1rem; font-weight: 600; width: 100%; margin-top: .9rem; cursor: pointer;
}
.tg-btn:hover { background: var(--accent-h); border-color: var(--accent-h); }
.tg-secret {
  font-family: ui-monospace, monospace; background: var(--hover-bg); border: 1px solid var(--rule);
  border-radius: 2px; padding: .5rem .7rem; font-size: .85rem; word-break: break-all; user-select: all;
}
.tg-backup { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin: .75rem 0; }
.tg-backup code { display:block; text-align:center; background: var(--hover-bg); border:1px solid var(--rule); border-radius:2px; padding:.4rem; font-weight:700; }
</style>
<div class="tg-wrap">
<div class="tg-card">

<?php if ($error): ?>
<div class="alert alert-danger py-2 mb-3" role="alert"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($backup_show !== null): ?>
  <!-- ═══ Krok końcowy: kody zapasowe, pokazane raz ═══ -->
  <h1 class="tg-h"><i class="bi bi-key-fill text-warning" aria-hidden="true"></i>Zapisz kody zapasowe</h1>
  <p class="tg-sub">Jeśli zgubisz dostęp do aplikacji uwierzytelniającej, użyj jednego z tych kodów zamiast 6-cyfrowego. Każdy działa tylko raz — <strong>ta strona pokaże je tylko teraz</strong>.</p>
  <div class="tg-backup">
    <?php foreach ($backup_show as $bc): ?>
    <code><?= h($bc) ?></code>
    <?php endforeach; ?>
  </div>
  <form method="post">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_action" value="finish">
    <button type="submit" class="tg-btn"><i class="bi bi-check2-circle" aria-hidden="true"></i>Zapisałem kody — przejdź do panelu</button>
  </form>

<?php elseif (!$enrolled): ?>
  <!-- ═══ Zakładanie 2FA — obowiązkowe ═══ -->
  <h1 class="tg-h"><i class="bi bi-shield-lock text-primary" aria-hidden="true"></i>Skonfiguruj 2FA</h1>
  <p class="tg-sub">
    Od teraz logowanie do panelu dydaktyka wymaga dodatkowego kodu z aplikacji
    <strong>Google Authenticator</strong> lub <strong>Microsoft Authenticator</strong> —
    to jednorazowy krok. Zeskanuj kod QR albo wpisz klucz ręcznie, potem podaj wygenerowany kod.
  </p>
  <div class="text-center mb-3">
    <canvas id="qrcode-canvas" class="border rounded p-2"
            aria-label="Kod QR do konfiguracji aplikacji TOTP"></canvas>
  </div>
  <label class="form-label small fw-semibold" for="tg-secret">Klucz ręczny (jeśli nie możesz zeskanować):</label>
  <div class="tg-secret mb-3" id="tg-secret"><?= h($pending_secret) ?></div>
  <form method="post">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_action" value="setup_confirm">
    <label class="form-label fw-semibold" for="code">Kod z aplikacji</label>
    <input type="text" name="code" id="code" class="tg-code" inputmode="numeric" pattern="[0-9]{6}"
           maxlength="6" placeholder="______" autofocus required aria-required="true">
    <button type="submit" class="tg-btn"><i class="bi bi-shield-check" aria-hidden="true"></i>Potwierdź i włącz 2FA</button>
  </form>
  <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
  <script>
  QRCode.toCanvas(document.getElementById('qrcode-canvas'), <?= json_encode($totp_uri) ?>, {width: 200},
    function(err) { if (err) console.error(err); });
  </script>

<?php else: ?>
  <!-- ═══ Weryfikacja kodu — konto już ma 2FA ═══ -->
  <h1 class="tg-h"><i class="bi bi-shield-lock text-primary" aria-hidden="true"></i>Weryfikacja dwuetapowa</h1>
  <p class="tg-sub">Podaj 6-cyfrowy kod z aplikacji uwierzytelniającej (albo jeden z zapisanych kodów zapasowych).</p>
  <form method="post">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_action" value="verify">
    <input type="text" name="code" class="tg-code" inputmode="numeric" maxlength="9"
           placeholder="______" autofocus required aria-required="true">
    <button type="submit" class="tg-btn"><i class="bi bi-shield-check" aria-hidden="true"></i>Zaloguj się</button>
  </form>
<?php endif; ?>

<p class="text-center mt-3 mb-0"><a href="login.php" style="color:var(--muted);font-size:.8rem">Anuluj i wróć do logowania</a></p>

</div>
</div>
<?php include __DIR__ . '/../kursant/_layout_foot.php'; ?>
