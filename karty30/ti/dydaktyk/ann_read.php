<?php
/**
 * karty30/ti/dydaktyk/ann_read.php — oznaczenie baneru komunikatu dla prowadzących jako przeczytanego.
 * POST: id (komunikat), _token (CSRF panelu dydaktyka).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/notifications.php';

$me = dyd_require();
dyd_token_check();
$id = (int)($_POST['id'] ?? 0);
if ($id > 0) ann_mark_read($id, (int)$me['user_id']);
header('Location: index.php?tab=komunikaty');
exit;
