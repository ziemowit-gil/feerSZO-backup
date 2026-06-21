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

$err = ''; $info = ''; $stage = 'phone'; // phone | code | choose

/** Proste hasło dla dziecka: słowo + 2 cyfry. */
function _parent_gen_child_pass(): string {
    $w = ['Kot','Pies','Dom','Las','Rok','Mak','Lis','Sad','Byk','Dab'];
    return $w[random_int(0, count($w)-1)] . random_int(10, 99);
}

// Wylogowanie
if (isset($_GET['logout'])) { parent_logout(); header('Location: parent.php'); exit; }

// ── Akcje zalogowanego rodzica: zarządzanie dostępem dziecka ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['_op'] ?? '', ['child_reset_pass', 'child_block', 'child_unblock'], true)) {
    $p = parent_current();
    if ($p && hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) {
        $sid = (int)$p['student_id']; // tylko własne dziecko (z sesji), nigdy z POST
        $op  = $_POST['_op'];
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
    }
}

$parent = parent_current();
$ptab   = $_GET['ptab'] ?? 'rozliczenia';
if (!in_array($ptab, ['rozliczenia','frekwencja','dostep'], true)) $ptab = 'rozliczenia';
$org = defined('ORG_NAME') ? ORG_NAME : 'Panel rodzica';
$KP_TITLE  = 'Panel rodzica';
$KP_TOPBAR = [
    'brand' => $org . ' — panel rodzica',
    'icon'  => 'people-fill',
    'user'  => $parent ? ('Opiekun: ' . $parent['name']) : '',
    'logout'=> $parent ? '?logout=1' : '',
];
$KP_BODY_CLASS = $parent ? '' : 'd-flex flex-column';
include __DIR__ . '/_layout_head.php';
?>

<?php if (!$parent): ?>
<main id="main" class="container d-flex align-items-center justify-content-center flex-grow-1 py-4">
  <div class="card shadow-lg border-0 w-100" style="max-width:420px">
    <div class="card-body p-4">
      <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-1">
        <i class="bi bi-shield-lock text-primary" aria-hidden="true"></i>Dostęp do rozliczeń dziecka
      </h1>
      <p class="text-body-secondary small mb-3">
        Zaloguj się kodem SMS wysłanym na numer opiekuna podany w placówce, lub skorzystaj z linku z e-maila.
      </p>

      <?php if ($err): ?><div class="alert alert-danger py-2" role="alert"><?= h($err) ?></div><?php endif; ?>
      <?php if ($info): ?><div class="alert alert-success py-2" role="status"><?= h($info) ?></div><?php endif; ?>

      <?php if ($stage === 'choose'):
        $kids = $_SESSION['k30_parent_choose'] ?? []; ?>
        <form method="post">
          <input type="hidden" name="_op" value="choose">
          <fieldset>
            <legend class="form-label">Wybierz kursanta:</legend>
            <?php foreach ($kids as $k): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="student_id" id="k<?= (int)$k['id'] ?>" value="<?= (int)$k['id'] ?>" required>
              <label class="form-check-label" for="k<?= (int)$k['id'] ?>"><?= h($k['name']) ?></label>
            </div>
            <?php endforeach; ?>
          </fieldset>
          <button class="btn btn-primary w-100 mt-3">Pokaż rozliczenia</button>
        </form>
      <?php elseif ($stage === 'code'): ?>
        <form method="post">
          <input type="hidden" name="_op" value="otp_verify">
          <label class="form-label" for="code">Kod z SMS</label>
          <input class="form-control mb-3" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="6-cyfrowy kod" required autofocus>
          <button class="btn btn-primary w-100">Zaloguj</button>
        </form>
        <form method="post" class="mt-2 text-center">
          <input type="hidden" name="_op" value="otp_request">
          <input type="hidden" name="phone" value="<?= h($_POST['phone'] ?? '') ?>">
          <button class="btn btn-link btn-sm">Wyślij kod ponownie</button>
        </form>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="_op" value="otp_request">
          <label class="form-label" for="phone">Numer telefonu opiekuna</label>
          <input class="form-control mb-3" id="phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="np. 600 100 200" required autofocus>
          <button class="btn btn-primary w-100"><i class="bi bi-chat-dots me-1" aria-hidden="true"></i>Wyślij kod SMS</button>
        </form>
      <?php endif; ?>

      <hr class="my-3">
      <a href="login.php" class="btn btn-link btn-sm w-100 text-decoration-none">
        <i class="bi bi-pc-display me-1" aria-hidden="true"></i>Jesteś kursantem? Zaloguj się hasłem
      </a>
    </div>
  </div>
</main>

<?php else:
  $childAcc = db_one("SELECT login, child_access_blocked FROM k30_ti_student_accounts WHERE id=?", [(int)$parent['student_id']]);
  $blocked  = !empty($childAcc['child_access_blocked']);
?>
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
<?php endif; ?>
</main>
<?php endif; ?>

<?php include __DIR__ . '/_layout_foot.php'; ?>
