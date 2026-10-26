<?php
/**
 * modules/helpdeskMail/lib/helpdeskMail.php — zgłoszenia Helpdesku z e-maila.
 *
 * Skrzynka podpięta w Helpdesk › Ustawienia (tabela helpdesk_mail_intake) zasila konsolę: wiadomość przychodząca,
 * zapisana już przez skanowanie Poczty (crm_communications — żadnych nowych uprawnień M365), zamienia się w zgłoszenie
 * (numer wg kategorii, jak w formularzu). Odpowiedź z numerem w temacie ([HD00042]) dopisuje się do wątku, gdy nadawca
 * to zgłaszający albo operator; inaczej powstaje nowe zgłoszenie z odwołaniem do numeru (nie wstrzykujemy wiadomości
 * do cudzego wątku). Duplikaty: helpdesk_mail_sources.message_key UNIQUE (Internet Message-ID). Pętle: te same filtry
 * autoresponderów/adresów systemowych co w zadaniach (tasksMailSkipReason), limit potwierdzeń na nadawcę.
 */
require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
require_once dirname(__DIR__, 3) . '/tasks/lib/tasksMail.php';

const HD_MAIL_ACK_PER_HOUR = 3;

function hdMailMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    helpdesk_migrate();
    db()->exec("CREATE TABLE IF NOT EXISTS helpdesk_mail_intake (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        mailbox_id  INTEGER NOT NULL UNIQUE REFERENCES poczta_mailboxes(id) ON DELETE CASCADE,
        category    TEXT NOT NULL DEFAULT 'it_inne',
        priority    TEXT NOT NULL DEFAULT 'normalny',
        ack_enabled INTEGER NOT NULL DEFAULT 1,
        is_active   INTEGER NOT NULL DEFAULT 1,
        since_at    TEXT NOT NULL,
        last_run_at TEXT,
        updated_by  INTEGER,
        updated_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    db()->exec("CREATE TABLE IF NOT EXISTS helpdesk_mail_sources (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        message_key TEXT NOT NULL UNIQUE,
        comm_id     INTEGER,
        ticket_id   INTEGER NOT NULL,
        kind        TEXT NOT NULL DEFAULT 'ticket',
        mailbox_id  INTEGER,
        from_email  TEXT NOT NULL DEFAULT '',
        subject     TEXT NOT NULL DEFAULT '',
        received_at TEXT,
        ack_sent_at TEXT,
        created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_hd_mail_src_ticket ON helpdesk_mail_sources(ticket_id)");
    db()->exec("CREATE TABLE IF NOT EXISTS helpdesk_mail_skips (
        comm_id    INTEGER PRIMARY KEY,
        reason     TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
}

/** Skrzynki podpięte do Helpdesku (z adresem). */
function hdMailIntakes(): array
{
    hdMailMigrate();
    return db_all("SELECT i.*, m.mailbox, m.display_name FROM helpdesk_mail_intake i
                   LEFT JOIN poczta_mailboxes m ON m.id=i.mailbox_id ORDER BY m.mailbox");
}

/** Skrzynki, które administrator może podpiąć (jeszcze nie zasilają Helpdesku). */
function hdMailAvailableMailboxes(): array
{
    hdMailMigrate();
    require_once dirname(__DIR__, 3) . '/includes/poczta.php';
    return db_all("SELECT id, mailbox, display_name FROM poczta_mailboxes
                   WHERE id NOT IN (SELECT mailbox_id FROM helpdesk_mail_intake) ORDER BY mailbox");
}

function hdMailIntakeSave(array $in, int $byUid): void
{
    hdMailMigrate();
    $mbx = (int)($in['mailbox_id'] ?? 0);
    if (!db_one("SELECT id FROM poczta_mailboxes WHERE id=?", [$mbx])) throw new \RuntimeException('Wybierz skrzynkę.');
    $cat = (string)($in['category'] ?? 'it_inne');
    if (!isset(hd_categories()[$cat])) $cat = 'it_inne';
    $pri = (string)($in['priority'] ?? 'normalny');
    if (!isset(HD_PRIORITIES[$pri])) $pri = 'normalny';
    $cur = db_one("SELECT since_at FROM helpdesk_mail_intake WHERE mailbox_id=?", [$mbx]);
    // Nowa skrzynka → tylko wiadomości od teraz (bez zalewu zgłoszeniami ze starej poczty)
    $since = $cur['since_at'] ?? date('Y-m-d H:i:s');
    db()->prepare("INSERT INTO helpdesk_mail_intake (mailbox_id, category, priority, ack_enabled, is_active, since_at, updated_by, updated_at)
                   VALUES (?,?,?,?,?,?,?,?)
                   ON CONFLICT(mailbox_id) DO UPDATE SET category=excluded.category, priority=excluded.priority,
                     ack_enabled=excluded.ack_enabled, is_active=excluded.is_active, updated_by=excluded.updated_by, updated_at=excluded.updated_at")
        ->execute([$mbx, $cat, $pri, !empty($in['ack_enabled']) ? 1 : 0, !empty($in['is_active']) ? 1 : 0, $since, $byUid, date('Y-m-d H:i:s')]);
}

function hdMailIntakeDelete(int $mailboxId): void
{
    hdMailMigrate();
    db()->prepare("DELETE FROM helpdesk_mail_intake WHERE mailbox_id=?")->execute([$mailboxId]);
}

function hdMailKey(array $c): string
{
    $k = trim((string)($c['internet_message_id'] ?? ''));
    return $k !== '' ? $k : 'comm:' . (int)$c['id'];
}

/** Numer zgłoszenia w temacie ([HD00042], [HER00007]…) albo null. */
function hdMailTicketNumber(string $subject): ?string
{
    require_once dirname(__DIR__, 2) . '/helpdeskCategories/lib/helpdeskCategories.php';
    return preg_match(hdNumberRegex(), $subject, $m) ? $m[1] : null;
}

/** Treść maila: tekst, bez cytowanych odpowiedzi nie tniemy (operator widzi całość), limit długości. */
function hdMailBody(array $c): string
{
    $body = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', (string)$c['body'])));
    return mb_substr($body !== '' ? $body : '(pusta wiadomość)', 0, 15000);
}

/** Przebieg automatu (cron co 5 min). $dryRun — tylko liczy. */
function hdMailIntakeRun(bool $dryRun = false): array
{
    hdMailMigrate();
    $stats = ['tickets' => 0, 'replies' => 0, 'skipped' => 0, 'acks' => 0];
    $GLOBALS['_hdMailAcks'] = 0;
    $own = tasksMailOwnAddresses();
    foreach (db_all("SELECT * FROM helpdesk_mail_intake WHERE is_active=1") as $in) {
        $rows = db_all(
            "SELECT c.*, m.mailbox AS mailbox_address FROM crm_communications c JOIN poczta_mailboxes m ON m.id=c.mailbox_id
             WHERE c.mailbox_id=? AND c.direction='in' AND c.channel='email' AND c.sent_at >= ?
               AND c.id = (SELECT MIN(c2.id) FROM crm_communications c2 WHERE c2.mailbox_id=c.mailbox_id
                           AND COALESCE(NULLIF(c2.internet_message_id,''), 'comm:'||c2.id) = COALESCE(NULLIF(c.internet_message_id,''), 'comm:'||c.id))
               AND NOT EXISTS (SELECT 1 FROM helpdesk_mail_skips s WHERE s.comm_id=c.id)
               AND NOT EXISTS (SELECT 1 FROM helpdesk_mail_sources s WHERE s.message_key = COALESCE(NULLIF(c.internet_message_id,''), 'comm:'||c.id))
             ORDER BY c.sent_at LIMIT 50",
            [(int)$in['mailbox_id'], $in['since_at']]
        );
        foreach ($rows as $c) {
            if ($dryRun) { $stats['tickets']++; continue; }
            try {
                $stats[hdMailIntakeOne($in, $c, $own)]++;
            } catch (\Throwable $e) {
                error_log('[helpdeskMail] wiadomość #' . $c['id'] . ': ' . $e->getMessage());
                db()->prepare("INSERT OR IGNORE INTO helpdesk_mail_skips (comm_id, reason) VALUES (?, ?)")
                    ->execute([(int)$c['id'], 'błąd: ' . mb_substr($e->getMessage(), 0, 200)]);
                $stats['skipped']++;
            }
        }
        if (!$dryRun) db()->prepare("UPDATE helpdesk_mail_intake SET last_run_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), (int)$in['id']]);
    }
    $stats['acks'] = $GLOBALS['_hdMailAcks'];
    return $stats;
}

/** Jedna wiadomość → 'tickets' | 'replies' | 'skipped'. */
function hdMailIntakeOne(array $in, array $c, array $own): string
{
    $skip = function (string $why) use ($c): string {
        db()->prepare("INSERT OR IGNORE INTO helpdesk_mail_skips (comm_id, reason) VALUES (?, ?)")->execute([(int)$c['id'], $why]);
        return 'skipped';
    };
    if ($why = tasksMailSkipReason($c, $own)) return $skip($why);
    // Autoodpowiedzi, raporty o niedostarczeniu i nieobecności bywają w temacie w różnej kolejności i nie tylko na początku
    if (preg_match('/(automatyczna odpowied|odpowied[zź] automatyczna|auto-?reply|automatic reply|out of office|poza biurem|nieobecno|undeliver|niedostarcz|delivery (status|failure)|failure notice|mail delivery|returned mail|zwr[oó]cona wiadomo|abwesenheit)/iu', (string)$c['subject'])) return $skip('odpowiedź automatyczna / raport doręczenia');

    $from    = strtolower(trim((string)$c['from_email']));
    $subject = trim((string)$c['subject']);
    $refNum  = hdMailTicketNumber($subject);
    require_once dirname(__DIR__, 2) . '/helpdeskMigrate/lib/helpdeskMigrate.php';
    $ref     = $refNum ? hdTicketByAnyNumber($refNum) : null;   // numer bieżący albo dawny (po migracji numeracji)

    // Odpowiedź w wątku: nadawca = zgłaszający albo operator
    if ($ref) {
        $isRequester = $from !== '' && strtolower(trim((string)$ref['requester_email'])) === $from;
        $opRow = db_one("SELECT id, name FROM users WHERE helpdesk_operator=1 AND is_active=1 AND lower(email)=?", [$from]);
        if ($isRequester || $opRow) {
            $ticketId = (int)$ref['id'];
            if ($ref['merged_into']) $ticketId = (int)$ref['merged_into'];
            $msgId = db_insert('helpdesk_messages', [
                'ticket_id' => $ticketId, 'user_id' => $isRequester ? ($ref['requester_id'] ?: null) : (int)$opRow['id'],
                'user_name' => $isRequester ? ((string)$ref['requester_name'] ?: $from) : (string)$opRow['name'],
                'body' => hdMailBody($c), 'is_internal' => 0,
            ]);
            $t = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]);
            $upd = ['updated_at' => date('Y-m-d H:i:s')];
            if ($isRequester && in_array($t['status'], ['rozwiązane', 'zamknięte', 'oczekuje'], true)) $upd['status'] = 'otwarte';
            if (!$isRequester && empty($t['first_response_at'])) $upd['first_response_at'] = date('Y-m-d H:i:s');
            $set = implode(',', array_map(fn($k) => "$k=?", array_keys($upd)));
            db()->prepare("UPDATE helpdesk_tickets SET $set WHERE id=?")->execute([...array_values($upd), $ticketId]);
            hdMailSaveAttachments($ticketId, $msgId, $c);
            db()->prepare("INSERT INTO helpdesk_mail_sources (message_key, comm_id, ticket_id, kind, mailbox_id, from_email, subject, received_at)
                           VALUES (?,?,?,?,?,?,?,?)")
                ->execute([hdMailKey($c), (int)$c['id'], $ticketId, 'reply', (int)$in['mailbox_id'], $from, $subject, (string)$c['sent_at']]);
            hd_notify_new_message($t, ['id' => $msgId, 'user_id' => $isRequester ? ($ref['requester_id'] ?: null) : (int)$opRow['id'],
                'user_name' => $isRequester ? $ref['requester_name'] : $opRow['name'], 'body' => hdMailBody($c), 'is_internal' => 0]);
            return 'replies';
        }
    }

    // Nowe zgłoszenie
    $category = isset(hd_categories()[$in['category']]) ? $in['category'] : 'it_inne';
    $priority = isset(HD_PRIORITIES[$in['priority']]) ? $in['priority'] : 'normalny';
    $number   = hd_next_number(hd_number_prefix_for($category), hd_number_pattern_for($category));
    $name     = trim((string)$c['from_name']) ?: $from;
    $user     = db_one("SELECT id, name FROM users WHERE lower(email)=? AND is_active=1", [$from]);
    $title    = mb_substr($subject !== '' ? $subject : '(bez tematu)', 0, 200);
    $body     = hdMailBody($c);
    if ($ref) $body = "Odwołanie do zgłoszenia {$ref['number']} (nadawca inny niż zgłaszający — wątek nie został zmieniony).\n\n" . $body;
    $ticketId = db_insert('helpdesk_tickets', [
        'number' => $number, 'title' => $title, 'description' => $body, 'category' => $category, 'priority' => $priority,
        'status' => 'nowe', 'requester_id' => $user['id'] ?? null, 'requester_name' => $user['name'] ?? $name,
        'requester_email' => $from, 'source' => 'email',
    ]);
    $msgId = db_insert('helpdesk_messages', [
        'ticket_id' => $ticketId, 'user_id' => $user['id'] ?? null, 'user_name' => $user['name'] ?? $name, 'body' => $body, 'is_internal' => 0,
    ]);
    hdMailSaveAttachments($ticketId, $msgId, $c);
    db()->prepare("INSERT INTO helpdesk_mail_sources (message_key, comm_id, ticket_id, kind, mailbox_id, from_email, subject, received_at)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([hdMailKey($c), (int)$c['id'], $ticketId, 'ticket', (int)$in['mailbox_id'], $from, $subject, (string)$c['sent_at']]);
    hd_redmine_sync_ticket($ticketId);
    hd_after_create($ticketId);
    hdMailNotifyOperators($ticketId, $number, $title);
    if ((int)$in['ack_enabled']) hdMailAck($ticketId, $number, $title, $name, $from);
    return 'tickets';
}

/** Załączniki maila → helpdesk_attachments (dozwolone rozszerzenia jak w formularzu). */
function hdMailSaveAttachments(int $ticketId, int $msgId, array $c): void
{
    $allow = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','zip','txt','csv'];
    $dir = UPLOAD_DIR . 'helpdesk/';
    foreach (tasksMailAttachments((int)$c['id']) as $a) {
        $ext = strtolower(pathinfo((string)$a['original_name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allow, true)) continue;
        $bytes = tasksMailAttachmentBytes($a, $c);
        if ($bytes === null || strlen($bytes) > 15 * 1024 * 1024) continue;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents($dir . $stored, $bytes) === false) continue;
        $_att = db_insert('helpdesk_attachments', ['ticket_id' => $ticketId, 'message_id' => $msgId, 'original_name' => (string)$a['original_name'],
            'stored_path' => 'helpdesk/' . $stored, 'file_size' => strlen($bytes), 'uploaded_by' => null]);
        try { require_once dirname(__DIR__, 3) . '/modules/doc_registry/lib/DocRegistry.php'; DocRegistry::assign('helpdesk_attachment', $_att); } catch (\Throwable $e) {}
    }
}

function hdMailNotifyOperators(int $ticketId, string $number, string $title): void
{
    try {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $url = hd_base_url() . '/helpdesk/view.php?id=' . $ticketId;
        foreach (db_all("SELECT email, name FROM users WHERE helpdesk_operator=1 AND is_active=1 AND email IS NOT NULL AND email != ''") as $op) {
            if (!hdNotifyAllows((string)$op['email'], 'new_ticket')) continue;
            mail_queue_add((string)$op['email'], (string)($op['name'] ?? ''), "[{$number}] Nowe zgłoszenie (e-mail): {$title}",
                hd_mail_layout(['title' => 'Nowe zgłoszenie z e-maila', 'preheader' => $number . ' — ' . $title,
                    'facts' => [['Numer', $number], ['Temat', $title]], 'cta' => ['label' => 'Otwórz zgłoszenie', 'url' => $url]]));
        }
    } catch (\Throwable $e) { error_log('[helpdeskMail] powiadomienie: ' . $e->getMessage()); }
}

/** Potwierdzenie dla nadawcy z numerem i linkiem do mikropanelu (adres systemowy, limit na nadawcę). */
function hdMailAck(int $ticketId, string $number, string $title, string $name, string $to): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    require_once dirname(__DIR__, 3) . '/includes/office_hours.php';
    if (!oh_acks_allowed()) return false;   // bramka Godzin pracy: potwierdzenia tylko poza godzinami pracy (gdy włączona)
    $recent = (int)(db_one("SELECT COUNT(*) AS n FROM helpdesk_mail_sources WHERE lower(from_email)=? AND ack_sent_at >= ?",
        [$to, date('Y-m-d H:i:s', strtotime('-1 hour'))])['n'] ?? 0);
    if ($recent >= HD_MAIL_ACK_PER_HOUR) return false;
    require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
    $track = hd_track_url(db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]) ?: ['id' => $ticketId]);
    $html = hd_mail_layout([
        'title' => 'Zgłoszenie zostało przyjęte', 'preheader' => $number . ' — ' . $title,
        'greeting' => 'Dzień dobry, ' . $name . ',',
        'intro' => '<p style="margin:0">Twoja wiadomość została zarejestrowana jako zgłoszenie.</p>',
        'facts' => [['Numer', $number], ['Temat', $title]],
        'cta' => ['label' => 'Śledź status i dopisz informacje', 'url' => $track],
        'footnote' => 'Możesz też odpowiedzieć na tę wiadomość, zachowując numer <strong>[' . h($number) . ']</strong> w temacie.',
    ]);
    $id = mail_queue_add($to, $name, "[{$number}] Zgłoszenie przyjęte: " . mb_substr($title, 0, 150), $html, '', 'helpdesk_mail_ack', $ticketId,
        '', false, [], '', [], [], ['Auto-Submitted' => 'auto-replied', 'X-Auto-Response-Suppress' => 'All']);
    if ($id) {
        db()->prepare("UPDATE helpdesk_mail_sources SET ack_sent_at=? WHERE ticket_id=? AND kind='ticket'")->execute([date('Y-m-d H:i:s'), $ticketId]);
        $GLOBALS['_hdMailAcks'] = ($GLOBALS['_hdMailAcks'] ?? 0) + 1;
    }
    return (bool)$id;
}


/** Adres, na który wracają odpowiedzi do maili Helpdesku (ustawienie hd_mail_reply_to albo pierwsza aktywna skrzynka). */
function hdMailReplyAddress(): string
{
    hdMailMigrate();
    $v = trim(org_setting('hd_mail_reply_to'));
    if ($v !== '') return $v;
    $r = db_one("SELECT m.mailbox FROM helpdesk_mail_intake i JOIN poczta_mailboxes m ON m.id=i.mailbox_id WHERE i.is_active=1 ORDER BY i.id LIMIT 1");
    return trim((string)($r['mailbox'] ?? ''));
}

/** Ostatnio przetworzone wiadomości skrzynek: zgłoszenia, odpowiedzi w wątku i pominięte (z powodem). */
function hdMailRecent(int $limit = 15): array
{
    hdMailMigrate();
    $rows = [];
    foreach (db_all("SELECT s.created_at AS at, s.kind, s.from_email, s.subject, t.id AS ticket_id, t.number
                     FROM helpdesk_mail_sources s LEFT JOIN helpdesk_tickets t ON t.id=s.ticket_id ORDER BY s.id DESC LIMIT ?", [$limit]) as $r)
        $rows[] = ['at' => $r['at'], 'what' => $r['kind'] === 'reply' ? 'odpowiedź w wątku' : 'nowe zgłoszenie', 'from' => $r['from_email'], 'subject' => $r['subject'],
                   'ticket_id' => (int)$r['ticket_id'], 'number' => (string)$r['number'], 'note' => ''];
    foreach (db_all("SELECT k.created_at AS at, k.reason, c.from_email, c.subject FROM helpdesk_mail_skips k LEFT JOIN crm_communications c ON c.id=k.comm_id ORDER BY k.created_at DESC LIMIT ?", [$limit]) as $r)
        $rows[] = ['at' => $r['at'], 'what' => 'pominięta', 'from' => (string)$r['from_email'], 'subject' => (string)$r['subject'], 'ticket_id' => 0, 'number' => '', 'note' => (string)$r['reason']];
    usort($rows, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
    return array_slice($rows, 0, $limit);
}
