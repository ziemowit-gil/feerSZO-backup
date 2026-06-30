<?php
/**
 * includes/ti_notices.php — Komunikaty placówki TI (tablica ogłoszeń dla kursantów).
 */

function ti_notices_migrate(): void {
    static $done = false; if ($done) return; $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_notices (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT NOT NULL,
            body        TEXT NOT NULL DEFAULT '',
            audience    TEXT NOT NULL DEFAULT 'all',
            is_pinned   INTEGER NOT NULL DEFAULT 0,
            is_active   INTEGER NOT NULL DEFAULT 1,
            expires_at  TEXT,
            author_id   INTEGER,
            author_name TEXT,
            created_at  TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_notice_reads (
            notice_id   INTEGER NOT NULL,
            student_id  INTEGER NOT NULL,
            read_at     TEXT NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (notice_id, student_id)
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_notice_active ON k30_ti_notices(is_active, created_at)");
    } catch (\Throwable $e) {}
}

function ti_notices_list_active_for_instructor(): array {
    try {
        return db_all(
            "SELECT * FROM k30_ti_notices
             WHERE is_active=1 AND (expires_at IS NULL OR expires_at >= date('now'))
             ORDER BY is_pinned DESC, created_at DESC",
            []
        );
    } catch (\Throwable $e) { return []; }
}

function ti_notices_list_admin(): array {
    try {
        return db_all(
            "SELECT n.*,
                    (SELECT COUNT(*) FROM k30_ti_notice_reads r WHERE r.notice_id=n.id) AS reads_count
             FROM k30_ti_notices n
             ORDER BY n.is_pinned DESC, n.created_at DESC",
            []
        );
    } catch (\Throwable $e) { return []; }
}

function ti_notices_list_for_student(int $student_id, ?int $course_id = null): array {
    try {
        $rows = db_all(
            "SELECT n.*,
                    (SELECT COUNT(*) FROM k30_ti_notice_reads r WHERE r.notice_id=n.id AND r.student_id=?) AS is_read
             FROM k30_ti_notices n
             WHERE n.is_active=1
               AND (n.expires_at IS NULL OR n.expires_at >= date('now'))
               AND (n.audience='all'
                    OR (n.audience='course' AND ? IS NOT NULL AND n.audience_ref=?)
                    )
             ORDER BY n.is_pinned DESC, n.created_at DESC",
            [$student_id, $course_id, $course_id]
        );
        return $rows;
    } catch (\Throwable $e) { return []; }
}

function ti_notices_unread_count(int $student_id): int {
    try {
        return (int)(db_one(
            "SELECT COUNT(*) AS n FROM k30_ti_notices n
             WHERE n.is_active=1
               AND (n.expires_at IS NULL OR n.expires_at >= date('now'))
               AND NOT EXISTS (SELECT 1 FROM k30_ti_notice_reads r WHERE r.notice_id=n.id AND r.student_id=?)",
            [$student_id]
        )['n'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

function ti_notices_mark_read(int $notice_id, int $student_id): void {
    try {
        db()->exec("INSERT OR IGNORE INTO k30_ti_notice_reads(notice_id,student_id) VALUES($notice_id,$student_id)");
    } catch (\Throwable $e) {}
}

function ti_notices_mark_all_read(int $student_id): void {
    try {
        $ids = db_all("SELECT id FROM k30_ti_notices WHERE is_active=1 AND (expires_at IS NULL OR expires_at>=date('now'))", []);
        foreach ($ids as $r) ti_notices_mark_read((int)$r['id'], $student_id);
    } catch (\Throwable $e) {}
}

function ti_notices_save(array $data): int {
    $id = db_insert('k30_ti_notices', [
        'title'       => $data['title'],
        'body'        => $data['body'] ?? '',
        'audience'    => $data['audience'] ?? 'all',
        'is_pinned'   => (int)($data['is_pinned'] ?? 0),
        'is_active'   => (int)($data['is_active'] ?? 1),
        'expires_at'  => ($data['expires_at'] ?? '') ?: null,
        'author_id'   => $data['author_id'] ?? null,
        'author_name' => $data['author_name'] ?? null,
    ]);

    if ((int)($data['is_active'] ?? 1)) {
        ti_notices_send_email($id, $data['title'], $data['body'] ?? '');
    }

    return $id;
}

function ti_notices_send_email(int $notice_id, string $title, string $body): void {
    if (!function_exists('mail_queue_add')) {
        @require_once __DIR__ . '/mail_queue.php';
        if (!function_exists('mail_queue_add')) return;
    }

    try {
        // Wszyscy aktywni kursanci z e-mailem (własnym lub z k30_clients)
        $students = db_all(
            "SELECT a.id AS student_id, a.is_minor, a.guardian_email, a.guardian_name,
                    COALESCE(cl.name, a.login) AS name,
                    COALESCE(cl.email, '') AS client_email
             FROM k30_ti_student_accounts a
             LEFT JOIN k30_clients cl ON cl.id=a.client_id
             WHERE a.is_active=1",
            []
        );
    } catch (\Throwable $e) { return; }

    $panel_url = defined('APP_URL') ? rtrim(APP_URL, '/') . '/karty30/ti/kursant/index.php?tab=komunikaty' : '';
    $subject   = 'Nowy komunikat placówki: ' . $title;
    $body_esc  = nl2br(htmlspecialchars($body));

    $sent = [];
    foreach ($students as $s) {
        $email = trim((string)$s['client_email']);
        $name  = $s['name'];

        if ($email && !in_array($email, $sent, true)) {
            $html = _ti_notice_email_html($name, $title, $body_esc, $panel_url);
            try { mail_queue_add($email, $name, $subject, $html, '', 'ti_notice', $notice_id); } catch (\Throwable $e) {}
            $sent[] = $email;
        }

        // Opiekun małoletniego
        $g_email = trim((string)$s['guardian_email']);
        $g_name  = trim((string)$s['guardian_name']) ?: 'Opiekun';
        if ($s['is_minor'] && $g_email && !in_array($g_email, $sent, true)) {
            $html = _ti_notice_email_html($g_name . ' (opiekun: ' . $name . ')', $title, $body_esc, $panel_url);
            try { mail_queue_add($g_email, $g_name, $subject, $html, '', 'ti_notice', $notice_id); } catch (\Throwable $e) {}
            $sent[] = $g_email;
        }
    }
}

function _ti_notice_email_html(string $recipient_name, string $title, string $body_esc, string $panel_url): string {
    $org = defined('ORG_NAME') ? htmlspecialchars(ORG_NAME) : 'Placówka TI';
    return '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"></head><body style="font-family:sans-serif;color:#1e293b;max-width:600px;margin:auto;padding:24px">
<div style="background:#c2410c;color:#fff;padding:14px 20px;border-radius:8px 8px 0 0">
  <strong style="font-size:1.1rem">📢 Nowy komunikat — ' . htmlspecialchars($org) . '</strong>
</div>
<div style="border:1px solid #e2e8f0;border-top:none;padding:20px;border-radius:0 0 8px 8px">
  <p>Cześć ' . htmlspecialchars($recipient_name) . ',</p>
  <p>Opublikowano nowy komunikat placówki:</p>
  <div style="background:#fff7ed;border-left:4px solid #c2410c;padding:12px 16px;margin:12px 0;border-radius:0 4px 4px 0">
    <strong style="font-size:1rem">' . htmlspecialchars($title) . '</strong>
    ' . ($body_esc ? '<div style="margin-top:8px;color:#475569">' . $body_esc . '</div>' : '') . '
  </div>
  ' . ($panel_url ? '<p><a href="' . htmlspecialchars($panel_url) . '" style="background:#c2410c;color:#fff;padding:8px 18px;border-radius:5px;text-decoration:none;display:inline-block">Przejdź do panelu kursanta</a></p>' : '') . '
  <hr style="border:none;border-top:1px solid #e2e8f0;margin:16px 0">
  <p style="font-size:.8rem;color:#94a3b8">Wiadomość wysłana automatycznie przez system ' . $org . '. Nie odpowiadaj na ten e-mail.</p>
</div>
</body></html>';
}

function ti_notices_update(int $id, array $data): void {
    db()->prepare(
        "UPDATE k30_ti_notices SET title=?, body=?, audience=?, is_pinned=?, is_active=?, expires_at=?, updated_at=datetime('now') WHERE id=?"
    )->execute([
        $data['title'],
        $data['body'] ?? '',
        $data['audience'] ?? 'all',
        (int)($data['is_pinned'] ?? 0),
        (int)($data['is_active'] ?? 1),
        ($data['expires_at'] ?? '') ?: null,
        $id,
    ]);
}
