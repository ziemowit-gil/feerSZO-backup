<?php
/**
 * Eksport workflow JRWA do pliku BPMN 2.0 (.bpmn).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';
require_role('admin');
require_module_enabled('ezd_enabled', 'Moduł kancelarii');

$jrwa_id = (int)($_GET['jrwa_id'] ?? 0);
$jrwa = $jrwa_id ? ezd_jrwa_get($jrwa_id) : null;
if (!$jrwa) { http_response_code(404); echo 'Nie znaleziono hasła JRWA.'; exit; }

$wf    = ezd_workflow_get($jrwa_id);
$steps = ezd_workflow_steps($jrwa_id);
$name  = ($wf['name'] ?? '') ?: ('Obieg ' . $jrwa['symbol'] . ' — ' . $jrwa['title']);
$xml   = ezd_workflow_to_bpmn($steps, $name);

$fname = 'workflow_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $jrwa['symbol']) . '.bpmn';
header('Content-Type: application/bpmn+xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($xml));
echo $xml;
exit;
