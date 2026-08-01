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

        // Sposób wyświetlania w panelu wolontariusza: 'feed' | 'banner' | 'popup'
        try { db()->exec("ALTER TABLE announcements ADD COLUMN display_mode TEXT NOT NULL DEFAULT 'feed'"); } catch (\Throwable $e) {}
        // Kategoria ogłoszenia — zob. ann_kategoria_options()
        try { db()->exec("ALTER TABLE announcements ADD COLUMN kategoria TEXT NOT NULL DEFAULT 'ogolne'"); } catch (\Throwable $e) {}

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

/** Liczba nieprzeczytanych danego typu (np. 'task'). */
function notif_unread_count_type(int $user_id, string $type): int {
    try {
        $row = db_one(
            "SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND type=? AND is_read=0",
            [$user_id, $type]
        );
        return (int)($row['c'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

/** Ostatnie powiadomienia danego typu. */
function notif_latest_type(int $user_id, string $type, int $limit = 6): array {
    try {
        return db_all(
            "SELECT * FROM notifications WHERE user_id=? AND type=? ORDER BY created_at DESC LIMIT ?",
            [$user_id, $type, $limit]
        );
    } catch (\Throwable $e) { return []; }
}

/** Liczba nieprzeczytanych z wyłączeniem podanego typu. */
function notif_unread_count_excl(int $user_id, string $excl_type): int {
    try {
        $row = db_one(
            "SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND type!=? AND is_read=0",
            [$user_id, $excl_type]
        );
        return (int)($row['c'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

/** Ostatnie powiadomienia z wyłączeniem podanego typu. */
function notif_latest_excl(int $user_id, string $excl_type, int $limit = 6): array {
    try {
        return db_all(
            "SELECT * FROM notifications WHERE user_id=? AND type!=? ORDER BY created_at DESC LIMIT ?",
            [$user_id, $excl_type, $limit]
        );
    } catch (\Throwable $e) { return []; }
}

// ── Ogłoszenia ────────────────────────────────────────────────────────────────

/** Dostępne kategorie ogłoszeń (klucz => etykieta). */
function ann_kategoria_options(): array {
    return [
        'ogolne'          => 'Ogólne',
        'pilne'           => 'Pilne',
        'wydarzenie'      => 'Wydarzenie',
        'techniczne'      => 'Techniczne / przerwa w działaniu',
        'rodo'            => 'RODO / Bezpieczeństwo',
        'administracyjne' => 'Administracyjne',
    ];
}

function ann_kategoria_label(string $kategoria): string {
    return ann_kategoria_options()[$kategoria] ?? 'Ogólne';
}

function ann_kategoria_color(string $kategoria): string {
    return match ($kategoria) {
        'pilne'           => '#DC2626',
        'wydarzenie'      => '#7C3AED',
        'techniczne'      => '#0EA5E9',
        'rodo'            => '#16A34A',
        'administracyjne' => '#6B7280',
        default           => '#F59E0B', // ogolne
    };
}

function ann_create(array $data, int $author_id, string $author_name): int {
    $kategoria = array_key_exists($data['kategoria'] ?? '', ann_kategoria_options()) ? $data['kategoria'] : 'ogolne';
    $id = db_insert('announcements', [
        'title'       => $data['title'],
        'body'        => $data['body'] ?? '',
        'audience'    => $data['audience'] ?? 'all',
        'kategoria'   => $kategoria,
        'author_id'   => $author_id,
        'author_name' => $author_name,
        'is_pinned'   => (int)($data['is_pinned'] ?? 0),
        'is_active'   => 1,
        'send_email'  => (int)($data['send_email'] ?? 0),
        'display_mode'=> in_array(($data['display_mode'] ?? 'feed'), ['feed','banner','popup'], true) ? $data['display_mode'] : 'feed',
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
                $org      = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
                $color    = ann_kategoria_color($kategoria);
                $kat_lbl  = ann_kategoria_label($kategoria);
                $subject  = '📢 ' . $kat_lbl . ': ' . $data['title'];
                $name     = htmlspecialchars($u['name'] ?? $u['email']);
                $title    = htmlspecialchars($data['title']);
                $body_txt = nl2br(htmlspecialchars($data['body'] ?? ''));
                $url      = htmlspecialchars($ann_url);
                $body_html = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,{$color},{$color}CC);padding:22px 26px;border-radius:10px 10px 0 0">
  <span style="display:inline-block;background:rgba(255,255,255,.25);color:#fff;font-size:.72rem;font-weight:700;
               padding:3px 10px;border-radius:999px;margin-bottom:8px">{$kat_lbl}</span>
  <h2 style="color:#fff;margin:6px 0 0;font-size:1.1rem">📢 Nowe ogłoszenie — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$name}</strong>!</p>
  <p>Opublikowano nowe ogłoszenie: <strong>{$title}</strong></p>
  <div style="border-left:3px solid {$color};border-radius:4px;padding:12px 16px;margin:16px 0;background:#F9FAFB;font-size:.92em">
    {$body_txt}
  </div>
  <div style="margin:22px 0;text-align:center">
    <a href="{$url}" style="background:{$color};color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
      Przejdź do ogłoszenia →
    </a>
  </div>
  <p style="color:#6c757d;font-size:.82em;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
    Wiadomość dotyczy: <strong>{$org}</strong>
  </p>
</div>
</body></html>
HTML;
                approval_send_email($u['email'], $subject, $body_html);
            } catch (\Throwable $e) {}
        }
    }

    return $id;
}

/** Miękkie usunięcie ogłoszenia (is_active=0) — nie usuwa fizycznie, zachowuje historię odczytań. */
function ann_delete(int $id): void {
    db()->prepare("UPDATE announcements SET is_active=0, updated_at=datetime('now') WHERE id=?")
        ->execute([$id]);

    // Sprzątnij powiadomienia w dzwonku wskazujące na usunięte ogłoszenie —
    // inaczej zostają jako martwe linki (kliknięcie prowadzi do "nie znaleziono").
    // URL musi być identyczny z tym budowanym w ann_create() przy notif_create().
    if (defined('APP_URL')) {
        try {
            db()->prepare("DELETE FROM notifications WHERE type='announcement' AND url=?")
                ->execute([APP_URL . '/komunikaty/announcement.php?id=' . $id]);
        } catch (\Throwable $e) {}
    }
}

/**
 * Pełna lista ogłoszeń do zarządzania przez admina — niezależna od tego,
 * czy bieżący administrator sam należy do audytorium danego ogłoszenia
 * (w przeciwieństwie do ann_list_for_user(), które filtruje po widoczności).
 */
function ann_all_list(string $kategoria = ''): array {
    try {
        $sql    = "SELECT a.*, (SELECT COUNT(*) FROM announcement_reads ar WHERE ar.announcement_id=a.id) AS read_count
                   FROM announcements a WHERE a.is_active=1";
        $params = [];
        if ($kategoria !== '' && array_key_exists($kategoria, ann_kategoria_options())) {
            $sql .= " AND a.kategoria=?";
            $params[] = $kategoria;
        }
        $sql .= " ORDER BY a.is_pinned DESC, a.created_at DESC";
        return db_all($sql, $params);
    } catch (\Throwable $e) {
        return [];
    }
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

function ann_list_for_user(int $user_id, string $role, string $kategoria = ''): array {
    try {
        $sql    = "SELECT a.*,
                    (SELECT COUNT(*) FROM announcement_reads ar
                     WHERE ar.announcement_id=a.id AND ar.user_id=?) AS is_read_by_me
             FROM announcements a
             WHERE a.is_active=1
               AND (a.expires_at IS NULL OR a.expires_at >= date('now'))";
        $params = [$user_id];
        if ($kategoria !== '' && array_key_exists($kategoria, ann_kategoria_options())) {
            $sql .= " AND a.kategoria=?";
            $params[] = $kategoria;
        }
        $sql .= " ORDER BY a.is_pinned DESC, a.created_at DESC";
        $rows = db_all($sql, $params);

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

/**
 * Komunikaty do wyświetlenia bezpośrednio w panelu wolontariusza —
 * tylko tryb 'banner' lub 'popup', nieprzeczytane przez danego użytkownika.
 * Wykorzystuje istniejące filtrowanie po audytorium i śledzenie odczytów.
 */
function ann_panel_messages(int $user_id, string $role): array {
    $all = ann_list_for_user($user_id, $role);
    return array_values(array_filter($all, function ($a) {
        $mode = $a['display_mode'] ?? 'feed';
        return in_array($mode, ['banner', 'popup'], true) && !(int)($a['is_read_by_me'] ?? 0);
    }));
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
