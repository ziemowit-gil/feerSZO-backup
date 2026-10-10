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
    $ile = ['sent' => 0, 'no_email' => 0, 'failed' => 0];

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
        "Wysłano: {$ile['sent']}. Bez adresu e-mail: {$ile['no_email']}. Nieudane: {$ile['failed']}.");
    header('Location: kursanci_onboarding.php'); exit;
}

$konta = ti_student_accounts_for_onboarding();

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
