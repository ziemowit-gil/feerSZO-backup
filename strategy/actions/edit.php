<?php
/**
 * strategy/actions/edit.php — dawny adres ekranu Działań. Moduł przebudowany (Metro UI):
 * modules/strategy_actions, krótki adres /dzialania/{id}/edycja. Przekierowanie zachowuje parametry zapytania.
 */
require_once dirname(__DIR__, 2) . '/config.php';
$id = (int)($_GET['id'] ?? 0);
$to = $id ? '/' . $id . '/edycja' : '';
$q = $_GET; unset($q['id']);
header('Location: ' . rtrim(APP_URL, '/') . '/dzialania' . $to . ($q ? '?' . http_build_query($q) : ''), true, 302);
exit;
