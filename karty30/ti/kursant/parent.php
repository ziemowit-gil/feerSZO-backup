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

// Wylogowanie
if (isset($_GET['logout'])) { parent_logout(); header('Location: parent.php'); exit; }

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
    </div>
  </div>
</main>

<?php else: ?>
<main id="main" class="container-xl px-3 py-4">
  <div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-mortarboard fs-3 text-primary" aria-hidden="true"></i>
    <div>
      <h1 class="h5 fw-bold mb-0">Kursant: <?= h($parent['name']) ?></h1>
      <p class="text-body-secondary small mb-0">Rozliczenia i frekwencja</p>
    </div>
  </div>

  <?php
    $rv_client_id    = $parent['client_id'];
    $rv_show_lessons = true;
    include __DIR__ . '/_rozliczenia_view.php';
  ?>
</main>
<?php endif; ?>

<?php include __DIR__ . '/_layout_foot.php'; ?>
