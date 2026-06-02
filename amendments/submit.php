<?php require_once dirname(__DIR__) . '/config.php';
$qs = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ' . APP_URL . '/contracts/approvals/amendments_submit.php' . $qs, true, 301); exit;
