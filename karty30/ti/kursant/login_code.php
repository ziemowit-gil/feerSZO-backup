<?php
/**
 * karty30/ti/kursant/login_code.php — stary adres kroku z kodem SMS.
 * Obsługuje go moduł Tożsamość (tozsamosc/kursant_kod.php).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
header('Location: ' . rtrim(APP_URL, '/') . '/tozsamosc/kursant_kod.php');
exit;
