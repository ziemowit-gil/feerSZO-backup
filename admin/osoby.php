<?php
require_once dirname(__DIR__) . '/config.php';
header('Location: ' . APP_URL . '/persons/index.php', true, 301);
exit;
