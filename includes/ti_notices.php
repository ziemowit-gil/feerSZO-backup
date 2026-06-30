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
    return db_insert('k30_ti_notices', [
        'title'       => $data['title'],
        'body'        => $data['body'] ?? '',
        'audience'    => $data['audience'] ?? 'all',
        'is_pinned'   => (int)($data['is_pinned'] ?? 0),
        'is_active'   => (int)($data['is_active'] ?? 1),
        'expires_at'  => ($data['expires_at'] ?? '') ?: null,
        'author_id'   => $data['author_id'] ?? null,
        'author_name' => $data['author_name'] ?? null,
    ]);
}

function ti_notices_update(int $id, array $data): void {
    db_query(
        "UPDATE k30_ti_notices SET title=?, body=?, audience=?, is_pinned=?, is_active=?, expires_at=?, updated_at=datetime('now') WHERE id=?",
        [
            $data['title'],
            $data['body'] ?? '',
            $data['audience'] ?? 'all',
            (int)($data['is_pinned'] ?? 0),
            (int)($data['is_active'] ?? 1),
            ($data['expires_at'] ?? '') ?: null,
            $id,
        ]
    );
}
