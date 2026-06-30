<?php
/**
 * karty30/ti/kursant/parent.php — Panel rodzica/opiekuna.
 * Dostęp do rozliczeń i frekwencji małoletniego kursanta.
 * Logowanie: link magiczny (?t=TOKEN, e-mail) LUB kod SMS (OTP) na numer opiekuna.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_reschedule.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
k30_ti_reschedule_migrate();

$err = ''; $info = ''; $stage = 'phone'; // phone | code | choose | pwd
if (($_GET['m'] ?? '') === 'pwd') $stage = 'pwd';
$role = in_array($_GET['role'] ?? '', ['up'], true) ? 'up' : 'rodzic'; // up = osoba upoważniona

/** Proste hasło dla dziecka: słowo + 2 cyfry. */
function _parent_gen_child_pass(): string {
    $w = ['Kot','Pies','Dom','Las','Rok','Mak','Lis','Sad','Byk','Dab'];
    return $w[random_int(0, count($w)-1)] . random_int(10, 99);
}

// Wylogowanie
if (isset($_GET['logout'])) { parent_logout(); header('Location: parent.php'); exit; }

// ── Akcje zalogowanego rodzica: zarządzanie dostępem dziecka ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['_op'] ?? '', ['child_reset_pass', 'child_block', 'child_unblock', 'parent_self_pass'], true)) {
    $p = parent_current();
    if ($p && hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) {
        $sid = (int)$p['student_id']; // tylko własne dziecko (z sesji), nigdy z POST
        $op  = $_POST['_op'];
        if ($op === 'parent_self_pass') {
            // Zmiana hasła własnego konta rodzica (login + hasło)
            $new1 = (string)($_POST['new_pass'] ?? '');
            $new2 = (string)($_POST['new_pass2'] ?? '');
            if (mb_strlen($new1) < 8) {
                $_SESSION['k30_parent_msg'] = ['err', 'Hasło musi mieć co najmniej 8 znaków.'];
            } elseif ($new1 !== $new2) {
                $_SESSION['k30_parent_msg'] = ['err', 'Hasła nie są identyczne.'];
            } else {
                db()->prepare("UPDATE k30_ti_student_accounts SET parent_password_hash=?, parent_must_change=0, updated_at=datetime('now') WHERE id=?")
                   ->execute([password_hash($new1, PASSWORD_BCRYPT), $sid]);
                student_start();
                if (isset($_SESSION[PARENT_SESSION_KEY])) $_SESSION[PARENT_SESSION_KEY]['must_change'] = 0;
                $_SESSION['k30_parent_msg'] = ['ok', 'Hasło opiekuna zostało zmienione.'];
            }
            header('Location: parent.php?ptab=dostep'); exit;
        }
        if ($op === 'child_block') {
            db()->prepare("UPDATE k30_ti_student_accounts SET child_access_blocked=1, updated_at=datetime('now') WHERE id=?")->execute([$sid]);
            $_SESSION['k30_parent_msg'] = ['ok', 'Wstrzymano dostęp dziecka do panelu.'];
        } elseif ($op === 'child_unblock') {
            db()->prepare("UPDATE k30_ti_student_accounts SET child_access_blocked=0, updated_at=datetime('now') WHERE id=?")->execute([$sid]);
            $_SESSION['k30_parent_msg'] = ['ok', 'Przywrócono dostęp dziecka do panelu.'];
        } else { // child_reset_pass
            $pass = _parent_gen_child_pass();
            db()->prepare("UPDATE k30_ti_student_accounts SET password_hash=?, must_change_password=1, updated_at=datetime('now') WHERE id=?")
               ->execute([password_hash($pass, PASSWORD_BCRYPT), $sid]);
            $_SESSION['k30_parent_newpass'] = $pass;
            $_SESSION['k30_parent_msg']     = ['ok', 'Ustawiono nowe hasło dziecka — przekaż je dziecku.'];
        }
    }
    header('Location: parent.php?ptab=dostep'); exit;
}

// Wiadomosc od rodzica do prowadzacego
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'parent_msg_send') {
    $p = parent_current();
    if ($p && hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) {
        $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 4000);
        $subj = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 200);
        if ($body !== '') {
            if (!function_exists('ti_msg_parent_send')) require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_messages.php';
            ti_msg_parent_send((int)$p['student_id'], $subj, $body, $p['name'] ?? 'Opiekun');
            $_SESSION['k30_parent_msg_sent'] = 1;
        }
    }
    header('Location: parent.php?ptab=wiadomosci'); exit;
}

// Propozycja nowego terminu lekcji od opiekuna — czeka na decyzję prowadzącego
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'propose_reschedule') {
    $p = parent_current();
    if ($p && hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) {
        $sid  = (int)($_POST['session_id'] ?? 0);
        $date = trim($_POST['lesson_date'] ?? '');
        $tf   = trim($_POST['time_from'] ?? '');
        $tt   = trim($_POST['time_to'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        // Lekcja musi należeć do kursu dziecka i być zaplanowana
        $own = db_one(
            "SELECT s.id FROM k30_ti_sessions s
             JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=? AND e.status='active'
             WHERE s.id=? AND s.status='planned'",
            [(int)$p['client_id'], $sid]
        );
        if ($own && $date !== '') {
            k30_ti_request_reschedule($sid, (int)$p['client_id'], $date, $tf, $tt, $reason, 'opiekun', $p['name'] ?? 'Opiekun');
            $_SESSION['k30_parent_msg'] = ['ok', 'Propozycja nowego terminu została wysłana do prowadzącego i czeka na decyzję.'];
        }
    }
    header('Location: parent.php?ptab=harmonogram'); exit;
}

// Ustawienia powiadomien rodzica
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'parent_notify_prefs') {
    $p = parent_current();
    if ($p && hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) {
        $sid = (int)$p['student_id'];
        db()->prepare("UPDATE k30_ti_student_accounts SET parent_notify_absence=?, parent_notify_grade=?, parent_notify_messages=? WHERE id=?")
           ->execute([
               isset($_POST['pn_absence'])  ? 1 : 0,
               isset($_POST['pn_grade'])    ? 1 : 0,
               isset($_POST['pn_messages']) ? 1 : 0,
               $sid,
           ]);
        $_SESSION['k30_parent_msg'] = ['ok', 'Ustawienia powiadomien zapisane.'];
    }
    header('Location: parent.php?ptab=dostep'); exit;
}

// Link magiczny
if (isset($_GET['t'])) {
    $sid = parent_token_student(trim($_GET['t']));
    if ($sid && parent_login_for_student($sid)) { header('Location: parent.php'); exit; }
    $err = 'Link wygasł lub jest nieprawidłowy. Zaloguj się kodem SMS.';
}

// ── Logowanie osoby upoważnionej (role=up) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'up_login' && !authp_current()) {
    if (authp_login((string)($_POST['login'] ?? ''), (string)($_POST['password'] ?? ''))) {
        header('Location: authorized_person.php'); exit;
    }
    $err  = 'Nieprawidłowy login lub hasło.';
    $role = 'up';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['_op'] ?? '';
    if ($op === 'otp_request') {
        $phone = trim($_POST['phone'] ?? '');
        try {
            $n = parent_otp_send($phone);
            if ($n > 0) {
                $via = $_SESSION['k30_parent_otp']['fallback_via'] ?? 'sms';
                $info = $via === 'email'
                    ? 'Wysyłka SMS nie powiodła się — kod wysłany na adres e-mail opiekuna.'
                    : 'Kod wysłano SMS-em na podany numer.';
                $stage = 'code';
            } else {
                $err = 'Nie znaleziono małoletniego kursanta przypisanego do tego numeru.';
            }
        } catch (\Throwable $e) {
            $err = 'Nie udało się wysłać SMS: ' . $e->getMessage();
        }
    } elseif ($op === 'otp_verify') {
        $kids = parent_otp_verify($_POST['code'] ?? '');
        if ($kids === null) { $err = 'Nieprawidłowy lub wygasły kod.'; $stage = 'code'; }
        elseif (count($kids) === 1) { parent_login_for_student((int)$kids[0]['id']); header('Location: parent.php'); exit; }
        else { student_start(); $_SESSION['k30_parent_choose'] = $kids; $stage = 'choose'; }
    } elseif ($op === 'choose') {
        student_start();
        $kids = $_SESSION['k30_parent_choose'] ?? [];
        $sid  = (int)($_POST['student_id'] ?? 0);
        if ($sid && in_array($sid, array_map(fn($k) => (int)$k['id'], $kids), true)) {
            unset($_SESSION['k30_parent_choose']);
            parent_login_for_student($sid); header('Location: parent.php'); exit;
        }
        $err = 'Wybierz kursanta.'; $stage = 'choose';
    } elseif ($op === 'pwd_login') {
        // Logowanie loginem i hasłem (konto rodzica)
        if (parent_login_with_password($_POST['login'] ?? '', $_POST['password'] ?? '')) {
            header('Location: parent.php'); exit;
        }
        $err = 'Nieprawidłowy login lub hasło konta rodzica.'; $stage = 'pwd';
    }
}

$parent = parent_current();

// Eksport PDF wykazu ocen dziecka
if ($parent && isset($_GET['grades_pdf'])) {
    require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_grades_pdf.php';
    ti_grades_pdf_student((int)$parent['client_id'], $parent['name'] ?? '');
}

$ptab   = $_GET['ptab'] ?? 'rozliczenia';
if (!in_array($ptab, ['rozliczenia','frekwencja','oceny','licencje','dostep','harmonogram','wiadomosci'], true)) $ptab = 'rozliczenia';
$org = defined('ORG_NAME') ? ORG_NAME : 'Panel rodzica';
$KP_TITLE  = 'Panel rodzica';
$KP_TOPBAR = [
    'brand' => $org . ' — panel rodzica',
    'icon'  => 'people-fill',
    'user'  => $parent ? ('Opiekun: ' . $parent['name']) : '',
    'logout'=> $parent ? '?logout=1' : '',
];
$KP_BODY_CLASS = $parent ? '' : 'd-flex align-items-center justify-content-center flex-column py-4 px-3';
include __DIR__ . '/_layout_head.php';
?>

<?php if (!$parent): ?>
<main id="main" class="kp-auth-wrap">
  <div class="card kp-auth-card shadow-lg border-0">
    <div class="row g-0">

      <!-- ── Panel marki (dekoracyjny — ukryty na telefonie) ───────────────── -->
      <div class="col-md-5 kp-auth-hero d-none d-md-flex flex-column justify-content-between p-4 p-lg-5"
           aria-hidden="true">
        <div>
          <span class="d-inline-flex align-items-center justify-content-center kp-auth-logo mb-4">
            <i class="bi bi-people-fill fs-2"></i>
          </span>
          <h2 class="h3 fw-bold mb-2">Panel rodzica</h2>
          <p class="mb-0 opacity-75"><?= h($org) ?></p>
        </div>
        <ul class="list-unstyled d-flex flex-column gap-3 mt-5 mb-0 small">
          <li class="kp-auth-feat"><i class="bi bi-receipt"></i><span>Rozliczenia i terminy płatności dziecka</span></li>
          <li class="kp-auth-feat"><i class="bi bi-calendar-check"></i><span>Frekwencja na zajęciach</span></li>
          <li class="kp-auth-feat"><i class="bi bi-shield-lock"></i><span>Zarządzanie dostępem dziecka do panelu</span></li>
        </ul>
      </div>

      <!-- ── Logowanie opiekuna (SMS / link) ──────────────────────────────── -->
      <div class="col-md-7">
        <div class="card-body p-4 p-lg-5">
          <h1 class="h4 fw-bold d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-shield-lock text-primary d-md-none" aria-hidden="true"></i>Dostęp zewnętrzny
          </h1>

          <!-- Wybór roli -->
          <?php if (in_array($stage, ['phone', 'pwd'], true) || $role === 'up'): ?>
          <div class="btn-group w-100 mb-4" role="group" aria-label="Wybierz rolę">
            <a href="parent.php" class="btn btn-lg <?= $role==='rodzic'?'btn-primary':'btn-outline-primary' ?>"
               <?= $role==='rodzic'?'aria-current="true"':'' ?>>
              <i class="bi bi-people me-1" aria-hidden="true"></i>Rodzic / opiekun
            </a>
            <a href="parent.php?role=up" class="btn btn-lg <?= $role==='up'?'btn-primary':'btn-outline-primary' ?>"
               <?= $role==='up'?'aria-current="true"':'' ?>>
              <i class="bi bi-person-check me-1" aria-hidden="true"></i>Osoba upoważniona
            </a>
          </div>
          <?php endif; ?>

          <?php if ($err): ?><div class="alert alert-danger d-flex align-items-center gap-2 py-2" role="alert"><i class="bi bi-exclamation-circle-fill flex-shrink-0" aria-hidden="true"></i><span><?= h($err) ?></span></div><?php endif; ?>
          <?php if ($info): ?><div class="alert alert-success d-flex align-items-center gap-2 py-2" role="status"><i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i><span><?= h($info) ?></span></div><?php endif; ?>

          <?php if ($role === 'up'): ?>
          <!-- ── Logowanie osoby upoważnionej ──────────────────────────────── -->
          <p class="text-body-secondary mb-3 small">
            Zaloguj się loginem i hasłem z kartki upoważnienia nadanej przez administratora.
          </p>
          <form method="post" autocomplete="on">
            <input type="hidden" name="_op" value="up_login">
            <label class="form-label fw-semibold" for="up-login">Login</label>
            <div class="input-group input-group-lg mb-3">
              <span class="input-group-text" aria-hidden="true"><i class="bi bi-person"></i></span>
              <input class="form-control form-control-lg" id="up-login" name="login"
                     autocomplete="username" value="<?= h($_POST['login'] ?? '') ?>" required autofocus>
            </div>
            <label class="form-label fw-semibold" for="up-pass">Hasło</label>
            <div class="input-group input-group-lg mb-4">
              <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
              <input class="form-control form-control-lg" id="up-pass" name="password"
                     type="password" autocomplete="current-password" placeholder="••••••••" required>
            </div>
            <button class="btn btn-primary btn-lg w-100">
              <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj się
            </button>
          </form>

          <?php else: ?>
          <!-- ── Logowanie rodzica / opiekuna ─────────────────────────────── -->
          <p class="text-body-secondary mb-4 small">
            <?= $stage === 'pwd'
              ? 'Zaloguj się loginem i hasłem konta opiekuna nadanym w placówce.'
              : 'Zaloguj się kodem SMS wysłanym na numer opiekuna podany w placówce, lub skorzystaj z linku z e-maila.' ?>
          </p>

          <?php // Przełącznik sposobu logowania opiekuna: SMS albo login+hasło
          if (in_array($stage, ['phone', 'pwd'], true)): ?>
          <div class="btn-group w-100 mb-4" role="group" aria-label="Sposób logowania opiekuna">
            <a href="parent.php" class="btn <?= $stage==='phone'?'btn-secondary':'btn-outline-secondary' ?>"
               <?= $stage==='phone'?'aria-current="true"':'' ?>>
              <i class="bi bi-chat-dots me-1" aria-hidden="true"></i>Kod SMS
            </a>
            <a href="parent.php?m=pwd" class="btn <?= $stage==='pwd'?'btn-secondary':'btn-outline-secondary' ?>"
               <?= $stage==='pwd'?'aria-current="true"':'' ?>>
              <i class="bi bi-person-lock me-1" aria-hidden="true"></i>Login i hasło
            </a>
          </div>
          <?php endif; ?>

          <?php if ($stage === 'choose'):
            $kids = $_SESSION['k30_parent_choose'] ?? []; ?>
            <form method="post">
              <input type="hidden" name="_op" value="choose">
              <fieldset>
                <legend class="form-label fw-semibold">Wybierz kursanta:</legend>
                <?php foreach ($kids as $k): ?>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="student_id" id="k<?= (int)$k['id'] ?>" value="<?= (int)$k['id'] ?>" required>
                  <label class="form-check-label" for="k<?= (int)$k['id'] ?>"><?= h($k['name']) ?></label>
                </div>
                <?php endforeach; ?>
              </fieldset>
              <button class="btn btn-primary btn-lg w-100 mt-3">Pokaż rozliczenia</button>
            </form>
          <?php elseif ($stage === 'pwd'): ?>
            <form method="post" autocomplete="on">
              <input type="hidden" name="_op" value="pwd_login">
              <label class="form-label fw-semibold" for="plogin">Login rodzica</label>
              <div class="input-group input-group-lg mb-3">
                <span class="input-group-text" aria-hidden="true"><i class="bi bi-person"></i></span>
                <input class="form-control form-control-lg" id="plogin" name="login" autocomplete="username"
                       value="<?= h($_POST['login'] ?? '') ?>" placeholder="np. j.kowalski-r" required autofocus>
              </div>
              <label class="form-label fw-semibold" for="ppass">Hasło</label>
              <div class="input-group input-group-lg mb-3">
                <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
                <input class="form-control form-control-lg" id="ppass" name="password" type="password"
                       autocomplete="current-password" placeholder="••••••••" required>
              </div>
              <button class="btn btn-primary btn-lg w-100"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj</button>
            </form>
          <?php elseif ($stage === 'code'): ?>
            <form method="post">
              <input type="hidden" name="_op" value="otp_verify">
              <label class="form-label fw-semibold" for="code">Kod z SMS</label>
              <div class="input-group input-group-lg mb-3">
                <span class="input-group-text" aria-hidden="true"><i class="bi bi-chat-dots"></i></span>
                <input class="form-control form-control-lg" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="6-cyfrowy kod" required autofocus>
              </div>
              <button class="btn btn-primary btn-lg w-100">Zaloguj</button>
            </form>
            <form method="post" class="mt-2 text-center">
              <input type="hidden" name="_op" value="otp_request">
              <input type="hidden" name="phone" value="<?= h($_POST['phone'] ?? '') ?>">
              <button class="btn btn-link btn-sm">Wyślij kod ponownie</button>
            </form>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="_op" value="otp_request">
              <label class="form-label fw-semibold" for="phone">Numer telefonu opiekuna</label>
              <div class="input-group input-group-lg mb-3">
                <span class="input-group-text" aria-hidden="true"><i class="bi bi-telephone"></i></span>
                <input class="form-control form-control-lg" id="phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="np. 600 100 200" required autofocus>
              </div>
              <button class="btn btn-primary btn-lg w-100"><i class="bi bi-chat-dots me-1" aria-hidden="true"></i>Wyślij kod SMS</button>
            </form>
          <?php endif; ?>

          <hr class="my-4">
          <a href="login.php" class="btn btn-outline-secondary w-100">
            <i class="bi bi-pc-display me-1" aria-hidden="true"></i>Jesteś kursantem? Zaloguj się hasłem
          </a>
        </div>
      </div>

    </div>
  </div>
</main>

<?php else:
  $childAcc = db_one("SELECT login, child_access_blocked, parent_login FROM k30_ti_student_accounts WHERE id=?", [(int)$parent['student_id']]);
  $blocked  = !empty($childAcc['child_access_blocked']);
  $childLicCount = count(k30_ti_client_licenses((int)$parent['client_id']));
  $parentHasAccount = !empty($childAcc['parent_login']);
  $parentMustChange = !empty($parent['must_change']);
?>
<?php if ($parentMustChange): ?>
<div class="container-xl px-3 pt-3">
  <div class="alert alert-warning d-flex align-items-center gap-2 mb-0" role="alert">
    <i class="bi bi-shield-exclamation fs-5" aria-hidden="true"></i>
    <div>Korzystasz z hasła tymczasowego. <a href="?ptab=dostep">Ustaw własne hasło</a> w zakładce „Dostęp dziecka".</div>
  </div>
</div>
<?php endif; ?>
<div class="container-xl px-3 pt-3">
  <div class="d-flex align-items-center gap-2 mb-2">
    <i class="bi bi-mortarboard fs-3 text-primary" aria-hidden="true"></i>
    <div>
      <h1 class="h5 fw-bold mb-0">Kursant: <?= h($parent['name']) ?></h1>
      <p class="text-body-secondary small mb-0">Panel opiekuna</p>
    </div>
  </div>
  <nav aria-label="Sekcje panelu rodzica">
    <ul class="nav nav-tabs">
      <li class="nav-item">
        <a class="nav-link <?= $ptab==='rozliczenia'?'active':'' ?>" href="?ptab=rozliczenia" <?= $ptab==='rozliczenia'?'aria-current="page"':'' ?>>
          <i class="bi bi-receipt me-1" aria-hidden="true"></i>Rozliczenia
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $ptab==='frekwencja'?'active':'' ?>" href="?ptab=frekwencja" <?= $ptab==='frekwencja'?'aria-current="page"':'' ?>>
          <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Frekwencja
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $ptab==='oceny'?'active':'' ?>" href="?ptab=oceny" <?= $ptab==='oceny'?'aria-current="page"':'' ?>>
          <i class="bi bi-table me-1" aria-hidden="true"></i>Oceny
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $ptab==='licencje'?'active':'' ?>" href="?ptab=licencje" <?= $ptab==='licencje'?'aria-current="page"':'' ?>>
          <i class="bi bi-key me-1" aria-hidden="true"></i>Licencje
          <?php if ($childLicCount > 0): ?><span class="badge text-bg-secondary ms-1"><?= $childLicCount ?></span><?php endif; ?>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $ptab==='harmonogram'?'active':'' ?>" href="?ptab=harmonogram" <?= $ptab==='harmonogram'?'aria-current="page"':'' ?>>
          <i class="bi bi-calendar-week me-1" aria-hidden="true"></i>Harmonogram
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $ptab==='wiadomosci'?'active':'' ?>" href="?ptab=wiadomosci" <?= $ptab==='wiadomosci'?'aria-current="page"':'' ?>>
          <i class="bi bi-chat-text me-1" aria-hidden="true"></i>Wiadomości
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $ptab==='dostep'?'active':'' ?>" href="?ptab=dostep" <?= $ptab==='dostep'?'aria-current="page"':'' ?>>
          <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Dostęp dziecka
          <?php if ($blocked): ?><span class="badge text-bg-danger ms-1" title="Dostęp wstrzymany"><i class="bi bi-lock-fill" aria-hidden="true"></i></span><?php endif; ?>
        </a>
      </li>
    </ul>
  </nav>
</div>

<main id="main" class="container-xl px-3 py-4">

<?php if ($ptab === 'rozliczenia'):
    $rv_client_id    = $parent['client_id'];
    $rv_show_lessons = false;
    include __DIR__ . '/_rozliczenia_view.php';
?>

<?php elseif ($ptab === 'frekwencja'): ?>
  <h2 class="h5 fw-bold d-flex align-items-center gap-2 mb-3"><i class="bi bi-calendar-check text-primary" aria-hidden="true"></i>Frekwencja</h2>
  <?php $rv_client_id = $parent['client_id']; include __DIR__ . '/_frekwencja_view.php'; ?>

<?php elseif ($ptab === 'oceny'):
    $pg = k30_ti_client_grades((int)$parent['client_id']);
    $pg_by_course = [];
    foreach ($pg as $g) { $pg_by_course[$g['course_name']][] = $g; }
?>
  <h2 class="h5 fw-bold d-flex align-items-center gap-2 mb-1">
    <i class="bi bi-table text-primary" aria-hidden="true"></i>Oceny dziecka
    <?php if ($pg): ?><a href="?grades_pdf=1" class="btn btn-sm btn-outline-danger ms-auto"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Pobierz PDF</a><?php endif; ?>
  </h2>
  <p class="text-body-secondary small mb-3">Oceny wystawione przez prowadzących wraz ze średnią ważoną per kurs.</p>
  <?php if (!$pg): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak ocen.</div>
  <?php else: ?>
  <?php foreach ($pg_by_course as $cname => $cgr):
    $avg = k30_ti_grades_average($cgr);
    [$abg,$afg] = k30_ti_grade_color($avg);
  ?>
  <div class="card mb-3">
    <div class="card-header d-flex flex-wrap align-items-center gap-2 py-2">
      <span class="fw-semibold"><i class="bi bi-pc-display me-1" aria-hidden="true"></i><?= h($cname) ?></span>
      <span class="badge text-bg-secondary"><?= count($cgr) ?> ocen</span>
      <?php if ($avg !== null): ?>
      <span class="ms-auto small text-body-secondary">Średnia ważona:</span>
      <span class="badge" style="background:<?= $abg ?>;color:<?= $afg ?>;font-size:.9rem"><?= number_format($avg, 2, ',', '') ?></span>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
        <thead class="table-light">
          <tr><th>Data</th><th>Ocena</th><th>Waga</th><th>Kategoria</th><th>Za co</th><th>Wystawił(a)</th></tr>
        </thead>
        <tbody>
          <?php foreach ($cgr as $g): ?>
          <tr>
            <td class="text-nowrap small"><?= h(substr($g['graded_at'],0,10)) ?></td>
            <td><?= k30_ti_grade_badge($g) ?></td>
            <td class="small"><?= h(rtrim(rtrim(number_format((float)$g['weight'],2,'.',''),'0'),'.') ?: '1') ?></td>
            <td class="small"><?= h(k30_ti_grade_category_label($g['category'])) ?></td>
            <td class="small"><?= $g['description'] ? h($g['description']) : '<span class="text-body-secondary">—</span>' ?></td>
            <td class="small text-nowrap"><?= !empty($g['graded_by_text']) ? h($g['graded_by_text']) : (!empty($g['graded_by_name']) ? h($g['graded_by_name']) : '<span class="text-body-secondary">—</span>') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

<?php elseif ($ptab === 'licencje'): ?>
  <h2 class="h5 fw-bold d-flex align-items-center gap-2 mb-1"><i class="bi bi-key text-primary" aria-hidden="true"></i>Licencje dziecka</h2>
  <p class="text-body-secondary small mb-3">Licencje na oprogramowanie (inne niż Microsoft&nbsp;365) przypisane dziecku. Klucze i hasła trzymaj w tajemnicy.</p>
  <?php $rv_client_id = $parent['client_id']; include __DIR__ . '/_licencje_view.php'; ?>

<?php elseif ($ptab === 'harmonogram'):
  $p_lessons = db_all(
    "SELECT s.id AS session_id, s.lesson_date, s.time_from, s.time_to, s.duration_min, s.topic, s.status,
            c.name AS course_name, c.location,
            a.attended, a.cancelled AS att_cancelled
     FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id=s.course_id
     LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=?
     WHERE s.course_id IN (
         SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active'
     )
     AND s.lesson_date >= date('now','-7 days')
     AND (s.status IS NULL OR s.status != 'removed')
     ORDER BY s.lesson_date ASC, s.time_from ASC
     LIMIT 120",
    [$parent['client_id'], $parent['client_id']]
  );
  $months_pl_har = ['','sty','lut','mar','kwi','maj','cze','lip','sie','wrz','paź','lis','gru'];
  $days_pl_har   = ['Nd','Pn','Wt','Śr','Czw','Pt','Sb'];
  $today_har     = date('Y-m-d');
  $ptok_har      = student_token();
  // Mapa lekcji z oczekującą propozycją terminu (dziecko)
  $resch_pending_har = [];
  foreach (db_all(
      "SELECT session_id, proposed_date, proposed_from FROM k30_ti_reschedule_requests
       WHERE client_id=? AND status='pending'", [(int)$parent['client_id']]) as $rp) {
      $resch_pending_har[(int)$rp['session_id']] = $rp;
  }
  $hmsg = $_SESSION['k30_parent_msg'] ?? null; unset($_SESSION['k30_parent_msg']);
?>
  <h2 class="h5 fw-bold d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-calendar-week text-primary" aria-hidden="true"></i>Harmonogram zajęć
  </h2>
  <?php if ($hmsg): ?>
  <div class="alert alert-<?= $hmsg[0]==='ok'?'success':'danger' ?> py-2"><?= h($hmsg[1]) ?></div>
  <?php endif; ?>
  <?php if (!$p_lessons): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Brak nadchodzących lekcji.</div>
  <?php else: ?>
  <div class="d-flex flex-column gap-2">
    <?php foreach ($p_lessons as $l):
      $ld   = (string)($l['lesson_date'] ?? '');
      $dt   = $ld ? new DateTime($ld) : null;
      $dow  = $dt ? $days_pl_har[(int)$dt->format('w')] : '';
      $is_today   = $ld === $today_har;
      $is_past    = $ld && $ld < $today_har;
      $is_canc    = ($l['status'] ?? '') === 'cancelled' || !empty($l['att_cancelled']);
      $attended   = (int)($l['attended'] ?? 0);
    ?>
    <div class="card <?= $is_canc ? 'opacity-50' : ($is_today ? 'border-primary' : '') ?>">
      <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center gap-3">
        <div class="text-center" style="min-width:3rem">
          <div class="fw-bold <?= $is_today ? 'text-primary' : 'text-body-secondary' ?>" style="font-size:.75rem"><?= $dow ?></div>
          <div class="fw-bold fs-5 lh-1"><?= $dt ? $dt->format('d') : '' ?></div>
          <div class="text-body-secondary" style="font-size:.72rem"><?= $dt ? ($months_pl_har[(int)$dt->format('n')] . ' ' . $dt->format('y')) : '' ?></div>
        </div>
        <div class="flex-grow-1">
          <div class="fw-semibold small"><?= h($l['course_name']) ?></div>
          <?php if (trim((string)($l['topic'] ?? '')) !== ''): ?>
          <div class="text-body-secondary small"><?= h($l['topic']) ?></div>
          <?php endif; ?>
          <?php if (trim((string)($l['location'] ?? '')) !== ''): ?>
          <div class="text-body-secondary small"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= h($l['location']) ?></div>
          <?php endif; ?>
        </div>
        <div class="text-end small">
          <?php if ($l['time_from']): ?>
          <div class="fw-semibold"><?= h(substr((string)$l['time_from'],0,5)) ?>-<?= h(substr((string)$l['time_to'],0,5)) ?></div>
          <?php elseif ((int)$l['duration_min']): ?>
          <div class="text-body-secondary"><?= (int)$l['duration_min'] ?> min</div>
          <?php endif; ?>
          <?php if ($is_canc): ?>
          <span class="badge text-bg-secondary">odwołana</span>
          <?php elseif ($is_past && $attended): ?>
          <span class="badge text-bg-success">obecny</span>
          <?php elseif ($is_past && !$attended): ?>
          <span class="badge text-bg-danger">nieobecny</span>
          <?php elseif ($is_today): ?>
          <span class="badge text-bg-primary">dziś</span>
          <?php else: ?>
          <span class="badge text-bg-light text-dark border">zaplanowana</span>
          <?php endif; ?>
          <?php
            $sidh = (int)($l['session_id'] ?? 0);
            $rph  = $resch_pending_har[$sidh] ?? null;
            $can_propose = !$is_canc && !$is_past && ($l['status'] ?? 'planned') === 'planned';
            if ($can_propose):
              if ($rph):
                $rphW = date('d.m.Y', strtotime($rph['proposed_date'])) . ($rph['proposed_from'] ? ' '.substr((string)$rph['proposed_from'],0,5) : ''); ?>
          <div class="mt-1"><span class="badge text-bg-info" title="Czeka na decyzję prowadzącego"><i class="bi bi-calendar2-range me-1" aria-hidden="true"></i>Propozycja: <?= h($rphW) ?></span></div>
          <?php else: ?>
          <div class="mt-1">
            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2"
                    data-reschedule-session="<?= $sidh ?>"
                    data-lesson-label="<?= h($l['course_name'].' — '.($dt ? $dt->format('d.m.Y') : '')) ?>"
                    data-lesson-date="<?= h((string)$l['lesson_date']) ?>"
                    data-lesson-from="<?= h((string)($l['time_from'] ?? '')) ?>"
                    data-lesson-to="<?= h((string)($l['time_to'] ?? '')) ?>">
              <i class="bi bi-calendar2-range me-1" aria-hidden="true"></i>Zaproponuj termin
            </button>
          </div>
          <?php endif; endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="text-body-secondary small mt-3"><i class="bi bi-info-circle me-1"></i>Pokazuje lekcje od 7 dni wstecz.</p>

  <!-- Modal: propozycja nowego terminu (opiekun) -->
  <div class="modal fade" id="reschedLessonModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <input type="hidden" name="_token"     value="<?= h($ptok_har) ?>">
        <input type="hidden" name="_op"          value="propose_reschedule">
        <input type="hidden" name="session_id"   id="rs_session_id" value="">
        <div class="modal-header">
          <h2 class="modal-title h5"><i class="bi bi-calendar2-range text-primary me-2" aria-hidden="true"></i>Zaproponuj nowy termin</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">Lekcja: <strong id="rs_lesson_label"></strong></p>
          <p class="text-body-secondary small mb-3">Propozycja zostanie wysłana do prowadzącego. Termin zmieni się dopiero po jego akceptacji.</p>
          <div class="mb-2">
            <label class="form-label fw-semibold" for="rs_date">Proponowana data</label>
            <input type="date" class="form-control" id="rs_date" name="lesson_date" required>
          </div>
          <div class="row g-2">
            <div class="col-6 mb-2">
              <label class="form-label" for="rs_from">Od <span class="text-body-secondary fw-normal">(opc.)</span></label>
              <input type="time" class="form-control" id="rs_from" name="time_from">
            </div>
            <div class="col-6 mb-2">
              <label class="form-label" for="rs_to">Do <span class="text-body-secondary fw-normal">(opc.)</span></label>
              <input type="time" class="form-control" id="rs_to" name="time_to">
            </div>
          </div>
          <label class="form-label fw-semibold" for="rs_reason">Uzasadnienie <span class="text-body-secondary fw-normal">(opc.)</span></label>
          <textarea class="form-control" id="rs_reason" name="reason" rows="2" maxlength="1000"
                    placeholder="np. kolizja z innymi obowiazkami, wizyta lekarska…"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij propozycję</button>
        </div>
      </form>
    </div>
  </div>
  <script>
  (function(){
    var modalEl = document.getElementById('reschedLessonModal');
    if (!modalEl) return;
    document.querySelectorAll('[data-reschedule-session]').forEach(function(btn){
      btn.addEventListener('click', function(){
        document.getElementById('rs_session_id').value = btn.getAttribute('data-reschedule-session');
        document.getElementById('rs_lesson_label').textContent = btn.getAttribute('data-lesson-label') || '';
        document.getElementById('rs_date').value = btn.getAttribute('data-lesson-date') || '';
        document.getElementById('rs_from').value = (btn.getAttribute('data-lesson-from') || '').slice(0,5);
        document.getElementById('rs_to').value   = (btn.getAttribute('data-lesson-to') || '').slice(0,5);
        document.getElementById('rs_reason').value = '';
        new bootstrap.Modal(modalEl).show();
      });
    });
  })();
  </script>
  <?php endif; ?>

<?php elseif ($ptab === 'wiadomosci'):
  if (!function_exists('ti_msg_list_for_parent')) require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_messages.php';
  $p_msgs   = ti_msg_list_for_parent((int)$parent['student_id']);
  $msg_sent = !empty($_SESSION['k30_parent_msg_sent']); unset($_SESSION['k30_parent_msg_sent']);
  $ptok     = student_token();
  $p_threads = [];
  foreach ($p_msgs as $m) {
    $key = trim((string)($m['subject'] ?? ''));
    if ($key === '') $key = '__ogolny__';
    if (!isset($p_threads[$key])) $p_threads[$key] = ['subject' => $key === '__ogolny__' ? 'Wiadomości ogólne' : $key, 'msgs' => [], 'last_at' => ''];
    $p_threads[$key]['msgs'][] = $m;
    if ($m['created_at'] > $p_threads[$key]['last_at']) $p_threads[$key]['last_at'] = $m['created_at'];
  }
  uasort($p_threads, fn($a,$b) => strcmp($b['last_at'], $a['last_at']));
  $active_ts = (string)($_GET['ts'] ?? ($msg_sent ? ($_GET['ts'] ?? '') : ''));
  if ($active_ts === '' && !empty($p_threads)) $active_ts = array_key_first($p_threads);
?>
  <h2 class="h5 fw-bold d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-chat-text text-primary" aria-hidden="true"></i>Wiadomości z prowadzącym
  </h2>
  <?php if ($msg_sent): ?>
  <div class="alert alert-success alert-dismissible py-2 small mb-3">
    <i class="bi bi-check-circle me-1"></i>Wiadomość wysłana.
    <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>
  <!-- Nowa wiadomość / temat -->
  <div class="card mb-3">
    <div class="card-header small fw-semibold py-2"><i class="bi bi-pencil-square me-1"></i>Napisz do prowadzącego</div>
    <div class="card-body py-3">
      <form method="post">
        <input type="hidden" name="_token" value="<?= h($ptok) ?>">
        <input type="hidden" name="_op" value="parent_msg_send">
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1" for="pmsg-subj">Temat (opcjonalnie)</label>
          <input type="text" class="form-control form-control-sm" id="pmsg-subj" name="subject" maxlength="200" placeholder="np. Pytanie o nieobecność">
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold mb-1" for="pmsg-body">Treść</label>
          <textarea class="form-control form-control-sm" id="pmsg-body" name="body" rows="3" maxlength="4000" required placeholder="Twoja wiadomość..."></textarea>
        </div>
        <button class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Wyślij</button>
      </form>
    </div>
  </div>
  <?php if (empty($p_threads)): ?>
  <div class="text-body-secondary small text-center py-4">
    <i class="bi bi-chat-text fs-1 opacity-25 d-block mb-2"></i>Brak wiadomości. Napisz pierwszą wiadomość powyżej.
  </div>
  <?php else: ?>
  <?php foreach ($p_threads as $tkey => $thread):
    $is_active = ($tkey === $active_ts);
  ?>
  <div class="card mb-2 <?= $is_active ? 'border-primary' : '' ?>">
    <div class="card-header d-flex align-items-center gap-2 py-2 small">
      <span class="fw-semibold"><?= h($thread['subject']) ?></span>
      <span class="text-body-secondary ms-auto"><?= count($thread['msgs']) ?> wiad.</span>
    </div>
    <div class="card-body py-2 px-3">
      <div class="d-flex flex-column gap-2" style="max-height:40vh;overflow-y:auto">
        <?php foreach ($thread['msgs'] as $m):
          $mine = ($m['sender'] ?? '') === 'parent';
          $is_staff = ($m['sender'] ?? '') === 'staff';
          $ts   = $m['created_at'] ? date('d.m.Y H:i', strtotime($m['created_at'])) : '';
          $name = $mine ? 'Ty (opiekun)' : ($is_staff ? ($m['sender_name'] ?: 'Prowadzący') : h($m['sender_name'] ?: 'Kursant'));
        ?>
        <div class="d-flex <?= $mine ? 'justify-content-end' : 'justify-content-start' ?>">
          <div class="px-3 py-2 rounded-4 <?= $mine ? 'bg-primary text-white' : 'bg-body-tertiary border' ?>" style="max-width:85%;word-break:break-word">
            <div class="d-flex gap-2 mb-1" style="font-size:.72rem">
              <span class="fw-semibold"><?= is_string($name) ? $name : h((string)$name) ?></span>
              <span class="opacity-75"><?= h($ts) ?></span>
            </div>
            <div style="white-space:pre-wrap;line-height:1.45"><?= nl2br(h($m['body'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

<?php elseif ($ptab === 'dostep'):
    $pmsg      = $_SESSION['k30_parent_msg'] ?? null;      unset($_SESSION['k30_parent_msg']);
    $pnewpass  = $_SESSION['k30_parent_newpass'] ?? null;  unset($_SESSION['k30_parent_newpass']);
    $login_url = rtrim(APP_URL, '/') . '/karty30/ti/kursant/login.php';
    $ptok      = student_token();
?>
  <div class="card border-0 shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-shield-lock me-2 text-primary" aria-hidden="true"></i>Zarządzaj dostępem dziecka</div>
    <div class="card-body">
      <?php if ($pmsg): ?>
      <div class="alert alert-<?= $pmsg[0]==='ok'?'success':'danger' ?> py-2"><?= h($pmsg[1]) ?></div>
      <?php endif; ?>
      <?php if ($pnewpass): ?>
      <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-key-fill fs-5" aria-hidden="true"></i>
        <div>
          <div class="fw-semibold">Nowe dane logowania dziecka — zapisz teraz, nie pokażemy ich ponownie.</div>
          <div class="small mt-1">Login: <span class="font-monospace fw-bold"><?= h($childAcc['login'] ?? '') ?></span>
            · Hasło: <span class="font-monospace fw-bold text-danger"><?= h($pnewpass) ?></span></div>
          <div class="small text-body-secondary">Przy pierwszym logowaniu dziecko ustawi własne hasło. Logowanie: <a href="<?= h($login_url) ?>" target="_blank" rel="noopener"><?= h($login_url) ?></a></div>
        </div>
      </div>
      <?php endif; ?>

      <dl class="row small mb-3">
        <dt class="col-sm-3 text-body-secondary fw-normal">Login dziecka</dt>
        <dd class="col-sm-9 font-monospace"><?= h($childAcc['login'] ?? '—') ?></dd>
        <dt class="col-sm-3 text-body-secondary fw-normal">Status dostępu</dt>
        <dd class="col-sm-9">
          <?php if ($blocked): ?><span class="badge text-bg-danger">wstrzymany przez opiekuna</span>
          <?php else: ?><span class="badge text-bg-success">aktywny</span><?php endif; ?>
        </dd>
      </dl>

      <div class="d-flex flex-wrap gap-2">
        <form method="post" onsubmit="return confirm('Ustawić nowe hasło dziecka? Dotychczasowe przestanie działać.')">
          <input type="hidden" name="_token" value="<?= h($ptok) ?>">
          <input type="hidden" name="_op"    value="child_reset_pass">
          <button class="btn btn-sm btn-outline-warning"><i class="bi bi-key me-1" aria-hidden="true"></i>Ustaw nowe hasło</button>
        </form>
        <?php if ($blocked): ?>
        <form method="post" onsubmit="return confirm('Przywrócić dziecku dostęp do panelu?')">
          <input type="hidden" name="_token" value="<?= h($ptok) ?>">
          <input type="hidden" name="_op"    value="child_unblock">
          <button class="btn btn-sm btn-outline-success"><i class="bi bi-unlock me-1" aria-hidden="true"></i>Przywróć dostęp</button>
        </form>
        <?php else: ?>
        <form method="post" onsubmit="return confirm('Wstrzymać dziecku dostęp do panelu? Nie będzie mogło się zalogować, dopóki nie przywrócisz dostępu.')">
          <input type="hidden" name="_token" value="<?= h($ptok) ?>">
          <input type="hidden" name="_op"    value="child_block">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-lock me-1" aria-hidden="true"></i>Wstrzymaj dostęp</button>
        </form>
        <?php endif; ?>
      </div>
      <p class="text-body-secondary small mb-0 mt-2">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Tu zarządzasz logowaniem dziecka do panelu kursanta (hasło i wstrzymanie dostępu). Twój dostęp opiekuna pozostaje aktywny niezależnie.
      </p>
    </div>
  </div>

  <!-- Ustawienia powiadomien e-mail dla rodzica -->
  <?php
    $pn_acc = db_one("SELECT parent_notify_absence, parent_notify_grade, parent_notify_messages, guardian_email FROM k30_ti_student_accounts WHERE id=?", [(int)$parent['student_id']]);
    $gemail = trim((string)($pn_acc['guardian_email'] ?? ''));
    $ptok_n = student_token();
  ?>
  <div class="card border-0 shadow-sm mt-3">
    <div class="card-header fw-semibold"><i class="bi bi-bell me-2 text-primary" aria-hidden="true"></i>Powiadomienia e-mail</div>
    <div class="card-body">
      <?php if ($gemail === ''): ?>
      <div class="alert alert-warning py-2 small mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Brak adresu e-mail opiekuna w systemie. Skontaktuj się z prowadzącym, aby dodać adres.</div>
      <?php else: ?>
      <p class="small text-body-secondary mb-3">Powiadomienia będą wysyłane na: <strong><?= h($gemail) ?></strong></p>
      <form method="post">
        <input type="hidden" name="_token" value="<?= h($ptok_n) ?>">
        <input type="hidden" name="_op" value="parent_notify_prefs">
        <div class="d-flex flex-column gap-2">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="pn_absence" name="pn_absence" <?= !empty($pn_acc['parent_notify_absence']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="pn_absence">Nieobecność dziecka na zajęciach</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="pn_grade" name="pn_grade" <?= !empty($pn_acc['parent_notify_grade']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="pn_grade">Nowa ocena dziecka</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="pn_messages" name="pn_messages" <?= !empty($pn_acc['parent_notify_messages']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="pn_messages">Nowa wiadomość od prowadzącego</label>
          </div>
        </div>
        <button class="btn btn-sm btn-primary mt-3"><i class="bi bi-check2 me-1"></i>Zapisz</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($parentHasAccount): ?>
  <div class="card border-0 shadow-sm mt-3">
    <div class="card-header fw-semibold"><i class="bi bi-person-lock me-2 text-primary" aria-hidden="true"></i>Hasło opiekuna</div>
    <div class="card-body">
      <dl class="row small mb-3">
        <dt class="col-sm-3 text-body-secondary fw-normal">Twój login</dt>
        <dd class="col-sm-9 font-monospace"><?= h($childAcc['parent_login']) ?></dd>
      </dl>
      <form method="post" class="row g-2" style="max-width:480px">
        <input type="hidden" name="_token" value="<?= h($ptok) ?>">
        <input type="hidden" name="_op"    value="parent_self_pass">
        <div class="col-12">
          <label class="form-label small fw-semibold" for="pnew">Nowe hasło (min. 8 znaków)</label>
          <input type="password" class="form-control form-control-sm" id="pnew" name="new_pass" minlength="8" autocomplete="new-password" required>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold" for="pnew2">Powtórz nowe hasło</label>
          <input type="password" class="form-control form-control-sm" id="pnew2" name="new_pass2" minlength="8" autocomplete="new-password" required>
        </div>
        <div class="col-12">
          <button class="btn btn-sm btn-primary"><i class="bi bi-key me-1" aria-hidden="true"></i>Zmień hasło</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>
</main>
<?php endif; ?>

<?php include __DIR__ . '/_layout_foot.php'; ?>
