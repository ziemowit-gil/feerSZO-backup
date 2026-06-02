<?php
/**
 * Migration: SMS login
 * Run once: creates sms_login_tokens table and inserts default settings.
 */
if (!defined('APP_INSTALLED')) {
    require_once __DIR__ . '/config.php';
}
require_once __DIR__ . '/includes/db.php';

$done = [];
$skip = [];

// ── Table ─────────────────────────────────────────────────────────────────────
db()->exec("CREATE TABLE IF NOT EXISTS sms_login_tokens (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    phone       TEXT    NOT NULL,
    code        TEXT    NOT NULL,
    user_id     INTEGER NOT NULL,
    expires_at  TEXT    NOT NULL,
    used_at     TEXT,
    created_at  TEXT    DEFAULT (datetime('now'))
)");
$done[] = 'Tabela sms_login_tokens — gotowa';

// ── Settings ──────────────────────────────────────────────────────────────────
$defaults = [
    'sms_enabled'      => '0',
    'sms_provider'     => 'smsapi',
    // smsapi.pl
    'sms_api_token'    => '',
    'sms_sender_name'  => 'INFO',
    // Twilio
    'sms_twilio_sid'   => '',
    'sms_twilio_token' => '',
    'sms_twilio_from'  => '',
];
foreach ($defaults as $key => $val) {
    $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$key]);
    if ($exists) { $skip[] = $key; continue; }
    db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute([$key, $val]);
    $done[] = "Ustawienie '$key' dodane";
}

echo "<pre>\n";
foreach ($done as $d) echo "✓ $d\n";
foreach ($skip as $s) echo "— '$s' już istnieje\n";
echo "\nMigracja SMS zakończona.\n</pre>";
