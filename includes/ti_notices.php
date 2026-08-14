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
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_notice_instr_reads (
            notice_id   INTEGER NOT NULL,
            user_id     INTEGER NOT NULL,
            read_at     TEXT NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (notice_id, user_id)
        )");
    } catch (\Throwable $e) {}

    // Seed jednorazowy — komunikat o przejściu na Zoom od 1 września 2026
    try {
        $seeded = db_one("SELECT value FROM settings WHERE key_='ti_notice_zoom_2026_seeded'");
        if (!$seeded) {
            db()->prepare("INSERT OR IGNORE INTO settings (key_, value) VALUES ('ti_notice_zoom_2026_seeded','1')")->execute();
            db()->prepare(
                "INSERT INTO k30_ti_notices (title, body, audience, is_pinned, is_active, expires_at, author_name, created_at, updated_at)
                 VALUES (?, ?, 'all', 1, 1, NULL, 'System', datetime('now'), datetime('now'))"
            )->execute([
                'Od 1 września 2026 zajęcia odbywają się przez Zoom',
                'Od 1 września 2026 wszystkie zajęcia TI prowadzone są zdalnie przez platformę Zoom.'
                . "\n\nLink do kursu znajdziesz w zakładce Moje lekcje — wyświetla się przy każdej zaplanowanej lekcji."
                . "\nJeśli masz pytania, skontaktuj się z prowadzącym lub biurem placówki.",
            ]);
        }
    } catch (\Throwable $e) {}
}

function ti_notices_list_active_for_instructor(int $user_id = 0): array {
    try {
        if ($user_id) {
            return db_all(
                "SELECT n.*,
                        (SELECT 1 FROM k30_ti_notice_instr_reads r WHERE r.notice_id=n.id AND r.user_id=?) AS is_read
                 FROM k30_ti_notices n
                 WHERE n.is_active=1 AND (n.expires_at IS NULL OR n.expires_at >= date('now'))
                 ORDER BY n.is_pinned DESC, n.created_at DESC",
                [$user_id]
            );
        }
        return db_all(
            "SELECT * FROM k30_ti_notices
             WHERE is_active=1 AND (expires_at IS NULL OR expires_at >= date('now'))
             ORDER BY is_pinned DESC, created_at DESC",
            []
        );
    } catch (\Throwable $e) { return []; }
}

function ti_notices_unread_count_instructor(int $user_id): int {
    try {
        return (int)(db_one(
            "SELECT COUNT(*) c FROM k30_ti_notices n
             WHERE n.is_active=1 AND (n.expires_at IS NULL OR n.expires_at >= date('now'))
               AND NOT EXISTS (SELECT 1 FROM k30_ti_notice_instr_reads r WHERE r.notice_id=n.id AND r.user_id=?)",
            [$user_id]
        )['c'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

function ti_notices_mark_read_instructor(int $notice_id, int $user_id): void {
    try {
        db()->prepare("INSERT OR IGNORE INTO k30_ti_notice_instr_reads (notice_id, user_id) VALUES (?,?)")->execute([$notice_id, $user_id]);
    } catch (\Throwable $e) {}
}

function ti_notices_mark_all_read_instructor(int $user_id): void {
    $list = ti_notices_list_active_for_instructor();
    foreach ($list as $n) {
        ti_notices_mark_read_instructor((int)$n['id'], $user_id);
    }
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
    $rname = htmlspecialchars($recipient_name, ENT_QUOTES, 'UTF-8');
    $tit   = htmlspecialchars($title,          ENT_QUOTES, 'UTF-8');
    $purl  = htmlspecialchars($panel_url,      ENT_QUOTES, 'UTF-8');
    $btn   = $panel_url
        ? '<p style="margin:20px 0 0"><a href="' . $purl . '" style="display:inline-block;background:#2563eb;color:#fff;padding:11px 22px;border-radius:6px;text-decoration:none;font-weight:700">Przejdź do panelu kursanta →</a></p>'
        : '';
    $inner = '<p style="margin:0 0 14px">Cześć <strong>' . $rname . '</strong>,</p>'
           . '<p style="margin:0 0 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b">Komunikat</p>'
           . '<p style="margin:0 0 16px;font-size:17px;font-weight:700">📢 ' . $tit . '</p>'
           . ($body_esc ? '<div style="background:#f8fafc;border-left:3px solid #64748b;padding:12px 16px;border-radius:0 6px 6px 0;margin:0 0 20px;font-size:14px;color:#334155">' . $body_esc . '</div>' : '')
           . $btn;
    return _feer_email_tpl($inner, $title, $panel_url, '');
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
