<?php
/**
 * crm/cases/download.php — Pobieranie pliku sprawy CRM.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$fid  = (int)($_GET['id'] ?? 0);
$file = db_one("SELECT * FROM crm_case_files WHERE id=?", [$fid]);
if (!$file) { http_response_code(404); die('Plik nie istnieje.'); }

// Sprawdź dostęp do sprawy
$case = db_one("SELECT * FROM crm_cases WHERE id=?", [(int)$file['case_id']]);
if (!$case) { http_response_code(404); die('Sprawa nie istnieje.'); }

$path = dirname(dirname(__DIR__)) . '/uploads/' . $file['stored_path'];
if (!is_file($path)) { http_response_code(404); die('Plik fizyczny nie istnieje.'); }

$disp = $file['display_name'] ?: $file['original_name'];
$mime = $file['mime_type'] ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode($disp) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
