<?php
/**
 * Walidacja kryptograficzna podpisu załącznika (AJAX → JSON).
 * Zwraca certyfikat(y) podpisującego + status integralności.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

header('Content-Type: application/json; charset=UTF-8');

$id = (int)($_GET['zal_id'] ?? $_GET['id'] ?? 0);
$z  = ezd_zal_get($id);
if (!$z) { http_response_code(404); echo json_encode(['error' => 'Załącznik nie istnieje.']); exit; }

$path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/' . $z['filename'];
$res  = ezd_validate_signature($path, $z['original_name']);
$res['file'] = $z['original_name'];

echo json_encode($res, JSON_UNESCAPED_UNICODE);
