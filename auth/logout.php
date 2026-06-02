<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
logout_user();
header('Location: ' . APP_URL . '/auth/login.php');
exit;
