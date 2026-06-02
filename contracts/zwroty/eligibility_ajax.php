<?php
// Endpoint AJAX — info o dostępności zwrotu dla danej umowy
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/zwroty_kosztow.php';

if (!current_user()) { http_response_code(403); exit; }

$contract_id   = (int)($_GET['umowa_id'] ?? 0);
$contract_type = $_GET['umowa_type'] ?? 'wolontariat';

$fm          = new FinanceManager();
$eligibility = $fm->validateEligibility($contract_id, $contract_type);

include __DIR__ . '/partials/eligibility_info.php';
