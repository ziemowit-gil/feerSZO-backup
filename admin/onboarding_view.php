<?php
require_once dirname(__DIR__) . '/config.php';
$id = (int)($_GET['id'] ?? 0);
header('Location: ' . APP_URL . '/onboarding/view.php?id=' . $id);
exit;
