<?php
/** Panel dydaktyka (kierownik): zarządzanie kontami EDU z generatora — bez ról administracyjnych Entra. */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/includes/ti_online.php';
require_once dirname(__DIR__, 3) . '/modules/ms_edu_bulk/logic/msEduBulk.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$dyd_name = (string)($me['name'] ?? '') ?: 'Kierownik';
karty30_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    $accs = msEduBulkAccountsByIds((array)($_POST['ids'] ?? []));
    if (!m365_edu_ready()) flash_set('danger', 'Tenant EDU nie jest skonfigurowany.');
    elseif (!$accs) flash_set('danger', 'Zaznacz konta.');
    elseif (!in_array($op, ['suspend', 'restore', 'manager', 'mfa'], true)) flash_set('danger', 'Ta akcja jest dostępna tylko w panelu administratora.');
    else {
        $r = msEduBulkAccountsAct(m365_edu(), $accs, $op, ['licenses' => !empty($_POST['licenses']), 'manager' => $_POST['manager'] ?? '']);
        flash_set($r['errors'] ? 'warning' : 'success', 'Wykonano: ' . $r['done'] . '/' . count($accs) . ($r['errors'] ? '. Błędy: ' . implode(' | ', $r['errors']) : '.'));
    }
    header('Location: kontaEduZarzadzaj.php'); exit;
}

$zaQ = trim((string)($_GET['q'] ?? '')); $zaStatus = (string)($_GET['status'] ?? '');
$zaRows = msEduBulkAccountsList($zaQ, $zaStatus);
$zaAction = 'kontaEduZarzadzaj.php'; $zaTokenName = '_token'; $zaTokenValue = dyd_token(); $zaAllowRoles = false;

$KP_TITLE  = 'Konta EDU — zarządzanie — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $KIER_CUR = 'microsoft365.php'; $KIER_LABEL = 'Microsoft 365'; include __DIR__ . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <h1 class="h4 mb-0 fw-bold"><i class="bi bi-person-gear text-primary me-2" aria-hidden="true"></i>Konta EDU — zarządzanie</h1>
    <a href="microsoft365.php" class="btn btn-sm btn-outline-secondary ms-auto">← Microsoft 365</a>
  </div>
  <?= flash_html() ?>
  <?php include dirname(__DIR__, 3) . '/modules/ms_edu_bulk/views/zarzadzanie.php'; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
