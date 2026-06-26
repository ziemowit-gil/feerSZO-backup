<?php
/**
 * karty30/ti/kursant/vlab_contract_pdf.php — PDF umowy VLab dla kursanta.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/vlab_contracts.php';
require_once __DIR__ . '/auth.php';

$student = student_require();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Brak ID.'); }

$contract = db_one(
    "SELECT vc.*, a.login AS account_login, c.name AS client_name, c.email AS client_email
     FROM k30_vlab_contracts vc
     JOIN k30_clients c ON c.id=vc.client_id
     LEFT JOIN k30_ti_student_accounts a ON a.id=vc.account_id
     WHERE vc.id=? AND vc.client_id=?",
    [$id, (int)$student['client_id']]
);
if (!$contract) { http_response_code(404); exit('Nie znaleziono umowy.'); }

vlab_contract_pdf($contract);
