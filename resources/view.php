<?php
/**
 * resources/view.php — Moduł Zasobów przeniesiony do Systemu Rezerwacji Sal
 * (SRS): modules/srs/. Ten adres pozostaje jako przekierowanie dla
 * istniejących linków/zakładek.
 */
require_once dirname(__DIR__) . '/config.php';

$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . APP_URL . '/modules/srs/view.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
