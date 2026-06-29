<?php
/* Przekierowanie — plik przeniesiony do extforms/konsultacjeADNGO/export_pdf.php */
require_once dirname(__DIR__) . '/config.php';
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . APP_URL . '/extforms/konsultacjeADNGO/export_pdf.php' . ($qs ? '?' . $qs : ''), true, 301);
exit;
