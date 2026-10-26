<?php
/**
 * contracts/szk/consents.php — „Zgody i dokumenty” umowy SZK: decyzje kadry, skan dokumentu, pobranie skanu (POST/GET z karty umowy).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractConsents.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('contract_szk', 'Ten typ umowy');
$cu = current_user();
$uid = (int)($cu['id'] ?? 0);
$by = (string)($cu['name'] ?? $cu['email'] ?? '');
$guard = function (int $contractId): array {
    $c = TiTrainingContractStore::get($contractId);
    if (!$c || !contract_can_access('szk', $c['_row'])) { flash_set('error', 'Nie ma takiej umowy albo brak dostępu.'); header('Location: ' . APP_URL . '/contracts/szk/list.php'); exit; }
    return $c;
};

if (isset($_GET['dl'])) {                              // pobranie skanu
    $r = TiSzkConsents::get((int)$_GET['dl']);
    if ($r) $guard((int)$r['contract_id']);
    $path = $r ? TiSzkConsents::absPath($r) : '';
    if ($path === '' || !is_file($path)) { http_response_code(404); exit('Nie znaleziono pliku.'); }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . TiSzkConsents::mime($r));
    header('Content-Disposition: inline; filename="' . rawurlencode($r['file_name'] ?: 'skan') . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . APP_URL . '/contracts/szk/list.php'); exit; }
csrf_check();
$cid = (int)($_POST['id'] ?? 0);
$guard($cid);
$back = APP_URL . '/contracts/szk/view.php?id=' . $cid . '#szk-consents';
if (!can_edit()) { flash_set('error', 'Brak uprawnień do edycji umów.'); header('Location: ' . $back); exit; }
$act = (string)($_POST['act'] ?? '');
$err = '';
if ($act === 'init') {
    TiSzkConsents::ensureDefaults($cid);
    log_contract_action('szk', $cid, $uid, 'update', 'Utworzono listę zgód i dokumentów.');
} elseif (in_array($act, ['decide', 'attach'], true)) {
    $r = TiSzkConsents::get((int)($_POST['item'] ?? 0));
    if (!$r || (int)$r['contract_id'] !== $cid) $err = 'Nie ma takiej pozycji.';
    elseif ($act === 'decide') {
        $err = TiSzkConsents::decide((int)$r['id'], (string)($_POST['state'] ?? ''), $by, 'kadra', (string)($_POST['note'] ?? ''));
        if ($err === '') log_contract_action('szk', $cid, $uid, 'update', 'Zgoda „' . $r['title'] . '”: ' . (TiSzkConsents::STATES[$_POST['state']] ?? ''));
    } else {
        $err = isset($_FILES['skan']) ? TiSzkConsents::attach((int)$r['id'], $_FILES['skan']) : 'Wybierz plik.';
        if ($err === '') log_contract_action('szk', $cid, $uid, 'upload', 'Skan: ' . $r['title']);
    }
}
if ($err !== '') flash_set('error', $err); else flash_set('success', 'Zapisano.');
header('Location: ' . $back); exit;
