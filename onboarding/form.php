<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/includes/approval.php';
auth_start();

// ─── helpers ────────────────────────────────────────────────────────────────

function get_setting(string $key): string {
    return db_one("SELECT value FROM settings WHERE key_=?", [$key])['value'] ?? '';
}

function ob_redirect(int $step): void {
    header('Location: ?step=' . $step);
    exit;
}

// ─── check enabled ──────────────────────────────────────────────────────────

$enabled = get_setting('onboarding_enabled');
$ob_title        = get_setting('onboarding_title') ?: 'Rejestracja wolontariusza';
$ob_intro        = get_setting('onboarding_intro');
$org_name        = defined('ORG_NAME') ? ORG_NAME : '';
$ob_logo         = get_setting('onboarding_logo');
$ob_accent       = get_setting('onboarding_accent_color') ?: '#0d6efd';
$ob_bg           = get_setting('onboarding_bg_color')     ?: '#f0f4f8';
$ob_custom_css   = get_setting('onboarding_custom_css');
// sanitise colour values — must be a valid CSS colour starting with # or rgb
if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $ob_accent)) $ob_accent = '#0d6efd';
if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $ob_bg))     $ob_bg     = '#f0f4f8';

$disabled_page = ($enabled === '0');

// ─── session / step routing ─────────────────────────────────────────────────

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($step < 1 || $step > 5) $step = 1;

$ob_id = $_SESSION['ob_id'] ?? null;

if ($step > 1 && $step < 5 && !$ob_id) {
    ob_redirect(1);
}

$errors   = [];
$success  = '';

// ═══════════════════════════════════════════════════════════════════════════
// POST HANDLING
// ═══════════════════════════════════════════════════════════════════════════

if (!$disabled_page && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // ── Step 1 POST ──────────────────────────────────────────────────────
    if ($step === 1) {
        $imie_nazwisko = trim($_POST['imie_nazwisko'] ?? '');
        $pesel         = trim($_POST['pesel'] ?? '');
        $data_urodzenia = trim($_POST['data_urodzenia'] ?? '');
        $adres         = trim($_POST['adres'] ?? '');
        $telefon_raw   = preg_replace('/\D/', '', trim($_POST['telefon'] ?? ''));
        $email         = trim($_POST['email'] ?? '');

        if ($imie_nazwisko === '') $errors[] = 'Imię i nazwisko jest wymagane.';
        if ($adres === '') $errors[] = 'Adres jest wymagany.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Podaj prawidłowy adres e-mail.';

        // Normalize phone
        $telefon_norm = $telefon_raw;
        if (strlen($telefon_raw) === 9) {
            $telefon_norm = '48' . $telefon_raw;
        } elseif (strlen($telefon_raw) === 11 && str_starts_with($telefon_raw, '48')) {
            $telefon_norm = $telefon_raw;
        } elseif ($telefon_raw === '') {
            $errors[] = 'Numer telefonu jest wymagany.';
        } else {
            // Accept as-is if 9 digits already prefixed differently — still require non-empty
            if (strlen($telefon_raw) < 9) {
                $errors[] = 'Podaj prawidłowy numer telefonu (9 cyfr).';
            }
        }

        // Auto-fill data_urodzenia from PESEL
        if ($pesel !== '' && $data_urodzenia === '') {
            $bd = pesel_to_birthdate($pesel);
            if ($bd) $data_urodzenia = $bd;
        }

        if (empty($errors)) {
            $token = bin2hex(random_bytes(16));
            $new_id = db_insert('onboarding_volunteers', [
                'session_token'   => $token,
                'status'          => 'new',
                'imie_nazwisko'   => $imie_nazwisko,
                'pesel'           => $pesel,
                'data_urodzenia'  => $data_urodzenia,
                'adres'           => $adres,
                'telefon'         => $telefon_norm,
                'email'           => $email,
                'phone_verified'  => 0,
                'email_verified'  => 0,
                'klauzula_accepted' => 0,
                'ip_address'      => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
            $_SESSION['ob_id']   = $new_id;
            $_SESSION['ob_step'] = 2;
            ob_redirect(2);
        }
    }

    // ── Step 2 POST ──────────────────────────────────────────────────────
    if ($step === 2) {
        $action = $_POST['action'] ?? '';
        $vol    = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$ob_id]);

        if ($action === 'resend' || $action === 'send') {
            $code    = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires = date('Y-m-d H:i:s', time() + 600);
            db_update('onboarding_volunteers', [
                'phone_code'         => $code,
                'phone_code_expires' => $expires,
                'updated_at'         => date('Y-m-d H:i:s'),
            ], $ob_id);
            try {
                sms_send($vol['telefon'], "Twój kod weryfikacyjny: {$code} (ważny 10 min)");
                $success = 'Kod SMS został wysłany.';
            } catch (\RuntimeException $e) {
                $errors[] = 'Nie udało się wysłać SMS. Spróbuj ponownie.';
            }
        } elseif ($action === 'verify') {
            $input_code = trim($_POST['code'] ?? '');
            $vol = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$ob_id]);
            if ($vol['phone_code'] === null || $input_code !== $vol['phone_code']) {
                $errors[] = 'Błędny kod, spróbuj ponownie.';
            } elseif (strtotime($vol['phone_code_expires']) < time()) {
                $errors[] = 'Kod wygasł. Wyślij nowy kod.';
            } else {
                db_update('onboarding_volunteers', [
                    'phone_verified'  => 1,
                    'phone_code'      => null,
                    'updated_at'      => date('Y-m-d H:i:s'),
                ], $ob_id);
                $_SESSION['ob_step'] = 3;
                ob_redirect(3);
            }
        }
    }

    // ── Step 3 POST ──────────────────────────────────────────────────────
    if ($step === 3) {
        $action = $_POST['action'] ?? '';
        $vol    = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$ob_id]);

        if ($action === 'resend_email') {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 86400);
            db_update('onboarding_volunteers', [
                'email_token'         => $token,
                'email_token_expires' => $expires,
                'updated_at'          => date('Y-m-d H:i:s'),
            ], $ob_id);
            $link = APP_URL . '/onboarding/verify_email.php?token=' . $token . '&id=' . $ob_id;
            $html = '<p>Kliknij poniższy link, aby zweryfikować swój adres e-mail:</p>'
                  . '<p><a href="' . $link . '">' . $link . '</a></p>';
            approval_send_email($vol['email'], 'Weryfikacja adresu e-mail', $html);
            $success = 'Link weryfikacyjny został wysłany ponownie.';
        } elseif ($action === 'check') {
            $vol = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$ob_id]);
            if ((int)$vol['email_verified'] === 1) {
                $_SESSION['ob_step'] = 4;
                ob_redirect(4);
            } else {
                $errors[] = 'E-mail jeszcze nie zweryfikowany.';
            }
        }
    }

    // ── Step 4 POST ──────────────────────────────────────────────────────
    if ($step === 4) {
        $klauzula_text = get_setting('onboarding_klauzula');
        if ($klauzula_text === '') {
            $klauzula_text = "Administratorem danych osobowych jest organizacja. Dane przetwarzane są w celu realizacji wolontariatu na podstawie zgody (art. 6 ust. 1 lit. a RODO). Przysługuje Pani/Panu prawo dostępu do danych, ich sprostowania, usunięcia, ograniczenia przetwarzania oraz wniesienia skargi do organu nadzorczego.";
        }
        $accepted = isset($_POST['klauzula']) && $_POST['klauzula'] === '1';
        if (!$accepted) {
            $errors[] = 'Musisz zaakceptować klauzulę informacyjną, aby kontynuować.';
        } else {
            $vol = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$ob_id]);
            db_update('onboarding_volunteers', [
                'klauzula_accepted' => 1,
                'klauzula_version'  => md5($klauzula_text),
                'status'            => 'pending',
                'updated_at'        => date('Y-m-d H:i:s'),
            ], $ob_id);

            // Notification email
            $notify_email = get_setting('onboarding_notify_email');
            if ($notify_email !== '') {
                $link = APP_URL . '/onboarding/view.php?id=' . $ob_id;
                $html = '<p>Nowe zgłoszenie wolontariusza: <strong>' . h($vol['imie_nazwisko']) . '</strong>'
                      . ' (' . h($vol['email']) . ')</p>'
                      . '<p><a href="' . $link . '">Przejdź do zgłoszenia</a></p>';
                approval_send_email($notify_email, 'Nowe zgłoszenie wolontariusza: ' . $vol['imie_nazwisko'] . ' (' . $vol['email'] . ')', $html);
            }

            $_SESSION['ob_step'] = 5;
            ob_redirect(5);
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// GET HANDLING (side effects on first visit)
// ═══════════════════════════════════════════════════════════════════════════

if (!$disabled_page && $_SERVER['REQUEST_METHOD'] === 'GET') {

    // ── Step 2 GET — auto-send SMS ───────────────────────────────────────
    if ($step === 2 && $ob_id) {
        $require_sms = get_setting('onboarding_require_sms');
        $sms_ok      = sms_is_enabled();

        if ($require_sms !== '1' || !$sms_ok) {
            // Skip: mark verified
            db_update('onboarding_volunteers', [
                'phone_verified' => 1,
                'updated_at'     => date('Y-m-d H:i:s'),
            ], $ob_id);
            $_SESSION['ob_step'] = 3;
            ob_redirect(3);
        }

        // Auto-send on first visit (no code yet)
        $vol = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$ob_id]);
        if ($vol['phone_code'] === null) {
            $code    = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires = date('Y-m-d H:i:s', time() + 600);
            db_update('onboarding_volunteers', [
                'phone_code'         => $code,
                'phone_code_expires' => $expires,
                'updated_at'         => date('Y-m-d H:i:s'),
            ], $ob_id);
            try {
                sms_send($vol['telefon'], "Twój kod weryfikacyjny: {$code} (ważny 10 min)");
            } catch (\RuntimeException $e) {
                $errors[] = 'Nie udało się wysłać SMS. Użyj przycisku „Wyślij ponownie".';
            }
        }
    }

    // ── Step 3 GET — send email verification ────────────────────────────
    if ($step === 3 && $ob_id) {
        $require_email = get_setting('onboarding_require_email');

        if ($require_email !== '1') {
            db_update('onboarding_volunteers', [
                'email_verified' => 1,
                'updated_at'     => date('Y-m-d H:i:s'),
            ], $ob_id);
            $_SESSION['ob_step'] = 4;
            ob_redirect(4);
        }

        $vol = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$ob_id]);

        // Already verified
        if ((int)$vol['email_verified'] === 1) {
            $_SESSION['ob_step'] = 4;
            ob_redirect(4);
        }

        // Send token on first visit
        if ($vol['email_token'] === null) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 86400);
            db_update('onboarding_volunteers', [
                'email_token'         => $token,
                'email_token_expires' => $expires,
                'updated_at'          => date('Y-m-d H:i:s'),
            ], $ob_id);
            $vol['email_token'] = $token;
            $link = APP_URL . '/onboarding/verify_email.php?token=' . $token . '&id=' . $ob_id;
            $html = '<p>Kliknij poniższy link, aby zweryfikować swój adres e-mail:</p>'
                  . '<p><a href="' . $link . '">' . $link . '</a></p>';
            approval_send_email($vol['email'], 'Weryfikacja adresu e-mail', $html);
        }
    }

    // ── Step 5 GET — clear session ───────────────────────────────────────
    if ($step === 5) {
        unset($_SESSION['ob_id'], $_SESSION['ob_step']);
    }
}

// ─── reload vol record for display ──────────────────────────────────────────

$vol = null;
if ($ob_id && $step > 1 && $step <= 4) {
    $vol = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$ob_id]);
}

// ═══════════════════════════════════════════════════════════════════════════
// RENDER
// ═══════════════════════════════════════════════════════════════════════════

$page_title = h($ob_title) . ($org_name ? ' — ' . h($org_name) : '');

$step_labels = ['Dane', 'Telefon', 'E-mail', 'Klauzula'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $page_title ?></title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    :root {
      --ob-accent: <?= h($ob_accent) ?>;
      --ob-bg:     <?= h($ob_bg) ?>;
      --ob-accent-dark: <?= h($ob_accent) ?>;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
      background: var(--ob-bg);
      min-height: 100vh;
    }

    /* ── Card ──────────────────────────────────────────────── */
    .ob-card {
      border-radius: .75rem;
      border: 1px solid #e8ecf0;
      box-shadow: 0 2px 12px rgba(0,0,0,.07);
      background: #fff;
    }
    .ob-card-body { padding: 2rem; }
    @media (max-width: 575px) { .ob-card-body { padding: 1.25rem; } }

    /* ── Form controls ─────────────────────────────────────── */
    .form-control:focus,
    .form-select:focus {
      border-color: var(--ob-accent);
      box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--ob-accent) 25%, transparent);
    }
    .input-group-text {
      background: #f8f9fa;
      border-color: #ced4da;
      color: #6c757d;
      font-weight: 600;
    }
    .form-control-lg { font-size: 1.1rem; letter-spacing: .15em; }

    /* ── Buttons ───────────────────────────────────────────── */
    .btn-ob-primary {
      background-color: var(--ob-accent);
      border-color: var(--ob-accent);
      color: #fff;
      font-weight: 600;
      padding: .65rem 1.5rem;
      border-radius: .5rem;
      transition: filter .15s, transform .1s;
    }
    .btn-ob-primary:hover,
    .btn-ob-primary:focus {
      filter: brightness(.88);
      color: #fff;
    }
    .btn-ob-primary:active { transform: scale(.98); }
    .btn-ob-primary:disabled { opacity: .6; }

    /* ── Step indicator ────────────────────────────────────── */
    .step-indicator {
      display: flex;
      align-items: flex-start;
      position: relative;
      margin-bottom: 2rem;
    }
    .step-indicator::before {
      content: '';
      position: absolute;
      top: 13px;
      left: calc(100% / 8);
      right: calc(100% / 8);
      height: 2px;
      background: #dee2e6;
      z-index: 0;
    }
    .step-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      flex: 1;
      position: relative;
      font-size: .78rem;
      z-index: 1;
    }
    .step-item:not(:last-child)::after {
      content: '';
      position: absolute;
      top: 13px;
      left: 50%;
      width: 100%;
      height: 2px;
      background: #dee2e6;
      z-index: 0;
    }
    .step-item.done::after,
    .step-item.active::after {
      background: var(--ob-accent);
    }
    .step-circle {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: .8rem;
      border: 2px solid #dee2e6;
      background: #fff;
      z-index: 1;
      position: relative;
      transition: background .2s, border-color .2s;
    }
    .step-item.active .step-circle {
      background: var(--ob-accent);
      border-color: var(--ob-accent);
      color: #fff;
      box-shadow: 0 0 0 3px color-mix(in srgb, var(--ob-accent) 20%, transparent);
    }
    .step-item.done .step-circle {
      background: var(--ob-accent);
      border-color: var(--ob-accent);
      color: #fff;
    }
    .step-label {
      margin-top: 6px;
      color: #9ca3af;
      font-weight: 500;
      white-space: nowrap;
    }
    .step-item.active .step-label { color: var(--ob-accent); font-weight: 700; }
    .step-item.done .step-label   { color: var(--ob-accent); }

    /* ── Klauzula ──────────────────────────────────────────── */
    .klauzula-box {
      max-height: 280px;
      overflow-y: auto;
      border: 1px solid #dee2e6;
      padding: 1rem;
      background: #fafafa;
      border-radius: .5rem;
      white-space: pre-wrap;
      font-size: .88rem;
      line-height: 1.6;
      color: #374151;
    }

    /* ── Success state ─────────────────────────────────────── */
    .ob-success-icon { font-size: 3.5rem; color: var(--ob-accent); }

    <?php if ($ob_custom_css): ?>
    /* ── custom CSS ──── */
    <?= $ob_custom_css . "\n" ?>
    <?php endif; ?>
  </style>
</head>
<body>
<div class="container py-5" style="max-width:600px">

  <?php if ($ob_logo): ?>
  <div class="text-center mb-3">
    <img src="<?= APP_URL . '/' . h($ob_logo) ?>"
         alt="<?= h($org_name) ?>"
         style="max-height:80px;max-width:240px;object-fit:contain">
  </div>
  <?php elseif ($org_name): ?>
  <div class="text-center mb-2 text-muted small fw-semibold"><?= h($org_name) ?></div>
  <?php endif; ?>

  <h4 class="text-center mb-4 fw-bold"><?= h($ob_title) ?></h4>

<?php if ($disabled_page): ?>
  <div class="ob-card">
    <div class="ob-card-body text-center py-5">
      <i class="bi bi-x-circle text-danger" style="font-size:3rem"></i>
      <h5 class="mt-3">Formularz niedostępny</h5>
      <p class="text-muted">Formularz rejestracyjny jest chwilowo niedostępny. Spróbuj ponownie później.</p>
    </div>
  </div>
<?php else: ?>

  <?php if ($step >= 1 && $step <= 4): ?>
  <!-- Step indicator -->
  <div class="step-indicator mb-4">
    <?php foreach ($step_labels as $i => $label):
      $num = $i + 1;
      $cls = '';
      if ($num < $step) $cls = 'done';
      elseif ($num === $step) $cls = 'active';
    ?>
    <div class="step-item <?= $cls ?>">
      <div class="step-circle">
        <?php if ($num < $step): ?>
          <i class="bi bi-check"></i>
        <?php else: ?>
          <?= $num ?>
        <?php endif; ?>
      </div>
      <div class="step-label"><?= h($label) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0 ps-3">
      <?php foreach ($errors as $e): ?>
      <li><?= h($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($success): ?>
  <div class="alert alert-success"><?= h($success) ?></div>
  <?php endif; ?>

  <div class="ob-card">
    <div class="ob-card-body">

    <?php
    // ═══════════════════════════════════════════════════════════
    // STEP 1
    // ═══════════════════════════════════════════════════════════
    if ($step === 1):
      if ($ob_intro): ?>
      <div class="mb-3 text-muted"><?= $ob_intro ?></div>
      <?php endif; ?>
      <form method="post" action="?step=1" id="step1form">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

        <div class="mb-3">
          <label class="form-label fw-semibold">Imię i nazwisko <span class="text-danger">*</span></label>
          <input type="text" name="imie_nazwisko" class="form-control"
                 value="<?= h($_POST['imie_nazwisko'] ?? '') ?>" required autofocus>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">PESEL <span class="text-muted fw-normal">(opcjonalnie)</span></label>
          <input type="text" name="pesel" id="pesel_input" class="form-control"
                 maxlength="11" pattern="\d{11}"
                 value="<?= h($_POST['pesel'] ?? '') ?>"
                 placeholder="Wypełnij, aby auto-uzupełnić datę urodzenia">
          <div class="form-text">Wprowadź PESEL, by automatycznie uzupełnić datę urodzenia.</div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">Data urodzenia</label>
          <input type="date" name="data_urodzenia" id="data_urodzenia_input" class="form-control"
                 value="<?= h($_POST['data_urodzenia'] ?? '') ?>">
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">Adres zamieszkania <span class="text-danger">*</span></label>
          <input type="text" name="adres" class="form-control"
                 value="<?= h($_POST['adres'] ?? '') ?>" required
                 placeholder="ul. Przykładowa 1, 00-000 Miasto">
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">Numer telefonu <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text">+48</span>
            <input type="tel" name="telefon" class="form-control phone-48"
                   id="telefon_input"
                   value="<?= h($_POST['telefon'] ?? '') ?>"
                   placeholder="123456789" maxlength="9" required>
          </div>
          <div class="form-text">Podaj 9-cyfrowy numer komórkowy.</div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">Adres e-mail <span class="text-danger">*</span></label>
          <input type="email" name="email" class="form-control"
                 value="<?= h($_POST['email'] ?? '') ?>" required>
        </div>

        <div class="d-grid">
          <button type="submit" class="btn btn-ob-primary">
            Dalej <i class="bi bi-arrow-right"></i>
          </button>
        </div>
      </form>

      <script>
      // PESEL → data urodzenia
      function peselToBirthdate(pesel) {
        if (!/^\d{11}$/.test(pesel)) return null;
        var y = parseInt(pesel.substring(0,2), 10);
        var m = parseInt(pesel.substring(2,4), 10);
        var d = parseInt(pesel.substring(4,6), 10);
        var year;
        if (m >= 81 && m <= 92)      { year = 1800 + y; m -= 80; }
        else if (m >= 1  && m <= 12)  { year = 1900 + y; }
        else if (m >= 21 && m <= 32)  { year = 2000 + y; m -= 20; }
        else if (m >= 41 && m <= 52)  { year = 2100 + y; m -= 40; }
        else if (m >= 61 && m <= 72)  { year = 2200 + y; m -= 60; }
        else return null;
        var mm = String(m).padStart(2,'0');
        var dd = String(d).padStart(2,'0');
        return year + '-' + mm + '-' + dd;
      }
      document.getElementById('pesel_input').addEventListener('input', function() {
        var bd = peselToBirthdate(this.value);
        if (bd) document.getElementById('data_urodzenia_input').value = bd;
      });
      // Phone: strip leading 48 for display, submit as 9 digits (server prepends 48)
      document.getElementById('step1form').addEventListener('submit', function() {
        var inp = document.getElementById('telefon_input');
        var val = inp.value.replace(/\D/g,'');
        if (val.length === 11 && val.startsWith('48')) val = val.substring(2);
        inp.value = val;
      });
      </script>

    <?php
    // ═══════════════════════════════════════════════════════════
    // STEP 2
    // ═══════════════════════════════════════════════════════════
    elseif ($step === 2):
      $display_phone = $vol ? ('+' . $vol['telefon']) : '';
    ?>
      <h5 class="mb-3"><i class="bi bi-phone"></i> Weryfikacja numeru telefonu</h5>
      <p class="text-muted mb-3">
        Wysłaliśmy kod SMS na numer <strong><?= h($display_phone) ?></strong>.<br>
        Wpisz go poniżej, aby potwierdzić swój numer telefonu.
      </p>

      <form method="post" action="?step=2">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="verify">
        <div class="mb-3">
          <label class="form-label fw-semibold">Kod weryfikacyjny (6 cyfr)</label>
          <input type="text" name="code" class="form-control form-control-lg text-center"
                 maxlength="6" pattern="\d{6}" placeholder="000000"
                 autocomplete="one-time-code" autofocus>
        </div>
        <div class="d-grid mb-3">
          <button type="submit" class="btn btn-ob-primary">
            <i class="bi bi-check-lg"></i> Zweryfikuj kod
          </button>
        </div>
      </form>

      <form method="post" action="?step=2" class="text-center">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="resend">
        <button type="submit" class="btn btn-link btn-sm text-muted">
          <i class="bi bi-arrow-clockwise"></i> Wyślij kod ponownie
        </button>
      </form>

    <?php
    // ═══════════════════════════════════════════════════════════
    // STEP 3
    // ═══════════════════════════════════════════════════════════
    elseif ($step === 3):
      $display_email = $vol ? $vol['email'] : '';
    ?>
      <h5 class="mb-3"><i class="bi bi-envelope"></i> Weryfikacja adresu e-mail</h5>
      <p class="text-muted mb-3">
        Wysłaliśmy link weryfikacyjny na adres <strong><?= h($display_email) ?></strong>.<br>
        Kliknij w link w wiadomości, a następnie wróć tutaj i naciśnij przycisk poniżej.
      </p>

      <form method="post" action="?step=3">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="check">
        <div class="d-grid mb-3">
          <button type="submit" class="btn btn-ob-primary">
            <i class="bi bi-arrow-clockwise"></i> Sprawdź weryfikację
          </button>
        </div>
      </form>

      <form method="post" action="?step=3" class="text-center">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="resend_email">
        <button type="submit" class="btn btn-link btn-sm text-muted">
          <i class="bi bi-send"></i> Wyślij link ponownie
        </button>
      </form>

    <?php
    // ═══════════════════════════════════════════════════════════
    // STEP 4
    // ═══════════════════════════════════════════════════════════
    elseif ($step === 4):
      $klauzula_text = get_setting('onboarding_klauzula');
      if ($klauzula_text === '') {
          $klauzula_text = "Administratorem danych osobowych jest organizacja. Dane przetwarzane są w celu realizacji wolontariatu na podstawie zgody (art. 6 ust. 1 lit. a RODO). Przysługuje Pani/Panu prawo dostępu do danych, ich sprostowania, usunięcia, ograniczenia przetwarzania oraz wniesienia skargi do organu nadzorczego.";
      }
    ?>
      <h5 class="mb-3"><i class="bi bi-shield-check"></i> Klauzula informacyjna RODO</h5>
      <div class="klauzula-box mb-3"><?= h($klauzula_text) ?></div>

      <form method="post" action="?step=4">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <div class="mb-3 form-check">
          <input type="checkbox" class="form-check-input" name="klauzula" value="1" id="klauzula_check" required>
          <label class="form-check-label" for="klauzula_check">
            Zapoznałam/em się z treścią klauzuli informacyjnej i wyrażam zgodę na przetwarzanie moich danych osobowych w celu wolontariatu.
          </label>
        </div>
        <div class="d-grid">
          <button type="submit" class="btn btn-success">
            <i class="bi bi-check-lg"></i> Akceptuję i wysyłam zgłoszenie
          </button>
        </div>
      </form>

    <?php
    // ═══════════════════════════════════════════════════════════
    // STEP 5
    // ═══════════════════════════════════════════════════════════
    elseif ($step === 5):
    ?>
      <div class="text-center py-4">
        <i class="bi bi-check-circle-fill text-success" style="font-size:4rem"></i>
        <h4 class="mt-3 fw-bold">Dziękujemy!</h4>
        <p class="text-muted">
          Twoje zgłoszenie zostało przyjęte.<br>
          Skontaktujemy się z Tobą po weryfikacji danych.
        </p>
      </div>
    <?php endif; ?>

    </div><!-- /.ob-card-body -->
  </div><!-- /.ob-card -->

<?php endif; // disabled_page ?>

</div><!-- /.container -->
<script src="<?= APP_URL ?>/assets/bootstrap.bundle.min.js"></script>
</body>
</html>
