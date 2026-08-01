<?php
/**
 * register.php — Samodzielna rejestracja konta panelowego.
 *
 * Dla wolontariuszy, którzy mają podpisaną umowę, ale nie mają jeszcze
 * konta w panelu SZO. Weryfikuje tożsamość (email + numer umowy + PESEL/dokument),
 * potwierdza kodem SMS, tworzy konto i pozwala ustawić hasło.
 *
 * 3 kroki w state machine:
 *   1. Dane umowy — cross-match
 *   2. Kod SMS — weryfikacja tożsamości
 *   3. Hasło  — ustawienie + redirect do logowania
 *
 * Konto zakładane dopiero PO weryfikacji SMS (brak zombie-kont).
 * Strona STANDALONE — nie includuje header.php.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/includes/password_validator.php';

auth_start();

// Zalogowany → panel
if (current_user()) {
    header('Location: ' . APP_URL . '/portal.php');
    exit;
}

// ── Rate limiting (max 5 prób / IP / godzina) ─────────────────────────────────
function _reg_rate_check(): bool {
    $pdo = db();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT NOT NULL,
            ip TEXT NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Throwable $e) {}
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $since = date('Y-m-d H:i:s', time() - 3600);
    $stmt  = $pdo->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = ? AND created_at > ?");
    $stmt->execute([$ip, $since]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return (int)($row['c'] ?? 0) < 5;
}

function _reg_rate_record(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        db()->prepare("INSERT INTO login_attempts (identifier, ip) VALUES (?, ?)")->execute([$ip, $ip]);
    } catch (\Throwable $e) {}
}

// ── Stałe błędów ──────────────────────────────────────────────────────────────
const REG_ERR_NOTFOUND   = 'Nie znaleziono umowy dla podanych danych. Sprawdź e-mail i PESEL/dokument.';
const REG_ERR_HASACCOUNT = 'Dla tej umowy istnieje już aktywne konto. Zaloguj się lub skorzystaj z odzyskiwania hasła.';
const REG_ERR_NOPHONE    = 'Brak numeru telefonu w umowie — rejestracja wymaga weryfikacji SMS. Skontaktuj się z biurem.';
const REG_ERR_RATE       = 'Zbyt wiele prób weryfikacji. Spróbuj ponownie za godzinę.';
const REG_ERR_SMS        = 'Podany kod jest nieprawidłowy lub wygasł. Spróbuj ponownie.';

/**
 * Szuka umowy wolontariackiej pasującej do danych.
 * Zwraca ['contract' => row, 'phone' => string] lub null/string (kod błędu).
 */
function _reg_find_contract(string $email, string $pesel_or_doc): array|null|string {
    $stmt = db()->prepare(
        "SELECT * FROM umowy_wolontariat
         WHERE LOWER(email) = LOWER(?)
           AND (SUBSTR(pesel, -5) = ? OR id_document_number = ?)
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$email, $pesel_or_doc, $pesel_or_doc]);
    $contract = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$contract) return null;

    $existing = db_one(
        "SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND is_active = 1",
        [$email]
    );
    if ($existing) return 'has_account';

    $phone = trim((string)($contract['telefon'] ?? ''));
    if ($phone === '') return 'no_phone';

    return ['contract' => $contract, 'phone' => $phone];
}

/**
 * Zakłada konto panelowe dla wolontariusza po pozytywnej weryfikacji SMS.
 * Zwraca nowe user id lub 0 przy błędzie.
 */
function _reg_provision_account(array $contract): int {
    $email = trim((string)($contract['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;
    if (db_one("SELECT id FROM users WHERE LOWER(email) = LOWER(?)", [$email])) return 0;

    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
    db_insert('users', [
        'name'         => trim((string)($contract['imie_nazwisko'] ?? '')) ?: $email,
        'email'        => $email,
        'password'     => $hash,
        'role'         => 'viewer',
        'is_active'    => 1,
        'portal_scope' => $contract['portal_scope'] ?? null,
        'created_at'   => date('Y-m-d H:i:s'),
    ]);
    $uid = (int) db()->lastInsertId();
    log_system_action(
        $uid,
        'self_register_wolontariat',
        'Konto założone przez /user/register.php (umowa ' . ($contract['numer_umowy'] ?? '') . '), IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '')
    );
    return $uid;
}

// ── Odczyt stanu z sesji ──────────────────────────────────────────────────────
$step      = (int) ($_SESSION['reg_step']      ?? 1);
$sms_fails = (int) ($_SESSION['reg_sms_fails'] ?? 0);
$error     = '';
$success   = '';

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'restart') {
        foreach (['reg_step','reg_sms_code','reg_sms_exp','reg_email','reg_contract_id','reg_sms_fails'] as $k) {
            unset($_SESSION[$k]);
        }
        header('Location: ' . APP_URL . '/user/register.php');
        exit;
    }

    // ── KROK 1: Weryfikacja umowy ─────────────────────────────────────────────
    if ($action === 'verify' && $step === 1) {
        if (!_reg_rate_check()) {
            $error = REG_ERR_RATE;
        } else {
            _reg_rate_record();
            $email = trim($_POST['email']        ?? '');
            $pesel = trim($_POST['pesel_or_doc'] ?? '');

            $result = _reg_find_contract($email, $pesel);

            if ($result === null) {
                $error = REG_ERR_NOTFOUND;
            } elseif ($result === 'has_account') {
                $error = REG_ERR_HASACCOUNT;
            } elseif ($result === 'no_phone') {
                $error = REG_ERR_NOPHONE;
            } else {
                // Generujemy kod SMS — konto zakładamy DOPIERO po weryfikacji
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $exp  = time() + 600;

                try {
                    $org_name = defined('ORG_NAME') ? ORG_NAME : '';
                    sms_send($result['phone'], "Kod rejestracji konta: {$code}. Ważny 10 min. [{$org_name}]");
                } catch (\Throwable $e) {
                    // Milcząco — nie ujawniamy błędu
                }

                $_SESSION['reg_step']       = 2;
                $_SESSION['reg_sms_code']   = $code;
                $_SESSION['reg_sms_exp']    = $exp;
                $_SESSION['reg_email']      = $result['contract']['email'];
                $_SESSION['reg_contract_id'] = (int) $result['contract']['id'];
                $_SESSION['reg_sms_fails']  = 0;
                $step = 2;
                $success = 'Jeśli dane są poprawne, kod weryfikacyjny został wysłany SMS-em na numer z umowy.';
            }
        }
    }

    // ── KROK 2: Weryfikacja kodu SMS ──────────────────────────────────────────
    elseif ($action === 'verify_sms' && $step === 2) {
        $input_code  = trim($_POST['sms_code'] ?? '');
        $stored_code = (string) ($_SESSION['reg_sms_code'] ?? '');
        $stored_exp  = (int)    ($_SESSION['reg_sms_exp']  ?? 0);

        $ok = $stored_code !== ''
            && time() < $stored_exp
            && hash_equals($stored_code, $input_code);

        if ($ok) {
            unset($_SESSION['reg_sms_code'], $_SESSION['reg_sms_exp']);
            $_SESSION['reg_step']     = 3;
            $_SESSION['reg_sms_fails'] = 0;
            $step = 3;
        } else {
            $sms_fails++;
            $_SESSION['reg_sms_fails'] = $sms_fails;
            if ($sms_fails >= 3) {
                foreach (['reg_step','reg_sms_code','reg_sms_exp','reg_email','reg_contract_id','reg_sms_fails'] as $k) {
                    unset($_SESSION[$k]);
                }
                $step      = 1;
                $sms_fails = 0;
                $error = 'Zbyt wiele błędnych kodów. Zacznij od nowa.';
            } else {
                $error = REG_ERR_SMS;
            }
        }
    }

    // ── KROK 3: Ustawienie hasła i założenie konta ────────────────────────────
    elseif ($action === 'set_password' && $step === 3) {
        $contract_id = (int) ($_SESSION['reg_contract_id'] ?? 0);
        $pass        = $_POST['password_new']     ?? '';
        $confirm     = $_POST['password_confirm'] ?? '';

        if ($pass !== $confirm) {
            $error = 'Hasła nie są identyczne.';
        } else {
            $validation = PasswordValidator::validate($pass);
            if (!$validation['ok']) {
                $error = implode(' ', $validation['errors']);
            } else {
                // Pobierz umowę na świeżo (race-condition check)
                $contract = db_one("SELECT * FROM umowy_wolontariat WHERE id = ?", [$contract_id]);
                if (!$contract) {
                    $error = 'Błąd danych rejestracji. Zacznij od nowa.';
                } else {
                    $existing = db_one(
                        "SELECT id FROM users WHERE LOWER(email) = LOWER(?)",
                        [$contract['email']]
                    );
                    if ($existing) {
                        $error = REG_ERR_HASACCOUNT;
                    } else {
                        $uid = _reg_provision_account($contract);
                        if (!$uid) {
                            $error = 'Nie udało się założyć konta. Skontaktuj się z administratorem.';
                        } else {
                            $hash = password_hash($pass, PASSWORD_BCRYPT);
                            db()->prepare("UPDATE users SET password = ?, allow_local_fallback = 1 WHERE id = ?")
                                ->execute([$hash, $uid]);
                            log_system_action($uid, 'self_register_password_set',
                                'Ustawiono hasło przy samodzielnej rejestracji (/user/register.php)');

                            foreach (['reg_step','reg_sms_code','reg_sms_exp','reg_email','reg_contract_id','reg_sms_fails'] as $k) {
                                unset($_SESSION[$k]);
                            }

                            flash_set('success', 'Konto zostało założone. Możesz się teraz zalogować.');
                            header('Location: ' . APP_URL . '/auth/login.php');
                            exit;
                        }
                    }
                }
            }
        }
    }
}

$org_name    = defined('ORG_NAME') ? ORG_NAME : '';
$step_labels = [1 => 'Dane umowy', 2 => 'Kod SMS', 3 => 'Hasło'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rejestracja — <?= h($org_name) ?></title>
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous">
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    :root{--tz:#1E6DFF;--tz-strong:#1656d6;--tz-50:#eef4ff;--tz-line:#E5E9F0;}
    body{background:radial-gradient(1200px 500px at 50% -10%,#e7f0ff 0%,rgba(231,240,255,0) 60%),#F4F6F9;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;color:#111827}
    .wrap{max-width:520px;width:100%}
    .tz-brandbar{display:flex;align-items:center;justify-content:center;gap:.55rem;margin-bottom:1.25rem}
    .tz-brandbar .mark{width:34px;height:34px;border-radius:10px;background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.05rem}
    .tz-brandbar .txt{font-weight:700;letter-spacing:.02em;color:#1146ad}
    .tz-brandbar .txt small{display:block;font-weight:500;font-size:.68rem;letter-spacing:.06em;color:#6B7280;text-transform:uppercase}
    .wrap .card{border:1px solid var(--tz-line);border-radius:16px;box-shadow:0 12px 40px -12px rgba(30,109,255,.25)}
    .wrap .btn-primary{--bs-btn-bg:var(--tz-strong);--bs-btn-border-color:var(--tz-strong);--bs-btn-hover-bg:#0f3c9c;--bs-btn-hover-border-color:#0f3c9c}
    .wrap .btn-outline-primary{--bs-btn-color:var(--tz-strong);--bs-btn-border-color:var(--tz-line);--bs-btn-hover-bg:var(--tz-50);--bs-btn-hover-color:var(--tz-strong);--bs-btn-hover-border-color:var(--tz)}
    .wrap .form-control:focus{border-color:var(--tz);box-shadow:0 0 0 .2rem rgba(30,109,255,.18)}
    .wrap a{color:var(--tz-strong)}
    /* Pasek kroków */
    .step-bar{display:flex;align-items:center;justify-content:center;margin-bottom:2rem}
    .step-item{display:flex;flex-direction:column;align-items:center;gap:4px}
    .step-circle{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;border:2px solid}
    .step-circle.done{background:#1E6DFF;border-color:#1E6DFF;color:#fff}
    .step-circle.active{background:#fff;border-color:#1E6DFF;color:#1E6DFF}
    .step-circle.pending{background:#fff;border-color:#dee2e6;color:#adb5bd}
    .step-label{font-size:.72rem;text-align:center;color:#6c757d;max-width:80px}
    .step-label.active{color:#1E6DFF;font-weight:600}
    .step-connector{flex:1;height:2px;background:#dee2e6;margin:0 8px;margin-bottom:20px}
    .step-connector.done{background:#1E6DFF}
  </style>
</head>
<body>
<div class="wrap">

  <!-- Logo systemu -->
  <div class="tz-brandbar">
    <span class="mark" aria-hidden="true"><i class="bi bi-person-vcard-fill"></i></span>
    <span class="txt">System Tożsamości<small><?= h($org_name) ?></small></span>
  </div>

  <!-- Nagłówek -->
  <div class="text-center mb-4">
    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
         style="width:64px;height:64px;background:#eef4ff">
      <i class="bi bi-person-plus-fill fs-2" style="color:#1E6DFF" aria-hidden="true"></i>
    </div>
    <h1 class="h5 fw-bold mb-1">Rejestracja konta</h1>
    <p class="text-muted small mb-0">Utwórz konto panelowe SZO na podstawie podpisanej umowy</p>
  </div>

  <!-- Pasek kroków -->
  <div class="step-bar">
    <?php for ($i = 1; $i <= 3; $i++): ?>
      <?php if ($i > 1): ?>
        <div class="step-connector<?= $step > $i - 1 ? ' done' : '' ?>"></div>
      <?php endif; ?>
      <div class="step-item">
        <div class="step-circle <?= $step > $i ? 'done' : ($step === $i ? 'active' : 'pending') ?>">
          <?php if ($step > $i): ?>
            <i class="bi bi-check-lg" aria-hidden="true"></i>
          <?php else: ?>
            <?= $i ?>
          <?php endif; ?>
        </div>
        <span class="step-label<?= $step === $i ? ' active' : '' ?>"><?= h($step_labels[$i]) ?></span>
      </div>
    <?php endfor; ?>
  </div>

  <!-- Komunikaty -->
  <?php if ($error !== ''): ?>
  <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
    <div><?= h($error) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($success !== ''): ?>
  <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-info-circle-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
    <div><?= h($success) ?></div>
  </div>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- KROK 1: Dane umowy                                                      -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <?php if ($step === 1): ?>
  <div class="card">
    <div class="card-body p-4">
      <h6 class="fw-semibold mb-1">Podaj dane z umowy</h6>
      <p class="text-muted small mb-3">
        Wypełnij poniższe pola, aby potwierdzić tożsamość. Po weryfikacji wyślemy
        jednorazowy kod SMS na numer telefonu podany w umowie.
      </p>
      <form method="post" novalidate>
        <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="verify">

        <div class="mb-3">
          <label for="email" class="form-label fw-semibold small">Adres e-mail</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
            <input type="email" class="form-control" id="email" name="email"
                   placeholder="E-mail podany w umowie"
                   value="<?= h($_POST['email'] ?? '') ?>"
                   required autofocus autocomplete="email">
          </div>
        </div>

        <div class="mb-4">
          <label for="pesel_or_doc" class="form-label fw-semibold small">
            PESEL lub numer dokumentu tożsamości
          </label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person-vcard" aria-hidden="true"></i></span>
            <input type="text" class="form-control" id="pesel_or_doc" name="pesel_or_doc"
                   placeholder="Ostatnie 5 cyfr PESEL lub pełny numer dokumentu"
                   value="<?= h($_POST['pesel_or_doc'] ?? '') ?>"
                   required>
          </div>
          <div class="form-text">Ostatnie 5 cyfr PESEL (np. <code>12345</code>) lub pełny numer dokumentu.</div>
        </div>

        <div class="d-grid">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-shield-check me-1" aria-hidden="true"></i>Zweryfikuj i wyślij kod SMS
          </button>
        </div>
      </form>
    </div>
  </div>

  <div class="card mt-3" style="border:1px solid var(--tz-line)">
    <div class="card-body p-3 d-flex align-items-start gap-2">
      <i class="bi bi-info-circle text-primary flex-shrink-0 mt-1" aria-hidden="true"></i>
      <div class="small text-muted">
        Rejestracja jest możliwa wyłącznie dla osób posiadających ważną umowę wolontariacką.
        Jeśli Twoja umowa jest innego typu (praca, zlecenie), skontaktuj się z administratorem.
        <br class="d-none d-sm-block">
        Masz już konto? <a href="<?= h(APP_URL . '/auth/login.php') ?>">Zaloguj się</a>
        lub <a href="<?= h(APP_URL . '/user/verify_reset.php') ?>">zresetuj hasło</a>.
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- KROK 2: Kod SMS                                                         -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <?php elseif ($step === 2): ?>
  <div class="card">
    <div class="card-body p-4">
      <h6 class="fw-semibold mb-1">Wprowadź kod SMS</h6>
      <p class="text-muted small mb-3">
        Jeśli dane były poprawne, wysłaliśmy 6-cyfrowy kod na numer telefonu z umowy.
        Kod jest ważny przez <strong>10 minut</strong>.
      </p>
      <form method="post" novalidate>
        <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="verify_sms">

        <div class="mb-4">
          <label for="sms_code" class="form-label fw-semibold small">Kod SMS (6 cyfr)</label>
          <div class="input-group" style="max-width:240px">
            <span class="input-group-text"><i class="bi bi-chat-square-dots" aria-hidden="true"></i></span>
            <input type="text" class="form-control form-control-lg text-center"
                   id="sms_code" name="sms_code"
                   maxlength="6" minlength="6"
                   inputmode="numeric" pattern="[0-9]{6}"
                   placeholder="000000"
                   autocomplete="one-time-code"
                   required autofocus
                   style="letter-spacing:.3em;font-size:1.3rem;font-weight:600">
          </div>
          <?php if ($sms_fails > 0): ?>
          <div class="form-text text-danger">
            Błędna próba <?= $sms_fails ?>/3. Po 3 błędach zaczniesz od nowa.
          </div>
          <?php endif; ?>
        </div>

        <div class="d-grid mb-3">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Potwierdź kod
          </button>
        </div>
      </form>

      <div class="text-center">
        <form method="post" style="display:inline">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="restart">
          <button type="submit" class="btn btn-link btn-sm text-muted p-0">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć i popraw dane
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- KROK 3: Hasło                                                           -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <?php elseif ($step === 3): ?>
  <div class="card">
    <div class="card-body p-4">
      <h6 class="fw-semibold mb-1">Ustaw hasło do konta</h6>
      <p class="text-muted small mb-3">
        Tożsamość potwierdzona — Twoje konto zostanie założone po ustawieniu hasła.
        Hasło musi mieć co najmniej 8 znaków, wielką literę, małą literę i cyfrę.
      </p>
      <form method="post" novalidate>
        <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="set_password">

        <div class="mb-3">
          <label for="password_new" class="form-label fw-semibold small">Hasło</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
            <input type="password" class="form-control" id="password_new" name="password_new"
                   minlength="8" required autofocus autocomplete="new-password">
            <button class="btn btn-outline-secondary" type="button" id="togglePwdNew"
                    aria-label="Pokaż/ukryj hasło">
              <i class="bi bi-eye" id="eyeNew" aria-hidden="true"></i>
            </button>
          </div>
          <div class="form-text">Min. 8 znaków, wielka litera, mała litera, cyfra.</div>
        </div>

        <div class="mb-4">
          <label for="password_confirm" class="form-label fw-semibold small">Powtórz hasło</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock-fill" aria-hidden="true"></i></span>
            <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                   minlength="8" required autocomplete="new-password">
            <button class="btn btn-outline-secondary" type="button" id="togglePwdConfirm"
                    aria-label="Pokaż/ukryj powtórzone hasło">
              <i class="bi bi-eye" id="eyeConfirm" aria-hidden="true"></i>
            </button>
          </div>
        </div>

        <div class="d-grid">
          <button type="submit" class="btn btn-success btn-lg">
            <i class="bi bi-person-check me-1" aria-hidden="true"></i>Załóż konto i zaloguj się
          </button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Powrót do logowania -->
  <div class="text-center mt-3">
    <a href="<?= h(APP_URL . '/auth/login.php') ?>" class="text-muted small text-decoration-none">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do strony logowania
    </a>
  </div>

</div><!-- /.wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmY+ASSbXX+/Y0DmfhVpJkJEYJA3"
        crossorigin="anonymous"></script>
<script>
function togglePwd(btnId, inputId, eyeId) {
  var btn = document.getElementById(btnId);
  if (!btn) return;
  btn.addEventListener('click', function() {
    var inp = document.getElementById(inputId);
    var eye = document.getElementById(eyeId);
    if (!inp) return;
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    if (eye) eye.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
  });
}
togglePwd('togglePwdNew', 'password_new', 'eyeNew');
togglePwd('togglePwdConfirm', 'password_confirm', 'eyeConfirm');

var smsInput = document.getElementById('sms_code');
if (smsInput) {
  smsInput.addEventListener('input', function() {
    if (this.value.replace(/\D/g, '').length === 6) this.form.submit();
  });
}
</script>
</body>
</html>
