<?php
/** Przekierowanie do modułu Strategii. */
require_once dirname(__DIR__) . '/config.php';
header('Location: ' . APP_URL . '/strategy/spheres/index.php', true, 301); exit;
