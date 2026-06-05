<?php
/** Przekierowanie do samodzielnego modułu Strategii. */
require_once dirname(__DIR__) . '/config.php';
header('Location: ' . APP_URL . '/strategy/index.php', true, 301);
exit;
