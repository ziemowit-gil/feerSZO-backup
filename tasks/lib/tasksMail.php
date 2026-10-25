<?php
/**
 * tasks/lib/tasksMail.php — zadania z e-maila.
 *
 * 1. Ręcznie: „Utwórz zadanie” przy wiadomości w Skrzynce CRM i w Poczcie EZD → formularz tasks/router.php?_route=/nowe&comm=ID
 *    (temat → tytuł, treść → opis, wybrane załączniki → pliki zadania, link zwrotny do wiadomości).
 * 2. Automatycznie: skrzynka obszaru (task_mail_intake) — wiadomości przychodzące na wskazaną skrzynkę z modułu Poczta
 *    (te same dane, które zapisuje PocztaScanService; ŻADNYCH nowych uprawnień M365) zamieniają się w zadania;
 *    nadawca dostaje potwierdzenie z numerem z adresu systemowego (context_type spoza listy korespondencji).
 *
 * Duplikaty: task_mail_sources.message_key UNIQUE (Internet Message-ID; ta sama wiadomość ma w crm_communications
 * osobny wiersz per dopasowany kontakt). Pętle: bez zadań i potwierdzeń dla autoresponderów, adresów systemowych,
 * własnych skrzynek i odpowiedzi na nasze potwierdzenie ([ZAD-n] w temacie → komentarz do istniejącego zadania);
 * limit potwierdzeń na nadawcę.
 */
require_once __DIR__ . '/tasksCreate.php';

const TASKS_MAIL_ACK_CONTEXT = 'task_mail_ack';
const TASKS_MAIL_ACK_PER_HOUR = 3;
const TASKS_MAIL_AUTO_SUBJECT = '/^(auto(matic)?[ -]?(reply|response|antwort)|automatyczna odpowied|odpowied[zź] automatyczna|out of office|poza biurem|nieobecno|undeliver|niedostarcz|delivery (status|failure)|mail delivery|returned mail|zwrócona wiadomo|autoreply|abwesenheit|read:|przeczytano:)/iu';
const TASKS_MAIL_AUTO_SENDER = '/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster|bounce|notifications?|powiadomienia)([+.@_-]|$)/i';

function tasksMailMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS task_mail_sources (
        task_id      INTEGER PRIMARY KEY REFERENCES tasks(id) ON DELETE CASCADE,
        comm_id      INTEGER,
        message_key  TEXT NOT NULL UNIQUE,
        source       TEXT NOT NULL DEFAULT 'manual',
        mailbox_id   INTEGER,
        from_email   TEXT NOT NULL DEFAULT '',
        from_name    TEXT NOT NULL DEFAULT '',
        subject      TEXT NOT NULL DEFAULT '',
        received_at  TEXT,
        ack_sent_at  TEXT,
        created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_task_mail_src_comm ON task_mail_sources(comm_id)");
    db()->exec("CREATE TABLE IF NOT EXISTS task_mail_intake (
        workspace_id     INTEGER PRIMARY KEY REFERENCES task_workspaces(id) ON DELETE CASCADE,
        mailbox_id       INTEGER NOT NULL REFERENCES poczta_mailboxes(id) ON DELETE CASCADE,
        list_id          INTEGER NOT NULL REFERENCES task_lists(id) ON DELETE CASCADE,
        assignee_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        priority         INTEGER NOT NULL DEFAULT 2,
        ack_enabled      INTEGER NOT NULL DEFAULT 1,
        is_active        INTEGER NOT NULL DEFAULT 1,
        since_at         TEXT NOT NULL,
        last_run_at      TEXT,
        updated_by       INTEGER,
        updated_at       TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_task_mail_intake_mbx ON task_mail_intake(mailbox_id)");
    db()->exec("CREATE TABLE IF NOT EXISTS task_mail_skips (
        comm_id    INTEGER PRIMARY KEY,
        reason     TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
}

/** Wiadomość z bazy poczty (crm_communications) z nazwą skrzynki. */
function tasksMailComm(int $commId): ?array
{
    require_once dirname(__DIR__, 2) . '/includes/poczta.php';
    return db_one("SELECT c.*, m.mailbox AS mailbox_address FROM crm_communications c
                   LEFT JOIN poczta_mailboxes m ON m.id=c.mailbox_id WHERE c.id=? AND c.channel='email'", [$commId]) ?: null;
}

/** Czy użytkownik może czytać wiadomość: uprawnienie do skrzynki; bez skrzynki — Skrzynka CRM albo EZD. */
function tasksMailCanRead(array $c): bool
{
    require_once dirname(__DIR__, 2) . '/includes/poczta_acl.php';
    if (!empty($c['mailbox_id'])) return poczta_can_access((int)$c['mailbox_id'], 'read');
    require_once dirname(__DIR__, 2) . '/includes/crm_perms.php';
    return is_admin() || crm_can('inbox', 'read') || can_read('ezd');
}

/** Załączniki wiadomości. */
function tasksMailAttachments(int $commId): array
{
    return db_all("SELECT id, original_name, mime_type, size_bytes, stored_path, graph_attachment_id
                   FROM poczta_attachments WHERE communication_id=? ORDER BY id", [$commId]);
}

/** Klucz deduplikacji: Internet Message-ID (wspólny dla wierszy per kontakt), zapasowo id wiersza. */
function tasksMailKey(array $c): string
{
    $k = trim((string)($c['internet_message_id'] ?? ''));
    return $k !== '' ? $k : 'comm:' . (int)$c['id'];
}

/** Zadanie już utworzone z tej wiadomości (dowolny wiersz tej samej wiadomości). */
function tasksMailExistingTask(array $c): ?array
{
    tasksMailMigrate();
    return db_one("SELECT s.task_id, t.title, t.workspace_id FROM task_mail_sources s JOIN tasks t ON t.id=s.task_id
                   WHERE s.message_key=? AND t.deleted_at IS NULL", [tasksMailKey($c)]) ?: null;
}

/** Link do wiadomości w module, z którego przyszła (CRM/EZD). */
function tasksMailBackUrl(int $commId, string $src = 'crm'): string
{
    return $src === 'ezd' ? APP_URL . '/ezd/poczta/thread.php?comm_id=' . $commId : APP_URL . '/crm/inbox.php?msg=' . $commId;
}

/** Opis zadania z treści maila. */
function tasksMailDescription(array $c, string $src = 'crm'): string
{
    $from = trim(($c['from_name'] ?? '') . ' <' . ($c['from_email'] ?? '') . '>');
    $body = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', (string)$c['body'])));
    return "E-mail od: {$from}\nOtrzymano: " . date('d.m.Y H:i', strtotime((string)$c['sent_at']))
        . "\nWiadomość: " . tasksMailBackUrl((int)$c['id'], $src) . "\n\n" . mb_substr($body, 0, 15000);
}

/** Bajty załącznika: z dysku albo dociągnięte z Microsoft Graph (jak crm/api/attachment.php). */
function tasksMailAttachmentBytes(array $a, array $c): ?string
{
    if (!empty($a['stored_path'])) {
        $root = realpath(rtrim(UPLOAD_DIR, '/'));
        $real = realpath(rtrim(UPLOAD_DIR, '/') . '/' . ltrim((string)$a['stored_path'], '/'));
        if ($root && $real && str_starts_with($real, $root) && is_readable($real)) return (string)file_get_contents($real);
    }
    if (empty($a['graph_attachment_id']) || empty($c['outlook_message_id']) || empty($c['mailbox_address'])) return null;
    try {
        require_once dirname(__DIR__, 2) . '/includes/m365.php';
        $bytes = (new M365Graph())->get_attachment_content((string)$c['mailbox_address'], (string)$c['outlook_message_id'], (string)$a['graph_attachment_id']);
        return $bytes !== '' ? $bytes : null;
    } catch (\Throwable $e) {
        error_log('[tasksMail] załącznik #' . $a['id'] . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Zapisuje źródło (e-mail) zadania i kopiuje wybrane załączniki. Zwraca listę ostrzeżeń (pominięte pliki).
 * $attIds = null → wszystkie załączniki.
 */
function tasksMailAttachSource(int $taskId, array $c, string $source, int $byUid, ?array $attIds = null): array
{
    tasksMailMigrate();
    db()->prepare("INSERT INTO task_mail_sources (task_id, comm_id, message_key, source, mailbox_id, from_email, from_name, subject, received_at, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$taskId, (int)$c['id'], tasksMailKey($c), $source, $c['mailbox_id'] ?: null, (string)$c['from_email'],
                   (string)$c['from_name'], (string)$c['subject'], (string)$c['sent_at'], date('Y-m-d H:i:s')]);
    task_log($taskId, $byUid, 'mail_source', null, trim(($c['from_name'] ?: $c['from_email']) . ': ' . $c['subject']),
        ['comm_id' => (int)$c['id'], 'source' => $source]);
    $warn = [];
    foreach (tasksMailAttachments((int)$c['id']) as $a) {
        if ($attIds !== null && !in_array((int)$a['id'], $attIds, true)) continue;
        $bytes = tasksMailAttachmentBytes($a, $c);
        if ($bytes === null) { $warn[] = $a['original_name'] . ': nie udało się pobrać pliku ze skrzynki.'; continue; }
        if ($e = tasksCreateAttachBytes($taskId, (string)$a['original_name'], $bytes, $byUid)) $warn[] = $e;
    }
    return $warn;
}

/** Źródło e-mail zadania (do karty zadania). */
function tasksMailSourceOf(int $taskId): ?array
{
    try {
        tasksMailMigrate();
        return db_one("SELECT * FROM task_mail_sources WHERE task_id=?", [$taskId]) ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

// ── Skrzynka obszaru ─────────────────────────────────────────────────────────

function tasksMailIntakeFor(int $wsId): ?array
{
    tasksMailMigrate();
    return db_one("SELECT i.*, m.mailbox, m.display_name FROM task_mail_intake i LEFT JOIN poczta_mailboxes m ON m.id=i.mailbox_id
                   WHERE i.workspace_id=?", [$wsId]) ?: null;
}

/** Skrzynki, które lider może podpiąć (musi mieć do nich dostęp do odczytu). */
function tasksMailMailboxesFor(int $uid): array
{
    require_once dirname(__DIR__, 2) . '/includes/poczta.php';
    require_once dirname(__DIR__, 2) . '/includes/poczta_acl.php';
    $rows = db_all("SELECT id, mailbox, display_name, enabled, status FROM poczta_mailboxes ORDER BY mailbox");
    // Bieżący użytkownik bez jawnego id — poczta_can_access() przepuszcza wtedy administratora
    $self = (int)(current_user()['id'] ?? 0) === $uid;
    return array_values(array_filter($rows, fn($m) => poczta_can_access((int)$m['id'], 'read', $self ? null : $uid)));
}

function tasksMailIntakeSave(int $wsId, array $in, int $byUid): void
{
    tasksMailMigrate();
    $mbx = (int)($in['mailbox_id'] ?? 0);
    if (!$mbx) {
        db()->prepare("DELETE FROM task_mail_intake WHERE workspace_id=?")->execute([$wsId]);
        return;
    }
    if (!in_array($mbx, array_map('intval', array_column(tasksMailMailboxesFor($byUid), 'id')), true)) {
        throw new \RuntimeException('Nie masz dostępu do tej skrzynki.');
    }
    $other = db_one("SELECT w.name FROM task_mail_intake i JOIN task_workspaces w ON w.id=i.workspace_id WHERE i.mailbox_id=? AND i.workspace_id<>?", [$mbx, $wsId]);
    if ($other) throw new \RuntimeException('Ta skrzynka zasila już obszar „' . $other['name'] . '”.');
    $list = db_one("SELECT id FROM task_lists WHERE id=? AND workspace_id=?", [(int)($in['list_id'] ?? 0), $wsId]);
    if (!$list) throw new \RuntimeException('Wybierz kolumnę dla nowych zadań.');
    $asg = (int)($in['assignee_user_id'] ?? 0) ?: null;
    $cur = tasksMailIntakeFor($wsId);
    // Nowa skrzynka → tylko wiadomości od teraz (bez zalewu zadaniami ze starej poczty)
    $since = ($cur && (int)$cur['mailbox_id'] === $mbx) ? $cur['since_at'] : date('Y-m-d H:i:s');
    db()->prepare("INSERT INTO task_mail_intake (workspace_id, mailbox_id, list_id, assignee_user_id, priority, ack_enabled, is_active, since_at, updated_by, updated_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?)
                   ON CONFLICT(workspace_id) DO UPDATE SET mailbox_id=excluded.mailbox_id, list_id=excluded.list_id,
                     assignee_user_id=excluded.assignee_user_id, priority=excluded.priority, ack_enabled=excluded.ack_enabled,
                     is_active=excluded.is_active, since_at=excluded.since_at, updated_by=excluded.updated_by, updated_at=excluded.updated_at")
        ->execute([$wsId, $mbx, (int)$list['id'], $asg, max(1, min(4, (int)($in['priority'] ?? 2))),
                   !empty($in['ack_enabled']) ? 1 : 0, !empty($in['is_active']) ? 1 : 0, $since, $byUid, date('Y-m-d H:i:s')]);
}

/** Adresy, z których nie tworzymy zadań ani nie wysyłamy potwierdzeń (własne skrzynki i nadawcy systemowi). */
function tasksMailOwnAddresses(): array
{
    $own = array_map('strtolower', array_column(db_all("SELECT mailbox FROM poczta_mailboxes"), 'mailbox'));
    foreach (['system_mail_from', 'system_mail_reply_to', 'notify_from_email', 'smtp_from_email', 'm365_send_from_email'] as $k) {
        $v = strtolower(trim((string)(db_one("SELECT value FROM settings WHERE key_=?", [$k])['value'] ?? '')));
        if ($v !== '') $own[] = $v;
    }
    return array_values(array_unique(array_filter($own)));
}

/** Powód pominięcia wiadomości przez automat albo null. */
function tasksMailSkipReason(array $c, array $own): ?string
{
    $from = strtolower(trim((string)$c['from_email']));
    if ($from === '') return 'brak nadawcy';
    if (in_array($from, $own, true)) return 'nadawca to skrzynka/adres systemu';
    if (preg_match(TASKS_MAIL_AUTO_SENDER, $from)) return 'adres automatyczny';
    if (preg_match(TASKS_MAIL_AUTO_SUBJECT, trim((string)$c['subject']))) return 'odpowiedź automatyczna';
    return null;
}

/**
 * Przebieg automatu (cron co 5 min, po skanowaniu skrzynek). Zwraca statystykę.
 * $dryRun — tylko liczy, nic nie zapisuje.
 */
function tasksMailIntakeRun(bool $dryRun = false): array
{
    tasksMailMigrate();
    $stats = ['tasks' => 0, 'comments' => 0, 'skipped' => 0, 'acks' => 0];
    $own = tasksMailOwnAddresses();
    $intakes = db_all("SELECT i.*, w.name AS ws_name FROM task_mail_intake i JOIN task_workspaces w ON w.id=i.workspace_id AND w.is_active=1
                       WHERE i.is_active=1");
    foreach ($intakes as $in) {
        $creator = (int)($in['updated_by'] ?: db_one("SELECT created_by FROM task_workspaces WHERE id=?", [(int)$in['workspace_id']])['created_by']);
        $rows = db_all(
            "SELECT c.*, m.mailbox AS mailbox_address FROM crm_communications c JOIN poczta_mailboxes m ON m.id=c.mailbox_id
             WHERE c.mailbox_id=? AND c.direction='in' AND c.channel='email' AND c.sent_at >= ?
               AND c.id = (SELECT MIN(c2.id) FROM crm_communications c2 WHERE c2.mailbox_id=c.mailbox_id
                           AND COALESCE(NULLIF(c2.internet_message_id,''), 'comm:'||c2.id) = COALESCE(NULLIF(c.internet_message_id,''), 'comm:'||c.id))
               AND NOT EXISTS (SELECT 1 FROM task_mail_skips s WHERE s.comm_id=c.id)
               AND NOT EXISTS (SELECT 1 FROM task_mail_sources s WHERE s.message_key = COALESCE(NULLIF(c.internet_message_id,''), 'comm:'||c.id))
             ORDER BY c.sent_at LIMIT 50",
            [(int)$in['mailbox_id'], $in['since_at']]
        );
        foreach ($rows as $c) {
            if ($dryRun) { $stats['tasks']++; continue; }
            try {
                $stats[tasksMailIntakeOne($in, $c, $own, $creator)]++;
            } catch (\Throwable $e) {
                error_log('[tasksMail intake] wiadomość #' . $c['id'] . ': ' . $e->getMessage());
                db()->prepare("INSERT OR IGNORE INTO task_mail_skips (comm_id, reason) VALUES (?, ?)")->execute([(int)$c['id'], 'błąd: ' . mb_substr($e->getMessage(), 0, 200)]);
                $stats['skipped']++;
            }
        }
        if (!$dryRun) db()->prepare("UPDATE task_mail_intake SET last_run_at=? WHERE workspace_id=?")->execute([date('Y-m-d H:i:s'), (int)$in['workspace_id']]);
    }
    $stats['acks'] = $GLOBALS['_tasksMailAcks'] ?? 0;
    return $stats;
}

/** Jedna wiadomość ze skrzynki obszaru → 'tasks' | 'comments' | 'skipped'. */
function tasksMailIntakeOne(array $in, array $c, array $own, int $creator): string
{
    $skip = function (string $why) use ($c): string {
        db()->prepare("INSERT OR IGNORE INTO task_mail_skips (comm_id, reason) VALUES (?, ?)")->execute([(int)$c['id'], $why]);
        return 'skipped';
    };
    if ($why = tasksMailSkipReason($c, $own)) return $skip($why);

    // Odpowiedź na nasze potwierdzenie ([ZAD-n] w temacie) → komentarz w zadaniu tego obszaru, bez nowego zadania
    if (preg_match('/\[ZAD-(\d+)\]/', (string)$c['subject'], $m)) {
        $t = db_one("SELECT id FROM tasks WHERE id=? AND workspace_id=? AND deleted_at IS NULL", [(int)$m[1], (int)$in['workspace_id']]);
        if ($t) {
            $body = 'E-mail od ' . trim($c['from_name'] . ' <' . $c['from_email'] . '>') . ":\n\n" . mb_substr(trim((string)$c['body']), 0, 8000);
            $now = date('Y-m-d H:i:s');
            db_insert('task_comments', ['task_id' => (int)$t['id'], 'author_id' => $creator, 'body' => $body,
                                        'created_at' => $now, 'updated_at' => $now]);
            task_log((int)$t['id'], $creator, 'comment_added', null, 'E-mail od ' . $c['from_email']);
            $skip('odpowiedź dopisana do zadania #' . (int)$t['id']);
            return 'comments';
        }
    }

    $taskId = tasksCreate([
        'workspace_id' => (int)$in['workspace_id'], 'list_id' => (int)$in['list_id'],
        'title' => trim((string)$c['subject']) ?: '(bez tematu)', 'description' => tasksMailDescription($c, 'crm'),
        'priority' => (int)$in['priority'], 'assignees' => $in['assignee_user_id'] ? [(int)$in['assignee_user_id']] : [],
        'log_meta' => ['from_mailbox' => (int)$in['mailbox_id']],
    ], $creator);
    tasksMailAttachSource($taskId, $c, 'mailbox', $creator);
    if ((int)$in['ack_enabled']) tasksMailAck($taskId, $c, (string)$in['ws_name']);
    return 'tasks';
}

/** Potwierdzenie dla nadawcy z numerem zadania (adres systemowy, limit na nadawcę). */
function tasksMailAck(int $taskId, array $c, string $wsName): bool
{
    $to = strtolower(trim((string)$c['from_email']));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $recent = (int)(db_one("SELECT COUNT(*) AS n FROM task_mail_sources WHERE lower(from_email)=? AND ack_sent_at >= ?",
        [$to, date('Y-m-d H:i:s', strtotime('-1 hour'))])['n'] ?? 0);
    if ($recent >= TASKS_MAIL_ACK_PER_HOUR) return false;

    require_once dirname(__DIR__, 2) . '/includes/mail_queue.php';
    $org = function_exists('org_setting') ? (org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '')) : '';
    $subj = 'Przyjęliśmy zgłoszenie [ZAD-' . $taskId . ']: ' . mb_substr(trim((string)$c['subject']) ?: '(bez tematu)', 0, 150);
    $html = '<p>Dzień dobry,</p><p>Twoja wiadomość <strong>„' . htmlspecialchars((string)$c['subject']) . '”</strong> została przyjęta'
        . ' i zarejestrowana jako zadanie nr <strong>ZAD-' . $taskId . '</strong>'
        . ($wsName !== '' ? ' (' . htmlspecialchars($wsName) . ')' : '') . '.</p>'
        . '<p>Jeśli chcesz coś dopisać, odpowiedz na tę wiadomość, zostawiając numer <strong>[ZAD-' . $taskId . ']</strong> w temacie.</p>'
        . '<p style="color:#64748b;font-size:12px">To automatyczne potwierdzenie' . ($org !== '' ? ' — ' . htmlspecialchars($org) : '') . '.</p>';
    $id = mail_queue_add($to, (string)$c['from_name'], $subj, $html, '', TASKS_MAIL_ACK_CONTEXT, $taskId,
        '', false, [], '', [], [], ['Auto-Submitted' => 'auto-replied', 'X-Auto-Response-Suppress' => 'All']);
    if ($id) {
        db()->prepare("UPDATE task_mail_sources SET ack_sent_at=? WHERE task_id=?")->execute([date('Y-m-d H:i:s'), $taskId]);
        $GLOBALS['_tasksMailAcks'] = ($GLOBALS['_tasksMailAcks'] ?? 0) + 1;
    }
    return (bool)$id;
}
