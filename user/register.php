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
require_once dirname(__DIR__) . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/includes/password_validator.php';
require_once dirname(__DIR__) . '/includes/auth_security.php';

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
const REG_ERR_PIN        = 'Nieprawidłowy PIN rejestracji. Poproś administratora o aktualny kod.';

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
            $pin   = trim($_POST['register_pin'] ?? '');

            // PIN od administratora — brama przed jakimkolwiek szukaniem umowy,
            // żeby formularz nie potwierdzał istnienia danych osobie bez PIN-u.
            if (!register_pin_check($pin)) {
                authlog_write(null, 'register_pin_fail', $email,
                              'Błędny PIN rejestracji, IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
                $error = REG_ERR_PIN;
            } else {
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

$step_labels = [1 => 'Dane umowy', 2 => 'Kod SMS', 3 => 'Hasło'];

require_once dirname(__DIR__) . '/includes/auth_screen.php';
auth_screen_head([
    'title'   => 'Załóż konto',
    'tab'     => 'register',
    'main_id' => 'reg-main',
]);
?>

    <?php auth_screen_steps($step_labels, $step); ?>

    <?php if ($error !== ''): ?>
    <div class="l-alert l-alert-danger" role="alert">
      <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
      <span id="login-error-text"><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
    <div class="l-alert l-alert-info" role="status">
      <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
      <span><?= h($success) ?></span>
    </div>
    <?php endif; ?>

    <?php if (!register_is_open() && $step === 1): ?>
    <!-- ══ Rejestracja zamknięta przez administratora ═══════════════════ -->
    <h1 class="ks-h1">Rejestracja zamknięta</h1>
    <p class="ks-lead">
      Zakładanie kont z formularza jest w tej chwili wyłączone. Jeśli masz podpisaną umowę
      i potrzebujesz konta, poproś administratora o <strong>PIN rejestracji</strong> albo
      o założenie konta.
    </p>
    <a href="<?= h(APP_URL . '/auth/report_login_issue.php') ?>" class="ks-btn ks-btn--primary">
      <i class="bi bi-envelope" aria-hidden="true"></i>Napisz do administratora
    </a>
    <a href="<?= h(APP_URL . '/auth/login.php') ?>" class="ks-btn ks-btn--ghost">
      <i class="bi bi-arrow-left" aria-hidden="true"></i>Wróć do logowania
    </a>

    <?php elseif ($step === 1): ?>
    <!-- ══ Krok 1 — dane z umowy ════════════════════════════════════════ -->
    <h1 class="ks-h1">Załóż konto</h1>
    <p class="ks-lead">Podaj PIN od administratora oraz e-mail i PESEL z umowy wolontariackiej — wyślemy kod SMS na Twój numer telefonu.</p>

    <form method="post" novalidate>
      <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="verify">

      <div class="ks-field">
        <label for="register_pin">PIN rejestracji <span style="color:var(--ks-muted);font-weight:400">(6 cyfr od administratora)</span></label>
        <input type="text" class="form-control ks-otp" id="register_pin" name="register_pin"
               maxlength="6" minlength="6" inputmode="numeric" pattern="[0-9]{6}"
               placeholder="000000" autocomplete="off" required autofocus
               aria-describedby="pin-hint">
        <p class="ks-fieldhint" id="pin-hint">
          Kod otrzymasz od osoby, która przyjmowała Twoją umowę. Bez niego nie założysz konta.
        </p>
      </div>

      <div class="ks-field">
        <label for="email">Adres e-mail</label>
        <input type="email" class="form-control" id="email" name="email"
               placeholder="adres@email.pl" value="<?= h($_POST['email'] ?? '') ?>"
               required autofocus autocomplete="email">
      </div>

      <div class="ks-field">
        <label for="pesel_or_doc">PESEL lub numer dokumentu</label>
        <input type="text" class="form-control" id="pesel_or_doc" name="pesel_or_doc"
               placeholder="Ostatnie 5 cyfr PESEL lub pełny numer dokumentu"
               value="<?= h($_POST['pesel_or_doc'] ?? '') ?>" required>
        <p class="ks-fieldhint">Ostatnie 5 cyfr PESEL (np. <code>12345</code>) lub pełny numer dokumentu tożsamości.</p>
      </div>

      <button type="submit" class="ks-btn ks-btn--primary">
        <i class="bi bi-shield-check" aria-hidden="true"></i>Zweryfikuj i wyślij kod SMS
      </button>
    </form>

    <p class="ks-note">
      Rejestracja wyłącznie dla umów wolontariackich. Inny typ umowy? Skontaktuj się z administratorem.<br>
      Masz już konto? <a href="<?= h(APP_URL . '/auth/login.php') ?>">Zaloguj się</a> lub
      <a href="<?= h(APP_URL . '/user/verify_reset.php') ?>">odzyskaj dostęp</a>.
    </p>

    <?php elseif ($step === 2): ?>
    <!-- ══ Krok 2 — kod SMS ═════════════════════════════════════════════ -->
    <h1 class="ks-h1">Kod SMS</h1>
    <p class="ks-lead">Wysłaliśmy 6-cyfrowy kod na numer telefonu z umowy. Kod jest ważny przez <strong>10 minut</strong>.</p>

    <form method="post" novalidate>
      <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="verify_sms">

      <div class="ks-field">
        <label for="sms_code" class="sr">Kod SMS — 6 cyfr</label>
        <input type="text" class="form-control ks-otp" id="sms_code" name="sms_code"
               maxlength="6" minlength="6" inputmode="numeric" pattern="[0-9]{6}"
               placeholder="000000" autocomplete="one-time-code" required autofocus
               aria-describedby="sms-hint">
        <p class="ks-fieldhint" id="sms-hint" style="text-align:center<?= $sms_fails > 0 ? ';color:#b91c1c' : '' ?>">
          <?= $sms_fails > 0
              ? 'Błędna próba ' . (int)$sms_fails . '/3 — po 3 błędach zaczniesz od nowa.'
              : 'Wpisz 6 cyfr z wiadomości SMS' ?>
        </p>
      </div>

      <button type="submit" class="ks-btn ks-btn--primary">
        <i class="bi bi-check-circle" aria-hidden="true"></i>Potwierdź kod
      </button>
    </form>

    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="restart">
      <button type="submit" class="ks-btn ks-btn--ghost">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>Wróć i popraw dane
      </button>
    </form>

    <?php elseif ($step === 3): ?>
    <!-- ══ Krok 3 — hasło ═══════════════════════════════════════════════ -->
    <h1 class="ks-h1">Ustaw hasło</h1>
    <p class="ks-lead">Tożsamość potwierdzona. Wybierz hasło — po jego ustawieniu konto będzie gotowe.</p>

    <form method="post" novalidate>
      <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="set_password">

      <div class="ks-field">
        <label for="password_new">Hasło</label>
        <div class="pass-wrap">
          <input type="password" class="form-control" id="password_new" name="password_new"
                 minlength="8" required autofocus autocomplete="new-password">
          <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false"
                  onclick="togglePass('password_new', this)">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        </div>
        <p class="ks-fieldhint">Min. 8 znaków, wielka litera, mała litera, cyfra.</p>
      </div>

      <div class="ks-field">
        <label for="password_confirm">Powtórz hasło</label>
        <div class="pass-wrap">
          <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                 minlength="8" required autocomplete="new-password">
          <button type="button" class="pass-toggle" aria-label="Pokaż hasło" aria-pressed="false"
                  onclick="togglePass('password_confirm', this)">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="ks-btn ks-btn--ok">
        <i class="bi bi-person-check" aria-hidden="true"></i>Załóż konto i zaloguj się
      </button>
    </form>
    <?php endif; ?>

<?php
auth_screen_foot([
    'links' => [
        ['url' => APP_URL . '/auth/login.php',              'label' => 'Wróć do logowania',   'icon' => 'bi-arrow-left'],
        ['url' => APP_URL . '/auth/report_login_issue.php', 'label' => 'Problem z kontem',    'icon' => 'bi-life-preserver'],
    ],
]);
