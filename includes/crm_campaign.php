<?php
/**
 * includes/crm_campaign.php — kampanie mailowe CRM: segmentacja odbiorców,
 * wstrzykiwanie trackingu (pixel otwarcia, przepisane linki, stopka unsubscribe)
 * i kolejkowanie wysyłki przez istniejący mail_queue.
 *
 * Segmentacja reużywa dokładnie tę samą logikę grup/tagów co crm/api/mass_send.php
 * (collect_recipients), dokładając filtr email_opt_out + wymóg niepustego e-maila.
 */

require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/mail_queue.php';

/** Zbiera ID kontaktów wg segmentu (tags|groups|contacts|all), wzorem collect_recipients() z mass_send.php. */
function crm_campaign_collect_ids(string $segment_type, array $cfg): array {
    $ids = [];

    if ($segment_type === 'all') {
        foreach (db_all("SELECT id FROM crm_contacts WHERE crm_active=1") as $r) $ids[] = (int)$r['id'];
        return array_values(array_unique($ids));
    }

    if ($segment_type === 'groups') {
        $group_ids = array_filter(array_map('intval', (array)($cfg['group_ids'] ?? [])));
        foreach ($group_ids as $group_id) {
            foreach (db_all("SELECT contact_id FROM crm_group_members WHERE group_id=?", [$group_id]) as $r) $ids[] = (int)$r['contact_id'];
            $linked = db_all(
                "SELECT gm.contact_id FROM crm_group_members gm
                 JOIN crm_group_links gl ON gl.child_group_id=gm.group_id
                 WHERE gl.parent_group_id=?", [$group_id]
            );
            foreach ($linked as $r) $ids[] = (int)$r['contact_id'];
            foreach (db_all("SELECT id FROM crm_groups WHERE parent_id=?", [$group_id]) as $sg) {
                foreach (db_all("SELECT contact_id FROM crm_group_members WHERE group_id=?", [(int)$sg['id']]) as $r) $ids[] = (int)$r['contact_id'];
            }
        }
        return array_values(array_unique($ids));
    }

    if ($segment_type === 'tags') {
        $tags = array_filter(array_map('trim', (array)($cfg['tags'] ?? [])));
        foreach ($tags as $tag) {
            foreach (db_all("SELECT contact_id FROM crm_tags WHERE tag=?", [$tag]) as $r) $ids[] = (int)$r['contact_id'];
        }
        return array_values(array_unique($ids));
    }

    if ($segment_type === 'contacts') {
        foreach ((array)($cfg['contact_ids'] ?? []) as $cid) $ids[] = (int)$cid;
        return array_values(array_unique(array_filter($ids)));
    }

    return [];
}

/** Zwraca pełne wiersze kontaktów gotowe do wysyłki: aktywne, z e-mailem, bez opt-out. */
function crm_campaign_resolve_recipients(string $segment_type, array $segment_config): array {
    $ids = crm_campaign_collect_ids($segment_type, $segment_config);
    if (!$ids) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = db_all(
        "SELECT * FROM crm_contacts
         WHERE id IN ($placeholders) AND crm_active=1
           AND email IS NOT NULL AND TRIM(email) <> ''
           AND COALESCE(email_opt_out,0)=0",
        $ids
    );
    return $rows;
}

/** Wstrzykuje pixel otwarcia, przepisuje linki na przekierowania przez token, dokłada stopkę unsubscribe. */
function crm_campaign_inject_tracking(string $html, string $token): string {
    $base = rtrim(APP_URL, '/');

    // Przepisz istniejące linki http(s) na przekierowanie przez click.php (pomiń mailto/tel/kotwice)
    $html = preg_replace_callback(
        '/href\s*=\s*"(https?:\/\/[^"]+)"/i',
        function (array $m) use ($base, $token) {
            $tracked = $base . '/crm/track/click.php?t=' . urlencode($token) . '&u=' . urlencode($m[1]);
            return 'href="' . htmlspecialchars($tracked, ENT_QUOTES) . '"';
        },
        $html
    );

    $pixel = '<img src="' . htmlspecialchars($base . '/crm/track/open.php?t=' . urlencode($token), ENT_QUOTES)
           . '" width="1" height="1" style="display:none" alt="">';
    $unsub = '<div style="font-size:11px;color:#9CA3AF;text-align:center;padding:12px 0">'
           . 'Nie chcesz otrzymywać takich wiadomości? '
           . '<a href="' . htmlspecialchars($base . '/crm/track/unsub.php?t=' . urlencode($token), ENT_QUOTES) . '" style="color:#6B7280">Wypisz się</a>'
           . '</div>';

    $extra = $unsub . $pixel;
    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $extra . '</body>', $html, 1);
    }
    return $html . $extra;
}

/** Kolejkuje wysyłkę kampanii — tworzy wiersze crm_campaign_recipients + mail_queue. */
function crm_campaign_queue_send(int $campaign_id): array {
    $campaign = db_one("SELECT * FROM crm_campaigns WHERE id=?", [$campaign_id]);
    if (!$campaign) return ['queued' => 0, 'error' => 'Nie znaleziono kampanii.'];
    if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) {
        return ['queued' => 0, 'error' => 'Kampania jest już w trakcie/została wysłana.'];
    }

    $template = db_one("SELECT * FROM crm_templates WHERE id=?", [(int)$campaign['template_id']]);
    if (!$template) return ['queued' => 0, 'error' => 'Nie znaleziono szablonu.'];

    $segment_config = json_decode($campaign['segment_config'] ?? '{}', true) ?: [];
    $recipients = crm_campaign_resolve_recipients($campaign['segment_type'], $segment_config);

    db()->prepare("UPDATE crm_campaigns SET status='sending', recipients_count=? WHERE id=?")
        ->execute([count($recipients), $campaign_id]);

    $subject_tpl = $campaign['subject'] ?: ($template['subject'] ?? '');
    $queued = 0;

    foreach ($recipients as $contact) {
        // Idempotencja: pomiń kontakt jeśli już zakolejkowany dla tej kampanii (np. wznowienie po błędzie)
        $exists = db_one("SELECT id FROM crm_campaign_recipients WHERE campaign_id=? AND contact_id=?", [$campaign_id, $contact['id']]);
        if ($exists) continue;

        $token = bin2hex(random_bytes(32));
        $rendered_subject = CrmManager::renderTemplate($subject_tpl, $contact);
        $rendered_body    = CrmManager::renderTemplate($template['body'], $contact);
        $tracked_body     = crm_campaign_inject_tracking($rendered_body, $token);

        $mail_id = mail_queue_add(
            $contact['email'], $contact['imie_nazwisko'] ?? '', $rendered_subject ?: 'Wiadomość',
            $tracked_body, '', 'crm_campaign', $campaign_id, '', false
        );

        db_insert('crm_campaign_recipients', [
            'campaign_id'    => $campaign_id,
            'contact_id'     => $contact['id'],
            'mail_queue_id'  => $mail_id,
            'tracking_token' => $token,
            'status'         => 'queued',
        ]);

        db_insert('crm_communications', [
            'contact_id'    => $contact['id'],
            'channel'       => 'email',
            'direction'     => 'out',
            'template_name' => 'kampania:' . $campaign['name'],
            'subject'       => $rendered_subject,
            'body'          => $tracked_body,
            'status'        => 'w kolejce',
            'sent_by'       => $campaign['created_by'],
            'sent_at'       => date('Y-m-d H:i:s'),
        ]);

        $queued++;
    }

    return ['queued' => $queued];
}

/** Przelicza liczniki kampanii na podstawie stanu mail_queue i flaguje 'sent' po dostarczeniu wszystkiego. */
function crm_campaign_refresh_stats(int $campaign_id): void {
    $campaign = db_one("SELECT * FROM crm_campaigns WHERE id=?", [$campaign_id]);
    if (!$campaign || $campaign['status'] !== 'sending') return;

    $rows = db_all(
        "SELECT cr.id, cr.status AS rcpt_status, mq.status AS mq_status
         FROM crm_campaign_recipients cr
         LEFT JOIN mail_queue mq ON mq.id = cr.mail_queue_id
         WHERE cr.campaign_id=?", [$campaign_id]
    );

    $sent = $failed = $pending = 0;
    foreach ($rows as $r) {
        if ($r['mq_status'] === 'sent') {
            $sent++;
            if ($r['rcpt_status'] === 'queued') {
                db()->prepare("UPDATE crm_campaign_recipients SET status='sent', sent_at=datetime('now') WHERE id=?")->execute([$r['id']]);
            }
        } elseif ($r['mq_status'] === 'failed') {
            $failed++;
            if ($r['rcpt_status'] === 'queued') {
                db()->prepare("UPDATE crm_campaign_recipients SET status='failed' WHERE id=?")->execute([$r['id']]);
            }
        } else {
            $pending++;
        }
    }

    $status = ($pending === 0 && count($rows) > 0) ? 'sent' : 'sending';
    db()->prepare(
        "UPDATE crm_campaigns SET sent_count=?, failed_count=?, status=?, sent_at=CASE WHEN ?='sent' AND sent_at IS NULL THEN datetime('now') ELSE sent_at END WHERE id=?"
    )->execute([$sent, $failed, $status, $status, $campaign_id]);
}
