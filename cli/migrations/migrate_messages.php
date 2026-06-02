<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
$db = db();
$db->exec("CREATE TABLE IF NOT EXISTS messages (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    context_type  TEXT NOT NULL DEFAULT 'contract',
    context_id    INTEGER NOT NULL,
    contract_type TEXT NOT NULL DEFAULT '',
    sender_type   TEXT NOT NULL DEFAULT 'admin',
    sender_id     INTEGER,
    sender_name   TEXT NOT NULL DEFAULT '',
    body          TEXT NOT NULL DEFAULT '',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_read       INTEGER NOT NULL DEFAULT 0
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_messages_ctx ON messages(context_type, context_id)");
echo "messages — OK\n";
