<?php
/**
 * crm/track/click.php — publiczne przekierowanie z licznikiem kliknięć kampanii.
 *
 * ŚCIEŻKA WŁAŚCIWA:  ?t=token&l=ID   — cel pobierany z crm_campaign_links.
 * ŚCIEŻKA ZGODNOŚCI: ?t=token&u=URL  — dla wiadomości wysłanych przed wdrożeniem
 *                    identyfikatorów linków.
 *
 * DLACZEGO ZMIANA: wariant ?u=<pełny URL> pozwalał podstawić dowolny adres i użyć
 * naszej domeny jako odbijaka w phishingu (open redirect). Teraz cel musi być
 * ZAREJESTROWANY dla tej kampanii — albo w crm_campaign_links, albo dosłownie
 * obecny w zamrożonej treści wysyłki. Nieznany cel nie jest przekierowaniem,
 * tylko powrotem na stronę główną.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/crm_campaign.php';

$token   = (string)($_GET['t'] ?? '');
$link_id = (int)($_GET['l'] ?? 0);
$legacy  = (string)($_GET['u'] ?? '');

$fallback = rtrim(APP_URL, '/') . '/';
$dest     = $fallback;
$rcpt     = null;
$resolved_link_id = null;

try {
    $rcpt = preg_match('/^[a-f0-9]{16,128}$/i', $token) ? crm_campaign_recipient_by_token($token) : null;

    if ($link_id > 0) {
        // Link musi należeć do TEJ kampanii — inaczej cudzy identyfikator
        // pozwalałby przekierować odbiorcę gdzie indziej.
        $row = $rcpt
            ? db_one("SELECT id, url FROM crm_campaign_links WHERE id=? AND campaign_id=?", [$link_id, (int)$rcpt['campaign_id']])
            : db_one("SELECT id, url FROM crm_campaign_links WHERE id=?", [$link_id]);
        if ($row) { $dest = (string)$row['url']; $resolved_link_id = (int)$row['id']; }

    } elseif ($legacy !== '' && $rcpt) {
        $parsed = parse_url($legacy);
        $scheme_ok = $parsed && in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true)
                     && filter_var($legacy, FILTER_VALIDATE_URL);
        if ($scheme_ok) {
            $row = db_one("SELECT id FROM crm_campaign_links WHERE campaign_id=? AND url=?", [(int)$rcpt['campaign_id'], $legacy]);
            if ($row) {
                $dest = $legacy;
                $resolved_link_id = (int)$row['id'];
            } else {
                // Ostatnia furtka dla starych wysyłek: cel musi dosłownie
                // występować w zamrożonej treści kampanii (albo w jej szablonie).
                $c = db_one(
                    "SELECT c.html_snapshot, t.body FROM crm_campaigns c
                     LEFT JOIN crm_templates t ON t.id = c.template_id WHERE c.id=?",
                    [(int)$rcpt['campaign_id']]
                );
                $haystack = (string)($c['html_snapshot'] ?? '') . (string)($c['body'] ?? '');
                if ($haystack !== '' && str_contains($haystack, $legacy)) $dest = $legacy;
            }
        }
    }
} catch (\Throwable $e) {}

header('Location: ' . $dest, true, 302);
header('Cache-Control: no-store, private');
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

// Księgowanie po przekierowaniu — odbiorca nie czeka na zapis.
if ($rcpt) {
    try {
        crm_campaign_log_event(
            (int)$rcpt['campaign_id'], (int)$rcpt['id'], (int)$rcpt['contact_id'], 'click', $resolved_link_id,
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '']
        );
        db()->prepare(
            "UPDATE crm_campaign_recipients
                SET click_count = click_count + 1,
                    first_clicked_at = COALESCE(first_clicked_at, datetime('now'))
              WHERE id=?"
        )->execute([(int)$rcpt['id']]);
        if (empty($rcpt['first_clicked_at'])) {
            db()->prepare("UPDATE crm_campaigns SET clicked_count=clicked_count+1 WHERE id=?")->execute([(int)$rcpt['campaign_id']]);
        }
        // Kliknięcie oznacza też otwarcie — część klientów nie ładuje pikseli,
        // więc bez tego kampania miałaby więcej klików niż otwarć.
        if (empty($rcpt['opened_at'])) {
            db()->prepare("UPDATE crm_campaign_recipients SET opened_at=datetime('now') WHERE id=?")->execute([(int)$rcpt['id']]);
            db()->prepare("UPDATE crm_campaigns SET opened_count=opened_count+1 WHERE id=?")->execute([(int)$rcpt['campaign_id']]);
            crm_campaign_log_event((int)$rcpt['campaign_id'], (int)$rcpt['id'], (int)$rcpt['contact_id'], 'open', null,
                ['detail' => 'wywnioskowane z kliknięcia']);
        }
    } catch (\Throwable $e) {}
}
