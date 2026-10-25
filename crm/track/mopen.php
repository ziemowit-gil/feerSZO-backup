<?php
/**
 * crm/track/mopen.php — publiczny piksel otwarcia wysyłki MASOWEJ (zob. includes/crmMassStats.php).
 * Zawsze zwraca obrazek; księgowanie po odpowiedzi, błąd bazy nie psuje wyświetlenia wiadomości.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); } else { @ob_flush(); @flush(); }
$t = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{16,128}$/i', $t)) return;
try {
    require_once dirname(__DIR__, 2) . '/includes/crmMassStats.php';
    crm_mass_stats_schema();
    db()->prepare("UPDATE crm_mass_track SET open_count=open_count+1, opened_at=COALESCE(opened_at, datetime('now')) WHERE token=?")->execute([$t]);
} catch (\Throwable $e) {}
