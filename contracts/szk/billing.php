<?php
/** contracts/szk/billing.php — księgowanie należności umowy SZK w rozliczeniach TI (POST z karty umowy). */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractBilling.php';

require_login();
require_module_enabled('contract_szk', 'Ten typ umowy');
$id = (int)($_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) { header('Location: ' . APP_URL . '/contracts/szk/list.php'); exit; }
csrf_check();
$back = APP_URL . '/contracts/szk/view.php?id=' . $id . '#szk-billing';
$c = TiTrainingContractStore::get($id);
if (!can_edit() || !$c || !contract_can_access('szk', $c['_row'])) { flash_set('error', 'Brak uprawnień albo dostępu do umowy.'); header('Location: ' . $back); exit; }
$clear = ($_POST['act'] ?? '') === 'clear';
$r = TiSzkBilling::sync($id, $clear);
if ($r['error'] !== '') flash_set('error', $r['error']);
else {
    log_contract_action('szk', $id, (int)(current_user()['id'] ?? 0), 'update', ($clear ? 'Wycofano należności z rozliczeń' : 'Zsynchronizowano należności z rozliczeniami') . ' (operacji: ' . (int)$r['posted'] . ')');
    flash_set('success', ($clear ? 'Wycofano należności' : 'Zsynchronizowano należności') . ' — operacji w księdze: ' . (int)$r['posted'] . '.');
}
header('Location: ' . $back); exit;
