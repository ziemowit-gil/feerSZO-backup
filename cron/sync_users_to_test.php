#!/usr/bin/env php
<?php
define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/user_sync.php';

$users  = db_all("SELECT name, first_name, last_name, email, password, role, is_active FROM users ORDER BY id");
$result = user_sync_batch($users);

echo '[sync_users_to_test] ' . date('Y-m-d H:i:s') . ' — ';
if ($result) {
    echo "ok: created={$result['created']} updated={$result['updated']} skipped={$result['skipped']}";
} else {
    echo 'FAILED or not configured';
}
echo "\n";
