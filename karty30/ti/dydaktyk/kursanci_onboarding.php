<?php
/**
 * karty30/ti/dydaktyk/kursanci_onboarding.php — mail onboardingowy dla kursantów, per konto (kierownik / admin).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_student_onboarding.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];
karty30_migrate();
ti_panel_roles_migrate();

if (!(is_admin() || ti_panel_role($uid) === 'kierownik')) {
    http_response_code(403);
    die('Wysyłka do kursantów jest dla kierownika TI.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    $ile = ['sent' => 0, 'scheduled' => 0, 'no_email' => 0, 'failed' => 0];

    if ($op === 'one') {
        $res = ti_student_onboarding_send((int)($_POST['account_id'] ?? 0));
        $ile[$res] = ($ile[$res] ?? 0) + 1;
    } elseif ($op === 'all') {
        foreach (ti_student_accounts_for_onboarding() as $a) {
            if (!(int)$a['is_active']) continue;
            $res = ti_student_onboarding_send((int)$a['id']);
            $ile[$res] = ($ile[$res] ?? 0) + 1;
        }
    }

    flash_set($ile['failed'] ? 'warning' : 'success',
        "Wysłano: {$ile['sent']}. Zaplanowano na godz. 7:00 (poza oknem 7–22): {$ile['scheduled']}. Bez adresu e-mail: {$ile['no_email']}. Nieudane: {$ile['failed']}.");
    header('Location: kursanci_onboarding.php'); exit;
}

$konta = ti_student_accounts_for_onboarding();

// Podgląd maila dla wybranego konta (bez wysyłki).
$podglad = null;
$pid = (int)($_GET['podglad'] ?? 0);
foreach ($konta as $a) {
    if ((int)$a['id'] === $pid) {
        [$p_txt, $p_html] = ti_student_onboarding_mail((string)$a['client_name'], (string)$a['login'],
            ti_student_onboarding_grupy((int)$a['client_id']));
        $podglad = ['a' => $a, 'txt' => $p_txt];
        break;
    }
}

$KP_TITLE      = 'Mail onboardingowy dla kursantów — Panel dydaktyka';
$KP_TOPBAR     = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<link rel="stylesheet" href="../assets/ti_skin.css">

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:960px">
<?= flash_html() ?>

<h1 class="h4 fw-bold mb-2"><i class="bi bi-envelope-paper text-primary me-2" aria-hidden="true"></i>Mail onboardingowy dla kursantów</h1>
<p class="small mb-3">
  Każdy kursant dostaje osobny mail z loginem i linkiem do panelu. Hasła w mailu nie ma — kursant dostaje je od prowadzącego.
  Konta bez adresu e-mail w karcie klienta są pomijane.
</p>

<?php if ($podglad): $pa = $podglad['a']; ?>
<section class="card border-0 shadow-sm mb-3" aria-labelledby="podglad-h">
  <div class="card-header bg-transparent d-flex align-items-center gap-2">
    <h2 id="podglad-h" class="h6 fw-semibold mb-0">Podgląd maila</h2>
    <a href="kursanci_onboarding.php" class="btn btn-sm btn-outline-secondary ms-auto">Zamknij podgląd</a>
  </div>
  <div class="card-body">
    <dl class="row small mb-3">
      <dt class="col-sm-3">Do</dt>
      <dd class="col-sm-9"><?= h($pa['client_name']) ?> &lt;<?= h($pa['client_email']) ?>&gt;</dd>
      <dt class="col-sm-3">Temat</dt>
      <dd class="col-sm-9">Witamy w panelu kursanta – jak zacząć</dd>
    </dl>
    <pre class="border rounded p-3 bg-light small mb-3" style="white-space:pre-wrap" tabindex="0" aria-label="Treść maila"><?= h($podglad['txt']) ?></pre>
    <?php if (trim((string)$pa['client_email']) !== '' && (int)$pa['is_active']): ?>
    <form method="post" onsubmit="return confirm('Wysłać ten mail do <?= h(addslashes($pa['client_name'])) ?>?');">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="one">
      <input type="hidden" name="account_id" value="<?= (int)$pa['id'] ?>">
      <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij ten mail</button>
    </form>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if (!$konta): ?>
<div class="alert alert-info">Nie ma kont kursantów.</div>
<?php else: ?>

<form method="post" class="mb-3" onsubmit="return confirm('Wysłać mail onboardingowy do wszystkich aktywnych kont kursantów?');">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <input type="hidden" name="_op" value="all">
  <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij wszystkim aktywnym</button>
</form>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <caption class="visually-hidden">Konta kursantów i wysyłka maila onboardingowego</caption>
    <thead><tr><th scope="col">Kursant</th><th scope="col">Login</th><th scope="col">E-mail</th><th scope="col">Konto</th><th scope="col" class="text-end">Akcja</th></tr></thead>
    <tbody>
    <?php foreach ($konta as $a): $ma_mail = trim((string)$a['client_email']) !== ''; ?>
      <tr>
        <td><?= h($a['client_name']) ?></td>
        <td class="font-monospace small"><?= h($a['login']) ?></td>
        <td class="small"><?= $ma_mail ? h($a['client_email']) : '<span class="badge bg-warning text-dark">brak e-maila</span>' ?></td>
        <td><?= (int)$a['is_active'] ? '<span class="badge bg-success">aktywne</span>' : '<span class="badge bg-secondary">nieaktywne</span>' ?></td>
        <td class="text-end">
          <a href="kursanci_onboarding.php?podglad=<?= (int)$a['id'] ?>" class="btn btn-sm btn-outline-secondary">Podgląd</a>
          <?php if ($ma_mail && (int)$a['is_active']): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Wysłać mail do <?= h(addslashes($a['client_name'])) ?>?');">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="one">
            <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary">Wyślij</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
