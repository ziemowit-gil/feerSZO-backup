<?php
/**
 * strategy/actions/index.php — dawny adres ekranu Działań. Moduł przebudowany (Metro UI):
 * modules/strategy_actions, krótki adres /dzialania. Przekierowanie zachowuje parametry zapytania.
 */
require_once dirname(__DIR__, 2) . '/config.php';
$to = '';
$q = $_GET; unset($q['id']);
header('Location: ' . rtrim(APP_URL, '/') . '/dzialania' . $to . ($q ? '?' . http_build_query($q) : ''), true, 302);
exit;
