<?php
/**
 * tasks/lib/tasksMail.php — zadania z e-maila.
 *
 * 1. Ręcznie: „Utwórz zadanie” przy wiadomości w Skrzynce CRM i w Poczcie EZD → formularz tasks/router.php?_route=/nowe&comm=ID
 *    (temat → tytuł, treść → opis, wybrane załączniki → pliki zadania, link zwrotny do wiadomości).
 * 2. Automatycznie: skrzynka obszaru (task_mail_imap) — wiadomości przychodzące na skrzynkę obszaru, czytaną przez IMAP
 *    (modules/mailImap; od 2026-11-13 bez Microsoft 365 i bez modułu Poczta), zamieniają się w zadania; nadawca dostaje
 *    potwierdzenie z numerem z adresu systemowego (context_type spoza listy korespondencji).
 *
 * Duplikaty: task_mail_sources.message_key UNIQUE (Internet Message-ID; ta sama wiadomość ma w crm_communications
 * osobny wiersz per dopasowany kontakt, a w skrzynce obszaru może przyjść kilka razy). Pętle: bez zadań i potwierdzeń dla autoresponderów, adresów systemowych,
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
    // task_mail_intake (skrzynka obszaru z modułu Poczta / Microsoft 365) — wycofane 2026-11-13, zastąpione przez task_mail_imap
    db()->exec("CREATE TABLE IF NOT EXISTS task_mail_skips (
        comm_id    INTEGER PRIMARY KEY,
        reason     TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    // Skrzynka obszaru przez IMAP (2026-11-13; dawne task_mail_intake z modułu Poczta/Microsoft 365 nie jest już czytane)
    db()->exec("CREATE TABLE IF NOT EXISTS task_mail_imap (
        workspace_id     INTEGER PRIMARY KEY REFERENCES task_workspaces(id) ON DELETE CASCADE,
        address          TEXT NOT NULL,
        host             TEXT NOT NULL,
        port             INTEGER NOT NULL DEFAULT 993,
        security         TEXT NOT NULL DEFAULT 'ssl',
        user             TEXT NOT NULL,
        pass_enc         TEXT NOT NULL,
        list_id          INTEGER NOT NULL REFERENCES task_lists(id) ON DELETE CASCADE,
        assignee_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        priority         INTEGER NOT NULL DEFAULT 2,
        ack_enabled      INTEGER NOT NULL DEFAULT 1,
        is_active        INTEGER NOT NULL DEFAULT 1,
        uidvalidity      INTEGER NOT NULL DEFAULT 0,
        last_uid         INTEGER NOT NULL DEFAULT 0,
        since_at         TEXT NOT NULL,
        last_run_at      TEXT,
        last_error       TEXT NOT NULL DEFAULT '',
        updated_by       INTEGER,
        updated_at       TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_task_mail_imap_addr ON task_mail_imap(address)");
    db()->exec("CREATE TABLE IF NOT EXISTS task_mail_imap_skips (
        message_key  TEXT NOT NULL,
        workspace_id INTEGER NOT NULL,
        from_email   TEXT NOT NULL DEFAULT '',
        subject      TEXT NOT NULL DEFAULT '',
        reason       TEXT NOT NULL,
        created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (workspace_id, message_key)
    )");
}

const TASKS_MAIL_IMAP_CTX = 'task_mail_imap.pass';

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

/**
 * Bajty załącznika wiadomości z modułu Poczta (Skrzynka CRM, Poczta EZD) — tylko plik zapisany na dysku przy skanowaniu.
 * Od 2026-11-13 Zadania nie dociągają brakujących plików z Microsoft Graph; taki załącznik trafia do ostrzeżeń.
 */
function tasksMailAttachmentBytes(array $a, array $c): ?string
{
    if (empty($a['stored_path'])) return null;
    $root = realpath(rtrim(UPLOAD_DIR, '/'));
    $real = realpath(rtrim(UPLOAD_DIR, '/') . '/' . ltrim((string)$a['stored_path'], '/'));
    return ($root && $real && str_starts_with($real, $root) && is_readable($real)) ? (string)file_get_contents($real) : null;
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
        if ($bytes === null) { $warn[] = $a['original_name'] . ': pliku nie ma na serwerze SZO (skanowanie poczty go nie pobrało).'; continue; }
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

// ── Skrzynka obszaru (IMAP) ──────────────────────────────────────────────────

function tasksMailIntakeFor(int $wsId): ?array
{
    tasksMailMigrate();
    return db_one("SELECT * FROM task_mail_imap WHERE workspace_id=?", [$wsId]) ?: null;
}

/**
 * Zapis ustawień skrzynki obszaru (lider). Puste hasło = bez zmiany; pusty adres = odłączenie skrzynki.
 * Nowa skrzynka zaczyna od wiadomości, które przyjdą od tej chwili. Zwraca komunikat testu logowania (null = działa).
 */
function tasksMailIntakeSave(int $wsId, array $in, int $byUid): ?string
{
    tasksMailMigrate();
    require_once dirname(__DIR__, 2) . '/modules/mailImap/lib/ImapAccount.php';
    $addr = strtolower(trim((string)($in['address'] ?? '')));
    if ($addr === '') {
        db()->prepare("DELETE FROM task_mail_imap WHERE workspace_id=?")->execute([$wsId]);
        return null;
    }
    if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Podaj adres skrzynki, np. rekrutacja@feer.org.pl.');
    $other = db_one("SELECT w.name FROM task_mail_imap i JOIN task_workspaces w ON w.id=i.workspace_id WHERE i.address=? AND i.workspace_id<>?", [$addr, $wsId]);
    if ($other) throw new \RuntimeException('Ta skrzynka zasila już obszar „' . $other['name'] . '”.');
    $list = db_one("SELECT id FROM task_lists WHERE id=? AND workspace_id=?", [(int)($in['list_id'] ?? 0), $wsId]);
    if (!$list) throw new \RuntimeException('Wybierz kolumnę dla nowych zadań.');
    $cur = tasksMailIntakeFor($wsId);
    try {
        $acc = ImapAccount::normalize(['host' => $in['host'] ?? '', 'port' => $in['port'] ?? '', 'security' => $in['security'] ?? 'ssl',
                                       'user' => trim((string)($in['user'] ?? '')) ?: $addr, 'pass' => (string)($in['pass'] ?? '')],
                                      TASKS_MAIL_IMAP_CTX, $cur && $cur['address'] === $addr ? (string)$cur['pass_enc'] : '');
    } catch (\InvalidArgumentException $e) {
        throw new \RuntimeException($e->getMessage());
    }
    $asg = (int)($in['assignee_user_id'] ?? 0) ?: null;
    // Inna skrzynka (adres, serwer albo login) → kursor od nowa: tylko wiadomości od teraz
    $same = $cur && $cur['address'] === $addr && $cur['host'] === $acc['host'] && $cur['user'] === $acc['user'];
    db()->prepare("INSERT INTO task_mail_imap (workspace_id, address, host, port, security, user, pass_enc, list_id, assignee_user_id, priority,
                     ack_enabled, is_active, uidvalidity, last_uid, since_at, last_error, updated_by, updated_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'',?,?)
                   ON CONFLICT(workspace_id) DO UPDATE SET address=excluded.address, host=excluded.host, port=excluded.port,
                     security=excluded.security, user=excluded.user, pass_enc=excluded.pass_enc, list_id=excluded.list_id,
                     assignee_user_id=excluded.assignee_user_id, priority=excluded.priority, ack_enabled=excluded.ack_enabled,
                     is_active=excluded.is_active, uidvalidity=excluded.uidvalidity, last_uid=excluded.last_uid, since_at=excluded.since_at,
                     last_error='', updated_by=excluded.updated_by, updated_at=excluded.updated_at")
        ->execute([$wsId, $addr, $acc['host'], $acc['port'], $acc['security'], $acc['user'], $acc['pass_enc'], (int)$list['id'], $asg,
                   max(1, min(4, (int)($in['priority'] ?? 2))), !empty($in['ack_enabled']) ? 1 : 0, !empty($in['is_active']) ? 1 : 0,
                   $same ? (int)$cur['uidvalidity'] : 0, $same ? (int)$cur['last_uid'] : 0,
                   $same ? $cur['since_at'] : date('Y-m-d H:i:s'), $byUid, date('Y-m-d H:i:s')]);
    $a = ImapAccount::fromStored(tasksMailIntakeFor($wsId) ?? [], TASKS_MAIL_IMAP_CTX);
    return $a ? $a->test() : 'Nie udało się odczytać zapisanego hasła.';
}

/** Adresy, z których nie tworzymy zadań ani nie wysyłamy potwierdzeń (własne skrzynki i nadawcy systemowi). */
function tasksMailOwnAddresses(): array
{
    tasksMailMigrate();
    $own = array_map('strtolower', array_column(db_all("SELECT address FROM task_mail_imap"), 'address'));
    try { $own = array_merge($own, array_map('strtolower', array_column(db_all("SELECT mailbox FROM poczta_mailboxes"), 'mailbox'))); } catch (\Throwable $e) {}
    foreach (['system_mail_from', 'system_mail_reply_to', 'notify_from_email', 'smtp_from_email', 'tasks_mail_reply_address'] as $k) {
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
    if (!empty($c['auto'])) return 'odpowiedź automatyczna';
    if (preg_match(TASKS_MAIL_AUTO_SENDER, $from)) return 'adres automatyczny';
    if (preg_match(TASKS_MAIL_AUTO_SUBJECT, trim((string)$c['subject']))) return 'odpowiedź automatyczna';
    return null;
}

/**
 * Przebieg automatu (cron co 5 min). Każda aktywna skrzynka obszaru: logowanie IMAP, wiadomości od kursora (najwyżej 50),
 * zadania albo komentarze. Zwraca statystykę. $dryRun — tylko liczy nowe wiadomości, nic nie zapisuje ani nie przesuwa kursora.
 */
function tasksMailIntakeRun(bool $dryRun = false): array
{
    tasksMailMigrate();
    require_once dirname(__DIR__, 2) . '/modules/mailImap/lib/ImapAccount.php';
    $stats = ['tasks' => 0, 'comments' => 0, 'skipped' => 0, 'acks' => 0, 'errors' => 0];
    $own = tasksMailOwnAddresses();
    $intakes = db_all("SELECT i.*, w.name AS ws_name, w.created_by AS ws_creator FROM task_mail_imap i
                       JOIN task_workspaces w ON w.id=i.workspace_id AND w.is_active=1 WHERE i.is_active=1");
    foreach ($intakes as $in) {
        $wsId = (int)$in['workspace_id'];
        $creator = (int)($in['updated_by'] ?: $in['ws_creator']);
        $acc = ImapAccount::fromStored($in, TASKS_MAIL_IMAP_CTX);
        $err = '';
        try {
            if (!$acc) throw new \RuntimeException('Nie udało się odczytać zapisanego hasła skrzynki.');
            $imap = $acc->open();
            $res = $acc->fetchNew($imap, (int)$in['uidvalidity'], (int)$in['last_uid']);
            $since = strtotime((string)$in['since_at']) ?: 0;
            foreach ($res['messages'] as $uid => $msg) {
                if ($msg === null) { $stats['skipped']++; continue; }
                $c = tasksMailFromMime($msg, (string)$in['address'], (int)$res['cursor'][0], (int)$uid);
                if ($c['received'] < $since - 86400) continue;   // stara wiadomość dopisana do skrzynki wstecz
                if ($dryRun) { $stats['tasks']++; continue; }
                if (db_one("SELECT 1 AS x FROM task_mail_sources WHERE message_key=?", [$c['internet_message_id']])
                    || db_one("SELECT 1 AS x FROM task_mail_imap_skips WHERE workspace_id=? AND message_key=?", [$wsId, $c['internet_message_id']])) continue;
                try {
                    $stats[tasksMailIntakeOne($in, $c, $own, $creator, $msg->attachments)]++;
                } catch (\Throwable $e) {
                    error_log('[tasksMail intake] obszar ' . $wsId . ', UID ' . $uid . ': ' . $e->getMessage());
                    tasksMailImapSkip($wsId, $c, 'błąd: ' . mb_substr($e->getMessage(), 0, 200));
                    $stats['skipped']++;
                }
            }
            if (!$dryRun) db()->prepare("UPDATE task_mail_imap SET uidvalidity=?, last_uid=? WHERE workspace_id=?")
                               ->execute([(int)$res['cursor'][0], (int)$res['cursor'][1], $wsId]);
            $imap->logout();
        } catch (\Throwable $e) {
            $err = mb_substr($e->getMessage(), 0, 300);
            $stats['errors']++;
            error_log('[tasksMail intake] obszar ' . $wsId . ': ' . $err);
        }
        if (!$dryRun) db()->prepare("UPDATE task_mail_imap SET last_run_at=?, last_error=? WHERE workspace_id=?")->execute([date('Y-m-d H:i:s'), $err, $wsId]);
    }
    $stats['acks'] = $GLOBALS['_tasksMailAcks'] ?? 0;
    return $stats;
}

/** Wiadomość z IMAP w kształcie wiersza poczty (pola używane przez opis zadania, potwierdzenie i filtry). */
function tasksMailFromMime(MimeMessage $m, string $mailbox, int $uidValidity, int $uid): array
{
    $ts = $m->date ?? time();
    return [
        'id' => 0, 'mailbox_id' => null, 'mailbox_address' => $mailbox,
        'internet_message_id' => $m->messageId !== '' ? $m->messageId : 'imap:' . $mailbox . ':' . $uidValidity . ':' . $uid,
        'from_email' => $m->fromEmail, 'from_name' => $m->fromName, 'subject' => $m->subject,
        'body' => $m->text, 'sent_at' => date('Y-m-d H:i:s', $ts), 'received' => $ts, 'auto' => $m->isAutomatic(),
    ];
}

function tasksMailImapSkip(int $wsId, array $c, string $why): void
{
    db()->prepare("INSERT OR IGNORE INTO task_mail_imap_skips (message_key, workspace_id, from_email, subject, reason) VALUES (?,?,?,?,?)")
        ->execute([(string)$c['internet_message_id'], $wsId, mb_substr((string)$c['from_email'], 0, 200), mb_substr((string)$c['subject'], 0, 300), $why]);
}

/**
 * Jedna wiadomość ze skrzynki obszaru → 'tasks' | 'comments' | 'skipped'.
 * $attachments: [['name','mime','bytes']] z MimeMessage.
 */
function tasksMailIntakeOne(array $in, array $c, array $own, int $creator, array $attachments = []): string
{
    $wsId = (int)$in['workspace_id'];
    $skip = function (string $why) use ($c, $wsId): string {
        tasksMailImapSkip($wsId, $c, $why);
        return 'skipped';
    };
    if ($why = tasksMailSkipReason($c, $own)) return $skip($why);

    // Odpowiedź na nasze potwierdzenie ([ZAD-n] w temacie) → komentarz w zadaniu tego obszaru, bez nowego zadania
    if (preg_match('/\[ZAD-(\d+)\]/', (string)$c['subject'], $m)) {
        $t = db_one("SELECT id FROM tasks WHERE id=? AND workspace_id=? AND deleted_at IS NULL", [(int)$m[1], $wsId]);
        if ($t) {
            $body = 'E-mail od ' . trim($c['from_name'] . ' <' . $c['from_email'] . '>') . ":\n\n" . mb_substr(trim((string)$c['body']), 0, 8000);
            $now = date('Y-m-d H:i:s');
            db_insert('task_comments', ['task_id' => (int)$t['id'], 'author_id' => $creator, 'body' => $body,
                                        'created_at' => $now, 'updated_at' => $now]);
            task_log((int)$t['id'], $creator, 'comment_added', null, 'E-mail od ' . $c['from_email']);
            foreach ($attachments as $a) tasksCreateAttachBytes((int)$t['id'], (string)$a['name'], (string)$a['bytes'], $creator);
            $skip('odpowiedź dopisana do zadania #' . (int)$t['id']);
            return 'comments';
        }
    }

    $from = trim(($c['from_name'] ?? '') . ' <' . ($c['from_email'] ?? '') . '>');
    $body = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', (string)$c['body'])));
    $taskId = tasksCreate([
        'workspace_id' => $wsId, 'list_id' => (int)$in['list_id'],
        'title' => trim((string)$c['subject']) ?: '(bez tematu)',
        'description' => "E-mail od: {$from}\nOtrzymano: " . date('d.m.Y H:i', strtotime((string)$c['sent_at']))
                       . "\nSkrzynka: " . $in['address'] . "\n\n" . mb_substr($body, 0, 15000),
        'priority' => (int)$in['priority'], 'assignees' => $in['assignee_user_id'] ? [(int)$in['assignee_user_id']] : [],
        'log_meta' => ['from_mailbox' => (string)$in['address']],
    ], $creator);
    db()->prepare("INSERT INTO task_mail_sources (task_id, comm_id, message_key, source, mailbox_id, from_email, from_name, subject, received_at, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$taskId, null, (string)$c['internet_message_id'], 'mailbox', null, (string)$c['from_email'], (string)$c['from_name'],
                   (string)$c['subject'], (string)$c['sent_at'], date('Y-m-d H:i:s')]);
    task_log($taskId, $creator, 'mail_source', null, trim(($c['from_name'] ?: $c['from_email']) . ': ' . $c['subject']),
        ['mailbox' => (string)$in['address'], 'source' => 'mailbox']);
    foreach ($attachments as $a) {
        if ($e = tasksCreateAttachBytes($taskId, (string)$a['name'], (string)$a['bytes'], $creator)) error_log('[tasksMail intake] ZAD-' . $taskId . ': ' . $e);
    }
    if ((int)$in['ack_enabled']) tasksMailAck($taskId, $c, (string)$in['ws_name']);
    return 'tasks';
}

/** Potwierdzenie dla nadawcy z numerem zadania (adres systemowy, limit na nadawcę). */
function tasksMailAck(int $taskId, array $c, string $wsName): bool
{
    $to = strtolower(trim((string)$c['from_email']));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    require_once dirname(__DIR__, 2) . '/includes/office_hours.php';
    if (!oh_acks_allowed()) return false;   // bramka Godzin pracy: potwierdzenia tylko poza godzinami pracy (gdy włączona)
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
