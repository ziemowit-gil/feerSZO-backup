<?php
/* Przekierowanie — plik przeniesiony do extforms/konsultacjeADNGO/zip.php */
require_once dirname(__DIR__) . '/config.php';
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . APP_URL . '/extforms/konsultacjeADNGO/zip.php' . ($qs ? '?' . $qs : ''), true, 301);
exit;
