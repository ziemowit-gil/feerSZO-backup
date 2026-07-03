<?php
/**
 * crm/track/unsub.php — publiczna strona wypisania z wysyłek mailowych.
 * Ustawia flagę GLOBALNĄ na kontakcie (crm_contacts.email_opt_out) — respektowaną
 * także przez automatyzacje (includes/crm_automation.php), nie tylko kampanie.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

$token = (string)($_GET['t'] ?? '');
$ok = false;

if ($token !== '') {
    try {
        $r = db_one("SELECT id, campaign_id, contact_id FROM crm_campaign_recipients WHERE tracking_token=?", [$token]);
        if ($r) {
            db()->prepare("UPDATE crm_campaign_recipients SET unsubscribed_at=COALESCE(unsubscribed_at, datetime('now')) WHERE id=?")->execute([$r['id']]);
            $contact = db_one("SELECT email_opt_out FROM crm_contacts WHERE id=?", [$r['contact_id']]);
            if ($contact && (int)$contact['email_opt_out'] === 0) {
                db()->prepare("UPDATE crm_contacts SET email_opt_out=1, email_opt_out_at=datetime('now') WHERE id=?")->execute([$r['contact_id']]);
                db()->prepare("UPDATE crm_campaigns SET unsubscribed_count=unsubscribed_count+1 WHERE id=?")->execute([$r['campaign_id']]);
            }
            $ok = true;
        }
    } catch (\Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Wypisano z wysyłek</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:system-ui,sans-serif;background:#F9FAFB;color:#1F2937;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:2rem;max-width:420px;text-align:center}
h1{font-size:1.1rem;margin:0 0 .5rem}
p{font-size:.9rem;color:#6B7280;margin:0}
</style>
</head>
<body>
<div class="box">
  <h1><?= $ok ? 'Wypisano z wysyłek' : 'Link nieprawidłowy lub wygasł' ?></h1>
  <p><?= $ok
        ? 'Nie będziesz już otrzymywać wiadomości z tej kampanii mailowej.'
        : 'Ten link wypisania nie jest już aktywny.' ?></p>
</div>
</body>
</html>
