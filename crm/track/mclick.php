<?php
/**
 * crm/track/mclick.php — przekierowanie z liczeniem kliknięć wysyłki MASOWEJ.
 * Adres docelowy pochodzi wyłącznie z tabeli crm_mass_track_links po ID (bez otwartego przekierowania);
 * token musi istnieć, a link należeć do tej samej wysyłki.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/crmMassStats.php';
$t = (string)($_GET['t'] ?? '');
$l = (int)($_GET['l'] ?? 0);
$url = '';
try {
    crm_mass_stats_schema();
    if (preg_match('/^[a-f0-9]{16,128}$/i', $t) && $l > 0) {
        $tr = db_one("SELECT id, send_id FROM crm_mass_track WHERE token=?", [$t]);
        $lk = $tr ? db_one("SELECT id, url FROM crm_mass_track_links WHERE id=? AND send_id=?", [$l, (int)$tr['send_id']]) : null;
        if ($lk && preg_match('~^https?://~i', (string)$lk['url'])) {
            $url = (string)$lk['url'];
            db()->prepare("UPDATE crm_mass_track SET click_count=click_count+1, clicked_at=COALESCE(clicked_at, datetime('now')), opened_at=COALESCE(opened_at, datetime('now')) WHERE id=?")->execute([(int)$tr['id']]);
            db()->prepare("UPDATE crm_mass_track_links SET clicks=clicks+1 WHERE id=?")->execute([(int)$lk['id']]);
        }
    }
} catch (\Throwable $e) {}
if ($url === '') { http_response_code(404); echo 'Link nieaktywny.'; exit; }
header('Cache-Control: no-store');
header('Location: ' . $url, true, 302);
exit;
