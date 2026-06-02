<?php
/**
 * Moduł Uchwały i Zarządzenia — init DB + funkcje pomocnicze.
 */

function _res_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS resolutions (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        type        TEXT    NOT NULL DEFAULT 'uchwala',  -- uchwala | zarzadzenie | decyzja
        number      TEXT    NOT NULL DEFAULT '',
        date        DATE    NOT NULL,
        title       TEXT    NOT NULL,
        body        TEXT    NOT NULL DEFAULT '',          -- Markdown
        category    TEXT    NOT NULL DEFAULT '',
        status      TEXT    NOT NULL DEFAULT 'draft',    -- draft | active | archived
        attachment  TEXT    NOT NULL DEFAULT '',
        signed_by   INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
        created_by  INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
        tags        TEXT    NOT NULL DEFAULT '',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_res_type   ON resolutions(type)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_res_status ON resolutions(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_res_date   ON resolutions(date DESC)");

    // Automatyczna numeracja per rok i typ
    $pdo->exec("CREATE TABLE IF NOT EXISTS resolution_counters (
        id    INTEGER PRIMARY KEY AUTOINCREMENT,
        type  TEXT NOT NULL,
        year  INTEGER NOT NULL,
        seq   INTEGER NOT NULL DEFAULT 0,
        UNIQUE(type, year)
    )");
}

// ── Numeracja ─────────────────────────────────────────────────────────────────

function res_next_number(string $type, int $year = 0): string {
    _res_init();
    if (!$year) $year = (int)date('Y');
    $prefix = match($type) {
        'uchwala'     => 'U',
        'zarzadzenie' => 'Z',
        'decyzja'     => 'D',
        default       => strtoupper(substr($type, 0, 1)),
    };
    db()->prepare(
        "INSERT INTO resolution_counters (type, year, seq) VALUES (?, ?, 1)
         ON CONFLICT(type, year) DO UPDATE SET seq = seq + 1"
    )->execute([$type, $year]);
    $row = db_one("SELECT seq FROM resolution_counters WHERE type=? AND year=?", [$type, $year]);
    $seq = str_pad((string)($row['seq'] ?? 1), 2, '0', STR_PAD_LEFT);
    return "{$prefix}/{$seq}/{$year}";
}

// ── CRUD ──────────────────────────────────────────────────────────────────────

function res_get_all(array $filters = []): array {
    _res_init();
    $where = []; $params = [];

    if (!empty($filters['type'])) {
        $where[] = 'r.type=?'; $params[] = $filters['type'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'r.status=?'; $params[] = $filters['status'];
    }
    if (!empty($filters['category'])) {
        $where[] = 'r.category=?'; $params[] = $filters['category'];
    }
    if (!empty($filters['year'])) {
        $where[] = "strftime('%Y', r.date)=?"; $params[] = (string)$filters['year'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(r.title LIKE ? OR r.number LIKE ? OR r.tags LIKE ?)';
        $q = '%' . $filters['q'] . '%';
        array_push($params, $q, $q, $q);
    }

    $sql = "SELECT r.*, u.name AS signer_name, cb.name AS creator_name
            FROM resolutions r
            LEFT JOIN users u  ON u.id  = r.signed_by
            LEFT JOIN users cb ON cb.id = r.created_by"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY r.date DESC, r.id DESC";

    return db_all($sql, $params);
}

function res_get(int $id): ?array {
    _res_init();
    return db_one(
        "SELECT r.*, u.name AS signer_name, cb.name AS creator_name
         FROM resolutions r
         LEFT JOIN users u  ON u.id  = r.signed_by
         LEFT JOIN users cb ON cb.id = r.created_by
         WHERE r.id=?", [$id]
    );
}

function res_create(array $data, int $user_id): int {
    _res_init();
    $data['created_by'] = $user_id;
    $data['created_at'] = date('Y-m-d H:i:s');
    $data['updated_at'] = date('Y-m-d H:i:s');
    if (empty($data['number'])) {
        $data['number'] = res_next_number($data['type'], (int)date('Y', strtotime($data['date'] ?? 'now')));
    }
    return db_insert('resolutions', $data);
}

function res_update(int $id, array $data): void {
    _res_init();
    $data['updated_at'] = date('Y-m-d H:i:s');
    $set = implode(', ', array_map(fn($k) => "$k=:$k", array_keys($data)));
    $data['id'] = $id;
    db()->prepare("UPDATE resolutions SET $set WHERE id=:id")->execute($data);
}

function res_delete(int $id): void {
    _res_init();
    $row = db_one("SELECT attachment FROM resolutions WHERE id=?", [$id]);
    if ($row && $row['attachment']) {
        @unlink(dirname(__DIR__) . '/uploads/resolutions/' . $row['attachment']);
    }
    db()->prepare("DELETE FROM resolutions WHERE id=?")->execute([$id]);
}

function res_upload(int $id, string $field): ?string {
    _res_init();
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) return null;

    $allowed = ['pdf','doc','docx','odt','png','jpg','jpeg','zip'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return 'Niedozwolony typ pliku.';
    if ($f['size'] > 20 * 1024 * 1024) return 'Plik zbyt duży (maks. 20 MB).';

    $dir = dirname(__DIR__) . '/uploads/resolutions/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $old = db_one("SELECT attachment FROM resolutions WHERE id=?", [$id]);
    if ($old && $old['attachment']) @unlink($dir . $old['attachment']);

    $stored = 'res_' . $id . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $stored)) return 'Błąd zapisu pliku.';

    db()->prepare("UPDATE resolutions SET attachment=? WHERE id=?")->execute([$stored, $id]);
    return null;
}

// ── Słowniki ──────────────────────────────────────────────────────────────────

function res_type_label(string $t): array {
    return match($t) {
        'uchwala'     => ['Uchwała',      'bi-hammer',          'primary'],
        'zarzadzenie' => ['Zarządzenie',  'bi-person-gear',     'warning'],
        'decyzja'     => ['Decyzja',      'bi-clipboard-check', 'info'],
        default       => [$t,             'bi-file-text',       'secondary'],
    };
}

function res_status_label(string $s): array {
    return match($s) {
        'draft'    => ['Projekt',   'secondary'],
        'active'   => ['Aktywna',  'success'],
        'archived' => ['Archiwum', 'light'],
        default    => [$s,         'light'],
    };
}

function res_categories(): array {
    _res_init();
    $rows = db_all("SELECT DISTINCT category FROM resolutions WHERE category!='' ORDER BY category");
    return array_column($rows, 'category');
}

function res_years(): array {
    _res_init();
    $rows = db_all("SELECT DISTINCT strftime('%Y', date) AS y FROM resolutions ORDER BY y DESC");
    return array_column($rows, 'y');
}

function res_stats(): array {
    _res_init();
    $rows = db_all("SELECT type, status, COUNT(*) AS c FROM resolutions GROUP BY type, status");
    $out = [];
    foreach ($rows as $r) {
        $out[$r['type']][$r['status']] = (int)$r['c'];
    }
    return $out;
}

function res_users_list(): array {
    return db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");
}
