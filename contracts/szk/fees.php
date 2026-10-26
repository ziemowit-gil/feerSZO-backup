<?php
/** contracts/szk/fees.php — zapis „Promocje i opłaty” umowy SZK (POST z karty umowy). */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_access.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once dirname(dirname(__DIR__)) . '/modules/ti_training_contract/logic/trainingContractFees.php';

require_login();
require_module_enabled('contract_szk', 'Ten typ umowy');
$id = (int)($_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) { header('Location: ' . APP_URL . '/contracts/szk/list.php'); exit; }
csrf_check();
if (!can_edit()) { flash_set('error', 'Brak uprawnień do edycji umów.'); header('Location: view.php?id=' . $id); exit; }
$row = TiTrainingContractStore::get($id);
if (!$row || !contract_can_access('szk', $row['_row'])) { flash_set('error', 'Nie ma takiej umowy albo brak dostępu.'); header('Location: ' . APP_URL . '/contracts/szk/list.php'); exit; }
$err = TiSzkFees::save($id, $_POST, (int)(current_user()['id'] ?? 0));
if ($err !== '') flash_set('error', $err); else flash_set('success', 'Zapisano opłaty i promocję.');
header('Location: ' . APP_URL . '/contracts/szk/view.php?id=' . $id . '#szk-fees'); exit;
