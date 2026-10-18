<?php
/**
 * directory/org_unit_detail.php — Fragment HTML szczegółów jednostki (AJAX).
 * Wywoływany przez org_chart.php przy kliknięciu w węzeł drzewa.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/directory.php';
require_once dirname(__DIR__) . '/includes/org.php';

require_login();

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    header('Location: ' . APP_URL . '/directory/org_chart.php');
    exit;
}

$unit_id = (int)($_GET['id'] ?? 0);
if (!$unit_id) { http_response_code(400); echo 'Brak ID'; exit; }

require_once dirname(__DIR__) . '/modules/katalog_metro/lib/katalogMetro.php';
// Fragment w zakładce „Drzewo” — ten sam widok co karta jednostki (kafle składu i podległych)
katalogUnitDetail($unit_id, '', 'drzewo');
