<?php
// Przeniesione do modułu Zadania
require_once dirname(__DIR__) . '/config.php';
header('Location: ' . APP_URL . '/tasks/settings/areas.php', true, 301);
exit;
