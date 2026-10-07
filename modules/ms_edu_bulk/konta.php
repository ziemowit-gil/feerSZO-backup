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

$cmp = null; $cmpErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['_op'] ?? '', ['compare', 'sync_missing', 'register_szo', 'recreate_szo', 'recreate_registry'], true)) {
    csrf_check();
    if (!m365_edu_ready()) $cmpErr = 'Tenant EDU nie jest skonfigurowany.';
    else {
        try {
            if ($_POST['_op'] === 'register_szo') {
                flash_set('success', 'Dodano do rejestru EDU: ' . msEduBulkRegisterSzoStudents((array)($_POST['sids'] ?? [])) . '.');
                header('Location: ' . $self); exit;
            }
            if ($_POST['_op'] === 'recreate_szo') {
                $r = msEduBulkRecreateSzoStudents((array)($_POST['sids'] ?? []));
                flash_set($r['errors'] ? 'warning' : 'success', 'Utworzono w tenancie EDU: ' . $r['done'] . ($r['errors'] ? '. Błędy: ' . implode(' | ', $r['errors']) : '.'));
                header('Location: ' . $self); exit;
            }
            if ($_POST['_op'] === 'recreate_registry') {
                $accs = msEduBulkAccountsByIds((array)($_POST['ids'] ?? []));
                // Hasła zachowujemy: z wyników bieżącej sesji (generator) i z wklejonej listy „login hasło”
                $passMap = [];
                foreach ($_SESSION['msEduBulkResult'] ?? [] as $sr) if (!empty($sr['upn']) && !empty($sr['password'])) $passMap[mb_strtolower($sr['upn'])] = $sr['password'];
                $passMap = msEduBulkParsePasswordList((string)($_POST['passwords'] ?? '')) + $passMap;
                $res = msEduBulkRecreateRegistry(m365_edu(), $accs, $passMap, msEduBulkSkuList($_POST['skus'] ?? []));
                $newPw = array_filter($res['ok'], fn($r) => $r['password_new']);
                if ($newPw) $_SESSION['msEduBulkResult'] = array_merge($_SESSION['msEduBulkResult'] ?? [], array_values($newPw));
                flash_set($res['errors'] || $newPw ? 'warning' : 'success', 'Utworzono w tenancie EDU: ' . count($res['ok'])
                    . ($newPw ? '. Dla ' . count($newPw) . ' kont nie znano wcześniejszego hasła — nadano nowe (lista w wynikach generatora): ' . implode(', ', array_column($newPw, 'upn')) : '')
                    . ($res['errors'] ? '. Błędy: ' . implode(' | ', $res['errors']) : '.'));
                header('Location: ' . $self); exit;
            }
            if ($_POST['_op'] === 'sync_missing') {
                $n = msEduBulkMarkMissingDeleted(msEduBulkAccountsByIds((array)($_POST['ids'] ?? [])));
                flash_set('success', 'Przeniesiono do usuniętych: ' . $n . '.');
                header('Location: ' . $self . '?widok=usuniete'); exit;
            }
            $cmp = msEduBulkCompareTenant(m365_edu());
            $zaSkus = m365_edu()->get_subscribed_skus();
        } catch (\Throwable $e) { $cmpErr = $e->getMessage(); }
    }
    $_GET['widok'] = 'porownanie';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    // Szybka akcja przy koncie (suspend_one / restore_one) — dotyczy tylko tego jednego konta
    if (in_array($op, ['suspend_one', 'restore_one'], true)) { $op = substr($op, 0, -4); $_POST['ids'] = [(int)($_POST['one'] ?? 0)]; }
    $accs = msEduBulkAccountsByIds((array)($_POST['ids'] ?? []));
    if (!m365_edu_ready()) flash_set('danger', 'Tenant EDU nie jest skonfigurowany.');
    elseif (!$accs) flash_set('danger', 'Zaznacz konta.');
    elseif ($op === 'role' && !isset(M365Graph::SAFE_DIRECTORY_ROLES[$_POST['role'] ?? ''])) flash_set('danger', 'Wybierz rolę.');
    else {
        $r = msEduBulkAccountsAct(m365_edu(), $accs, $op, ['licenses' => !empty($_POST['licenses']), 'manager' => $_POST['manager'] ?? '', 'role' => $_POST['role'] ?? '', 'group' => msEduBulkGroupFromPost($_POST), 'skus' => $_POST['skus'] ?? []]);
        flash_set($r['errors'] ? 'warning' : 'success', 'Wykonano: ' . $r['done'] . '/' . count($accs) . ($r['errors'] ? '. Błędy: ' . implode(' | ', $r['errors']) : '.'));
    }
    header('Location: ' . $self); exit;
}

$widok = in_array($_GET['widok'] ?? '', ['usuniete', 'porownanie'], true) ? $_GET['widok'] : 'wygenerowane';
$zaQ = trim((string)($_GET['q'] ?? '')); $zaStatus = (string)($_GET['status'] ?? ''); $zaGrupa = (string)($_GET['grupa'] ?? '');
$zaRows = $widok === 'wygenerowane' ? msEduBulkAccountsList($zaQ, $zaStatus, $zaGrupa) : [];
$delRows = $widok === 'usuniete' ? msEduBulkDeletedList($zaQ) : [];
$cntGen = (int)db()->query("SELECT COUNT(*) FROM msedu_bulk_accounts")->fetchColumn();
$cntDel = (int)db()->query("SELECT COUNT(*) FROM msedu_bulk_deleted")->fetchColumn();
$zaAction = $self; $zaTokenName = '_csrf'; $zaTokenValue = csrf_token(); $zaAllowRoles = true;
$zaSkus = m365_edu_ready() ? m365_edu()->get_subscribed_skus() : [];
$zaDetailUrl = str_starts_with($self, '/ms') ? '/ms/konto' : 'konto.php';
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<div class="container-xl py-4">
  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-person-gear me-2"></i>Konta EDU — zarządzanie</h1>
    <a href="<?= h(dirname($self)) ?>" class="btn btn-sm btn-outline-secondary ms-auto">← Generator kont</a>
  </div>
  <?= flash_html() ?>
  <ul class="nav nav-tabs mb-3" aria-label="Podgląd kont">
    <li class="nav-item"><a class="nav-link<?= $widok === 'wygenerowane' ? ' active' : '' ?>" <?= $widok === 'wygenerowane' ? 'aria-current="page"' : '' ?> href="<?= h($self) ?>">Wygenerowane <span class="badge text-bg-secondary"><?= $cntGen ?></span></a></li>
    <li class="nav-item"><a class="nav-link<?= $widok === 'usuniete' ? ' active' : '' ?>" <?= $widok === 'usuniete' ? 'aria-current="page"' : '' ?> href="<?= h($self) ?>?widok=usuniete">Usunięte <span class="badge text-bg-secondary"><?= $cntDel ?></span></a></li>
    <li class="nav-item"><a class="nav-link<?= $widok === 'porownanie' ? ' active' : '' ?>" <?= $widok === 'porownanie' ? 'aria-current="page"' : '' ?> href="<?= h($self) ?>?widok=porownanie">Porównanie z tenantem</a></li>
  </ul>
  <?php include __DIR__ . '/views/' . ['usuniete' => 'usuniete', 'porownanie' => 'porownanie', 'wygenerowane' => 'zarzadzanie'][$widok] . '.php'; ?>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php';
