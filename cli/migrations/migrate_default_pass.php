<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$exists = db_one("SELECT 1 FROM settings WHERE key_='default_user_password'");
if (!$exists) {
    db()->prepare("INSERT INTO settings (key_, value) VALUES ('default_user_password', '12345qwe')")->execute();
    echo "✓ Dodano ustawienie default_user_password = 12345qwe\n";
} else {
    echo "— Ustawienie już istnieje.\n";
}
