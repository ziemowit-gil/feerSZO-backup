<?php
/**
 * Moduł Procedury — helpery DB + migracja.
 */

// ── Auto-migracja tabel ──────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS procedures (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        title       TEXT    NOT NULL,
        content     TEXT    NOT NULL DEFAULT '',
        category    TEXT    NOT NULL DEFAULT '',
        status      TEXT    NOT NULL DEFAULT 'active',
        owner_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        version     INTEGER NOT NULL DEFAULT 1,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted_at  DATETIME
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS procedure_versions (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        procedure_id INTEGER NOT NULL REFERENCES procedures(id) ON DELETE CASCADE,
        version      INTEGER NOT NULL,
        title        TEXT    NOT NULL,
        content      TEXT    NOT NULL DEFAULT '',
        category     TEXT    NOT NULL DEFAULT '',
        owner_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        changed_by   INTEGER NOT NULL,
        change_note  TEXT    NOT NULL DEFAULT '',
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS procedure_relations (
        procedure_id INTEGER NOT NULL,
        related_id   INTEGER NOT NULL,
        PRIMARY KEY (procedure_id, related_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS procedure_attachments (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        procedure_id INTEGER NOT NULL REFERENCES procedures(id) ON DELETE CASCADE,
        filename     TEXT    NOT NULL,
        original_name TEXT   NOT NULL,
        mime_type    TEXT    NOT NULL DEFAULT '',
        file_size    INTEGER NOT NULL DEFAULT 0,
        uploaded_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        uploaded_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Indeksy
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_proc_status    ON procedures(status)"); } catch (\Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_proc_ver_pid   ON procedure_versions(procedure_id, version)"); } catch (\Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_proc_att_pid   ON procedure_attachments(procedure_id)"); } catch (\Throwable $e) {}
})();

// ── Stałe ────────────────────────────────────────────────────────────────────
const PROC_UPLOAD_SUBDIR = 'procedures/';
const PROC_ALLOWED_EXT   = ['pdf','doc','docx','xls','xlsx','odt','ods','png','jpg','jpeg','gif','webp','zip','txt','csv'];
const PROC_MAX_SIZE      = 15 * 1024 * 1024; // 15 MB

// ── CRUD ─────────────────────────────────────────────────────────────────────

function proc_get_all(array $f = []): array {
    $where  = ["p.status != 'deleted'"];
    $params = [];

    if (!empty($f['status'])) {
        $where[] = "p.status = ?";
        $params[] = $f['status'];
    } else {
        $where[] = "p.status = 'active'";
    }
    if (!empty($f['q'])) {
        $where[] = "(p.title LIKE ? OR p.category LIKE ?)";
        $params[] = '%' . $f['q'] . '%';
        $params[] = '%' . $f['q'] . '%';
    }
    if (!empty($f['category'])) {
        $where[] = "p.category = ?";
        $params[] = $f['category'];
    }
    if (!empty($f['owner_id'])) {
        $where[] = "p.owner_id = ?";
        $params[] = (int)$f['owner_id'];
    }

    $sql = "SELECT p.*, u.name AS owner_name,
                   c.name AS creator_name
            FROM procedures p
            LEFT JOIN users u ON u.id = p.owner_id
            LEFT JOIN users c ON c.id = p.created_by
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.updated_at DESC";
    return db_all($sql, $params);
}

function proc_get(int $id): ?array {
    return db_one(
        "SELECT p.*, u.name AS owner_name, c.name AS creator_name
         FROM procedures p
         LEFT JOIN users u ON u.id = p.owner_id
         LEFT JOIN users c ON c.id = p.created_by
         WHERE p.id = ?",
        [$id]
    );
}

function proc_get_version(int $procedure_id, int $version): ?array {
    return db_one(
        "SELECT v.*, u.name AS changed_by_name
         FROM procedure_versions v
         LEFT JOIN users u ON u.id = v.changed_by
         WHERE v.procedure_id = ? AND v.version = ?",
        [$procedure_id, $version]
    );
}

function proc_get_versions(int $procedure_id): array {
    return db_all(
        "SELECT v.*, u.name AS changed_by_name
         FROM procedure_versions v
         LEFT JOIN users u ON u.id = v.changed_by
         WHERE v.procedure_id = ?
         ORDER BY v.version DESC",
        [$procedure_id]
    );
}

function proc_get_related(int $procedure_id): array {
    return db_all(
        "SELECT p.id, p.title, p.category, p.status, p.version
         FROM procedure_relations r
         JOIN procedures p ON p.id = r.related_id
         WHERE r.procedure_id = ? AND p.status != 'deleted'
         ORDER BY p.title",
        [$procedure_id]
    );
}

function proc_get_attachments(int $procedure_id): array {
    return db_all(
        "SELECT a.*, u.name AS uploader_name
         FROM procedure_attachments a
         LEFT JOIN users u ON u.id = a.uploaded_by
         WHERE a.procedure_id = ?
         ORDER BY a.uploaded_at DESC",
        [$procedure_id]
    );
}

function proc_get_categories(): array {
    $rows = db_all("SELECT DISTINCT category FROM procedures WHERE category != '' AND status != 'deleted' ORDER BY category");
    return array_column($rows, 'category');
}

function proc_get_users_list(): array {
    return db_all("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name");
}

// ── Zapis (create/update) ──────────────────────────────────────────────────

function proc_create(array $data, int $user_id): int {
    $pdo = db();
    $pdo->prepare(
        "INSERT INTO procedures (title,content,category,owner_id,created_by,updated_at)
         VALUES (:title,:content,:category,:owner_id,:user_id,datetime('now'))"
    )->execute([
        ':title'    => trim($data['title']),
        ':content'  => $data['content'] ?? '',
        ':category' => trim($data['category'] ?? ''),
        ':owner_id' => $data['owner_id'] ? (int)$data['owner_id'] : null,
        ':user_id'  => $user_id,
    ]);
    $id = (int)$pdo->lastInsertId();

    // Snapshot wersji 1
    _proc_snapshot($id, 1, $data, $user_id, 'Utworzenie procedury');

    // Relacje
    if (!empty($data['related_ids'])) {
        proc_set_relations($id, (array)$data['related_ids']);
    }

    return $id;
}

function proc_update(int $id, array $data, int $user_id, string $change_note = ''): void {
    $proc = proc_get($id);
    if (!$proc) return;

    $new_version = $proc['version'] + 1;

    db()->prepare(
        "UPDATE procedures
         SET title=:title, content=:content, category=:category,
             owner_id=:owner_id, version=:version,
             updated_at=datetime('now')
         WHERE id=:id"
    )->execute([
        ':title'    => trim($data['title']),
        ':content'  => $data['content'] ?? '',
        ':category' => trim($data['category'] ?? ''),
        ':owner_id' => $data['owner_id'] ? (int)$data['owner_id'] : null,
        ':version'  => $new_version,
        ':id'       => $id,
    ]);

    _proc_snapshot($id, $new_version, $data, $user_id, $change_note ?: 'Modyfikacja');

    if (array_key_exists('related_ids', $data)) {
        proc_set_relations($id, (array)$data['related_ids']);
    }
}

function _proc_snapshot(int $id, int $version, array $data, int $user_id, string $note): void {
    db()->prepare(
        "INSERT INTO procedure_versions
             (procedure_id,version,title,content,category,owner_id,changed_by,change_note)
         VALUES (:pid,:ver,:title,:content,:category,:owner_id,:user_id,:note)"
    )->execute([
        ':pid'      => $id,
        ':ver'      => $version,
        ':title'    => trim($data['title']),
        ':content'  => $data['content'] ?? '',
        ':category' => trim($data['category'] ?? ''),
        ':owner_id' => $data['owner_id'] ? (int)$data['owner_id'] : null,
        ':user_id'  => $user_id,
        ':note'     => $note,
    ]);
}

function proc_delete(int $id, int $user_id): void {
    db()->prepare(
        "UPDATE procedures SET status='deleted', deleted_at=datetime('now') WHERE id=?"
    )->execute([$id]);
    try { log_system_action($user_id, 'proc_delete', "Usunięto procedurę #$id"); } catch (\Throwable $e) {}
}

function proc_archive(int $id, int $user_id): void {
    db()->prepare("UPDATE procedures SET status='archived' WHERE id=?")->execute([$id]);
    try { log_system_action($user_id, 'proc_archive', "Zarchiwizowano procedurę #$id"); } catch (\Throwable $e) {}
}

function proc_restore(int $id, int $user_id): void {
    db()->prepare("UPDATE procedures SET status='active', deleted_at=NULL WHERE id=?")->execute([$id]);
    try { log_system_action($user_id, 'proc_restore', "Przywrócono procedurę #$id"); } catch (\Throwable $e) {}
}

// ── Relacje ──────────────────────────────────────────────────────────────────

function proc_set_relations(int $procedure_id, array $related_ids): void {
    $pdo = db();
    // Usuń stare (w obu kierunkach)
    $pdo->prepare("DELETE FROM procedure_relations WHERE procedure_id=? OR related_id=?")->execute([$procedure_id, $procedure_id]);
    // Dodaj nowe (bidirektywnie)
    $ins = $pdo->prepare("INSERT OR IGNORE INTO procedure_relations (procedure_id, related_id) VALUES (?,?)");
    foreach ($related_ids as $rid) {
        $rid = (int)$rid;
        if ($rid > 0 && $rid !== $procedure_id) {
            $ins->execute([$procedure_id, $rid]);
            $ins->execute([$rid, $procedure_id]);
        }
    }
}

// ── Załączniki ───────────────────────────────────────────────────────────────

function proc_upload_attachment(int $procedure_id, string $field, int $user_id): ?string {
    if (empty($_FILES[$field]['tmp_name'])) return 'Nie wybrano pliku.';
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return 'Błąd przesyłania pliku (kod: ' . $f['error'] . ').';
    if ($f['size'] > PROC_MAX_SIZE) return 'Plik jest za duży (max 15 MB).';

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, PROC_ALLOWED_EXT, true)) {
        return 'Niedozwolony format pliku. Dozwolone: ' . implode(', ', PROC_ALLOWED_EXT);
    }

    $dir = UPLOAD_DIR . PROC_UPLOAD_SUBDIR . $procedure_id . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $stored_name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $stored_name)) {
        return 'Nie udało się zapisać pliku.';
    }

    $mime = $f['type'] ?: 'application/octet-stream';
    db()->prepare(
        "INSERT INTO procedure_attachments (procedure_id,filename,original_name,mime_type,file_size,uploaded_by)
         VALUES (?,?,?,?,?,?)"
    )->execute([$procedure_id, $stored_name, $f['name'], $mime, $f['size'], $user_id]);

    return null; // null = sukces
}

function proc_delete_attachment(int $attachment_id): bool {
    $att = db_one("SELECT * FROM procedure_attachments WHERE id=?", [$attachment_id]);
    if (!$att) return false;

    $path = UPLOAD_DIR . PROC_UPLOAD_SUBDIR . $att['procedure_id'] . '/' . $att['filename'];
    if (is_file($path)) unlink($path);

    db()->prepare("DELETE FROM procedure_attachments WHERE id=?")->execute([$attachment_id]);
    return true;
}

function proc_get_attachment(int $id): ?array {
    return db_one("SELECT * FROM procedure_attachments WHERE id=?", [$id]);
}

// ── Statystyki ───────────────────────────────────────────────────────────────

function proc_stats(): array {
    $r = db_one("SELECT
        COUNT(*) FILTER (WHERE status='active')   AS active,
        COUNT(*) FILTER (WHERE status='archived') AS archived
        FROM procedures WHERE status != 'deleted'");
    return $r ?? ['active' => 0, 'archived' => 0];
}

// ── Format rozmiaru pliku ────────────────────────────────────────────────────

function proc_filesize_human(int $bytes): string {
    if ($bytes < 1024)        return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

function proc_file_icon(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match(true) {
        in_array($ext, ['pdf'])                       => 'bi-file-earmark-pdf text-danger',
        in_array($ext, ['doc','docx','odt'])          => 'bi-file-earmark-word text-primary',
        in_array($ext, ['xls','xlsx','ods','csv'])    => 'bi-file-earmark-excel text-success',
        in_array($ext, ['png','jpg','jpeg','gif','webp']) => 'bi-file-earmark-image text-info',
        in_array($ext, ['zip'])                       => 'bi-file-earmark-zip text-warning',
        default                                        => 'bi-file-earmark text-secondary',
    };
}
