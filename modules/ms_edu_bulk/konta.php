<?php
/** Zarządzanie kontami EDU z generatora (admin): zawieszanie, menedżer, MFA, role Entra. */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/m365.php';
require_once dirname(__DIR__, 2) . '/includes/ti_online.php';
require_once __DIR__ . '/logic/msEduBulk.php';
require_once __DIR__ . '/logic/msEduBulkImport.php';

require_role('admin');
$PAGE_TITLE = 'Konta EDU — zarządzanie';
$self = strtok((string)($_SERVER['REQUEST_URI'] ?? 'konta.php'), '?');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    $accs = msEduBulkAccountsByIds((array)($_POST['ids'] ?? []));
    if (!m365_edu_ready()) flash_set('danger', 'Tenant EDU nie jest skonfigurowany.');
    elseif (!$accs) flash_set('danger', 'Zaznacz konta.');
    elseif ($op === 'role' && !isset(M365Graph::SAFE_DIRECTORY_ROLES[$_POST['role'] ?? ''])) flash_set('danger', 'Wybierz rolę.');
    else {
        $r = msEduBulkAccountsAct(m365_edu(), $accs, $op, ['licenses' => !empty($_POST['licenses']), 'manager' => $_POST['manager'] ?? '', 'role' => $_POST['role'] ?? '', 'group' => msEduBulkGroupFromPost($_POST)]);
        flash_set($r['errors'] ? 'warning' : 'success', 'Wykonano: ' . $r['done'] . '/' . count($accs) . ($r['errors'] ? '. Błędy: ' . implode(' | ', $r['errors']) : '.'));
    }
    header('Location: ' . $self); exit;
}

$zaQ = trim((string)($_GET['q'] ?? '')); $zaStatus = (string)($_GET['status'] ?? ''); $zaGrupa = (string)($_GET['grupa'] ?? '');
$zaRows = msEduBulkAccountsList($zaQ, $zaStatus, $zaGrupa);
$zaAction = $self; $zaTokenName = '_csrf'; $zaTokenValue = csrf_token(); $zaAllowRoles = true;
$zaDetailUrl = str_starts_with($self, '/ms') ? '/ms/konto' : 'konto.php';
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<div class="container-xl py-4">
  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-person-gear me-2"></i>Konta EDU — zarządzanie</h1>
    <a href="<?= h(dirname($self)) ?>" class="btn btn-sm btn-outline-secondary ms-auto">← Generator kont</a>
  </div>
  <?= flash_html() ?>
  <?php include __DIR__ . '/views/zarzadzanie.php'; ?>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php';
