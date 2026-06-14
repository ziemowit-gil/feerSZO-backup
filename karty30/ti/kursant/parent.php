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
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel rodzica — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root { --bg:#0f172a; --card:#1e293b; --border:#334155; --text:#f1f5f9; --muted:#94a3b8; --accent:#2563eb; }
* { box-sizing: border-box; }
body { background: var(--bg); color: var(--text); font-family: 'Segoe UI',Arial,sans-serif; margin: 0; min-height: 100vh; }
.topbar { background: var(--card); border-bottom: 1px solid var(--border); padding: .75rem 1.5rem; display: flex; align-items: center; gap: 1rem; }
.topbar-brand { font-weight: 700; font-size: 1rem; display: flex; align-items: center; gap: .5rem; color: var(--text); text-decoration: none; }
.topbar-brand i { color: var(--accent); font-size: 1.2rem; }
.topbar-user { margin-left: auto; display: flex; align-items: center; gap: .75rem; font-size: .83rem; color: var(--muted); }
.btn-logout { background: none; border: 1px solid var(--border); color: var(--muted); border-radius: 6px; padding: .3rem .75rem; font-size: .78rem; cursor: pointer; text-decoration:none; }
.btn-logout:hover { border-color: #ef4444; color: #ef4444; }
.content { padding: 1.5rem; max-width: 1000px; margin: 0 auto; }
.stats-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1rem 1.25rem; }
.stat-val  { font-size: 1.6rem; font-weight: 800; line-height: 1; }
.stat-lbl  { color: var(--muted); font-size: .78rem; margin-top: .3rem; }
.lesson-table { background: var(--card); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.lesson-table table { width: 100%; border-collapse: collapse; }
.lesson-table th { background: #0f172a; color: var(--muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; padding: .6rem 1rem; font-weight: 700; text-align:left; }
.lesson-table td { padding: .65rem 1rem; border-top: 1px solid var(--border); font-size: .86rem; vertical-align: middle; }
.att-yes { color: #22c55e; font-weight: 700; } .att-no { color: #ef4444; } .att-unk { color: var(--muted); }
.vlab-section-title { font-size: .95rem; font-weight: 700; color: var(--text); margin: 1.5rem 0 .75rem; display: flex; align-items: center; gap: .5rem; }
.vlab-st { font-size:.7rem; font-weight:700; padding:.15em .6em; border-radius:4px; }
.auth-box { max-width: 420px; margin: 3rem auto; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 2rem; }
.auth-box h3 { font-size: 1.1rem; margin: 0 0 .3rem; }
.auth-box p.sub { color: var(--muted); font-size: .85rem; margin-bottom: 1.25rem; }
.form-control, .form-control:focus { background:#0f172a; border:1px solid var(--border); color:var(--text); }
</style>
</head>
<body>

<div class="topbar">
  <span class="topbar-brand"><i class="bi bi-people-fill"></i><?= h($org) ?> — panel rodzica</span>
  <?php if ($parent): ?>
  <div class="topbar-user">
    <i class="bi bi-person-circle"></i><span>Opiekun: <?= h($parent['name']) ?></span>
    <a href="?logout=1" class="btn-logout"><i class="bi bi-box-arrow-right me-1"></i>Wyloguj</a>
  </div>
  <?php endif; ?>
</div>

<div class="content">

<?php if (!$parent): ?>
  <div class="auth-box">
    <h3><i class="bi bi-shield-lock me-1"></i>Dostęp do rozliczeń dziecka</h3>
    <p class="sub">Zaloguj się kodem SMS wysłanym na numer opiekuna podany w placówce, lub skorzystaj z linku z e-maila.</p>

    <?php if ($err): ?><div class="alert alert-danger py-2 small"><?= h($err) ?></div><?php endif; ?>
    <?php if ($info): ?><div class="alert alert-success py-2 small"><?= h($info) ?></div><?php endif; ?>

    <?php if ($stage === 'choose'):
      $kids = $_SESSION['k30_parent_choose'] ?? []; ?>
      <form method="post">
        <input type="hidden" name="_op" value="choose">
        <label class="form-label small">Wybierz kursanta:</label>
        <?php foreach ($kids as $k): ?>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="student_id" id="k<?= (int)$k['id'] ?>" value="<?= (int)$k['id'] ?>">
          <label class="form-check-label" for="k<?= (int)$k['id'] ?>"><?= h($k['name']) ?></label>
        </div>
        <?php endforeach; ?>
        <button class="btn btn-primary w-100 mt-3">Pokaż rozliczenia</button>
      </form>
    <?php elseif ($stage === 'code'): ?>
      <form method="post">
        <input type="hidden" name="_op" value="otp_verify">
        <label class="form-label small">Kod z SMS</label>
        <input class="form-control mb-3" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="6-cyfrowy kod" required autofocus>
        <button class="btn btn-primary w-100">Zaloguj</button>
      </form>
      <form method="post" class="mt-2 text-center">
        <input type="hidden" name="_op" value="otp_request">
        <input type="hidden" name="phone" value="<?= h($_POST['phone'] ?? '') ?>">
        <button class="btn btn-link btn-sm text-muted">Wyślij kod ponownie</button>
      </form>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="_op" value="otp_request">
        <label class="form-label small">Numer telefonu opiekuna</label>
        <input class="form-control mb-3" name="phone" inputmode="tel" placeholder="np. 600 100 200" required autofocus>
        <button class="btn btn-primary w-100"><i class="bi bi-chat-dots me-1"></i>Wyślij kod SMS</button>
      </form>
    <?php endif; ?>
  </div>

<?php else: ?>

  <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem">
    <i class="bi bi-mortarboard" style="font-size:1.4rem;color:var(--accent)"></i>
    <div>
      <h3 style="margin:0;font-size:1.15rem">Kursant: <?= h($parent['name']) ?></h3>
      <div style="color:var(--muted);font-size:.82rem">Rozliczenia i frekwencja</div>
    </div>
  </div>

  <?php
    $rv_client_id   = $parent['client_id'];
    $rv_show_lessons = true;
    include __DIR__ . '/_rozliczenia_view.php';
  ?>

<?php endif; ?>

</div>
</body>
</html>
