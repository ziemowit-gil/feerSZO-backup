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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{height:100%;margin:0;padding:0;background:#0f172a}

.skip-link{position:absolute;top:-100%;left:1rem;z-index:9999;background:var(--c,#2563eb);color:#fff;padding:.5rem 1.25rem;border-radius:0 0 8px 8px;font-weight:700;text-decoration:none;font-size:.95rem}
.skip-link:focus{top:0;outline:3px solid #FBBF24;outline-offset:2px}
*:focus-visible{outline:3px solid #FBBF24!important;outline-offset:3px!important}
*:focus:not(:focus-visible){outline:none}

/* ── Shell ───────────────────────────────────────────────────── */
.login-shell{min-height:100vh;display:flex;align-items:stretch}

/* ── Lewa ────────────────────────────────────────────────────── */
.login-left{
  width:300px;flex-shrink:0;
  background:linear-gradient(160deg,#0f172a 0%,#1e293b 55%,#1e3a5f 100%);
  display:flex;flex-direction:column;justify-content:space-between;
  padding:2.25rem 1.75rem;
  border-right:1px solid rgba(255,255,255,.06);
  position:relative;overflow:hidden;
}
.login-left::after{content:'';position:absolute;width:260px;height:260px;border-radius:50%;border:55px solid rgba(255,255,255,.025);bottom:-80px;right:-80px;pointer-events:none}
.left-logo{max-height:44px;max-width:140px;object-fit:contain;filter:brightness(0)invert(1);opacity:.85;display:block;margin-bottom:1rem}
.left-icon{width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fff;margin-bottom:1rem}
.left-org{font-size:1.05rem;font-weight:800;color:#fff;margin:0 0 .25rem;line-height:1.3}
.left-tagline{font-size:.77rem;color:rgba(255,255,255,.45);margin:0 0 1.75rem;line-height:1.5}
.left-steps{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:.6rem}
.left-step{display:flex;align-items:center;gap:.65rem;font-size:.8rem;color:rgba(255,255,255,.5)}
.left-step.active{color:#fff}
.left-step-num{width:24px;height:24px;border-radius:50%;border:1.5px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.75rem;flex-shrink:0;color:rgba(255,255,255,.4)}
.left-step.done .left-step-num{background:var(--c,#2563eb);border-color:var(--c,#2563eb);color:#fff}
.left-step.active .left-step-num{background:#fff;border-color:#fff;color:#0f172a}
.left-footer{position:relative;z-index:1}
.left-security{display:flex;align-items:center;gap:.4rem;font-size:.73rem;color:rgba(255,255,255,.3);margin-bottom:.4rem}
.left-copyright{font-size:.68rem;color:rgba(255,255,255,.2)}

/* ── Prawa ───────────────────────────────────────────────────── */
.login-right{flex:1;background:#F1F5F9;display:flex;align-items:center;justify-content:center;padding:2.5rem 1.5rem;overflow-y:auto}
.reg-box{width:100%;max-width:480px}

/* ── Nagłówek ────────────────────────────────────────────────── */
.reg-eyebrow{font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--c,#2563eb);margin:0 0 .3rem}
.reg-heading{font-size:1.55rem;font-weight:800;color:#0f172a;margin:0 0 .5rem;letter-spacing:-.01em}
.reg-lead{font-size:.88rem;color:#64748b;line-height:1.6;margin:0 0 1.4rem}

/* ── Karta formularza ────────────────────────────────────────── */
.reg-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:1.6rem 1.5rem;box-shadow:0 4px 20px rgba(0,0,0,.06)}

/* ── Pola ────────────────────────────────────────────────────── */
.form-label{font-size:.83rem;font-weight:600;color:#334155;margin-bottom:.35rem}
.form-control,.form-select{border-color:#d1d5db;border-radius:8px;font-size:.9rem}
.form-control:focus{border-color:var(--c,#2563eb);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.input-group-text{background:#f8fafc;border-color:#d1d5db;color:#64748b}
.form-text{font-size:.78rem;color:#94a3b8;margin-top:.3rem}

/* ── Przyciski ───────────────────────────────────────────────── */
.btn-reg-primary{
  display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;
  background:var(--c,#2563eb);color:#fff;border:2px solid var(--c,#2563eb);
  border-radius:9px;padding:.75rem 1.25rem;font-size:.95rem;font-weight:700;
  cursor:pointer;min-height:48px;transition:background .15s,border-color .15s;
}
.btn-reg-primary:hover{background:var(--c-dark,#1d4ed8);border-color:var(--c-dark,#1d4ed8)}
.btn-reg-primary.green{background:#16a34a;border-color:#16a34a}
.btn-reg-primary.green:hover{background:#15803d;border-color:#15803d}
.btn-back{background:none;border:none;padding:0;font-size:.82rem;color:#64748b;cursor:pointer;display:inline-flex;align-items:center;gap:.3rem;margin-top:.65rem}
.btn-back:hover{color:var(--c,#2563eb)}

/* ── Alerty ──────────────────────────────────────────────────── */
.reg-alert{display:flex;align-items:flex-start;gap:.6rem;padding:.85rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.85rem;line-height:1.55}
.reg-alert.danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.reg-alert.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.reg-alert i{flex-shrink:0;margin-top:.1rem}

/* ── Note ────────────────────────────────────────────────────── */
.reg-note{display:flex;align-items:flex-start;gap:.55rem;padding:.8rem .9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-top:1rem;font-size:.8rem;color:#64748b;line-height:1.55}
.reg-note i{flex-shrink:0;color:#94a3b8;margin-top:.1rem}
.reg-note a{color:var(--c,#2563eb);text-decoration:none}
.reg-note a:hover{text-decoration:underline}

/* ── SMS input ───────────────────────────────────────────────── */
.sms-input{letter-spacing:.4em;font-size:1.6rem;font-weight:700;text-align:center;max-width:200px;border-radius:10px;padding:.5rem}

/* ── Stopka ──────────────────────────────────────────────────── */
.reg-footer{display:flex;align-items:center;justify-content:space-between;padding-top:.9rem;margin-top:.9rem;border-top:1px solid #e2e8f0;font-size:.78rem;color:#94a3b8}
.reg-footer a{color:#64748b;text-decoration:none}
.reg-footer a:hover{color:var(--c,#2563eb)}

@media(prefers-contrast:high){.btn-reg-primary{border-width:3px}.reg-card{border-width:2px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important}}

@media(max-width:680px){
  .login-shell{flex-direction:column}
  .login-left{width:100%;padding:.9rem 1.25rem;flex-direction:row;align-items:center;gap:.75rem;border-right:none;border-bottom:1px solid rgba(255,255,255,.07)}
  .login-left::after{display:none}
  .login-left .left-tagline,.login-left .left-steps,.login-left .left-footer{display:none}
  .login-left .left-org{font-size:.9rem;margin:0}
  .login-left .left-logo{margin-bottom:0;max-height:28px}
  .login-left .left-icon{width:30px;height:30px;font-size:1rem;margin-bottom:0}
  .login-right{padding:1.25rem 1rem;align-items:flex-start}
  .reg-box{padding:.25rem 0}
}
</style>
</head>
<body>

<a href="#reg-content" class="skip-link">Przejdź do formularza</a>

<div class="login-shell">

<!-- ══ Lewa — branding ══════════════════════════════════════════════════════ -->
<aside class="login-left" aria-label="Informacje o organizacji">
  <div>
    <?php if ($_b['logo_url']): ?>
    <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($org_name) ?>" class="left-logo">
    <?php else: ?>
    <div class="left-icon" aria-hidden="true"><i class="bi bi-person-plus-fill"></i></div>
    <?php endif; ?>
    <p class="left-org"><?= h($org_name) ?></p>
    <?php if ($_tagline): ?><p class="left-tagline"><?= h($_tagline) ?></p><?php endif; ?>

    <ol class="left-steps" aria-label="Postęp rejestracji">
      <?php foreach ($step_labels as $i => $lbl): ?>
      <li class="left-step <?= $step > $i ? 'done' : ($step === $i ? 'active' : '') ?>">
        <span class="left-step-num" aria-hidden="true">
          <?= $step > $i ? '<i class="bi bi-check-lg"></i>' : $i ?>
        </span>
        <?= h($lbl) ?>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>

  <div class="left-footer">
    <div class="left-security"><i class="bi bi-lock-fill" aria-hidden="true"></i> Połączenie szyfrowane HTTPS</div>
    <div class="left-copyright">&copy; <?= date('Y') ?> · <?= h($org_name) ?></div>
  </div>
</aside>

<!-- ══ Prawa — formularz ════════════════════════════════════════════════════ -->
<div class="login-right">
<main class="reg-box" id="reg-content" tabindex="-1">

  <?php if ($error !== ''): ?>
  <div class="reg-alert danger" role="alert">
    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
    <div><?= h($error) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($success !== ''): ?>
  <div class="reg-alert info" role="status">
    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
    <div><?= h($success) ?></div>
  </div>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- KROK 1: Dane umowy                                                      -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <?php if ($step === 1): ?>
  <p class="reg-eyebrow">Krok 1 z 3</p>
  <h1 class="reg-heading">Dane umowy</h1>
  <p class="reg-lead">Podaj e-mail i PESEL z umowy — wyślemy kod SMS na numer telefonu z umowy.</p>

  <div class="reg-card">
    <form method="post" novalidate>
      <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="verify">

      <div class="mb-3">
        <label for="email" class="form-label">Adres e-mail</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
          <input type="email" class="form-control" id="email" name="email"
                 placeholder="E-mail podany w umowie"
                 value="<?= h($_POST['email'] ?? '') ?>"
                 required autofocus autocomplete="email">
        </div>
      </div>

      <div class="mb-4">
        <label for="pesel_or_doc" class="form-label">PESEL lub numer dokumentu</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person-vcard" aria-hidden="true"></i></span>
          <input type="text" class="form-control" id="pesel_or_doc" name="pesel_or_doc"
                 placeholder="Ostatnie 5 cyfr PESEL lub pełny numer dokumentu"
                 value="<?= h($_POST['pesel_or_doc'] ?? '') ?>"
                 required>
        </div>
        <div class="form-text">Ostatnie 5 cyfr PESEL (np. <code>12345</code>) lub pełny numer dokumentu.</div>
      </div>

      <button type="submit" class="btn-reg-primary">
        <i class="bi bi-shield-check" aria-hidden="true"></i>Zweryfikuj i wyślij kod SMS
      </button>
    </form>
  </div>

  <div class="reg-note">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <div>
      Rejestracja dostępna wyłącznie dla umów wolontariackich. Przy innym typie umowy skontaktuj się z administratorem.
      Masz konto? <a href="<?= h(APP_URL . '/auth/login.php') ?>">Zaloguj się</a>
      lub <a href="<?= h(APP_URL . '/user/verify_reset.php') ?>">zresetuj hasło</a>.
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- KROK 2: Kod SMS                                                         -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <?php elseif ($step === 2): ?>
  <p class="reg-eyebrow">Krok 2 z 3</p>
  <h1 class="reg-heading">Kod SMS</h1>
  <p class="reg-lead">Wysłaliśmy 6-cyfrowy kod na numer telefonu z umowy. Kod ważny przez <strong>10 minut</strong>.</p>

  <div class="reg-card">
    <form method="post" novalidate>
      <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="verify_sms">

      <div class="mb-4 text-center">
        <label for="sms_code" class="form-label d-block mb-2">Kod SMS</label>
        <input type="text" class="form-control sms-input mx-auto"
               id="sms_code" name="sms_code"
               maxlength="6" minlength="6"
               inputmode="numeric" pattern="[0-9]{6}"
               placeholder="000000"
               autocomplete="one-time-code"
               required autofocus>
        <?php if ($sms_fails > 0): ?>
        <div class="form-text text-danger mt-2">
          Błędna próba <?= $sms_fails ?>/3. Po 3 błędach zaczniesz od nowa.
        </div>
        <?php endif; ?>
      </div>

      <button type="submit" class="btn-reg-primary">
        <i class="bi bi-check-circle" aria-hidden="true"></i>Potwierdź kod
      </button>
    </form>

    <div class="text-center">
      <form method="post" style="display:inline">
        <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_action" value="restart">
        <button type="submit" class="btn-back">
          <i class="bi bi-arrow-left" aria-hidden="true"></i>Wróć i popraw dane
        </button>
      </form>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- KROK 3: Hasło                                                           -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <?php elseif ($step === 3): ?>
  <p class="reg-eyebrow">Krok 3 z 3</p>
  <h1 class="reg-heading">Ustaw hasło</h1>
  <p class="reg-lead">Tożsamość potwierdzona — ustaw hasło, aby dokończyć rejestrację.</p>

  <div class="reg-card">
    <form method="post" novalidate>
      <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="set_password">

      <div class="mb-3">
        <label for="password_new" class="form-label">Hasło</label>
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
        <label for="password_confirm" class="form-label">Powtórz hasło</label>
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

      <button type="submit" class="btn-reg-primary green">
        <i class="bi bi-person-check" aria-hidden="true"></i>Załóż konto i zaloguj się
      </button>
    </form>
  </div>
  <?php endif; ?>

  <div class="reg-footer">
    <a href="<?= APP_URL ?>/auth/login.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do logowania
    </a>
    <span><i class="bi bi-lock-fill" aria-hidden="true"></i> HTTPS</span>
  </div>

</main>
</div><!-- /login-right -->

</div><!-- /login-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
