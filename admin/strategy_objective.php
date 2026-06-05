<?php
/** Przekierowanie do modułu Strategii. */
require_once dirname(__DIR__) . '/config.php';
$id = (int)($_GET['id'] ?? 0);
$target = APP_URL . '/strategy/objectives/view.php' . ($id ? "?id={$id}" : '?new=1');
header('Location: ' . $target, true, 301); exit;
