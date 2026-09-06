<?php
/**
 * resources/admin/index.php — Moduł Zasobów przeniesiony do Systemu
 * Rezerwacji Sal (SRS): modules/srs/admin/. Ten adres pozostaje jako
 * przekierowanie dla istniejących linków/zakładek.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';

$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . APP_URL . '/modules/srs/admin/index.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
