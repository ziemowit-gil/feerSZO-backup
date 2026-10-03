<?php
/**
 * karty30/ti/kursant/material_file.php — pobieranie plików materiałów przez kursanta.
 * id=ID → załącznik materiału (gdy kursant aktywnie zapisany w kursie materiału)
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/owncloud.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
$student = student_current();
if (!$student) { http_response_code(401); exit('Sesja wygasła.'); }
$cid = (int)$student['client_id'];

$mid = (int)($_GET['id'] ?? 0);
// Materiał przypisany kursantowi przez prowadzącego (moduł ti_dydaktyka) — dostępny także spoza jego grup i okien dostępności
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_dydaktyka/logic/tiDydaktykaManager.php';
$viaAssignment = TiDydaktykaManager::studentMayOpenMaterial($cid, $mid);
$m = db_one(
    "SELECT * FROM k30_ti_materials
     WHERE id=? AND is_active=1
       AND course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')",
    [$mid, $cid]
);
if (!$m && $viaAssignment) $m = db_one("SELECT * FROM k30_ti_materials WHERE id=? AND is_active=1", [$mid]);
if ($m && $m['attach_path'] !== '') {
    if (!$viaAssignment && !k30_ti_is_available($m['open_at'] ?? null, $m['close_at'] ?? null)) {
        http_response_code(403);
        exit('Materiał jest obecnie niedostępny.');
    }
    if ($viaAssignment) TiDydaktykaManager::markOpened($cid, $mid);
    k30_ti_homework_send_file($m['attach_path'], $m['attach_name']);
}

http_response_code(404);
exit('Plik nie istnieje.');
