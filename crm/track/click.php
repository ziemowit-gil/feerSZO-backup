<?php
/**
 * crm/track/click.php — publiczne przekierowanie z licznikiem kliknięć kampanii.
 * ?t=token&u=docelowy_url (url-encoded). Tylko http/https jako cel (ochrona przed
 * javascript:/data: itp.) — token nie jest tajny (widoczny w źródle maila), więc
 * to klasyczny, akceptowalny kompromis linków trackujących, nie luka bezpieczeństwa.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

$token  = (string)($_GET['t'] ?? '');
$target = (string)($_GET['u'] ?? '');

$fallback = rtrim(APP_URL, '/') . '/';
$dest = $fallback;

$parsed = parse_url($target);
if ($parsed && in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true) && filter_var($target, FILTER_VALIDATE_URL)) {
    $dest = $target;
}

if ($token !== '') {
    try {
        $r = db_one("SELECT id, campaign_id, first_clicked_at FROM crm_campaign_recipients WHERE tracking_token=?", [$token]);
        if ($r) {
            db()->prepare("UPDATE crm_campaign_recipients SET click_count=click_count+1, first_clicked_at=COALESCE(first_clicked_at, datetime('now')) WHERE id=?")
                ->execute([$r['id']]);
            if (!$r['first_clicked_at']) {
                db()->prepare("UPDATE crm_campaigns SET clicked_count=clicked_count+1 WHERE id=?")->execute([$r['campaign_id']]);
            }
        }
    } catch (\Throwable $e) {}
}

header('Location: ' . $dest, true, 302);
