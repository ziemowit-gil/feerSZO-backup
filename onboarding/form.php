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
if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $ob_accent)) $ob_accent = '#0d6efd';
if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $ob_bg))     $ob_bg     = '#f0f4f8';

$disabled_page = ($enabled === '0');

// ─── session / step routing ─────────────────────────────────────────────────

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($step < 1 || $step > 6) $step = 1;

$ob_id = $_SESSION['ob_id'] ?? null;

if ($step > 1 && $step < 6 && !$ob_id) {
    ob_redirect(1);
}

$errors  = [];
$success = '';

// ═══════════════════════════════════════════════════════════════════════════
// POST HANDLING
// ═══════════════════════════════════════════════════════════════════════════

if (!$disabled_page && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // ── Step 1 POST ──────────────────────────────────────────────────────
    if ($step === 1) {
        $imie_nazwisko  = trim($_POST['imie_nazwisko'] ?? '');
        $pesel          = trim($_POST['pesel'] ?? '');
        $data_urodzenia = trim($_POST['data_urodzenia'] ?? '');
        $adres          = trim($_POST['adres'] ?? '');
        $telefon_raw    = preg_replace('/\D/', '', trim($_POST['telefon'] ?? ''));
        $email          = trim($_POST['email'] ?? '');

        $miejsce        = trim($_POST['miejsce_wolontariatu'] ?? '');
        $przedmiot      = trim($_POST['przedmiot_porozumienia'] ?? '');
        $data_rozp      = trim($_POST['data_rozpoczecia'] ?? '');
        $data_zak       = trim($_POST['data_zakonczenia'] ?? '');

        if ($imie_nazwisko === '') $errors[] = 'Imię i nazwisko jest wymagane.';
        if ($adres === '')         $errors[] = 'Adres jest wymagany.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Podaj prawidłowy adres e-mail.';

        $telefon_norm = $telefon_raw;
        if (strlen($telefon_raw) === 9) {
            $telefon_norm = '48' . $telefon_raw;
        } elseif (strlen($telefon_raw) === 11 && str_starts_with($telefon_raw, '48')) {
            $telefon_norm = $telefon_raw;
        } elseif ($telefon_raw === '') {
            $errors[] = 'Numer telefonu jest wymagany.';
        } else {
            if (strlen($telefon_raw) < 9) {
                $errors[] = 'Podaj prawidłowy numer telefonu (9 cyfr).';
            }
        }

        if ($pesel !== '' && $data_urodzenia === '') {
            $bd = pesel_to_birthdate($pesel);
            if ($bd) $data_urodzenia = $bd;
        }

        if (empty($errors)) {
            $token = bin2hex(random_bytes(16));
            $new_id = db_insert('onboarding_volunteers', [
                'session_token'          => $token,
                'status'                 => 'new',
                'imie_nazwisko'          => $imie_nazwisko,
                'pesel'                  => $pesel,
                'data_urodzenia'         => $data_urodzenia,
                'adres'                  => $adres,
                'telefon'                => $telefon_norm,
                'email'                  => $email,
                'miejsce_wolontariatu'   => $miejsce,
                'przedmiot_porozumienia' => $przedmiot,
                'data_rozpoczecia'       => $data_rozp,
                'data_zakonczenia'       => $data_zak,
                'phone_verified'         => 0,
                'email_verified'         => 0,
                'klauzula_accepted'      => 0,
                'ip_address'             => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent'             => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'created_at'             => date('Y-m-d H:i:s'),
                'updated_at'             => date('Y-m-d H:i:s'),
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
                    'phone_verified' => 1,
                    'phone_code'     => null,
                    'updated_at'     => date('Y-m-d H:i:s'),
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

    // ── Step 4 POST — klauzula RODO ──────────────────────────────────────
    if ($step === 4) {
        $klauzula_text = get_setting('onboarding_klauzula');
        if ($klauzula_text === '') {
            $klauzula_text = "Administratorem danych osobowych jest organizacja. Dane przetwarzane są w celu realizacji wolontariatu na podstawie zgody (art. 6 ust. 1 lit. a RODO). Przysługuje Pani/Panu prawo dostępu do danych, ich sprostowania, usunięcia, ograniczenia przetwarzania oraz wniesienia skargi do organu nadzorczego.";
        }
        $accepted = isset($_POST['klauzula']) && $_POST['klauzula'] === '1';
        if (!$accepted) {
            $errors[] = 'Musisz zaakceptować klauzulę informacyjną, aby kontynuować.';
        } else {
            db_update('onboarding_volunteers', [
                'klauzula_accepted' => 1,
                'klauzula_version'  => md5($klauzula_text),
                'updated_at'        => date('Y-m-d H:i:s'),
            ], $ob_id);
            $_SESSION['ob_step'] = 5;
            ob_redirect(5);
        }
    }

    // ── Step 5 POST — oświadczenie + zakończenie ──────────────────────────
    if ($step === 5) {
        $vol      = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$ob_id]);
        $file_ok  = false;
        $file_path = $vol['oswiadczenie_file'] ?? '';

        if (!empty($_FILES['oswiadczenie']['tmp_name'])) {
            $file    = $_FILES['oswiadczenie'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif'];
            if (!in_array($ext, $allowed_ext, true)) {
                $errors[] = 'Dozwolone formaty pliku: PDF, JPG, PNG, TIFF.';
            } elseif ($file['size'] > 10 * 1024 * 1024) {
                $errors[] = 'Plik nie może być większy niż 10 MB.';
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Błąd przesyłania pliku. Spróbuj ponownie.';
            } else {
                $dest_dir = rtrim(UPLOAD_DIR, '/') . '/onboarding/oswiadczenia/';
                if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);
                $new_name = 'oswiad_' . $ob_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dest_dir . $new_name)) {
                    $file_path = 'uploads/onboarding/oswiadczenia/' . $new_name;
                    db_update('onboarding_volunteers', [
                        'oswiadczenie_file' => $file_path,
                        'updated_at'        => date('Y-m-d H:i:s'),
                    ], $ob_id);
                    $file_ok = true;
                } else {
                    $errors[] = 'Nie udało się zapisać pliku. Sprawdź uprawnienia i spróbuj ponownie.';
                }
            }
        } elseif ($file_path !== '') {
            // Plik już wcześniej przesłany
            $file_ok = true;
        } else {
            $errors[] = 'Oświadczenie jest wymagane. Prześlij skan lub zdjęcie dokumentu.';
        }

        if ($file_ok && empty($errors)) {
            // Utwórz konto portalu, jeśli jeszcze nie istnieje
            $user_id = $vol['user_id'] ? (int)$vol['user_id'] : null;
            if (!$user_id && filter_var($vol['email'], FILTER_VALIDATE_EMAIL)) {
                $existing = db_one("SELECT id FROM users WHERE email=?", [$vol['email']]);
                if ($existing) {
                    $user_id = (int)$existing['id'];
                } else {
                    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
                    db_insert('users', [
                        'name'       => $vol['imie_nazwisko'] ?: $vol['email'],
                        'email'      => $vol['email'],
                        'password'   => $hash,
                        'role'       => 'viewer',
                        'is_active'  => 1,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $user_id   = (int)db()->lastInsertId();
                    $setup_tok = auth_generate_setup_token($user_id);
                    $setup_url = APP_URL . '/auth/set_password.php?token=' . $setup_tok;
                    $org       = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
                    $html_act  = '<p>Witaj ' . h($vol['imie_nazwisko']) . ',</p>'
                               . '<p>Twoje zgłoszenie wolontariackie zostało przyjęte i oczekuje na weryfikację. '
                               . 'Możesz już ustawić hasło do swojego konta — kliknij poniższy link:</p>'
                               . '<p><a href="' . $setup_url . '">' . $setup_url . '</a></p>'
                               . '<p>Link jest jednorazowy i wygasa po 24 godzinach. '
                               . 'Skontaktujemy się z Tobą po zweryfikowaniu danych przez koordynatora.</p>';
                    approval_send_email($vol['email'], 'Aktywuj konto — ' . $org, $html_act);
                }
                db_update('onboarding_volunteers', [
                    'user_id'    => $user_id,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], $ob_id);
            }

            db_update('onboarding_volunteers', [
                'status'     => 'pending',
                'updated_at' => date('Y-m-d H:i:s'),
            ], $ob_id);

            // Powiadomienie koordynatora
            $notify_email = get_setting('onboarding_notify_email');
            if ($notify_email !== '') {
                $link = APP_URL . '/onboarding/view.php?id=' . $ob_id;
                $html = '<p>Nowe zgłoszenie wolontariusza: <strong>' . h($vol['imie_nazwisko']) . '</strong>'
                      . ' (' . h($vol['email']) . ').</p>'
                      . '<p>Oświadczenie podatkowe/ZUS zostało dołączone do zgłoszenia.</p>'
                      . '<p><a href="' . $link . '">Przejdź do zgłoszenia</a></p>';
                approval_send_email($notify_email,
                    'Nowe zgłoszenie wolontariusza: ' . $vol['imie_nazwisko'] . ' (' . $vol['email'] . ')',
                    $html);
            }

            $_SESSION['ob_step'] = 6;
            ob_redirect(6);
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
            db_update('onboarding_volunteers', [
                'phone_verified' => 1,
                'updated_at'     => date('Y-m-d H:i:s'),
            ], $ob_id);
            $_SESSION['ob_step'] = 3;
            ob_redirect(3);
        }

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

        if ((int)$vol['email_verified'] === 1) {
            $_SESSION['ob_step'] = 4;
            ob_redirect(4);
        }

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

    // ── Step 6 GET — clear session ───────────────────────────────────────
    if ($step === 6) {
        unset($_SESSION['ob_id'], $_SESSION['ob_step']);
    }
}

// ─── reload vol record for display ──────────────────────────────────────────

$vol = null;
if ($ob_id && $step > 1 && $step <= 5) {
    $vol = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$ob_id]);
}

// ═══════════════════════════════════════════════════════════════════════════
// RENDER
// ═══════════════════════════════════════════════════════════════════════════

$page_title = h($ob_title) . ($org_name ? ' — ' . h($org_name) : '');

$step_labels = ['Dane', 'Telefon', 'E-mail', 'Klauzula', 'Oświadczenie'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $page_title ?></title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/bootstrap.min.css">
  <style>
    :root {
      --ob-accent: <?= h($ob_accent) ?>;
      --ob-bg:     <?= h($ob_bg) ?>;
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
      left: calc(100% / 10);
      right: calc(100% / 10);
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
      font-size: .75rem;
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

    /* ── Upload area ───────────────────────────────────────── */
    .upload-area {
      border: 2px dashed #ced4da;
      border-radius: .5rem;
      padding: 2rem;
      text-align: center;
      background: #fafafa;
      cursor: pointer;
      transition: border-color .2s, background .2s;
    }
    .upload-area:hover,
    .upload-area.dragover {
      border-color: var(--ob-accent);
      background: color-mix(in srgb, var(--ob-accent) 5%, #fff);
    }
    .upload-area input[type=file] {
      position: absolute;
      width: 0;
      height: 0;
      opacity: 0;
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
      <p class="text-muted mb-0">Formularz rejestracyjny jest chwilowo niedostępny. Spróbuj ponownie później.</p>
    </div>
  </div>
<?php else: ?>

  <?php if ($step >= 1 && $step <= 5): ?>
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
          &#10003;
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
    // STEP 1 — dane podstawowe i dane do umowy
    // ═══════════════════════════════════════════════════════════
    if ($step === 1):
      if ($ob_intro): ?>
      <div class="mb-3 text-muted"><?= $ob_intro ?></div>
      <?php endif; ?>
      <form method="post" action="?step=1" id="step1form">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

        <h6 class="mb-3 fw-semibold text-secondary border-bottom pb-2">Dane osobowe</h6>

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
            <input type="tel" name="telefon" class="form-control"
                   id="telefon_input"
                   value="<?= h($_POST['telefon'] ?? '') ?>"
                   placeholder="123456789" maxlength="9" required>
          </div>
          <div class="form-text">Podaj 9-cyfrowy numer komórkowy.</div>
        </div>

        <div class="mb-4">
          <label class="form-label fw-semibold">Adres e-mail <span class="text-danger">*</span></label>
          <input type="email" name="email" class="form-control"
                 value="<?= h($_POST['email'] ?? '') ?>" required>
          <div class="form-text">Na ten adres wyślemy link do aktywacji konta.</div>
        </div>

        <h6 class="mb-3 fw-semibold text-secondary border-bottom pb-2">Dane do porozumienia wolontariackiego</h6>

        <div class="mb-3">
          <label class="form-label fw-semibold">Miejsce wolontariatu</label>
          <input type="text" name="miejsce_wolontariatu" class="form-control"
                 value="<?= h($_POST['miejsce_wolontariatu'] ?? '') ?>"
                 placeholder="np. Biuro główne, ul. Przykładowa 1">
          <div class="form-text">Gdzie będziesz wykonywać wolontariat?</div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold">Zakres działań / przedmiot porozumienia</label>
          <textarea name="przedmiot_porozumienia" class="form-control" rows="3"
                    placeholder="Krótko opisz, w czym chcesz pomagać..."><?= h($_POST['przedmiot_porozumienia'] ?? '') ?></textarea>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Planowana data rozpoczecia</label>
            <input type="date" name="data_rozpoczecia" class="form-control"
                   value="<?= h($_POST['data_rozpoczecia'] ?? '') ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Planowana data zakończenia</label>
            <input type="date" name="data_zakonczenia" class="form-control"
                   value="<?= h($_POST['data_zakonczenia'] ?? '') ?>">
          </div>
        </div>

        <div class="d-grid">
          <button type="submit" class="btn btn-ob-primary">
            Dalej &rarr;
          </button>
        </div>
      </form>

      <script>
      function peselToBirthdate(pesel) {
        if (!/^\d{11}$/.test(pesel)) return null;
        var y = parseInt(pesel.substring(0,2), 10);
        var m = parseInt(pesel.substring(2,4), 10);
        var d = parseInt(pesel.substring(4,6), 10);
        var year;
        if (m >= 81 && m <= 92)     { year = 1800 + y; m -= 80; }
        else if (m >= 1  && m <= 12) { year = 1900 + y; }
        else if (m >= 21 && m <= 32) { year = 2000 + y; m -= 20; }
        else if (m >= 41 && m <= 52) { year = 2100 + y; m -= 40; }
        else if (m >= 61 && m <= 72) { year = 2200 + y; m -= 60; }
        else return null;
        return year + '-' + String(m).padStart(2,'0') + '-' + String(d).padStart(2,'0');
      }
      document.getElementById('pesel_input').addEventListener('input', function() {
        var bd = peselToBirthdate(this.value);
        if (bd) document.getElementById('data_urodzenia_input').value = bd;
      });
      document.getElementById('step1form').addEventListener('submit', function() {
        var inp = document.getElementById('telefon_input');
        var val = inp.value.replace(/\D/g,'');
        if (val.length === 11 && val.startsWith('48')) val = val.substring(2);
        inp.value = val;
      });
      </script>

    <?php
    // ═══════════════════════════════════════════════════════════
    // STEP 2 — weryfikacja SMS
    // ═══════════════════════════════════════════════════════════
    elseif ($step === 2):
      $display_phone = $vol ? ('+' . $vol['telefon']) : '';
    ?>
      <h5 class="mb-3">Weryfikacja numeru telefonu</h5>
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
          <button type="submit" class="btn btn-ob-primary">Zweryfikuj kod</button>
        </div>
      </form>

      <form method="post" action="?step=2" class="text-center">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="resend">
        <button type="submit" class="btn btn-link btn-sm text-muted">
          Wyslij kod ponownie
        </button>
      </form>

    <?php
    // ═══════════════════════════════════════════════════════════
    // STEP 3 — weryfikacja e-mail
    // ═══════════════════════════════════════════════════════════
    elseif ($step === 3):
      $display_email = $vol ? $vol['email'] : '';
    ?>
      <h5 class="mb-3">Weryfikacja adresu e-mail</h5>
      <p class="text-muted mb-3">
        Wysłaliśmy link weryfikacyjny na adres <strong><?= h($display_email) ?></strong>.<br>
        Kliknij w link w wiadomości, a następnie wróć tutaj i naciśnij przycisk poniżej.
      </p>

      <form method="post" action="?step=3">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="check">
        <div class="d-grid mb-3">
          <button type="submit" class="btn btn-ob-primary">Sprawdz weryfikację</button>
        </div>
      </form>

      <form method="post" action="?step=3" class="text-center">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="resend_email">
        <button type="submit" class="btn btn-link btn-sm text-muted">
          Wyslij link ponownie
        </button>
      </form>

    <?php
    // ═══════════════════════════════════════════════════════════
    // STEP 4 — klauzula RODO
    // ═══════════════════════════════════════════════════════════
    elseif ($step === 4):
      $klauzula_text = get_setting('onboarding_klauzula');
      if ($klauzula_text === '') {
          $klauzula_text = "Administratorem danych osobowych jest organizacja. Dane przetwarzane są w celu realizacji wolontariatu na podstawie zgody (art. 6 ust. 1 lit. a RODO). Przysługuje Pani/Panu prawo dostępu do danych, ich sprostowania, usunięcia, ograniczenia przetwarzania oraz wniesienia skargi do organu nadzorczego.";
      }
    ?>
      <h5 class="mb-3">Klauzula informacyjna RODO</h5>
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
          <button type="submit" class="btn btn-ob-primary">
            Akceptuję i przechodzę dalej &rarr;
          </button>
        </div>
      </form>

    <?php
    // ═══════════════════════════════════════════════════════════
    // STEP 5 — oświadczenie do celów podatkowych i ZUS
    // ═══════════════════════════════════════════════════════════
    elseif ($step === 5):
      $existing_file = $vol['oswiadczenie_file'] ?? '';
    ?>
      <h5 class="mb-2">Oświadczenie do celów podatkowych i ZUS</h5>
      <p class="text-muted mb-4" style="font-size:.93rem">
        Wypełnij i podpisz oświadczenie, a następnie prześlij jego skan lub zdjęcie.
        Dokument jest niezbędny do zawarcia porozumienia wolontariackiego.
        Dozwolone formaty: <strong>PDF, JPG, PNG, TIFF</strong> — maks. 10 MB.
      </p>

      <?php if ($existing_file): ?>
      <div class="alert alert-success py-2 mb-3">
        Plik juz przesłany. Możesz zastąpić go nowym lub kliknąć "Wyslij zgłoszenie", aby kontynuować.
      </div>
      <?php endif; ?>

      <form method="post" action="?step=5" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

        <div class="mb-4">
          <label class="form-label fw-semibold" for="oswiadczenie">
            Skan oświadczenia <?= $existing_file ? '' : '<span class="text-danger">*</span>' ?>
          </label>
          <div class="upload-area" id="upload-area" onclick="document.getElementById('oswiadczenie').click()">
            <input type="file" name="oswiadczenie" id="oswiadczenie"
                   accept=".pdf,.jpg,.jpeg,.png,.tiff,.tif"
                   <?= $existing_file ? '' : 'required' ?>>
            <p class="mb-1 fw-semibold" id="upload-label">Kliknij, aby wybrać plik</p>
            <p class="text-muted mb-0" style="font-size:.85rem">lub przeciągnij plik tutaj</p>
          </div>
        </div>

        <div class="d-grid">
          <button type="submit" class="btn btn-ob-primary">
            Wyslij zgłoszenie
          </button>
        </div>
      </form>

      <script>
      var uploadInput = document.getElementById('oswiadczenie');
      var uploadLabel = document.getElementById('upload-label');
      var uploadArea  = document.getElementById('upload-area');

      uploadInput.addEventListener('change', function() {
        if (this.files.length > 0) {
          uploadLabel.textContent = this.files[0].name;
          uploadArea.style.borderColor = 'var(--ob-accent)';
        }
      });
      uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
      });
      uploadArea.addEventListener('dragleave', function() {
        this.classList.remove('dragover');
      });
      uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) {
          uploadInput.files = e.dataTransfer.files;
          uploadLabel.textContent = e.dataTransfer.files[0].name;
          this.style.borderColor = 'var(--ob-accent)';
        }
      });
      </script>

    <?php
    // ═══════════════════════════════════════════════════════════
    // STEP 6 — dziękujemy
    // ═══════════════════════════════════════════════════════════
    elseif ($step === 6):
    ?>
      <div class="text-center py-4">
        <div class="ob-success-icon mb-3">&#10003;</div>
        <h4 class="fw-bold">Dziękujemy za zgłoszenie!</h4>
        <p class="text-muted">
          Twoje zgłoszenie zostało przyjęte i oczekuje na weryfikację koordynatora.<br>
          Na podany adres e-mail wysłaliśmy link do aktywacji konta — sprawdź skrzynkę pocztową.
        </p>
        <p class="text-muted" style="font-size:.9rem">
          Po zatwierdzeniu przez koordynatora zostaniesz poinformowany/a o dalszych krokach.
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
