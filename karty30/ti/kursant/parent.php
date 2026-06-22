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
require_once __DIR__ . '/auth.php';

karty30_migrate();

$err = ''; $info = ''; $stage = 'phone'; // phone | code | choose | pwd
if (($_GET['m'] ?? '') === 'pwd') $stage = 'pwd';

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

// Link magiczny
if (isset($_GET['t'])) {
    $sid = parent_token_student(trim($_GET['t']));
    if ($sid && parent_login_for_student($sid)) { header('Location: parent.php'); exit; }
    $err = 'Link wygasł lub jest nieprawidłowy. Zaloguj się kodem SMS.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['_op'] ?? '';
    if ($op === 'otp_request') {
        $phone = trim($_POST['phone'] ?? '');
        try {
            $n = parent_otp_send($phone);
            if ($n > 0) { $info = 'Kod wysłano SMS-em na podany numer.'; $stage = 'code'; }
            else        { $err = 'Nie znaleziono małoletniego kursanta przypisanego do tego numeru.'; }
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
if (!in_array($ptab, ['rozliczenia','frekwencja','oceny','licencje','dostep'], true)) $ptab = 'rozliczenia';
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
            <i class="bi bi-shield-lock text-primary d-md-none" aria-hidden="true"></i>Dostęp dla opiekuna
          </h1>
          <p class="text-body-secondary mb-4">
            Zaloguj się kodem SMS wysłanym na numer opiekuna podany w placówce, lub skorzystaj z linku z e-maila.
          </p>

          <?php if ($err): ?><div class="alert alert-danger d-flex align-items-center gap-2 py-2" role="alert"><i class="bi bi-exclamation-circle-fill flex-shrink-0" aria-hidden="true"></i><span><?= h($err) ?></span></div><?php endif; ?>
          <?php if ($info): ?><div class="alert alert-success d-flex align-items-center gap-2 py-2" role="status"><i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i><span><?= h($info) ?></span></div><?php endif; ?>

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
            <div class="text-center mt-3">
              <a href="parent.php" class="btn btn-link btn-sm">Wolisz logowanie kodem SMS?</a>
            </div>
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

          <?php if ($stage !== 'pwd'): ?>
          <div class="text-center mt-3">
            <a href="parent.php?m=pwd" class="btn btn-link btn-sm">
              <i class="bi bi-person-lock me-1" aria-hidden="true"></i>Masz konto rodzica (login i hasło)? Zaloguj się
            </a>
          </div>
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
            <td class="small text-nowrap"><?= !empty($g['graded_by_name']) ? h($g['graded_by_name']) : '<span class="text-body-secondary">—</span>' ?></td>
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
