<?php
// Przeniesiono do modułu Katalog pracowników.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
header('Location: ' . APP_URL . '/directory/profile_edit.php' . ($id ? '?id=' . $id : ''));
exit;
