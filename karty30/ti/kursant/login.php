<?php
/**
 * karty30/ti/kursant/login.php — stary adres logowania kursanta.
 * Logowanie obsługuje moduł Tożsamość (centralne SSO): przekierowanie, żeby zapisane linki działały.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
header('Location: ' . rtrim(APP_URL, '/') . '/tozsamosc/kursant.php');
exit;
