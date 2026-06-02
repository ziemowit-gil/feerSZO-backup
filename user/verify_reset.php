<?php
/**
 * verify_reset.php — Autonomiczne odzyskiwanie dostępu metodą cross-match.
 *
 * 3 kroki w jednej stronie (state machine via POST + session):
 *   1. Cross-match (email + numer_umowy + PESEL/dokument)
 *   2. Weryfikacja kodu SMS
 *   3. Ustawienie nowego hasła
 *
 * Strona STANDALONE — nie includuje header.php.
 * Dostępna bez logowania.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cpc.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/includes/password_validator.php';

// ── Sesja (bez wymagania logowania) ──────────────────────────────────────────
auth_start();

// ── Rate limiting (max 5 prób / IP / godzina) ─────────────────────────────────
function _vr_rate_check(): bool {
    $pdo = db();
    // Upewnij się, że tabela istnieje (tworzona przez auth_security, ale tu może nie być includowana)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT NOT NULL,
            ip         TEXT NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Throwable $e) {}

    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $since = date('Y-m-d H:i:s', time() - 3600);
    $stmt  = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = ? AND created_at > ?"
    );
    $stmt->execute([$ip, $since]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return (int) ($row['c'] ?? 0) < 5;
}

function _vr_rate_record(): void {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        db()->prepare("INSERT INTO login_attempts (identifier, ip) VALUES (?, ?)")
            ->execute([$ip, $ip]);
    } catch (\Throwable $e) {}
}

// ── Stała błędu (unikamy enumeracji) ─────────────────────────────────────────
const VR_ERR_CROSSMATCH  = 'Nie udało się zweryfikować danych. Sprawdź wprowadzone informacje.';
const VR_ERR_SMS         = 'Podany kod jest nieprawidłowy lub wygasł. Spróbuj ponownie.';
const VR_ERR_RATE        = 'Zbyt wiele prób weryfikacji. Spróbuj ponownie za godzinę.';

// ── Odczyt stanu z sesji ──────────────────────────────────────────────────────
$reset_step     = (int) ($_SESSION['vr_step']       ?? 1);
$reset_user_id  = (int) ($_SESSION['vr_user_id']    ?? 0);
$sms_fails      = (int) ($_SESSION['vr_sms_fails']  ?? 0);

$error   = '';
$success = '';

// ── Obsługa POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // Powrót do kroku 1 (reset stanu) — obsługiwany przed wszystkimi innymi akcjami
    if ($action === 'restart') {
        unset(
            $_SESSION['vr_step'],
            $_SESSION['vr_user_id'],
            $_SESSION['vr_sms_fails']
        );
        header('Location: ' . APP_URL . '/user/verify_reset.php');
        exit;
    }

    // ────────────────────────────────────────────────────────────────────────
    // KROK 1: Cross-match
    // ────────────────────────────────────────────────────────────────────────
    if ($action === 'crossmatch' && $reset_step === 1) {
        if (!_vr_rate_check()) {
            $error = VR_ERR_RATE;
        } else {
            _vr_rate_record();

            $email        = trim($_POST['email']         ?? '');
            $numer_umowy  = trim($_POST['numer_umowy']   ?? '');
            $pesel_or_doc = trim($_POST['pesel_or_doc']  ?? '');
            $no_numer     = !empty($_POST['no_numer_umowy']);   // ścieżka "bez numeru"
            $data_ur      = trim($_POST['data_urodzenia'] ?? '');

            $found_user = null;
            $pdo        = db();

            // ── Ścieżka BEZ numeru umowy (umowy przed 2026-06-01) ────────────────
            $recovery_input = trim($_POST['recovery_code'] ?? '');
            if ($no_numer && $email && $pesel_or_doc && preg_match('/^\d{8}$/', $recovery_input)) {
                // Szukamy umowy po email + PESEL/dokument, weryfikujemy hash kodu
                $candidates = [];

                // Wolontariat
                $stmt = $pdo->prepare(
                    "SELECT u.id, u.email, u.phone_number, u.twofa_phone,
                            u.is_minor, u.guardian_phone, u.guardian_email,
                            c.recovery_code_hash
                     FROM umowy_wolontariat c
                     JOIN users u ON u.email = c.email
                     WHERE c.email = ?
                       AND (c.numer_umowy IS NULL OR c.numer_umowy = '')
                       AND (c.data_zawarcia IS NULL OR c.data_zawarcia < '2026-06-01')
                       AND c.recovery_code_hash IS NOT NULL
                       AND (SUBSTR(c.pesel,-5) = ? OR c.id_document_number = ?)
                     LIMIT 5"
                );
                $stmt->execute([$email, $pesel_or_doc, $pesel_or_doc]);
                $candidates = array_merge($candidates, $stmt->fetchAll(\PDO::FETCH_ASSOC));

                // Pozostałe typy (jeśli mają kolumnę recovery_code_hash)
                foreach (['umowy_zlecenie','umowy_dzielo','umowy_praca'] as $_tbl) {
                    try {
                        $stmt = $pdo->prepare(
                            "SELECT u.id, u.email, u.phone_number, u.twofa_phone,
                                    u.is_minor, u.guardian_phone, u.guardian_email,
                                    c.recovery_code_hash
                             FROM {$_tbl} c
                             JOIN users u ON u.email = c.email
                             WHERE c.email = ?
                               AND (c.numer_umowy IS NULL OR c.numer_umowy = '')
                               AND (c.data_zawarcia IS NULL OR c.data_zawarcia < '2026-06-01')
                               AND c.recovery_code_hash IS NOT NULL
                               AND SUBSTR(c.pesel,-5) = ?
                             LIMIT 5"
                        );
                        $stmt->execute([$email, $pesel_or_doc]);
                        $candidates = array_merge($candidates, $stmt->fetchAll(\PDO::FETCH_ASSOC));
                    } catch (\Throwable $e) {}
                }

                // Sprawdź hash dla każdego kandydata
                foreach ($candidates as $_c) {
                    if (!empty($_c['recovery_code_hash']) &&
                        password_verify($recovery_input, $_c['recovery_code_hash'])) {
                        $found_user = $_c;
                        break;
                    }
                }
            }

            // ── Standardowa ścieżka Z numerem umowy ──────────────────────────────
            if (!$found_user && !$no_numer && $numer_umowy) {
                // --- Sprawdzenie w tabeli wolontariat ---
                $stmt = $pdo->prepare(
                    "SELECT u.id, u.email, u.phone_number, u.twofa_phone,
                            u.is_minor, u.guardian_phone, u.guardian_email
                     FROM umowy_wolontariat c
                     JOIN users u ON u.email = c.email
                     WHERE c.email = ?
                       AND c.numer_umowy = ?
                       AND (
                           SUBSTR(c.pesel, -5) = ?
                           OR c.id_document_number = ?
                       )
                     LIMIT 1"
                );
                $stmt->execute([$email, $numer_umowy, $pesel_or_doc, $pesel_or_doc]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) $found_user = $row;

                foreach (['umowy_zlecenie','umowy_dzielo','umowy_praca'] as $_tbl) {
                    if ($found_user) break;
                    try {
                        $stmt = $pdo->prepare(
                            "SELECT u.id, u.email, u.phone_number, u.twofa_phone,
                                    u.is_minor, u.guardian_phone, u.guardian_email
                             FROM {$_tbl} c
                             JOIN users u ON u.email = c.email
                             WHERE c.email = ?
                               AND c.numer_umowy = ?
                               AND SUBSTR(c.pesel, -5) = ?
                             LIMIT 1"
                        );
                        $stmt->execute([$email, $numer_umowy, $pesel_or_doc]);
                        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                        if ($row) $found_user = $row;
                    } catch (\Throwable $e) {}
                }
            }

            if (!$found_user) {
                $error = VR_ERR_CROSSMATCH;
            } else {
                $uid  = (int) $found_user['id'];
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $exp  = date('Y-m-d H:i:s', time() + 10 * 60);

                // Zapisz kod do bazy
                db()->prepare(
                    "UPDATE users SET sms_fallback_code = ?, sms_fallback_expires_at = ? WHERE id = ?"
                )->execute([$code, $exp, $uid]);

                // Ustal docelowy numer telefonu
                $is_minor       = !empty($found_user['is_minor']);
                $guardian_phone = trim($found_user['guardian_phone'] ?? '');
                $primary_phone  = trim($found_user['phone_number']   ?? '');
                $twofa_phone    = trim($found_user['twofa_phone']    ?? '');

                $target_phone = '';
                if ($is_minor && $guardian_phone !== '') {
                    $target_phone = $guardian_phone;
                } elseif ($primary_phone !== '') {
                    $target_phone = $primary_phone;
                } elseif ($twofa_phone !== '') {
                    $target_phone = $twofa_phone;
                }

                // Wyślij SMS jeśli numer jest dostępny
                if ($target_phone !== '') {
                    try {
                        $org_name = defined('ORG_NAME') ? ORG_NAME : '';
                        sms_send($target_phone, "Kod odzyskiwania dostępu: {$code}. Ważny 10 minut. [{$org_name}]");
                    } catch (\Throwable $e) {
                        // Milcząco ignorujemy — nie ujawniamy błędu SMS
                    }
                }

                // Log
                log_system_action(
                    $uid,
                    'password_reset_sms_sent',
                    'Wysłano kod odzyskiwania (cross-match) na IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '')
                );

                // Zapisz stan w sesji i przejdź do kroku 2
                $_SESSION['vr_step']      = 2;
                $_SESSION['vr_user_id']   = $uid;
                $_SESSION['vr_sms_fails'] = 0;

                $success = 'Jeśli podane dane są poprawne, kod weryfikacyjny zostanie wysłany SMS-em na numer przypisany do Twojego konta.';
                $reset_step = 2;
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // KROK 2: Weryfikacja kodu SMS
    // ────────────────────────────────────────────────────────────────────────
    elseif ($action === 'verify_sms' && $reset_step === 2 && $reset_user_id > 0) {
        $input_code = trim($_POST['sms_code'] ?? '');

        $user_row = db()->prepare(
            "SELECT sms_fallback_code, sms_fallback_expires_at FROM users WHERE id = ?"
        );
        $user_row->execute([$reset_user_id]);
        $u = $user_row->fetch(\PDO::FETCH_ASSOC);

        $stored  = (string) ($u['sms_fallback_code']         ?? '');
        $expires = (string) ($u['sms_fallback_expires_at']   ?? '');

        $code_ok = $stored !== ''
            && $expires > date('Y-m-d H:i:s')
            && hash_equals($stored, $input_code);

        if ($code_ok) {
            // Wyczyść kod
            db()->prepare(
                "UPDATE users SET sms_fallback_code = NULL, sms_fallback_expires_at = NULL WHERE id = ?"
            )->execute([$reset_user_id]);

            $_SESSION['vr_step']     = 3;
            $_SESSION['vr_sms_fails'] = 0;
            $reset_step = 3;
        } else {
            $sms_fails++;
            $_SESSION['vr_sms_fails'] = $sms_fails;

            if ($sms_fails >= 3) {
                // Po 3 błędnych próbach resetujemy i wysyłamy z powrotem do kroku 1
                unset(
                    $_SESSION['vr_step'],
                    $_SESSION['vr_user_id'],
                    $_SESSION['vr_sms_fails']
                );
                $reset_step    = 1;
                $reset_user_id = 0;
                $error = 'Zbyt wiele błędnych kodów. Zacznij proces od początku.';
            } else {
                $error = VR_ERR_SMS;
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // KROK 3: Nowe hasło
    // ────────────────────────────────────────────────────────────────────────
    elseif ($action === 'set_password' && $reset_step === 3 && $reset_user_id > 0) {
        $pass_new     = $_POST['password_new']     ?? '';
        $pass_confirm = $_POST['password_confirm'] ?? '';

        if ($pass_new !== $pass_confirm) {
            $error = 'Hasła nie są identyczne.';
        } else {
            $validation = PasswordValidator::validate($pass_new);
            if (!$validation['ok']) {
                $error = implode(' ', $validation['errors']);
            } else {
                $hash = password_hash($pass_new, PASSWORD_BCRYPT);
                db()->prepare("UPDATE users SET password = ? WHERE id = ?")
                     ->execute([$hash, $reset_user_id]);

                log_system_action(
                    $reset_user_id,
                    'password_reset',
                    'Użytkownik zresetował hasło metodą cross-match'
                );

                // Wyczyść stan sesji resetu
                unset(
                    $_SESSION['vr_step'],
                    $_SESSION['vr_user_id'],
                    $_SESSION['vr_sms_fails']
                );

                flash_set('success', 'Hasło zostało zmienione. Zaloguj się.');
                header('Location: ' . APP_URL . '/auth/login.php');
                exit;
            }
        }
    }
}

// ── Pomocnicze zmienne dla szablonu ──────────────────────────────────────────
$org_name = defined('ORG_NAME') ? ORG_NAME : '';

// Etykiety kroków
$step_labels = [
    1 => 'Weryfikacja danych',
    2 => 'Kod SMS',
    3 => 'Nowe hasło',
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Odzyskiwanie dostępu — <?= h($org_name) ?></title>
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous">
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body {
      background: #f0f4f8;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .reset-wrapper {
      max-width: 520px;
      width: 100%;
    }
    /* Pasek postępu kroków */
    .step-bar {
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 2rem;
    }
    .step-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
    }
    .step-circle {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: .9rem;
      border: 2px solid;
    }
    .step-circle.done {
      background: #0d6efd;
      border-color: #0d6efd;
      color: #fff;
    }
    .step-circle.active {
      background: #fff;
      border-color: #0d6efd;
      color: #0d6efd;
    }
    .step-circle.pending {
      background: #fff;
      border-color: #dee2e6;
      color: #adb5bd;
    }
    .step-label {
      font-size: .72rem;
      text-align: center;
      color: #6c757d;
      max-width: 80px;
    }
    .step-label.active { color: #0d6efd; font-weight: 600; }
    .step-connector {
      flex: 1;
      height: 2px;
      background: #dee2e6;
      margin: 0 8px;
      margin-bottom: 20px;
    }
    .step-connector.done { background: #0d6efd; }
  </style>
</head>
<body>
<div class="reset-wrapper">

  <!-- Nagłówek -->
  <div class="text-center mb-4">
    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning bg-opacity-10 mb-3"
         style="width:60px;height:60px">
      <i class="bi bi-key-fill text-warning fs-2"></i>
    </div>
    <h5 class="fw-bold mb-1">Odzyskiwanie dostępu</h5>
    <p class="text-muted small mb-0"><?= h($org_name) ?></p>
  </div>

  <!-- Pasek kroków -->
  <div class="step-bar">
    <?php for ($i = 1; $i <= 3; $i++): ?>
      <?php if ($i > 1): ?>
        <div class="step-connector<?= $reset_step > $i - 1 ? ' done' : '' ?>"></div>
      <?php endif; ?>
      <div class="step-item">
        <div class="step-circle <?=
          $reset_step > $i  ? 'done'    :
          ($reset_step == $i ? 'active' : 'pending')
        ?>">
          <?php if ($reset_step > $i): ?>
            <i class="bi bi-check-lg"></i>
          <?php else: ?>
            <?= $i ?>
          <?php endif; ?>
        </div>
        <span class="step-label<?= $reset_step == $i ? ' active' : '' ?>">
          <?= h($step_labels[$i]) ?>
        </span>
      </div>
    <?php endfor; ?>
  </div>

  <!-- Komunikaty -->
  <?php if ($error !== ''): ?>
  <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1"></i>
    <div><?= h($error) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($success !== ''): ?>
  <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-info-circle-fill fs-5 flex-shrink-0 mt-1"></i>
    <div><?= h($success) ?></div>
  </div>
  <?php endif; ?>

  <!-- ─────────────────────────────────────────────────────────────────────── -->
  <!-- KROK 1: Formularz cross-match                                          -->
  <!-- ─────────────────────────────────────────────────────────────────────── -->
  <?php if ($reset_step === 1): ?>
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h6 class="fw-semibold mb-1">Weryfikacja tożsamości</h6>
      <p class="text-muted small mb-3">
        Podaj dane z umowy, aby potwierdzić swoją tożsamość.
      </p>
      <form method="post" novalidate id="crossmatch-form">
        <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="crossmatch">

        <div class="mb-3">
          <label for="email" class="form-label fw-semibold small">Adres e-mail</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" class="form-control" id="email" name="email"
                   placeholder="Twój adres e-mail z umowy"
                   value="<?= h($_POST['email'] ?? '') ?>"
                   required autofocus>
          </div>
        </div>

        <!-- Przełącznik: mam / nie mam numeru umowy -->
        <div class="mb-3">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="no_numer_umowy"
                   name="no_numer_umowy" value="1"
                   <?= !empty($_POST['no_numer_umowy']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="no_numer_umowy">
              Nie znam numeru umowy
              <span class="text-muted">(umowy zawarte przed 1 czerwca 2026)</span>
            </label>
          </div>
        </div>

        <!-- Pole numeru umowy (ukrywane gdy brak) -->
        <div class="mb-3" id="row-numer-umowy" <?= !empty($_POST['no_numer_umowy']) ? 'style="display:none"' : '' ?>>
          <label for="numer_umowy" class="form-label fw-semibold small">Numer umowy</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-file-earmark-text"></i></span>
            <input type="text" class="form-control" id="numer_umowy" name="numer_umowy"
                   placeholder="np. RU/0001/2024/AB"
                   value="<?= h($_POST['numer_umowy'] ?? '') ?>">
          </div>
        </div>

        <!-- Kod odzyskiwania (widoczny tylko gdy brak numeru) -->
        <div class="mb-3" id="row-data-ur" <?= empty($_POST['no_numer_umowy']) ? 'style="display:none"' : '' ?>>
          <label for="recovery_code" class="form-label fw-semibold small">
            <i class="bi bi-shield-lock me-1 text-primary"></i>Kod odzyskiwania (8 cyfr)
          </label>
          <div class="input-group" style="max-width:260px">
            <span class="input-group-text"><i class="bi bi-key"></i></span>
            <input type="text" class="form-control font-monospace text-center fw-bold"
                   id="recovery_code" name="recovery_code"
                   placeholder="00000000" maxlength="8" inputmode="numeric" pattern="\d{8}"
                   style="letter-spacing:.25em;font-size:1.1rem"
                   value="<?= h($_POST['recovery_code'] ?? '') ?>">
          </div>
          <div class="form-text">
            Kod wysłany SMS-em lub e-mailem przy tworzeniu umowy.
          </div>
        </div>

        <div class="mb-4">
          <label for="pesel_or_doc" class="form-label fw-semibold small">
            PESEL lub numer dokumentu tożsamości
          </label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
            <input type="text" class="form-control" id="pesel_or_doc" name="pesel_or_doc"
                   placeholder="Ostatnie 5 cyfr PESEL lub pełny numer dokumentu"
                   value="<?= h($_POST['pesel_or_doc'] ?? '') ?>"
                   required>
          </div>
          <div class="form-text">
            Ostatnie 5 cyfr PESEL (np. <code>12345</code>) lub pełny numer dokumentu tożsamości.
          </div>
        </div>

        <div class="d-grid">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-arrow-right-circle me-1"></i>Weryfikuj dane
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
  (function() {
    var cb      = document.getElementById('no_numer_umowy');
    var rowNum  = document.getElementById('row-numer-umowy');
    var rowDur  = document.getElementById('row-data-ur');
    var inpNum  = document.getElementById('numer_umowy');
    var inpCode = document.getElementById('recovery_code');
    if (!cb) return;
    function toggle() {
      var noNum = cb.checked;
      rowNum.style.display = noNum ? 'none' : '';
      rowDur.style.display = noNum ? ''     : 'none';
      inpNum.required  = !noNum;
      if (inpCode) inpCode.required = noNum;
    }
    cb.addEventListener('change', toggle);
    toggle();
  })();
  </script>

  <!-- ─────────────────────────────────────────────────────────────────────── -->
  <!-- KROK 2: Kod SMS                                                        -->
  <!-- ─────────────────────────────────────────────────────────────────────── -->
  <?php elseif ($reset_step === 2): ?>
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h6 class="fw-semibold mb-1">Wprowadź kod SMS</h6>
      <p class="text-muted small mb-3">
        Jeśli dane były poprawne, na Twój numer telefonu wysłaliśmy 6-cyfrowy kod weryfikacyjny.
        Kod jest ważny przez <strong>10 minut</strong>.
      </p>
      <form method="post" novalidate>
        <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="verify_sms">

        <div class="mb-4">
          <label for="sms_code" class="form-label fw-semibold small">Kod SMS (6 cyfr)</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-chat-square-dots"></i></span>
            <input type="text" class="form-control form-control-lg text-center"
                   id="sms_code" name="sms_code"
                   maxlength="6" minlength="6"
                   inputmode="numeric" pattern="[0-9]{6}"
                   placeholder="000000"
                   autocomplete="one-time-code"
                   required autofocus
                   style="letter-spacing:.3em; font-size:1.3rem; font-weight:600">
          </div>
          <?php if ($sms_fails > 0): ?>
          <div class="form-text text-danger">
            Błędna próba <?= $sms_fails ?>/3. Po 3 błędach będziesz musiał zacząć od nowa.
          </div>
          <?php endif; ?>
        </div>

        <div class="d-grid mb-3">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle me-1"></i>Potwierdź kod
          </button>
        </div>
      </form>

      <!-- Powrót do kroku 1 -->
      <div class="text-center">
        <form method="post" style="display:inline">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="restart">
          <button type="submit" class="btn btn-link btn-sm text-muted p-0">
            <i class="bi bi-arrow-left me-1"></i>Wróć i popraw dane
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ─────────────────────────────────────────────────────────────────────── -->
  <!-- KROK 3: Nowe hasło                                                     -->
  <!-- ─────────────────────────────────────────────────────────────────────── -->
  <?php elseif ($reset_step === 3): ?>
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h6 class="fw-semibold mb-1">Ustaw nowe hasło</h6>
      <p class="text-muted small mb-3">
        Wybierz nowe hasło do swojego konta. Hasło musi mieć co najmniej 8 znaków,
        zawierać wielką literę, małą literę i cyfrę.
      </p>
      <form method="post" novalidate>
        <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="set_password">

        <div class="mb-3">
          <label for="password_new" class="form-label fw-semibold small">Nowe hasło</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" class="form-control" id="password_new" name="password_new"
                   minlength="8" required autofocus
                   autocomplete="new-password">
            <button class="btn btn-outline-secondary" type="button" id="togglePwdNew"
                    aria-label="Pokaż/ukryj hasło">
              <i class="bi bi-eye" id="eyeNew"></i>
            </button>
          </div>
          <div class="form-text">Min. 8 znaków, wielka litera, mała litera, cyfra.</div>
        </div>

        <div class="mb-4">
          <label for="password_confirm" class="form-label fw-semibold small">Powtórz nowe hasło</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
            <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                   minlength="8" required
                   autocomplete="new-password">
            <button class="btn btn-outline-secondary" type="button" id="togglePwdConfirm"
                    aria-label="Pokaż/ukryj powtórzone hasło">
              <i class="bi bi-eye" id="eyeConfirm"></i>
            </button>
          </div>
        </div>

        <div class="d-grid">
          <button type="submit" class="btn btn-success btn-lg">
            <i class="bi bi-check-circle me-1"></i>Ustaw hasło i zaloguj się
          </button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Link powrotu do logowania -->
  <div class="text-center mt-3">
    <a href="<?= h(APP_URL . '/auth/login.php') ?>" class="text-muted small text-decoration-none">
      <i class="bi bi-arrow-left me-1"></i>Wróć do strony logowania
    </a>
  </div>

</div><!-- /.reset-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmY+ASSbXX+/Y0DmfhVpJkJEYJA3"
        crossorigin="anonymous"></script>
<script>
  // Toggle widoczności hasła
  function togglePwd(btnId, inputId, eyeId) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.addEventListener('click', function () {
      var inp = document.getElementById(inputId);
      var eye = document.getElementById(eyeId);
      if (!inp) return;
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      if (eye) {
        eye.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
      }
    });
  }
  togglePwd('togglePwdNew',     'password_new',     'eyeNew');
  togglePwd('togglePwdConfirm', 'password_confirm', 'eyeConfirm');

  // Automatyczne przejście do następnego pola po wpisaniu 6 cyfr kodu SMS
  var smsInput = document.getElementById('sms_code');
  if (smsInput) {
    smsInput.addEventListener('input', function () {
      if (this.value.replace(/\D/g, '').length === 6) {
        this.form.submit();
      }
    });
  }

</script>
</body>
</html>
