<?php
require_once dirname(__DIR__) . '/config.php';
header('Location: ' . APP_URL . '/onboarding/index.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
exit;
