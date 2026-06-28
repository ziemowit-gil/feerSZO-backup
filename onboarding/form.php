<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/onboarding_schema.php';
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

$enabled       = get_setting('onboarding_enabled');
$ob_title      = get_setting('onboarding_title') ?: 'Rejestracja';
$ob_intro      = get_setting('onboarding_intro');
$org_name      = defined('ORG_NAME') ? ORG_NAME : '';
$ob_logo       = get_setting('onboarding_logo');
$ob_accent     = get_setting('onboarding_accent_color') ?: '#0d6efd';
$ob_bg         = get_setting('onboarding_bg_color')     ?: '#f0f4f8';

// Pre-compute RGB components for CSS custom property (needed for rgba() alpha usage)
(function () use ($ob_accent, &$ob_accent_rgb) {
    $hex = ltrim($ob_accent, '#');
    if (strlen($hex) === 3) {
        $ob_accent_rgb = hexdec($hex[0].$hex[0]) . ',' . hexdec($hex[1].$hex[1]) . ',' . hexdec($hex[2].$hex[2]);
    } else {
        $ob_accent_rgb = hexdec(substr($hex,0,2)) . ',' . hexdec(substr($hex,2,2)) . ',' . hexdec(substr($hex,4,2));
    }
})();
$ob_custom_css = get_setting('onboarding_custom_css');
if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $ob_accent)) $ob_accent = '#0d6efd';
if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $ob_bg))     $ob_bg     = '#f0f4f8';

$disabled_page = ($enabled === '0');

// ─── session / step routing ─────────────────────────────────────────────────

$step  = isset($_GET['step']) ? (int)$_GET['step'] : 1;
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
        $typ           = in_array($_POST['typ'] ?? '', ['wolontariusz', 'zleceniobiorca'], true)
                         ? $_POST['typ'] : 'wolontariusz';
        $imie_nazwisko = trim($_POST['imie_nazwisko'] ?? '');
        $pesel         = trim($_POST['pesel'] ?? '');
        $data_urodzenia = trim($_POST['data_urodzenia'] ?? '');
        $telefon_raw   = preg_replace('/\D/', '', trim($_POST['telefon'] ?? ''));
        $email         = trim($_POST['email'] ?? '');

        if ($imie_nazwisko === '') $errors[] = 'Imię i nazwisko jest wymagane.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Podaj prawidłowy adres e-mail.';

        // Telefon
        $telefon_norm = $telefon_raw;
        if (strlen($telefon_raw) === 9) {
            $telefon_norm = '48' . $telefon_raw;
        } elseif (strlen($telefon_raw) === 11 && str_starts_with($telefon_raw, '48')) {
            $telefon_norm = $telefon_raw;
        } elseif ($telefon_raw === '') {
            $errors[] = 'Numer telefonu jest wymagany.';
        } elseif (strlen($telefon_raw) < 9) {
            $errors[] = 'Podaj prawidłowy numer telefonu (9 cyfr).';
        }

        if ($pesel !== '' && $data_urodzenia === '') {
            $bd = pesel_to_birthdate($pesel);
            if ($bd) $data_urodzenia = $bd;
        }

        // Pola zależne od typu
        if ($typ === 'wolontariusz') {
            $adres = trim($_POST['adres'] ?? '');
            if ($adres === '') $errors[] = 'Adres jest wymagany.';

            $row_extra = [
                'adres' => $adres,
            ];
        } else {
            // zleceniobiorca — adres strukturalny
            $addr_street = trim($_POST['addr_street'] ?? '');
            $addr_house  = trim($_POST['addr_house']  ?? '');
            $addr_postal = trim($_POST['addr_postal'] ?? '');
            $addr_city   = trim($_POST['addr_city']   ?? '');
            if ($addr_street === '') $errors[] = 'Ulica jest wymagana.';
            if ($addr_house  === '') $errors[] = 'Numer domu jest wymagany.';
            if ($addr_postal === '') $errors[] = 'Kod pocztowy jest wymagany.';
            if ($addr_city   === '') $errors[] = 'Miejscowość jest wymagana.';

            $seria_nr   = trim($_POST['seria_nr_dowodu'] ?? '');
            $urzad_sk   = trim($_POST['urzad_skarbowy'] ?? '');
            $rachunek   = preg_replace('/\s/', '', trim($_POST['rachunek_bankowy'] ?? ''));
            // Walidacja numeru konta (26 cyfr)
            if ($rachunek !== '' && (!ctype_digit($rachunek) || strlen($rachunek) !== 26)) {
                $errors[] = 'Numer rachunku bankowego musi mieć 26 cyfr (bez spacji).';
            }

            // Zbuduj adres jednoliniowy jako adres fallback
            $adres_parts = array_filter([$addr_street . ' ' . $addr_house, trim($_POST['addr_flat'] ?? '') !== '' ? 'm. ' . trim($_POST['addr_flat']) : '', $addr_postal . ' ' . $addr_city]);
            $adres = implode(', ', $adres_parts);

            $row_extra = [
                'adres'           => $adres,
                'addr_street'     => $addr_street,
                'addr_house'      => $addr_house,
                'addr_flat'       => trim($_POST['addr_flat'] ?? ''),
                'addr_postal'     => $addr_postal,
                'addr_city'       => $addr_city,
                'seria_nr_dowodu' => $seria_nr,
                'urzad_skarbowy'  => $urzad_sk,
                'rachunek_bankowy'=> $rachunek,
            ];
        }

        if (empty($errors)) {
            $token  = bin2hex(random_bytes(16));
            $new_id = db_insert('onboarding_volunteers', array_merge([
                'session_token'  => $token,
                'status'         => 'new',
                'typ'            => $typ,
                'imie_nazwisko'  => $imie_nazwisko,
                'pesel'          => $pesel,
                'data_urodzenia' => $data_urodzenia,
                'telefon'        => $telefon_norm,
                'email'          => $email,
                'phone_verified' => 0,
                'email_verified' => 0,
                'klauzula_accepted' => 0,
                'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ], $row_extra));
            $_SESSION['ob_id']   = $new_id;
            $_SESSION['ob_step'] = 2;
            ob_redirect(2);
        }
    }

    // ── Step 2 POST — weryfikacja SMS ─────────────────────────────────────
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

    // ── Step 3 POST — weryfikacja e-mail ──────────────────────────────────
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
            $klauzula_text = "Administratorem danych osobowych jest organizacja. Dane przetwarzane są w celu realizacji wolontariatu lub umowy cywilnoprawnej na podstawie zgody (art. 6 ust. 1 lit. a RODO). Przysługuje Pani/Panu prawo dostępu do danych, ich sprostowania, usunięcia, ograniczenia przetwarzania oraz wniesienia skargi do organu nadzorczego.";
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
        $vol       = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$ob_id]);
        $file_ok   = false;
        $file_path = $vol['oswiadczenie_file'] ?? '';

        if (!empty($_FILES['oswiadczenie']['tmp_name'])) {
            $file = $_FILES['oswiadczenie'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
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
                    $errors[] = 'Nie udało się zapisać pliku. Spróbuj ponownie.';
                }
            }
        } elseif ($file_path !== '') {
            $file_ok = true;
        } else {
            $errors[] = 'Oświadczenie jest wymagane. Prześlij skan lub zdjęcie dokumentu.';
        }

        if ($file_ok && empty($errors)) {
            // Utwórz konto portalu
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
                    $typ_label = $vol['typ'] === 'zleceniobiorca' ? 'zleceniobiorcy' : 'wolontariackie';
                    $html_act  = '<p>Witaj ' . h($vol['imie_nazwisko']) . ',</p>'
                               . '<p>Twoje zgłoszenie ' . $typ_label . ' zostało przyjęte i oczekuje na weryfikację. '
                               . 'Możesz już ustawić hasło do swojego konta — kliknij poniższy link:</p>'
                               . '<p><a href="' . $setup_url . '">' . $setup_url . '</a></p>'
                               . '<p>Link jest jednorazowy i wygasa po 24 godzinach.</p>';
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

            $notify_email = get_setting('onboarding_notify_email');
            if ($notify_email !== '') {
                $link      = APP_URL . '/onboarding/view.php?id=' . $ob_id;
                $typ_label = $vol['typ'] === 'zleceniobiorca' ? 'zleceniobiorcy' : 'wolontariusza';
                $html      = '<p>Nowe zgłoszenie ' . $typ_label . ': <strong>' . h($vol['imie_nazwisko']) . '</strong>'
                           . ' (' . h($vol['email']) . ').</p>'
                           . '<p>Oświadczenie podatkowe/ZUS zostało dołączone do zgłoszenia.</p>'
                           . '<p><a href="' . $link . '">Przejdź do zgłoszenia</a></p>';
                approval_send_email($notify_email,
                    'Nowe zgłoszenie ' . $typ_label . ': ' . $vol['imie_nazwisko'] . ' (' . $vol['email'] . ')',
                    $html);
            }

            $_SESSION['ob_step'] = 6;
            ob_redirect(6);
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// GET HANDLING
// ═══════════════════════════════════════════════════════════════════════════

if (!$disabled_page && $_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($step === 2 && $ob_id) {
        $require_sms = get_setting('onboarding_require_sms');
        $sms_ok      = sms_is_enabled();

        if ($require_sms !== '1' || !$sms_ok) {
            db_update('onboarding_volunteers', ['phone_verified' => 1, 'updated_at' => date('Y-m-d H:i:s')], $ob_id);
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

    if ($step === 3 && $ob_id) {
        $require_email = get_setting('onboarding_require_email');

        if ($require_email !== '1') {
            db_update('onboarding_volunteers', ['email_verified' => 1, 'updated_at' => date('Y-m-d H:i:s')], $ob_id);
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
            $link = APP_URL . '/onboarding/verify_email.php?token=' . $token . '&id=' . $ob_id;
            $html = '<p>Kliknij poniższy link, aby zweryfikować swój adres e-mail:</p>'
                  . '<p><a href="' . $link . '">' . $link . '</a></p>';
            approval_send_email($vol['email'], 'Weryfikacja adresu e-mail', $html);
        }
    }

    if ($step === 6) {
        unset($_SESSION['ob_id'], $_SESSION['ob_step']);
    }
}

// ─── reload vol record ───────────────────────────────────────────────────────

$vol = null;
if ($ob_id && $step > 1 && $step <= 5) {
    $vol = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$ob_id]);
}

// ─── compute dynamic step list (skip hidden verification steps) ──────────────
// Steps: 1=Dane, 2=SMS(opt), 3=Email(opt), 4=Klauzula, 5=Oświadczenie
// We map URL step numbers to a visible progress position.
$_require_sms   = get_setting('onboarding_require_sms')   === '1' && sms_is_enabled();
$_require_email = get_setting('onboarding_require_email') === '1';

// Build ordered list of active URL steps (those that won't be instantly skipped)
$_active_steps = [1];
if ($_require_sms)   $_active_steps[] = 2;
if ($_require_email) $_active_steps[] = 3;
$_active_steps[] = 4;
$_active_steps[] = 5;

// Label for each URL step
$_step_names = [
    1 => 'Dane',
    2 => 'Telefon',
    3 => 'E-mail',
    4 => 'Zgoda RODO',
    5 => 'Oświadczenie',
];

// Position of current step in the visible list (1-based)
$_step_pos   = array_search($step, $_active_steps, true);
$_step_pos   = $_step_pos !== false ? $_step_pos + 1 : null; // null on step 6
$_step_total = count($_active_steps);

// ═══════════════════════════════════════════════════════════════════════════
// RENDER
// ═══════════════════════════════════════════════════════════════════════════

$page_title = h($ob_title) . ($org_name ? ' — ' . h($org_name) : '');
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
      --ob-accent:     <?= h($ob_accent) ?>;
      --ob-accent-rgb: <?= h($ob_accent_rgb) ?>;
      --ob-bg:         <?= h($ob_bg) ?>;
    }
    *, *::before, *::after { box-sizing: border-box; }

    body {
      background: var(--ob-bg);
      min-height: 100vh;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }

    /* ── Layout ──────────────────────────────────────────────── */
    .ob-wrap {
      max-width: 560px;
      margin: 0 auto;
      padding: 2.5rem 1rem 4rem;
    }
    @media (max-width: 480px) { .ob-wrap { padding-top: 1.5rem; } }

    /* ── Header ──────────────────────────────────────────────── */
    .ob-header { text-align: center; margin-bottom: 2rem; }
    .ob-header img { max-height: 72px; max-width: 220px; object-fit: contain; }
    .ob-org-name { font-size: .8rem; font-weight: 600; color: #9ca3af; letter-spacing: .04em; text-transform: uppercase; margin-bottom: .4rem; }
    .ob-title { font-size: 1.45rem; font-weight: 700; color: #111827; margin: 0; }

    /* ── Progress bar ────────────────────────────────────────── */
    .ob-progress { margin-bottom: 2rem; }
    .ob-progress-track {
      display: flex;
      align-items: center;
      gap: 0;
    }
    .ob-prog-step {
      display: flex;
      flex-direction: column;
      align-items: center;
      flex: 1;
      position: relative;
    }
    .ob-prog-step:not(:last-child)::after {
      content: '';
      position: absolute;
      top: 16px;
      left: calc(50% + 16px);
      right: calc(-50% + 16px);
      height: 2px;
      background: #e5e7eb;
      z-index: 0;
    }
    .ob-prog-step.done:not(:last-child)::after { background: var(--ob-accent); }
    .ob-prog-dot {
      width: 32px; height: 32px;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: .8rem; font-weight: 700;
      border: 2px solid #e5e7eb;
      background: #fff;
      color: #9ca3af;
      position: relative; z-index: 1;
      transition: all .2s;
    }
    .ob-prog-step.done .ob-prog-dot {
      background: var(--ob-accent); border-color: var(--ob-accent); color: #fff;
    }
    .ob-prog-step.active .ob-prog-dot {
      background: #fff; border-color: var(--ob-accent); color: var(--ob-accent);
      box-shadow: 0 0 0 4px rgba(var(--ob-accent-rgb),.15);
      font-weight: 800;
    }
    .ob-prog-label {
      font-size: .68rem; font-weight: 500; margin-top: 5px;
      color: #d1d5db; white-space: nowrap;
    }
    .ob-prog-step.done .ob-prog-label  { color: var(--ob-accent); }
    .ob-prog-step.active .ob-prog-label { color: var(--ob-accent); font-weight: 700; }
    .ob-prog-counter {
      text-align: center;
      font-size: .78rem;
      color: #9ca3af;
      margin-top: .6rem;
    }

    /* ── Card ────────────────────────────────────────────────── */
    .ob-card {
      background: #fff;
      border-radius: 1rem;
      border: 1px solid #e5e7eb;
      box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04);
      overflow: hidden;
    }
    .ob-card-head {
      padding: 1.5rem 2rem 0;
      border-bottom: 1px solid #f3f4f6;
      margin-bottom: 0;
    }
    .ob-card-head h2 {
      font-size: 1.1rem; font-weight: 700; color: #111827; margin-bottom: .3rem;
    }
    .ob-card-head p {
      font-size: .875rem; color: #6b7280; margin-bottom: 1.25rem; line-height: 1.5;
    }
    .ob-card-body { padding: 1.75rem 2rem; }
    @media (max-width: 480px) {
      .ob-card-head { padding: 1.25rem 1.25rem 0; }
      .ob-card-body { padding: 1.25rem; }
    }

    /* ── Inputs ──────────────────────────────────────────────── */
    .form-label { font-size: .875rem; font-weight: 600; color: #374151; margin-bottom: .35rem; }
    .form-control, .form-select {
      border-color: #d1d5db;
      border-radius: .5rem;
      font-size: .9rem;
      color: #111827;
      padding: .5rem .75rem;
      transition: border-color .15s, box-shadow .15s;
    }
    .form-control:focus, .form-select:focus {
      border-color: var(--ob-accent);
      box-shadow: 0 0 0 3px rgba(var(--ob-accent-rgb),.15);
      outline: none;
    }
    .form-control::placeholder { color: #9ca3af; }
    .form-text { font-size: .78rem; color: #9ca3af; margin-top: .25rem; }
    .input-group-text {
      background: #f9fafb; border-color: #d1d5db; color: #6b7280; font-weight: 600; font-size: .9rem;
    }

    /* ── Divider label ───────────────────────────────────────── */
    .field-group {
      margin-bottom: 1.5rem;
    }
    .field-group-label {
      display: flex; align-items: center; gap: .6rem;
      font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
      color: #9ca3af; margin-bottom: 1rem;
    }
    .field-group-label::after {
      content: ''; flex: 1; height: 1px; background: #f3f4f6;
    }

    /* ── Typ selector ────────────────────────────────────────── */
    .typ-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
    .typ-card {
      display: flex; flex-direction: column;
      padding: 1.1rem 1rem;
      border: 2px solid #e5e7eb;
      border-radius: .75rem;
      background: #fff;
      cursor: pointer;
      transition: border-color .15s, background .15s, box-shadow .15s;
      position: relative;
    }
    .typ-card:hover { border-color: var(--ob-accent); background: rgba(var(--ob-accent-rgb),.03); }
    .typ-card input[type=radio] { position: absolute; opacity: 0; width: 0; height: 0; }
    .typ-card.selected {
      border-color: var(--ob-accent);
      background: rgba(var(--ob-accent-rgb),.05);
      box-shadow: 0 0 0 3px rgba(var(--ob-accent-rgb),.12);
    }
    .typ-card-check {
      width: 18px; height: 18px; border-radius: 50%;
      border: 2px solid #d1d5db;
      display: flex; align-items: center; justify-content: center;
      margin-bottom: .75rem; flex-shrink: 0;
      transition: border-color .15s, background .15s;
    }
    .typ-card.selected .typ-card-check {
      border-color: var(--ob-accent); background: var(--ob-accent);
    }
    .typ-card.selected .typ-card-check::after {
      content: ''; width: 6px; height: 6px; border-radius: 50%; background: #fff;
    }
    .typ-card-icon {
      width: 40px; height: 40px; border-radius: .5rem;
      background: #f3f4f6;
      display: flex; align-items: center; justify-content: center;
      margin-bottom: .65rem;
      transition: background .15s;
    }
    .typ-card.selected .typ-card-icon { background: rgba(var(--ob-accent-rgb),.12); }
    .typ-card-icon svg { width: 22px; height: 22px; stroke: #6b7280; fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }
    .typ-card.selected .typ-card-icon svg { stroke: var(--ob-accent); }
    .typ-card-name { font-weight: 700; font-size: .9rem; color: #111827; margin-bottom: .2rem; }
    .typ-card-desc { font-size: .75rem; color: #9ca3af; line-height: 1.4; }

    /* ── Button ──────────────────────────────────────────────── */
    .btn-ob {
      display: block; width: 100%;
      background: var(--ob-accent);
      color: #fff;
      border: none;
      border-radius: .6rem;
      padding: .75rem 1.5rem;
      font-size: .9rem; font-weight: 700;
      cursor: pointer;
      transition: filter .15s, transform .1s;
      text-align: center;
    }
    .btn-ob:hover { filter: brightness(.9); }
    .btn-ob:active { transform: scale(.98); }
    .btn-ob:disabled { opacity: .55; cursor: not-allowed; }
    .btn-ob-ghost {
      display: inline-block;
      background: none; border: none;
      color: #9ca3af; font-size: .8rem; cursor: pointer;
      padding: .25rem .5rem; border-radius: .4rem;
      transition: color .15s;
      text-decoration: none;
    }
    .btn-ob-ghost:hover { color: #374151; }

    /* ── Klauzula box ────────────────────────────────────────── */
    .klauzula-box {
      max-height: 240px; overflow-y: auto;
      border: 1px solid #e5e7eb; border-radius: .6rem;
      padding: .875rem 1rem;
      background: #fafafa;
      font-size: .83rem; line-height: 1.65; color: #374151;
      white-space: pre-wrap;
    }
    .klauzula-box::-webkit-scrollbar { width: 5px; }
    .klauzula-box::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }

    /* ── Upload ──────────────────────────────────────────────── */
    .upload-zone {
      border: 2px dashed #d1d5db; border-radius: .75rem;
      padding: 2.5rem 1.5rem;
      text-align: center;
      background: #fafafa;
      cursor: pointer;
      transition: border-color .2s, background .2s;
      position: relative;
    }
    .upload-zone:hover, .upload-zone.dragover {
      border-color: var(--ob-accent);
      background: rgba(var(--ob-accent-rgb),.04);
    }
    .upload-zone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .upload-zone-icon {
      width: 48px; height: 48px; margin: 0 auto .75rem;
      border-radius: .6rem; background: #f3f4f6;
      display: flex; align-items: center; justify-content: center;
    }
    .upload-zone-icon svg { width: 24px; height: 24px; stroke: #9ca3af; fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }
    .upload-zone.has-file .upload-zone-icon { background: rgba(var(--ob-accent-rgb),.1); }
    .upload-zone.has-file .upload-zone-icon svg { stroke: var(--ob-accent); }
    .upload-zone-text { font-weight: 600; font-size: .88rem; color: #374151; margin-bottom: .2rem; }
    .upload-zone-hint { font-size: .78rem; color: #9ca3af; }

    /* ── SMS code input ──────────────────────────────────────── */
    .code-input {
      font-size: 2rem; letter-spacing: .3em; text-align: center;
      font-weight: 700; border-radius: .6rem;
      padding: .6rem 1rem;
    }

    /* ── Info box (email/sms instructions) ───────────────────── */
    .ob-info-box {
      background: #f8fafc; border: 1px solid #e5e7eb; border-radius: .75rem;
      padding: 1.25rem; margin-bottom: 1.25rem;
      display: flex; gap: .75rem; align-items: flex-start;
    }
    .ob-info-box-icon {
      width: 36px; height: 36px; flex-shrink: 0;
      border-radius: .5rem; background: rgba(var(--ob-accent-rgb),.1);
      display: flex; align-items: center; justify-content: center;
    }
    .ob-info-box-icon svg { width: 18px; height: 18px; stroke: var(--ob-accent); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .ob-info-box-text { font-size: .85rem; color: #374151; line-height: 1.5; }
    .ob-info-box-text strong { color: #111827; }

    /* ── Success ─────────────────────────────────────────────── */
    .ob-success {
      text-align: center; padding: 2rem 1rem;
    }
    .ob-success-icon {
      width: 72px; height: 72px; border-radius: 50%;
      background: rgba(var(--ob-accent-rgb),.1);
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 1.25rem;
    }
    .ob-success-icon svg { width: 36px; height: 36px; stroke: var(--ob-accent); fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }
    .ob-success h2 { font-size: 1.25rem; font-weight: 700; color: #111827; margin-bottom: .5rem; }
    .ob-success p  { font-size: .88rem; color: #6b7280; line-height: 1.6; margin: 0; }

    /* ── Alert ───────────────────────────────────────────────── */
    .ob-alert {
      border-radius: .6rem; padding: .875rem 1rem;
      font-size: .85rem; line-height: 1.5; margin-bottom: 1.25rem;
    }
    .ob-alert ul { margin: 0; padding-left: 1.25rem; }
    .ob-alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .ob-alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }

    /* ── Disabled page ───────────────────────────────────────── */
    .ob-disabled {
      text-align: center; padding: 3rem 1.5rem;
      color: #9ca3af; font-size: .9rem;
    }

    /* ── Checkbox ────────────────────────────────────────────── */
    .ob-check {
      display: flex; gap: .75rem; align-items: flex-start;
      padding: 1rem; background: #f9fafb; border-radius: .6rem;
      border: 1px solid #e5e7eb; margin-bottom: 1.25rem;
      cursor: pointer;
    }
    .ob-check input[type=checkbox] {
      width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px;
      accent-color: var(--ob-accent); cursor: pointer;
    }
    .ob-check-label { font-size: .85rem; color: #374151; line-height: 1.5; cursor: pointer; }

    <?php if ($ob_custom_css): ?>
    <?= $ob_custom_css . "\n" ?>
    <?php endif; ?>
  </style>
</head>
<body>
<div class="ob-wrap">

  <!-- Header -->
  <div class="ob-header">
    <?php if ($ob_logo): ?>
    <img src="<?= APP_URL . '/' . h($ob_logo) ?>" alt="<?= h($org_name) ?>" class="mb-3">
    <?php elseif ($org_name): ?>
    <div class="ob-org-name"><?= h($org_name) ?></div>
    <?php endif; ?>
    <h1 class="ob-title"><?= h($ob_title) ?></h1>
  </div>

<?php if ($disabled_page): ?>
  <div class="ob-card"><div class="ob-card-body ob-disabled">
    Formularz rejestracyjny jest chwilowo niedostepny. Sprobuj ponownie pozniej.
  </div></div>
<?php else: ?>

  <!-- Progress -->
  <?php if ($step >= 1 && $step <= 5 && $_step_pos !== null): ?>
  <div class="ob-progress">
    <div class="ob-progress-track">
      <?php foreach ($_active_steps as $idx => $url_step):
        $pos = $idx + 1;
        $cls = $pos < $_step_pos ? 'done' : ($pos === $_step_pos ? 'active' : '');
      ?>
      <div class="ob-prog-step <?= $cls ?>">
        <div class="ob-prog-dot">
          <?php if ($pos < $_step_pos): ?>
          <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="2,8 6,12 14,4"/></svg>
          <?php else: ?>
          <?= $pos ?>
          <?php endif; ?>
        </div>
        <div class="ob-prog-label"><?= h($_step_names[$url_step]) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="ob-prog-counter">Krok <?= $_step_pos ?> z <?= $_step_total ?></div>
  </div>
  <?php endif; ?>

  <!-- Alerts -->
  <?php if (!empty($errors)): ?>
  <div class="ob-alert ob-alert-danger">
    <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
  <?php if ($success): ?>
  <div class="ob-alert ob-alert-success"><?= h($success) ?></div>
  <?php endif; ?>

  <!-- Card -->
  <div class="ob-card">

  <?php
  // ═══════════════════════════════════════════════════════════
  // STEP 1 — dane
  // ═══════════════════════════════════════════════════════════
  if ($step === 1):
    $p = $_POST;
  ?>
    <div class="ob-card-head">
      <h2>Twoje dane</h2>
      <p>Wypelnij ponizszy formularz. Pola oznaczone gwiazdka (*) sa obowiazkowe.</p>
    </div>
    <div class="ob-card-body">
      <?php if ($ob_intro): ?>
      <div class="ob-alert ob-alert-success" style="background:#f0f9ff;border-color:#bae6fd;color:#0c4a6e"><?= $ob_intro ?></div>
      <?php endif; ?>

      <form method="post" action="?step=1" id="step1form" novalidate>
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

        <!-- Typ wspolpracy -->
        <div class="field-group">
          <div class="field-group-label">Rodzaj wspolpracy</div>
          <div class="typ-grid">

            <label class="typ-card <?= ($p['typ'] ?? 'wolontariusz') === 'wolontariusz' ? 'selected' : '' ?>" id="btn-wolontariusz">
              <input type="radio" name="typ" value="wolontariusz" <?= ($p['typ'] ?? 'wolontariusz') === 'wolontariusz' ? 'checked' : '' ?>>
              <div class="typ-card-check"></div>
              <div class="typ-card-icon">
                <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
              </div>
              <div class="typ-card-name">Wolontariusz</div>
              <div class="typ-card-desc">Porozumienie wolontariackie, bez wynagrodzenia</div>
            </label>

            <label class="typ-card <?= ($p['typ'] ?? '') === 'zleceniobiorca' ? 'selected' : '' ?>" id="btn-zleceniobiorca">
              <input type="radio" name="typ" value="zleceniobiorca" <?= ($p['typ'] ?? '') === 'zleceniobiorca' ? 'checked' : '' ?>>
              <div class="typ-card-check"></div>
              <div class="typ-card-icon">
                <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
              </div>
              <div class="typ-card-name">Zleceniobiorca</div>
              <div class="typ-card-desc">Umowa zlecenie lub o dzielo</div>
            </label>

          </div>
        </div>

        <!-- Dane osobowe -->
        <div class="field-group">
          <div class="field-group-label">Dane osobowe</div>

          <div class="mb-3">
            <label class="form-label" for="imie_nazwisko">Imie i nazwisko <span class="text-danger">*</span></label>
            <input type="text" id="imie_nazwisko" name="imie_nazwisko" class="form-control"
                   value="<?= h($p['imie_nazwisko'] ?? '') ?>" autocomplete="name" autofocus>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-7">
              <label class="form-label" for="pesel_input">PESEL</label>
              <input type="text" id="pesel_input" name="pesel" class="form-control"
                     maxlength="11" inputmode="numeric" pattern="\d{11}"
                     value="<?= h($p['pesel'] ?? '') ?>" placeholder="opcjonalnie"
                     autocomplete="off">
            </div>
            <div class="col-5">
              <label class="form-label" for="data_urodzenia_input">Data urodzenia</label>
              <input type="date" id="data_urodzenia_input" name="data_urodzenia" class="form-control"
                     value="<?= h($p['data_urodzenia'] ?? '') ?>">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="telefon_input">Numer telefonu <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text">+48</span>
              <input type="tel" id="telefon_input" name="telefon" class="form-control"
                     value="<?= h($p['telefon'] ?? '') ?>"
                     placeholder="123&nbsp;456&nbsp;789" maxlength="11" inputmode="tel" autocomplete="tel">
            </div>
          </div>

          <div class="mb-0">
            <label class="form-label" for="email_input">Adres e-mail <span class="text-danger">*</span></label>
            <input type="email" id="email_input" name="email" class="form-control"
                   value="<?= h($p['email'] ?? '') ?>" autocomplete="email" inputmode="email">
            <div class="form-text">Na ten adres wysylamy link do aktywacji konta.</div>
          </div>
        </div>

        <!-- Sekcja wolontariusz -->
        <div id="sekcja-wolontariusz" class="field-group">
          <div class="field-group-label">Adres zamieszkania</div>
          <div class="mb-0">
            <input type="text" name="adres" class="form-control"
                   value="<?= h($p['adres'] ?? '') ?>"
                   placeholder="ul. Przykladowa 1, 00-000 Miasto"
                   autocomplete="street-address">
          </div>
        </div>

        <!-- Sekcja zleceniobiorca -->
        <div id="sekcja-zleceniobiorca" style="display:none">

          <div class="field-group">
            <div class="field-group-label">Adres zamieszkania</div>
            <div class="row g-2 mb-2">
              <div class="col-7">
                <label class="form-label" for="addr_street">Ulica <span class="text-danger">*</span></label>
                <input type="text" id="addr_street" name="addr_street" class="form-control"
                       value="<?= h($p['addr_street'] ?? '') ?>" placeholder="ul. Przykladowa"
                       autocomplete="address-line1">
              </div>
              <div class="col-3">
                <label class="form-label" for="addr_house">Nr domu <span class="text-danger">*</span></label>
                <input type="text" id="addr_house" name="addr_house" class="form-control"
                       value="<?= h($p['addr_house'] ?? '') ?>" placeholder="1">
              </div>
              <div class="col-2">
                <label class="form-label" for="addr_flat">Lok.</label>
                <input type="text" id="addr_flat" name="addr_flat" class="form-control"
                       value="<?= h($p['addr_flat'] ?? '') ?>" placeholder="—">
              </div>
            </div>
            <div class="row g-2 mb-0">
              <div class="col-4">
                <label class="form-label" for="addr_postal">Kod pocztowy <span class="text-danger">*</span></label>
                <input type="text" id="addr_postal" name="addr_postal" class="form-control"
                       value="<?= h($p['addr_postal'] ?? '') ?>" placeholder="00-000"
                       maxlength="6" inputmode="numeric" autocomplete="postal-code">
              </div>
              <div class="col-8">
                <label class="form-label" for="addr_city">Miejscowosc <span class="text-danger">*</span></label>
                <input type="text" id="addr_city" name="addr_city" class="form-control"
                       value="<?= h($p['addr_city'] ?? '') ?>" autocomplete="address-level2">
              </div>
            </div>
          </div>

          <div class="field-group">
            <div class="field-group-label">Dane do umowy i rozliczen</div>

            <div class="mb-3">
              <label class="form-label" for="seria_nr_dowodu">Seria i numer dowodu osobistego lub paszportu</label>
              <input type="text" id="seria_nr_dowodu" name="seria_nr_dowodu" class="form-control"
                     value="<?= h($p['seria_nr_dowodu'] ?? '') ?>" placeholder="np. ABC 123456"
                     autocomplete="off" style="text-transform:uppercase">
              <div class="form-text">Wymagane do wystawienia rachunku i zgloszenia do ZUS.</div>
            </div>

            <div class="mb-3">
              <label class="form-label" for="urzad_skarbowy">Wlasciwy urzad skarbowy</label>
              <input type="text" id="urzad_skarbowy" name="urzad_skarbowy" class="form-control"
                     value="<?= h($p['urzad_skarbowy'] ?? '') ?>"
                     placeholder="np. US Warszawa-Mokotow">
            </div>

            <div class="mb-0">
              <label class="form-label" for="rachunek_bankowy">Numer rachunku bankowego (IBAN)</label>
              <input type="text" id="rachunek_bankowy" name="rachunek_bankowy" class="form-control"
                     value="<?= h($p['rachunek_bankowy'] ?? '') ?>"
                     placeholder="26 cyfr PL, bez spacji" maxlength="32"
                     inputmode="numeric" autocomplete="off">
              <div class="form-text">Numer konta do przelewu wynagrodzenia — 26 cyfr, bez liter ani spacji.</div>
            </div>
          </div>

        </div>

        <button type="submit" class="btn-ob">Przejdz dalej &rarr;</button>
      </form>
    </div>

    <script>
    (function() {
      var btnW = document.getElementById('btn-wolontariusz');
      var btnZ = document.getElementById('btn-zleceniobiorca');
      var secW = document.getElementById('sekcja-wolontariusz');
      var secZ = document.getElementById('sekcja-zleceniobiorca');

      function switchTyp(val) {
        var isW = val === 'wolontariusz';
        secW.style.display = isW ? '' : 'none';
        secZ.style.display = isW ? 'none' : '';
        btnW.classList.toggle('selected', isW);
        btnZ.classList.toggle('selected', !isW);
        secW.querySelectorAll('input,select,textarea').forEach(function(el){
          if (el.dataset.req) el.required = isW;
        });
        secZ.querySelectorAll('input,select,textarea').forEach(function(el){
          if (el.dataset.req) el.required = !isW;
        });
      }

      // Mark required fields per section
      // Wolontariusz: adres (only one field, no explicit required markup — server validates)
      // Zleceniobiorca: addr_street, addr_house, addr_postal, addr_city
      ['addr_street','addr_house','addr_postal','addr_city'].forEach(function(id){
        var el = document.getElementById(id);
        if (el) el.dataset.req = '1';
      });

      [btnW, btnZ].forEach(function(btn) {
        btn.addEventListener('click', function() {
          var r = this.querySelector('input[type=radio]');
          if (r && !r.checked) { r.checked = true; switchTyp(r.value); }
        });
      });
      document.querySelectorAll('[name=typ]').forEach(function(r){
        r.addEventListener('change', function(){ switchTyp(this.value); });
      });

      var checked = document.querySelector('[name=typ]:checked');
      switchTyp(checked ? checked.value : 'wolontariusz');

      // PESEL → data urodzenia
      function peselBd(p) {
        if (!/^\d{11}$/.test(p)) return null;
        var y=+p.slice(0,2), m=+p.slice(2,4), d=+p.slice(4,6), yr;
        if(m>=81){yr=1800+y;m-=80;}else if(m>=21&&m<=32){yr=2000+y;m-=20;}
        else if(m>=41&&m<=52){yr=2100+y;m-=40;}else if(m>=61){yr=2200+y;m-=60;}
        else yr=1900+y;
        return yr+'-'+String(m).padStart(2,'0')+'-'+String(d).padStart(2,'0');
      }
      document.getElementById('pesel_input').addEventListener('input', function(){
        var bd = peselBd(this.value);
        if (bd) document.getElementById('data_urodzenia_input').value = bd;
      });

      // Strip phone prefix on submit
      document.getElementById('step1form').addEventListener('submit', function(){
        var el = document.getElementById('telefon_input');
        var v  = el.value.replace(/\D/g,'');
        if (v.length===11 && v.startsWith('48')) v=v.slice(2);
        el.value = v;
      });

      // Format IBAN-like bank account on blur
      var rachunek = document.getElementById('rachunek_bankowy');
      if (rachunek) rachunek.addEventListener('blur', function(){
        this.value = this.value.replace(/\D/g,'');
      });

      // Uppercase doc number
      var doc = document.getElementById('seria_nr_dowodu');
      if (doc) doc.addEventListener('input', function(){ this.value = this.value.toUpperCase(); });
    })();
    </script>

  <?php
  // ═══════════════════════════════════════════════════════════
  // STEP 2 — SMS
  // ═══════════════════════════════════════════════════════════
  elseif ($step === 2):
    $display_phone = $vol ? preg_replace('/^48/', '+48 ', $vol['telefon']) : '';
  ?>
    <div class="ob-card-head">
      <h2>Weryfikacja telefonu</h2>
      <p>Wysylamy jednorazowy kod SMS na numer <strong><?= h($display_phone) ?></strong>.</p>
    </div>
    <div class="ob-card-body">

      <div class="ob-info-box">
        <div class="ob-info-box-icon">
          <svg viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
        </div>
        <div class="ob-info-box-text">
          Wpisz 6-cyfrowy kod z SMS-a. Kod wazny jest przez <strong>10 minut</strong>.
          Jezeli nie dotarl, sprawdz czy numer jest poprawny i kliknij „Wyslij ponownie".
        </div>
      </div>

      <form method="post" action="?step=2" id="sms-form">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="verify">
        <div class="mb-4">
          <label class="form-label" for="sms_code">Kod SMS</label>
          <input type="text" id="sms_code" name="code" class="form-control code-input"
                 maxlength="6" pattern="\d{6}" placeholder="000000"
                 autocomplete="one-time-code" inputmode="numeric" autofocus>
        </div>
        <button type="submit" class="btn-ob">Zweryfikuj kod</button>
      </form>

      <div class="text-center mt-3">
        <form method="post" action="?step=2" style="display:inline">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="resend">
          <button type="submit" class="btn-ob-ghost">Wyslij kod ponownie</button>
        </form>
      </div>
    </div>

    <script>
    // Auto-submit after 6 digits
    document.getElementById('sms_code').addEventListener('input', function(){
      if (this.value.replace(/\D/g,'').length === 6) {
        this.value = this.value.replace(/\D/g,'');
        document.getElementById('sms-form').submit();
      }
    });
    </script>

  <?php
  // ═══════════════════════════════════════════════════════════
  // STEP 3 — e-mail
  // ═══════════════════════════════════════════════════════════
  elseif ($step === 3):
    $display_email = $vol ? $vol['email'] : '';
  ?>
    <div class="ob-card-head">
      <h2>Weryfikacja e-mail</h2>
      <p>Sprawdz swoja skrzynke pocztowa i kliknij link weryfikacyjny.</p>
    </div>
    <div class="ob-card-body">

      <div class="ob-info-box">
        <div class="ob-info-box-icon">
          <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        </div>
        <div class="ob-info-box-text">
          Wyslalismy link weryfikacyjny na adres <strong><?= h($display_email) ?></strong>.<br>
          Po kliknieciu w link wróc tutaj i kliknij przycisk ponizej.
        </div>
      </div>

      <form method="post" action="?step=3">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="check">
        <button type="submit" class="btn-ob">Potwierdzam — e-mail zweryfikowany</button>
      </form>

      <div class="text-center mt-3">
        <form method="post" action="?step=3" style="display:inline">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="resend_email">
          <button type="submit" class="btn-ob-ghost">Wyslij link ponownie</button>
        </form>
      </div>
    </div>

  <?php
  // ═══════════════════════════════════════════════════════════
  // STEP 4 — klauzula RODO
  // ═══════════════════════════════════════════════════════════
  elseif ($step === 4):
    $klauzula_text = get_setting('onboarding_klauzula');
    if ($klauzula_text === '') {
        $klauzula_text = "Administratorem danych osobowych jest organizacja. Dane przetwarzane sa w celu realizacji wolontariatu lub umowy cywilnoprawnej na podstawie zgody (art. 6 ust. 1 lit. a RODO). Przysluguje Pani/Panu prawo dostepu do danych, ich sprostowania, usuniecia, ograniczenia przetwarzania oraz wniesienia skargi do organu nadzorczego.";
    }
  ?>
    <div class="ob-card-head">
      <h2>Zgoda na przetwarzanie danych</h2>
      <p>Przeczytaj tresc klauzuli, a nastepnie zaznacz zgode, aby kontynuowac.</p>
    </div>
    <div class="ob-card-body">

      <div class="klauzula-box mb-4"><?= h($klauzula_text) ?></div>

      <form method="post" action="?step=4">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <label class="ob-check" for="klauzula_check">
          <input type="checkbox" id="klauzula_check" name="klauzula" value="1" required>
          <span class="ob-check-label">
            Zapoznalam/em sie z trescia klauzuli informacyjnej i wyrazam zgode na przetwarzanie
            moich danych osobowych w powyzszym celu.
          </span>
        </label>
        <button type="submit" class="btn-ob">Akceptuje i przechodze dalej &rarr;</button>
      </form>
    </div>

  <?php
  // ═══════════════════════════════════════════════════════════
  // STEP 5 — oswiadczenie
  // ═══════════════════════════════════════════════════════════
  elseif ($step === 5):
    $existing_file = $vol['oswiadczenie_file'] ?? '';
  ?>
    <div class="ob-card-head">
      <h2>Oswiadczenie podatkowe i ZUS</h2>
      <p>Wypelnij i podpisz oswiadczenie do celow podatkowych i ZUS, a nastepnie przeslij jego skan lub zdjecie.</p>
    </div>
    <div class="ob-card-body">

      <?php if ($existing_file): ?>
      <div class="ob-alert ob-alert-success" style="margin-bottom:1.25rem">
        Plik zostal juz przeslany. Mozesz go zastapic nowym lub od razu wyslac zgloszenie.
      </div>
      <?php endif; ?>

      <form method="post" action="?step=5" enctype="multipart/form-data" id="upload-form">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

        <div class="mb-4">
          <label class="form-label" for="oswiadczenie">
            Skan lub zdjecie dokumentu <?= $existing_file ? '' : '<span class="text-danger">*</span>' ?>
          </label>
          <div class="upload-zone <?= $existing_file ? 'has-file' : '' ?>" id="upload-zone">
            <input type="file" name="oswiadczenie" id="oswiadczenie"
                   accept=".pdf,.jpg,.jpeg,.png,.tiff,.tif"
                   <?= $existing_file ? '' : 'required' ?>>
            <div class="upload-zone-icon">
              <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            </div>
            <div class="upload-zone-text" id="upload-label">
              <?= $existing_file ? 'Kliknij, aby zastapic plik' : 'Kliknij lub przeciagnij plik tutaj' ?>
            </div>
            <div class="upload-zone-hint">PDF, JPG, PNG, TIFF &mdash; maks. 10 MB</div>
          </div>
        </div>

        <button type="submit" class="btn-ob" id="upload-btn">Wyslij zgloszenie</button>
      </form>
    </div>

    <script>
    (function(){
      var zone  = document.getElementById('upload-zone');
      var input = document.getElementById('oswiadczenie');
      var label = document.getElementById('upload-label');
      var btn   = document.getElementById('upload-btn');

      function setFile(name) {
        label.textContent = name;
        zone.classList.add('has-file');
      }
      input.addEventListener('change', function(){
        if (this.files[0]) setFile(this.files[0].name);
      });
      zone.addEventListener('dragover', function(e){ e.preventDefault(); this.classList.add('dragover'); });
      zone.addEventListener('dragleave', function(){ this.classList.remove('dragover'); });
      zone.addEventListener('drop', function(e){
        e.preventDefault(); this.classList.remove('dragover');
        if (e.dataTransfer.files[0]) {
          input.files = e.dataTransfer.files;
          setFile(e.dataTransfer.files[0].name);
        }
      });
      document.getElementById('upload-form').addEventListener('submit', function(){
        btn.disabled = true;
        btn.textContent = 'Wysylanie…';
      });
    })();
    </script>

  <?php
  // ═══════════════════════════════════════════════════════════
  // STEP 6 — dziekujemy
  // ═══════════════════════════════════════════════════════════
  elseif ($step === 6):
  ?>
    <div class="ob-card-body">
      <div class="ob-success">
        <div class="ob-success-icon">
          <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h2>Dziekujemy za zgloszenie!</h2>
        <p>
          Twoje zgloszenie zostalo przyjete i oczekuje na weryfikacje koordynatora.<br><br>
          Na podany adres e-mail wysylamy link do aktywacji konta &mdash;
          sprawdz skrzynke pocztowa (rowniez folder SPAM).
        </p>
        <p style="margin-top:.75rem;font-size:.82rem;color:#9ca3af">
          Po zatwierdzeniu zgloszenia zostaniesz poinformowany/a o dalszych krokach.
        </p>
      </div>
    </div>
  <?php endif; ?>

  </div><!-- /.ob-card -->

<?php endif; // disabled_page ?>

</div><!-- /.ob-wrap -->
<script src="<?= APP_URL ?>/assets/bootstrap.bundle.min.js"></script>
</body>
</html>
