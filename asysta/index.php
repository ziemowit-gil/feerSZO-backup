<?php
/** asysta/index.php — przekierowanie na panel zgłoszeń asysty. */
require_once dirname(__DIR__) . '/config.php';
header('Location: ' . APP_URL . '/asysta/admin.php');
exit;
