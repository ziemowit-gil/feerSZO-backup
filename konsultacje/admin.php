<?php
/* Przekierowanie — panel przeniesiony do extforms/konsultacjeADNGO/admin.php */
require_once dirname(__DIR__) . '/config.php';
header('Location: ' . APP_URL . '/extforms/konsultacjeADNGO/admin.php', true, 301);
exit;
