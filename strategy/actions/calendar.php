<?php
/**
 * strategy/actions/calendar.php — dawny adres ekranu Działań. Moduł przebudowany (Metro UI):
 * modules/strategy_actions, krótki adres /dzialania/kalendarz.json. Przekierowanie zachowuje parametry zapytania.
 */
require_once dirname(__DIR__, 2) . '/config.php';
$to = '/kalendarz.json';
$q = $_GET; unset($q['id']);
header('Location: ' . rtrim(APP_URL, '/') . '/dzialania' . $to . ($q ? '?' . http_build_query($q) : ''), true, 302);
exit;
