<?php
/**
 * karty30/ti/dydaktyk/dyd_notif_read.php — „Oznacz wszystkie jako przeczytane” w powiadomieniach prowadzącego (POST).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_dyd_notifications/logic/dydNotifications.php';

$me = dyd_require();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
dyd_token_check();
dyd_notif_mark_seen((int)$me['user_id']);

// Powrót na tę samą stronę panelu (tylko adresy względne w obrębie panelu).
$back = (string)($_POST['back'] ?? '');
if ($back === '' || preg_match('#^(?:[a-z]+:)?//#i', $back) || str_contains($back, "\n")) $back = 'index.php';
header('Location: ' . $back);
exit;
