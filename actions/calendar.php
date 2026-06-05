<?php
/** Przekierowanie — moduł Działań przeniesiony do /strategy/actions/ */
require_once dirname(__DIR__) . '/config.php';
$target = APP_URL . '/strategy/actions/' . basename(__FILE__) . (($_SERVER['QUERY_STRING']??'') ? '?' . $_SERVER['QUERY_STRING'] : '');
header('Location: ' . $target, true, 301); exit;
