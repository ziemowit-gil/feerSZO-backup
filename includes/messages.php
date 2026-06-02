<?php
/**
 * Moduł wiadomości prywatnych
 * context_type:  'contract' | 'onboarding'
 * sender_type:   'admin'    | 'user'
 * recipient_type:'admin'    | 'opiekun'  (kto ma odpowiedzieć)
 * subject:       temat wiadomości (opcjonalny)
 */

function msg_ensure_table(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS messages (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        context_type   TEXT NOT NULL DEFAULT 'contract',
        context_id     INTEGER NOT NULL,
        contract_type  TEXT NOT NULL DEFAULT '',
        sender_type    TEXT NOT NULL DEFAULT 'admin',
        sender_id      INTEGER,
        sender_name    TEXT NOT NULL DEFAULT '',
        body           TEXT NOT NULL DEFAULT '',
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_read        INTEGER NOT NULL DEFAULT 0,
        recipient_type TEXT NOT NULL DEFAULT 'admin',
        subject        TEXT NOT NULL DEFAULT '',
        type_id        INTEGER DEFAULT NULL
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_messages_ctx ON messages(context_type, context_id)");
    // Migrations for older installs
    try { db()->exec("ALTER TABLE messages ADD COLUMN recipient_type TEXT NOT NULL DEFAULT 'admin'"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE messages ADD COLUMN subject TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE messages ADD COLUMN type_id INTEGER DEFAULT NULL"); } catch (\Throwable $e) {}
}

// ── jednorazowa migracja przy pierwszym załadowaniu ───────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->exec("ALTER TABLE messages ADD COLUMN recipient_type TEXT NOT NULL DEFAULT 'admin'"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE messages ADD COLUMN subject TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE messages ADD COLUMN type_id INTEGER DEFAULT NULL"); } catch (\Throwable $e) {}
    // v2: direct user-to-user (np. zadania)
    try { db()->exec("ALTER TABLE messages ADD COLUMN recipient_id INTEGER DEFAULT NULL"); } catch (\Throwable $e) {}
    try { db()->exec("CREATE INDEX IF NOT EXISTS idx_messages_recipient ON messages(recipient_id, is_read)"); } catch (\Throwable $e) {}
})();

function msg_thread(string $ctx_type, int $ctx_id): array {
    try {
        return db_all(
            "SELECT * FROM messages WHERE context_type=? AND context_id=? ORDER BY created_at ASC",
            [$ctx_type, $ctx_id]
        );
    } catch (\Throwable $e) { msg_ensure_table(); return []; }
}

function pmsg_send(
    string $ctx_type, int $ctx_id, string $contract_type,
    string $sender_type, ?int $sender_id, string $sender_name,
    string $body,
    string $recipient_type = 'admin',
    string $subject = '',
    ?int $type_id = null
): int {
    try {
        return db_insert('messages', [
            'context_type'   => $ctx_type,
            'context_id'     => $ctx_id,
            'contract_type'  => $contract_type,
            'sender_type'    => $sender_type,
            'sender_id'      => $sender_id,
            'sender_name'    => $sender_name,
            'body'           => $body,
            'created_at'     => date('Y-m-d H:i:s'),
            'is_read'        => 0,
            'recipient_type' => $recipient_type,
            'subject'        => $subject,
            'type_id'        => $type_id,
        ]);
    } catch (\Throwable $e) { msg_ensure_table(); return 0; }
}

/** Oznacz wiadomości jako przeczytane przez odbiorcę $reader_type */
function msg_mark_read(string $ctx_type, int $ctx_id, string $reader_type): void {
    // reader jest odbiorcą wiadomości wysłanych przez DRUGĄ stronę
    $sender = ($reader_type === 'admin') ? 'user' : 'admin';
    try {
        db()->prepare(
            "UPDATE messages SET is_read=1 WHERE context_type=? AND context_id=? AND sender_type=? AND is_read=0"
        )->execute([$ctx_type, $ctx_id, $sender]);
    } catch (\Throwable $e) {}
}

/** Liczba nieprzeczytanych wiadomości dla admina (wysłane przez userów) */
function msg_unread_admin(): int {
    try {
        $r = db_one("SELECT COUNT(*) AS c FROM messages WHERE sender_type='user' AND is_read=0");
        return (int)($r['c'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

/** Liczba nieprzeczytanych dla danego wątku z perspektywy reader_type */
function msg_unread_thread(string $ctx_type, int $ctx_id, string $reader_type): int {
    $sender = ($reader_type === 'admin') ? 'user' : 'admin';
    try {
        $r = db_one(
            "SELECT COUNT(*) AS c FROM messages WHERE context_type=? AND context_id=? AND sender_type=? AND is_read=0",
            [$ctx_type, $ctx_id, $sender]
        );
        return (int)($r['c'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

/** Liczba nieprzeczytanych wiadomości dla konkretnego usera (na podstawie listy jego umów) */
function msg_unread_user(array $contracts): int {
    if (!$contracts) return 0;
    $total = 0;
    foreach ($contracts as $c) {
        $total += msg_unread_thread('contract', (int)$c['id'], 'user');
    }
    return $total;
}

/** Pobierz nazwę typu wiadomości po ID (lub pusty string) */
function msg_type_name(?int $type_id): string {
    if (!$type_id) return '';
    try {
        $r = db_one("SELECT name FROM message_types WHERE id=?", [$type_id]);
        return $r['name'] ?? '';
    } catch (\Throwable $e) { return ''; }
}

/**
 * Wyślij powiadomienie email przy nowej wiadomości.
 *
 * @param string $direction  'to_admin' — notify supervisor/admin
 *                           'to_user'  — notify user
 * @param string $body       Treść wiadomości (plain text)
 * @param string $subject    Temat wiadomości
 */
function msg_send_notify(
    string $ctx_type, int $ctx_id, string $contract_type,
    string $direction, string $body, string $subject = ''
): void {
    // Sprawdź ustawienie globalnego wyłącznika
    require_once __DIR__ . '/functions.php';
    require_once __DIR__ . '/approval.php';

    $setting_key = ($direction === 'to_admin')
        ? 'msg_notify_admin_on_question'
        : 'msg_notify_user_on_reply';

    $enabled = org_setting($setting_key);
    // Domyślnie włączone (gdy ustawienia brak)
    if ($enabled === '0') return;

    $org      = defined('ORG_NAME') ? ORG_NAME : '';
    $view_url = APP_URL . '/contracts/' . $contract_type . '/view.php?id=' . $ctx_id;

    // ── Pobierz numer umowy ───────────────────────────────────────────────
    $contract_numer = '';
    $person_name    = '';
    if ($ctx_type === 'contract' && $contract_type) {
        try {
            $r = db_one(
                "SELECT numer_umowy, imie_nazwisko FROM umowy_{$contract_type} WHERE id=?",
                [$ctx_id]
            );
            $contract_numer = $r['numer_umowy']    ?? '';
            $person_name    = $r['imie_nazwisko']  ?? '';
        } catch (\Throwable $e) {}
    }

    $subj_txt   = $subject ? htmlspecialchars($subject) : '(brak tematu)';
    $body_html  = nl2br(htmlspecialchars(mb_substr($body, 0, 500)));
    $title      = $contract_numer ? "Umowa {$contract_numer}" : "#{$ctx_id}";
    if ($person_name) $title .= " — " . htmlspecialchars($person_name);

    if ($direction === 'to_admin') {
        // ── Adres docelowy: opiekun lub wszyscy admini ────────────────────
        $recipients = [];
        require_once __DIR__ . '/supervisors.php';
        $sup = ($ctx_type === 'contract') ? supervisor_get($contract_type, $ctx_id) : null;
        if ($sup && $sup['user_email']) {
            $recipients[] = $sup['user_email'];
        } else {
            try {
                $admins = db_all("SELECT email FROM users WHERE role='admin' AND is_active=1");
                foreach ($admins as $a) {
                    if ($a['email']) $recipients[] = $a['email'];
                }
            } catch (\Throwable $e) {}
        }

        if (!$recipients) return;

        $mail_subject = "Nowe pytanie: {$title} — {$org}";
        $mail_body = "
<p>Otrzymano nową wiadomość od użytkownika w systemie Rejestru Umów <strong>" . htmlspecialchars($org) . "</strong>.</p>
<table style='border-collapse:collapse;margin:12px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Umowa:</td><td><strong>" . htmlspecialchars($contract_numer ?: "#{$ctx_id}") . "</strong></td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Temat:</td><td><strong>{$subj_txt}</strong></td></tr>
</table>
<blockquote style='border-left:4px solid #2563eb;margin:12px 0;padding:8px 16px;background:#eff6ff;color:#1e293b'>{$body_html}</blockquote>
<p><a href='" . htmlspecialchars($view_url) . "?msg=1' style='background:#2563eb;color:#fff;padding:9px 20px;text-decoration:none;border-radius:4px;display:inline-block'>Otwórz wątek wiadomości →</a></p>
<p style='color:#888;font-size:.85em'>Wygenerowano automatycznie przez system Rejestru Umów.</p>
";
        foreach ($recipients as $email) {
            approval_send_email($email, $mail_subject, $mail_body);
        }

    } elseif ($direction === 'to_user') {
        // ── Adres docelowy: email użytkownika z umowy ─────────────────────
        $user_email = '';
        if ($ctx_type === 'contract' && $contract_type) {
            try {
                $r = db_one("SELECT email FROM umowy_{$contract_type} WHERE id=?", [$ctx_id]);
                $user_email = $r['email'] ?? '';
            } catch (\Throwable $e) {}
        }
        if (!$user_email) return;

        $panel_url   = APP_URL . '/panel/messages.php';
        $mail_subject = "Odpowiedź na Twoje pytanie — {$org}";
        $mail_body = "
<p>Dzień dobry,</p>
<p>Administrator odpowiedział na Twoje pytanie w systemie Rejestru Umów <strong>" . htmlspecialchars($org) . "</strong>.</p>
<table style='border-collapse:collapse;margin:12px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Temat:</td><td><strong>{$subj_txt}</strong></td></tr>
</table>
<blockquote style='border-left:4px solid #16a34a;margin:12px 0;padding:8px 16px;background:#f0fdf4;color:#1e293b'>{$body_html}</blockquote>
<p><a href='" . htmlspecialchars($panel_url) . "' style='background:#16a34a;color:#fff;padding:9px 20px;text-decoration:none;border-radius:4px;display:inline-block'>Przejdź do Moich wiadomości →</a></p>
<p style='color:#888;font-size:.85em'>Wygenerowano automatycznie przez system Rejestru Umów.</p>
";
        approval_send_email($user_email, $mail_subject, $mail_body);
    }
}

// ════════════════════════════════════════════════════════════════════════════
// Wiadomości wewnętrzne modułu Zadania (context_type='task')
// ════════════════════════════════════════════════════════════════════════════

/**
 * Wyślij wiadomość wewnętrzną powiązaną z zadaniem.
 *
 * @param int    $task_id        ID zadania (context_id)
 * @param int    $sender_id      ID nadawcy
 * @param string $sender_name    Imię i nazwisko nadawcy
 * @param int    $recipient_id   ID odbiorcy (konkretny user)
 * @param string $subject        Temat
 * @param string $body           Treść
 * @return int                   ID wiadomości
 */
function task_msg_send(
    int    $task_id,
    int    $sender_id,
    string $sender_name,
    int    $recipient_id,
    string $subject,
    string $body
): int {
    try {
        return db_insert('messages', [
            'context_type'   => 'task',
            'context_id'     => $task_id,
            'contract_type'  => 'task',
            'sender_type'    => 'admin',
            'sender_id'      => $sender_id,
            'sender_name'    => $sender_name,
            'body'           => $body,
            'created_at'     => date('Y-m-d H:i:s'),
            'is_read'        => 0,
            'recipient_type' => 'user',
            'recipient_id'   => $recipient_id,
            'subject'        => $subject,
            'type_id'        => null,
        ]);
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Liczba nieprzeczytanych wiadomości zadaniowych dla użytkownika.
 */
function task_msg_unread(int $user_id): int {
    try {
        return (int)(db_one(
            "SELECT COUNT(*) AS n FROM messages
             WHERE context_type='task' AND recipient_id=? AND is_read=0",
            [$user_id]
        )['n'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

/**
 * Pobierz skrzynkę odbiorczą zadaniową użytkownika.
 */
function task_msg_inbox(int $user_id, int $limit = 60): array {
    try {
        return db_all(
            "SELECT m.*,
                    t.title AS task_title,
                    t.id    AS task_id_real
             FROM messages m
             LEFT JOIN tasks t ON t.id = m.context_id AND m.context_type='task'
             WHERE m.context_type='task'
               AND (m.recipient_id=? OR m.sender_id=?)
             ORDER BY m.created_at DESC
             LIMIT ?",
            [$user_id, $user_id, $limit]
        );
    } catch (\Throwable $e) { return []; }
}

/**
 * Oznacz wiadomości task jako przeczytane przez odbiorcę.
 */
function task_msg_mark_read(int $user_id, ?int $task_id = null): void {
    try {
        if ($task_id) {
            db()->prepare(
                "UPDATE messages SET is_read=1
                 WHERE context_type='task' AND context_id=? AND recipient_id=? AND is_read=0"
            )->execute([$task_id, $user_id]);
        } else {
            db()->prepare(
                "UPDATE messages SET is_read=1
                 WHERE context_type='task' AND recipient_id=? AND is_read=0"
            )->execute([$user_id]);
        }
    } catch (\Throwable $e) {}
}
