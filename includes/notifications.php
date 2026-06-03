<?php
/**
 * Moduł Komunikaty + Powiadomienia
 * Tabele: announcements, notifications, announcement_reads
 */

if (!defined('APP_INSTALLED')) {
    require_once dirname(__DIR__) . '/config.php';
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// ── Migracja ─────────────────────────────────────────────────────────────────

function notif_migrate(): void {
    static $_done = false;
    if ($_done) return;
    $_done = true;

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS announcements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT '',
            audience TEXT NOT NULL DEFAULT 'all',
            author_id INTEGER,
            author_name TEXT NOT NULL DEFAULT '',
            is_pinned INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            send_email INTEGER NOT NULL DEFAULT 0,
            expires_at DATE DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        db()->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL DEFAULT 'system',
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '',
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        db()->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            announcement_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(announcement_id, user_id)
        )");

        // Indeksy dla wydajności
        try { db()->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, is_read)"); } catch (\Throwable $e) {}
        try { db()->exec("CREATE INDEX IF NOT EXISTS idx_ann_reads_user ON announcement_reads(user_id, announcement_id)"); } catch (\Throwable $e) {}
    } catch (\Throwable $e) {
        // Tablica już istnieje lub inny niekrytyczny błąd
    }
}

// ── Powiadomienia ─────────────────────────────────────────────────────────────

function notif_create(int $user_id, string $type, string $title, string $body = '', string $url = ''): int {
    return db_insert('notifications', [
        'user_id'    => $user_id,
        'type'       => $type,
        'title'      => $title,
        'body'       => $body,
        'url'        => $url,
        'is_read'    => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function notif_unread_count(int $user_id): int {
    try {
        $row = db_one("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0", [$user_id]);
        return (int)($row['c'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

function notif_latest(int $user_id, int $limit = 8): array {
    try {
        return db_all(
            "SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ?",
            [$user_id, $limit]
        );
    } catch (\Throwable $e) {
        return [];
    }
}

function notif_mark_read(int $user_id, ?int $id = null): void {
    try {
        if ($id !== null) {
            db()->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")
               ->execute([$id, $user_id]);
        } else {
            db()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")
               ->execute([$user_id]);
        }
    } catch (\Throwable $e) {}
}

// ── Ogłoszenia ────────────────────────────────────────────────────────────────

function ann_create(array $data, int $author_id, string $author_name): int {
    $id = db_insert('announcements', [
        'title'       => $data['title'],
        'body'        => $data['body'] ?? '',
        'audience'    => $data['audience'] ?? 'all',
        'author_id'   => $author_id,
        'author_name' => $author_name,
        'is_pinned'   => (int)($data['is_pinned'] ?? 0),
        'is_active'   => 1,
        'send_email'  => (int)($data['send_email'] ?? 0),
        'expires_at'  => ($data['expires_at'] ?? '') ?: null,
        'created_at'  => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);

    // Pobierz odbiorców
    $audience = $data['audience'] ?? 'all';
    $users    = _ann_get_audience_users($audience);

    $ann_url = defined('APP_URL') ? APP_URL . '/komunikaty/announcement.php?id=' . $id : '';

    foreach ($users as $u) {
        // Powiadomienie in-app
        try {
            notif_create(
                (int)$u['id'],
                'announcement',
                $data['title'],
                mb_substr($data['body'] ?? '', 0, 200),
                $ann_url
            );
        } catch (\Throwable $e) {}

        // Opcjonalny e-mail
        if (!empty($data['send_email']) && !empty($u['email'])) {
            try {
                require_once __DIR__ . '/approval.php';
                $subject  = 'Nowe ogłoszenie: ' . $data['title'];
                $body_html = '<p>Witaj ' . htmlspecialchars($u['name'] ?? $u['email']) . ',</p>'
                           . '<p>Opublikowano nowe ogłoszenie: <strong>' . htmlspecialchars($data['title']) . '</strong></p>'
                           . '<div style="white-space:pre-wrap;border-left:3px solid #F59E0B;padding:8px 12px;background:#FFFBEB">'
                           . htmlspecialchars($data['body'] ?? '')
                           . '</div>'
                           . '<p><a href="' . htmlspecialchars($ann_url) . '">Przejdź do ogłoszenia</a></p>';
                approval_send_email($u['email'], $subject, $body_html);
            } catch (\Throwable $e) {}
        }
    }

    return $id;
}

function _ann_get_audience_users(string $audience): array {
    try {
        if ($audience === 'all') {
            return db_all("SELECT id, name, email FROM users WHERE is_active=1", []);
        }
        if (str_starts_with($audience, 'role:')) {
            $role = substr($audience, 5);
            return db_all("SELECT id, name, email FROM users WHERE role=? AND is_active=1", [$role]);
        }
        if (str_starts_with($audience, 'unit:')) {
            $unit_id = (int)substr($audience, 5);
            return db_all(
                "SELECT u.id, u.name, u.email FROM users u
                 INNER JOIN org_members om ON om.user_id=u.id
                 WHERE om.unit_id=? AND u.is_active=1",
                [$unit_id]
            );
        }
        if (str_starts_with($audience, 'user:')) {
            $uid = (int)substr($audience, 5);
            $r = db_one("SELECT id, name, email FROM users WHERE id=? AND is_active=1", [$uid]);
            return $r ? [$r] : [];
        }
    } catch (\Throwable $e) {}
    return [];
}

function ann_list_for_user(int $user_id, string $role): array {
    try {
        $rows = db_all(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM announcement_reads ar
                     WHERE ar.announcement_id=a.id AND ar.user_id=?) AS is_read_by_me
             FROM announcements a
             WHERE a.is_active=1
               AND (a.expires_at IS NULL OR a.expires_at >= date('now'))
             ORDER BY a.is_pinned DESC, a.created_at DESC",
            [$user_id]
        );

        // Filtruj po audience
        return array_values(array_filter($rows, function ($a) use ($user_id, $role) {
            return _ann_user_can_see($a['audience'] ?? 'all', $user_id, $role);
        }));
    } catch (\Throwable $e) {
        return [];
    }
}

function _ann_user_can_see(string $audience, int $user_id, string $role): bool {
    if ($audience === 'all')    return true;
    if ($audience === 'public') return $role === 'admin'; // zalogowani admini też widzą
    if (str_starts_with($audience, 'role:')) {
        return $role === substr($audience, 5);
    }
    if (str_starts_with($audience, 'unit:')) {
        $unit_id = (int)substr($audience, 5);
        try {
            $r = db_one("SELECT 1 FROM org_members WHERE unit_id=? AND user_id=?", [$unit_id, $user_id]);
            return (bool)$r;
        } catch (\Throwable $e) {}
    }
    if (str_starts_with($audience, 'user:')) {
        return (int)substr($audience, 5) === $user_id;
    }
    return false;
}

function ann_mark_read(int $ann_id, int $user_id): void {
    try {
        db()->prepare(
            "INSERT OR IGNORE INTO announcement_reads (announcement_id, user_id, read_at)
             VALUES (?, ?, datetime('now'))"
        )->execute([$ann_id, $user_id]);
    } catch (\Throwable $e) {}
}

/**
 * Publiczne ogłoszenia — widoczne bez logowania (na stronie logowania).
 * Tylko audience='public', aktywne i nie-wygasłe.
 */
function ann_public_list(): array {
    try {
        return db_all(
            "SELECT * FROM announcements
             WHERE is_active=1 AND audience='public'
               AND (expires_at IS NULL OR expires_at >= date('now'))
             ORDER BY is_pinned DESC, created_at DESC
             LIMIT 5",
            []
        );
    } catch (\Throwable $e) {
        return [];
    }
}

function ann_audience_label(string $audience): string {
    if ($audience === 'all')    return 'Wszyscy użytkownicy';
    if ($audience === 'public') return 'Strona logowania (publiczne)';
    if ($audience === 'role:admin')  return 'Administratorzy';
    if ($audience === 'role:editor') return 'Edytorzy';
    if ($audience === 'role:viewer') return 'Przeglądający';
    if (str_starts_with($audience, 'unit:')) {
        $unit_id = (int)substr($audience, 5);
        try {
            $u = db_one("SELECT name FROM org_units WHERE id=?", [$unit_id]);
            if ($u) return 'Jednostka: ' . $u['name'];
        } catch (\Throwable $e) {}
        return 'Jednostka #' . $unit_id;
    }
    if (str_starts_with($audience, 'user:')) {
        $uid = (int)substr($audience, 5);
        try {
            $u = db_one("SELECT name FROM users WHERE id=?", [$uid]);
            if ($u) return 'Osoba: ' . $u['name'];
        } catch (\Throwable $e) {}
        return 'Osoba #' . $uid;
    }
    return $audience;
}

// ── Helpers UI ────────────────────────────────────────────────────────────────

function notif_type_icon(string $type): string {
    return match ($type) {
        'announcement' => 'bi-megaphone-fill',
        'contract'     => 'bi-file-text-fill',
        'approval'     => 'bi-diagram-3-fill',
        'task'         => 'bi-kanban-fill',
        'reservation'  => 'bi-calendar-check-fill',
        default        => 'bi-info-circle-fill',   // 'system' i inne
    };
}

function notif_type_color(string $type): string {
    return match ($type) {
        'announcement' => '#F59E0B',
        'contract'     => '#2563EB',
        'approval'     => '#16A34A',
        'task'         => '#8B5CF6',
        'reservation'  => '#6366F1',
        default        => '#6366F1',   // 'system'
    };
}
