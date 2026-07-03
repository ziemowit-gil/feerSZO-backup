<?php
/**
 * crm/track/open.php — publiczny (bez logowania) pixel śledzenia otwarcia kampanii.
 * Zawsze zwraca obrazek, nawet dla nieprawidłowego tokenu — nie zdradzamy jego ważności.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

$token = (string)($_GET['t'] ?? '');
if ($token !== '') {
    try {
        $r = db_one("SELECT id, campaign_id, opened_at FROM crm_campaign_recipients WHERE tracking_token=?", [$token]);
        if ($r && !$r['opened_at']) {
            db()->prepare("UPDATE crm_campaign_recipients SET opened_at=datetime('now') WHERE id=?")->execute([$r['id']]);
            db()->prepare("UPDATE crm_campaigns SET opened_count=opened_count+1 WHERE id=?")->execute([$r['campaign_id']]);
        }
    } catch (\Throwable $e) {}
}

// Przezroczysty GIF 1x1
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');
