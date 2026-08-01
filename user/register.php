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

$_b          = branding_load();
$_tagline    = '';
try { $_tagline = org_setting('login_tagline') ?: ''; } catch (\Throwable $e) {}
$org_name    = $_b['org_name'] ?: (defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : 'Fundacja Edukacji Empatii Rozwoju FEER');
$step_labels = [1 => 'Dane umowy', 2 => 'Kod SMS', 3 => 'Hasło'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Załóż konto — <?= h($org_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
/* ── Reset ───────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px;-webkit-text-size-adjust:100%}
body{
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;
  background:#f0f4f8;
  color:#0d1b2a;
  min-height:100vh;
  display:flex;
  flex-direction:column;
}

/* ── Focus ───────────────────────────────────────────────────── */
.skip-link{position:absolute;top:-9em;left:1rem;z-index:999;background:var(--c,#2563eb);color:#fff;padding:.5rem 1rem;border-radius:0 0 6px 6px;font-weight:700;text-decoration:none}
.skip-link:focus{top:0}
*:focus-visible{outline:2.5px solid var(--c,#2563eb);outline-offset:3px;border-radius:3px}
*:focus:not(:focus-visible){outline:none}

/* ── Top bar ─────────────────────────────────────────────────── */
.topbar{
  display:flex;align-items:center;justify-content:space-between;
  padding:.9rem 1.5rem;
  border-bottom:1px solid #dde3ea;
  background:#fff;
}
.topbar-brand{display:flex;align-items:center;gap:.55rem;text-decoration:none}
.topbar-logo{max-height:26px;object-fit:contain}
.topbar-icon{width:28px;height:28px;border-radius:7px;background:var(--c,#2563eb);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.85rem}
.topbar-org{font-size:.88rem;font-weight:700;color:#0d1b2a;letter-spacing:-.01em}
.topbar-back{display:inline-flex;align-items:center;gap:.35rem;font-size:.82rem;color:#64748b;text-decoration:none}
.topbar-back:hover{color:var(--c,#2563eb)}

/* ── Page body ───────────────────────────────────────────────── */
.page{flex:1;display:flex;align-items:flex-start;justify-content:center;padding:3rem 1.25rem 4rem}
.frame{width:100%;max-width:440px}

/* ── Step dots ───────────────────────────────────────────────── */
.step-dots{display:flex;align-items:center;gap:0;margin-bottom:2.25rem}
.step-dot-wrap{display:flex;align-items:center;gap:0}
.step-dot{
  width:8px;height:8px;border-radius:50%;
  border:2px solid #c9d2dd;
  background:transparent;
  transition:background .25s,border-color .25s,transform .25s;
}
.step-dot.done{background:var(--c,#2563eb);border-color:var(--c,#2563eb)}
.step-dot.active{
  width:28px;border-radius:4px;
  background:var(--c,#2563eb);border-color:var(--c,#2563eb);
}
.step-line{width:24px;height:2px;background:#c9d2dd;flex-shrink:0}
.step-line.done{background:var(--c,#2563eb)}
.step-count{margin-left:auto;font-size:.76rem;font-weight:600;color:#94a3b8;letter-spacing:.04em}

/* ── Heading ─────────────────────────────────────────────────── */
.heading{font-size:1.75rem;font-weight:800;color:#0d1b2a;letter-spacing:-.025em;line-height:1.15;margin-bottom:.5rem;text-wrap:balance}
.sub{font-size:.9rem;color:#64748b;line-height:1.6;margin-bottom:2rem}
.sub strong{color:#475569}

/* ── Alerts ──────────────────────────────────────────────────── */
.alert{display:flex;align-items:flex-start;gap:.6rem;padding:.85rem 1rem;border-radius:10px;margin-bottom:1.5rem;font-size:.85rem;line-height:1.55}
.alert i{flex-shrink:0;margin-top:.1rem;font-size:1rem}
.alert-danger{background:#fef2f2;border:1.5px solid #fca5a5;color:#7f1d1d}
.alert-info{background:#eff6ff;border:1.5px solid #93c5fd;color:#1e3a8a}

/* ── Field ───────────────────────────────────────────────────── */
.field{margin-bottom:1.25rem}
.field-label{display:block;font-size:.8rem;font-weight:600;color:#374151;letter-spacing:.02em;margin-bottom:.4rem}
.field-wrap{position:relative}
.field-icon{position:absolute;left:.9rem;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.95rem;pointer-events:none}
.field-input{
  width:100%;padding:.75rem .9rem .75rem 2.4rem;
  border:1.5px solid #d1d5db;border-radius:10px;
  font-size:.93rem;color:#0d1b2a;
  background:#fff;
  transition:border-color .15s,box-shadow .15s;
  appearance:none;
}
.field-input::placeholder{color:#c0c7d0}
.field-input:focus{border-color:var(--c,#2563eb);box-shadow:0 0 0 3px rgba(37,99,235,.12);outline:none}
.field-input.no-icon{padding-left:.9rem}
.field-hint{font-size:.76rem;color:#94a3b8;margin-top:.35rem;line-height:1.5}
.field-hint code{background:#f1f5f9;border-radius:3px;padding:.05em .3em;font-size:.9em}

/* Pokaż/ukryj hasło */
.pwd-wrap{position:relative}
.pwd-wrap .field-input{padding-right:2.6rem}
.pwd-toggle{position:absolute;right:.7rem;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;padding:.25rem;font-size:1rem;line-height:1;border-radius:4px}
.pwd-toggle:hover{color:#475569}

/* SMS */
.sms-wrap{display:flex;justify-content:center;margin-bottom:.5rem}
.sms-input{
  width:180px;text-align:center;
  font-size:2rem;font-weight:700;letter-spacing:.35em;
  padding:.6rem .5rem;border:1.5px solid #d1d5db;border-radius:12px;
  color:#0d1b2a;background:#fff;
  transition:border-color .15s,box-shadow .15s;
}
.sms-input:focus{border-color:var(--c,#2563eb);box-shadow:0 0 0 3px rgba(37,99,235,.12);outline:none}
.sms-hint{text-align:center;font-size:.8rem;color:#94a3b8;margin-bottom:1.5rem}
.sms-hint.err{color:#dc2626}

/* ── Buttons ─────────────────────────────────────────────────── */
.btn-primary{
  display:flex;align-items:center;justify-content:center;gap:.5rem;
  width:100%;padding:.8rem 1rem;
  background:var(--c,#2563eb);color:#fff;
  border:none;border-radius:10px;
  font-size:.95rem;font-weight:700;cursor:pointer;min-height:50px;
  transition:background .15s,transform .1s;
  letter-spacing:.01em;
}
.btn-primary:hover{background:var(--c-dark,#1d4ed8)}
.btn-primary:active{transform:scale(.98)}
.btn-primary.success-color{background:#16a34a}
.btn-primary.success-color:hover{background:#15803d}

.btn-ghost{
  display:inline-flex;align-items:center;gap:.35rem;
  background:none;border:none;cursor:pointer;
  font-size:.82rem;color:#64748b;padding:.4rem 0;
  margin-top:.75rem;
}
.btn-ghost:hover{color:var(--c,#2563eb)}

/* ── Note ────────────────────────────────────────────────────── */
.note{font-size:.8rem;color:#94a3b8;line-height:1.6;padding-top:1.25rem;margin-top:1.5rem;border-top:1px solid #e5e9ef}
.note a{color:#64748b;text-decoration:underline;text-underline-offset:2px}
.note a:hover{color:var(--c,#2563eb)}

/* ── Footer ──────────────────────────────────────────────────── */
.foot{text-align:center;padding:1.5rem;font-size:.75rem;color:#b0bac7}
.foot a{color:#94a3b8;text-decoration:none}
.foot a:hover{color:#64748b}

@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important;animation:none!important}}
@media(max-width:520px){
  .page{padding:1.75rem 1rem 3rem}
  .heading{font-size:1.45rem}
  .topbar{padding:.75rem 1rem}
}
</style>
</head>
<body>

<a href="#reg-main" class="skip-link">Przejdź do formularza</a>

<!-- Top bar -->
<header class="topbar">
  <a href="<?= APP_URL ?>/auth/login.php" class="topbar-brand" aria-label="<?= h($org_name) ?> — strona główna">
    <?php if ($_b['logo_url']): ?>
    <img src="<?= h($_b['logo_url']) ?>" alt="" class="topbar-logo">
    <?php else: ?>
    <span class="topbar-icon" aria-hidden="true"><i class="bi bi-building-heart"></i></span>
    <?php endif; ?>
    <span class="topbar-org"><?= h($org_name) ?></span>
  </a>
  <a href="<?= APP_URL ?>/auth/login.php" class="topbar-back">
    <i class="bi bi-arrow-left" aria-hidden="true"></i>Logowanie
  </a>
</header>

<!-- Main -->
<div class="page">
<main class="frame" id="reg-main" tabindex="-1">

  <!-- Step indicator -->
  <div class="step-dots" aria-label="Postęp: krok <?= $step ?> z 3" role="status">
    <?php foreach ($step_labels as $i => $lbl): ?>
      <?php if ($i > 1): ?>
        <div class="step-line <?= $step > $i - 1 ? 'done' : '' ?>"></div>
      <?php endif; ?>
      <div class="step-dot <?= $step > $i ? 'done' : ($step === $i ? 'active' : '') ?>"
           title="<?= h($lbl) ?>"></div>
    <?php endforeach; ?>
    <span class="step-count"><?= $step ?> / 3</span>
  </div>

  <!-- Alerts -->
  <?php if ($error !== ''): ?>
  <div class="alert alert-danger" role="alert">
    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
    <div><?= h($error) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($success !== ''): ?>
  <div class="alert alert-info" role="status">
    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
    <div><?= h($success) ?></div>
  </div>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- KROK 1                                                                   -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <?php if ($step === 1): ?>
  <h1 class="heading">Dane z&nbsp;umowy</h1>
  <p class="sub">Podaj e-mail i PESEL z umowy wolontariackiej — wyślemy kod SMS na&nbsp;Twój numer telefonu.</p>

  <form method="post" novalidate>
    <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_action" value="verify">

    <div class="field">
      <label for="email" class="field-label">Adres e-mail</label>
      <div class="field-wrap">
        <i class="bi bi-envelope field-icon" aria-hidden="true"></i>
        <input type="email" class="field-input" id="email" name="email"
               placeholder="adres@email.pl"
               value="<?= h($_POST['email'] ?? '') ?>"
               required autofocus autocomplete="email">
      </div>
    </div>

    <div class="field">
      <label for="pesel_or_doc" class="field-label">PESEL lub numer dokumentu</label>
      <div class="field-wrap">
        <i class="bi bi-person-vcard field-icon" aria-hidden="true"></i>
        <input type="text" class="field-input" id="pesel_or_doc" name="pesel_or_doc"
               placeholder="Ostatnie 5 cyfr PESEL lub pełny numer dokumentu"
               value="<?= h($_POST['pesel_or_doc'] ?? '') ?>"
               required>
      </div>
      <p class="field-hint">Ostatnie 5 cyfr PESEL (np.&nbsp;<code>12345</code>) lub pełny numer dokumentu tożsamości.</p>
    </div>

    <button type="submit" class="btn-primary">
      <i class="bi bi-shield-check" aria-hidden="true"></i>Zweryfikuj i wyślij kod SMS
    </button>
  </form>

  <p class="note">
    Rejestracja wyłącznie dla umów wolontariackich. Inny typ umowy? Skontaktuj się z administratorem.<br>
    Masz konto? <a href="<?= h(APP_URL . '/auth/login.php') ?>">Zaloguj się</a> lub
    <a href="<?= h(APP_URL . '/user/verify_reset.php') ?>">zresetuj hasło</a>.
  </p>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- KROK 2                                                                   -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <?php elseif ($step === 2): ?>
  <h1 class="heading">Kod SMS</h1>
  <p class="sub">Wysłaliśmy 6-cyfrowy kod na numer telefonu z umowy. Kod jest ważny przez&nbsp;<strong>10&nbsp;minut</strong>.</p>

  <form method="post" novalidate>
    <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_action" value="verify_sms">

    <div class="sms-wrap">
      <input type="text" class="sms-input"
             id="sms_code" name="sms_code"
             maxlength="6" minlength="6"
             inputmode="numeric" pattern="[0-9]{6}"
             placeholder="000000"
             autocomplete="one-time-code"
             required autofocus
             aria-label="Kod SMS — 6 cyfr">
    </div>
    <?php if ($sms_fails > 0): ?>
    <p class="sms-hint err">Błędna próba <?= $sms_fails ?>/3 — po 3 błędach zaczniesz od nowa.</p>
    <?php else: ?>
    <p class="sms-hint">Wpisz 6 cyfr z wiadomości SMS</p>
    <?php endif; ?>

    <button type="submit" class="btn-primary">
      <i class="bi bi-check-circle" aria-hidden="true"></i>Potwierdź kod
    </button>
  </form>

  <form method="post" style="display:block">
    <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_action" value="restart">
    <button type="submit" class="btn-ghost">
      <i class="bi bi-arrow-left" aria-hidden="true"></i>Wróć i popraw dane
    </button>
  </form>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- KROK 3                                                                   -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <?php elseif ($step === 3): ?>
  <h1 class="heading">Ustaw hasło</h1>
  <p class="sub">Tożsamość potwierdzona. Wybierz hasło — po jego ustawieniu konto będzie gotowe.</p>

  <form method="post" novalidate>
    <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_action" value="set_password">

    <div class="field">
      <label for="password_new" class="field-label">Hasło</label>
      <div class="field-wrap pwd-wrap">
        <i class="bi bi-lock field-icon" aria-hidden="true"></i>
        <input type="password" class="field-input" id="password_new" name="password_new"
               minlength="8" required autofocus autocomplete="new-password">
        <button type="button" class="pwd-toggle" id="togglePwdNew" aria-label="Pokaż hasło">
          <i class="bi bi-eye" id="eyeNew" aria-hidden="true"></i>
        </button>
      </div>
      <p class="field-hint">Min.&nbsp;8&nbsp;znaków, wielka litera, mała litera, cyfra.</p>
    </div>

    <div class="field">
      <label for="password_confirm" class="field-label">Powtórz hasło</label>
      <div class="field-wrap pwd-wrap">
        <i class="bi bi-lock-fill field-icon" aria-hidden="true"></i>
        <input type="password" class="field-input" id="password_confirm" name="password_confirm"
               minlength="8" required autocomplete="new-password">
        <button type="button" class="pwd-toggle" id="togglePwdConfirm" aria-label="Pokaż hasło">
          <i class="bi bi-eye" id="eyeConfirm" aria-hidden="true"></i>
        </button>
      </div>
    </div>

    <button type="submit" class="btn-primary success-color">
      <i class="bi bi-person-check" aria-hidden="true"></i>Załóż konto i zaloguj się
    </button>
  </form>
  <?php endif; ?>

</main>
</div>

<footer class="foot">
  &copy; <?= date('Y') ?> <a href="<?= APP_URL ?>/auth/login.php"><?= h($org_name) ?></a>
</footer>

<script>
function togglePwd(btnId, inputId, eyeId) {
  var btn = document.getElementById(btnId);
  if (!btn) return;
  btn.addEventListener('click', function() {
    var inp = document.getElementById(inputId);
    var eye = document.getElementById(eyeId);
    if (!inp) return;
    var show = inp.type === 'password';
    inp.type  = show ? 'text' : 'password';
    inp.setAttribute('autocomplete', show ? 'off' : 'new-password');
    if (eye) eye.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    this.setAttribute('aria-label', show ? 'Ukryj hasło' : 'Pokaż hasło');
  });
}
togglePwd('togglePwdNew',     'password_new',     'eyeNew');
togglePwd('togglePwdConfirm', 'password_confirm', 'eyeConfirm');

var smsInput = document.getElementById('sms_code');
if (smsInput) {
  smsInput.addEventListener('input', function() {
    if (this.value.replace(/\D/g,'').length === 6) this.form.submit();
  });
}
</script>
</body>
</html>
