<?php
/**
 * crm/track/open.php — publiczny (bez logowania) pixel śledzenia otwarcia kampanii.
 * Zawsze zwraca obrazek, nawet dla nieprawidłowego tokenu — nie zdradzamy jego ważności.
 *
 * Zdarzenie zapisujemy do crm_campaign_events przy KAŻDYM otwarciu (stąd
 * „otwarcia łącznie"), a opened_at na odbiorcy ustawiamy raz — pierwszy raz
 * wyznacza „otwarcia unikalne". Licznik na kampanii jest cache'em dla list.
 *
 * Obrazek leci do przeglądarki PRZED zapisem: statystyka nie może opóźniać
 * wyświetlenia wiadomości ani jej zepsuć, gdy baza jest chwilowo niedostępna.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

// Przezroczysty GIF 1x1 — najpierw odpowiedź, potem księgowanie.
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
else { @ob_flush(); @flush(); }

$token = (string)($_GET['t'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{16,128}$/i', $token)) return;

try {
    require_once dirname(__DIR__, 2) . '/includes/functions.php';
    require_once dirname(__DIR__, 2) . '/includes/crm_campaign.php';

    $r = crm_campaign_recipient_by_token($token);
    if (!$r) return;

    crm_campaign_log_event(
        (int)$r['campaign_id'], (int)$r['id'], (int)$r['contact_id'], 'open', null,
        ['ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '']
    );

    if (empty($r['opened_at'])) {
        db()->prepare("UPDATE crm_campaign_recipients SET opened_at=datetime('now') WHERE id=?")->execute([(int)$r['id']]);
        db()->prepare("UPDATE crm_campaigns SET opened_count=opened_count+1 WHERE id=?")->execute([(int)$r['campaign_id']]);
    }
} catch (\Throwable $e) {}
