<?php
/**
 * Moduł „Dokumenty organizacji" — proste dokumenty plikowe (statut, regulaminy,
 * formularze, wzory) z tytułem, kategorią i opisem. Admin wgrywa, wszyscy czytają.
 * Wzorowane na module Procedury (bez wersjonowania — jeden plik = jeden dokument).
 */

// ── Auto-migracja ─────────────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS org_documents (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        title         TEXT    NOT NULL,
        description   TEXT    NOT NULL DEFAULT '',
        category      TEXT    NOT NULL DEFAULT '',
        filename      TEXT    NOT NULL DEFAULT '',
        original_name TEXT    NOT NULL DEFAULT '',
        mime_type     TEXT    NOT NULL DEFAULT '',
        file_size     INTEGER NOT NULL DEFAULT 0,
        is_active     INTEGER NOT NULL DEFAULT 1,
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orgdoc_active ON org_documents(is_active)"); } catch (\Throwable $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orgdoc_cat    ON org_documents(category)"); } catch (\Throwable $e) {}
})();

// ── Stałe ─────────────────────────────────────────────────────────────────────
const ORGDOC_UPLOAD_SUBDIR = 'org_documents/';
const ORGDOC_ALLOWED_EXT   = ['pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp','png','jpg','jpeg','gif','webp','zip','txt','csv'];
const ORGDOC_MAX_SIZE      = 25 * 1024 * 1024; // 25 MB

// ── Odczyt ────────────────────────────────────────────────────────────────────
function org_docs_all(array $f = []): array {
    $where = []; $p = [];
    if (!empty($f['active_only'])) $where[] = 'is_active = 1';
    if (!empty($f['q'])) {
        $where[] = '(title LIKE ? OR description LIKE ?)';
        $like = '%' . $f['q'] . '%'; $p[] = $like; $p[] = $like;
    }
    if (!empty($f['category'])) { $where[] = 'category = ?'; $p[] = $f['category']; }
    $sql = "SELECT d.*, u.name AS created_by_name FROM org_documents d
            LEFT JOIN users u ON u.id = d.created_by";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY d.category, d.title';
    return db_all($sql, $p);
}

function org_docs_get(int $id): ?array {
    return db_one("SELECT * FROM org_documents WHERE id=?", [$id]) ?: null;
}

function org_docs_categories(): array {
    return array_column(
        db_all("SELECT DISTINCT category FROM org_documents WHERE category != '' ORDER BY category"),
        'category'
    );
}

// ── Zapis ─────────────────────────────────────────────────────────────────────
/**
 * Wgraj plik dokumentu. Zwraca metadane lub rzuca RuntimeException.
 * @return array{filename:string,original_name:string,mime_type:string,file_size:int}
 */
function org_docs_upload(string $field): array {
    if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Nie przesłano pliku lub wystąpił błąd wysyłki.');
    }
    $size = (int)$_FILES[$field]['size'];
    if ($size <= 0 || $size > ORGDOC_MAX_SIZE) {
        throw new RuntimeException('Plik jest pusty lub przekracza ' . (ORGDOC_MAX_SIZE / 1048576) . ' MB.');
    }
    $orig = $_FILES[$field]['name'];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ORGDOC_ALLOWED_EXT, true)) {
        throw new RuntimeException('Niedozwolony typ pliku (.' . $ext . ').');
    }
    $dir = rtrim(UPLOAD_DIR, '/') . '/' . ORGDOC_UPLOAD_SUBDIR;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fname = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . $fname)) {
        throw new RuntimeException('Nie udało się zapisać pliku na serwerze.');
    }
    return [
        'filename'      => $fname,
        'original_name' => $orig,
        'mime_type'     => $_FILES[$field]['type'] ?: 'application/octet-stream',
        'file_size'     => $size,
    ];
}

function org_docs_create(array $data, int $userId): int {
    return db_insert('org_documents', [
        'title'         => $data['title'],
        'description'   => $data['description'] ?? '',
        'category'      => $data['category'] ?? '',
        'filename'      => $data['filename'] ?? '',
        'original_name' => $data['original_name'] ?? '',
        'mime_type'     => $data['mime_type'] ?? '',
        'file_size'     => (int)($data['file_size'] ?? 0),
        'is_active'     => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        'created_by'    => $userId ?: null,
    ]);
}

function org_docs_update(int $id, array $data): void {
    $data['updated_at'] = date('Y-m-d H:i:s');
    db_update('org_documents', $data, $id);
}

function org_docs_delete(int $id): void {
    $d = org_docs_get($id);
    if ($d && $d['filename']) {
        $path = rtrim(UPLOAD_DIR, '/') . '/' . ORGDOC_UPLOAD_SUBDIR . $d['filename'];
        if (is_file($path)) @unlink($path);
    }
    db()->prepare("DELETE FROM org_documents WHERE id=?")->execute([$id]);
}

/** Wyślij plik do przeglądarki (podgląd lub pobranie). */
function org_docs_send_file(int $id, bool $download = false): void {
    $d = org_docs_get($id);
    if (!$d || $d['filename'] === '') { http_response_code(404); exit('Nie znaleziono dokumentu.'); }
    $path = rtrim(UPLOAD_DIR, '/') . '/' . ORGDOC_UPLOAD_SUBDIR . $d['filename'];
    if (!is_file($path)) { http_response_code(404); exit('Plik nie istnieje.'); }
    header('Content-Type: ' . ($d['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=600');
    $disp = $download ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', $d['original_name'] ?: $d['filename']) . '"');
    readfile($path);
    exit;
}

// ── Helpery prezentacji ─────────────────────────────────────────────────────────
function org_docs_filesize_human(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

function org_docs_file_icon(string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match (true) {
        $ext === 'pdf'                              => 'bi-file-earmark-pdf',
        in_array($ext, ['doc','docx','odt'], true)  => 'bi-file-earmark-word',
        in_array($ext, ['xls','xlsx','ods','csv'], true) => 'bi-file-earmark-spreadsheet',
        in_array($ext, ['ppt','pptx','odp'], true)  => 'bi-file-earmark-slides',
        in_array($ext, ['png','jpg','jpeg','gif','webp'], true) => 'bi-file-earmark-image',
        $ext === 'zip'                              => 'bi-file-earmark-zip',
        default                                     => 'bi-file-earmark-text',
    };
}
