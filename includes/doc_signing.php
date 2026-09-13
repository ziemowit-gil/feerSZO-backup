<?php
/**
 * includes/doc_signing.php — moduł „Podpisz dokument".
 *
 * User wgrywa dokument, pobiera go, podpisuje SAMODZIELNIE poza systemem
 * (własnym certyfikatem X.509 — kwalifikowanym lub niekwalifikowanym, np.
 * mSzafir/Certum/SimplySign), a następnie wgrywa podpisany plik z powrotem. Serwer nie ma
 * dostępu do klucza prywatnego — podpis powstaje zawsze „zewnętrznie".
 * Kryptograficzną detekcję/walidację podpisu wykonuje includes/sigcheck.php
 * (współdzielone z modułem EZD i walidacją podpisów umów).
 */
require_once __DIR__ . '/sigcheck.php';

const DOC_SIGN_UPLOAD_SUBDIR      = 'podpisy/';
const DOC_SIGN_MAX_SIZE           = 20 * 1024 * 1024; // 20 MB
const DOC_SIGN_ALLOWED_ORIGINAL_EXT = ['pdf','doc','docx','odt','xls','xlsx','ods','txt'];

/** Tworzy schemat tabeli przy pierwszym użyciu modułu (idempotentne). */
function doc_signing_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS doc_signatures (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id           INTEGER NOT NULL REFERENCES users(id) ON DELETE SET NULL,
        title             TEXT    NOT NULL,
        status            TEXT    NOT NULL DEFAULT 'oczekuje',
        original_filename TEXT    NOT NULL DEFAULT '',
        original_name     TEXT    NOT NULL DEFAULT '',
        original_mime     TEXT,
        original_size     INTEGER,
        signed_filename   TEXT,
        signed_name       TEXT,
        signed_mime       TEXT,
        signed_size       INTEGER,
        sig_format        TEXT,
        sig_signer_cn     TEXT,
        sig_issuer        TEXT,
        sig_serial        TEXT,
        sig_valid_from    TEXT,
        sig_valid_to      TEXT,
        sig_integrity     TEXT,
        sig_messages      TEXT,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        signed_at         DATETIME
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ds_user ON doc_signatures(user_id)");
}

function ds_dir(int $id): string {
    return UPLOAD_DIR . DOC_SIGN_UPLOAD_SUBDIR . $id . '/';
}

function ds_filesize_human(int $bytes): string {
    if ($bytes < 1024)        return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

/** @return array{label:string,class:string} */
function ds_status_badge(string $status): array {
    return match ($status) {
        'podpisany' => ['label' => 'Podpisany', 'class' => 'success'],
        default     => ['label' => 'Oczekuje na podpis', 'class' => 'warning text-dark'],
    };
}

function ds_get(int $id): ?array {
    return db_one("SELECT * FROM doc_signatures WHERE id=?", [$id]) ?: null;
}

function ds_list_for_user(int $user_id): array {
    return db_all("SELECT * FROM doc_signatures WHERE user_id=? ORDER BY created_at DESC", [$user_id]);
}

function ds_list_all(): array {
    return db_all(
        "SELECT ds.*, u.name AS owner_name, u.email AS owner_email
         FROM doc_signatures ds
         LEFT JOIN users u ON u.id = ds.user_id
         ORDER BY ds.created_at DESC"
    );
}

/**
 * Zapisuje nowy dokument do podpisania (plik z $_FILES[$field]).
 * @return array{ok:bool,error:?string,id:?int}
 */
function ds_upload_original(int $user_id, string $title, string $field = 'doc_file'): array {
    $title = trim($title);
    if ($title === '') return ['ok' => false, 'error' => 'Podaj nazwę/opis dokumentu.', 'id' => null];
    if (mb_strlen($title) > 255) $title = mb_substr($title, 0, 255);

    if (empty($_FILES[$field]['tmp_name'])) return ['ok' => false, 'error' => 'Nie wybrano pliku.', 'id' => null];
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'Błąd przesyłania pliku (kod: ' . $f['error'] . ').', 'id' => null];
    if ($f['size'] > DOC_SIGN_MAX_SIZE) return ['ok' => false, 'error' => 'Plik jest za duży (max 20 MB).', 'id' => null];

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, DOC_SIGN_ALLOWED_ORIGINAL_EXT, true)) {
        return ['ok' => false, 'error' => 'Niedozwolony format pliku. Dozwolone: ' . implode(', ', DOC_SIGN_ALLOWED_ORIGINAL_EXT), 'id' => null];
    }

    db()->prepare("INSERT INTO doc_signatures (user_id, title, status) VALUES (?,?, 'oczekuje')")
        ->execute([$user_id, $title]);
    $id = (int)db()->lastInsertId();

    $dir = ds_dir($id);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $stored_name = 'original_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    if (!move_uploaded_file($f['tmp_name'], $dir . $stored_name)) {
        db()->prepare("DELETE FROM doc_signatures WHERE id=?")->execute([$id]);
        return ['ok' => false, 'error' => 'Nie udało się zapisać pliku.', 'id' => null];
    }

    db()->prepare("UPDATE doc_signatures SET original_filename=?, original_name=?, original_mime=?, original_size=? WHERE id=?")
        ->execute([$stored_name, $f['name'], $f['type'] ?: 'application/octet-stream', $f['size'], $id]);

    return ['ok' => true, 'error' => null, 'id' => $id];
}

/**
 * Wgrywa podpisaną wersję dokumentu i uruchamia kryptograficzną detekcję/walidację
 * podpisu (includes/sigcheck.php). Odrzuca plik, jeśli nie wykryto w nim podpisu.
 * @return array{ok:bool,error:?string,info:?array}
 */
function ds_upload_signed(int $id, int $user_id, bool $is_staff, string $field = 'signed_file'): array {
    $row = ds_get($id);
    if (!$row) return ['ok' => false, 'error' => 'Nie znaleziono dokumentu.', 'info' => null];
    if ((int)$row['user_id'] !== $user_id && !$is_staff) {
        return ['ok' => false, 'error' => 'Brak uprawnień do tego dokumentu.', 'info' => null];
    }

    if (empty($_FILES[$field]['tmp_name'])) return ['ok' => false, 'error' => 'Nie wybrano pliku.', 'info' => null];
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'Błąd przesyłania pliku (kod: ' . $f['error'] . ').', 'info' => null];
    if ($f['size'] > DOC_SIGN_MAX_SIZE) return ['ok' => false, 'error' => 'Plik jest za duży (max 20 MB).', 'info' => null];

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, EZD_SIG_EXTS, true)) {
        return ['ok' => false, 'error' => 'Niedozwolony format pliku. Dozwolone: ' . implode(', ', EZD_SIG_EXTS), 'info' => null];
    }

    $dir = ds_dir($id);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $stored_name = 'signed_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $path = $dir . $stored_name;

    if (!move_uploaded_file($f['tmp_name'], $path)) {
        return ['ok' => false, 'error' => 'Nie udało się zapisać pliku.', 'info' => null];
    }

    $info = ezd_signature_info($path, $f['name']);
    if (!$info['signed']) {
        @unlink($path);
        return ['ok' => false, 'error' => 'Przesłany plik nie zawiera wykrywalnego podpisu elektronicznego. Podpisz dokument certyfikatem X.509 i wgraj podpisaną wersję.', 'info' => null];
    }
    $valid = ezd_validate_signature($path, $f['name']);
    $cert  = $valid['certs'][0] ?? null;

    db()->prepare(
        "UPDATE doc_signatures SET
            signed_filename=?, signed_name=?, signed_mime=?, signed_size=?,
            sig_format=?, sig_signer_cn=?, sig_issuer=?, sig_serial=?,
            sig_valid_from=?, sig_valid_to=?, sig_integrity=?, sig_messages=?,
            status='podpisany', signed_at=CURRENT_TIMESTAMP
         WHERE id=?"
    )->execute([
        $stored_name, $f['name'], $f['type'] ?: 'application/octet-stream', $f['size'],
        $info['type'] ?: null, $cert['cn'] ?? $info['signer'] ?? null, $cert['issuer'] ?? null, $cert['serial'] ?? null,
        $cert['from'] ?? null, $cert['to'] ?? null, $valid['integrity'] ?? null, json_encode($valid['messages'] ?? [], JSON_UNESCAPED_UNICODE),
        $id,
    ]);

    return ['ok' => true, 'error' => null, 'info' => array_merge($info, ['cert' => $cert])];
}

/** Usuwa dokument oczekujący na podpis (właściciel lub personel). Podpisanych nie usuwamy — pozostają jako dowód. */
function ds_delete(int $id, int $user_id, bool $is_staff): array {
    $row = ds_get($id);
    if (!$row) return ['ok' => false, 'error' => 'Nie znaleziono dokumentu.'];
    if ((int)$row['user_id'] !== $user_id && !$is_staff) return ['ok' => false, 'error' => 'Brak uprawnień do tego dokumentu.'];
    if ($row['status'] !== 'oczekuje') return ['ok' => false, 'error' => 'Podpisanego dokumentu nie można usunąć.'];

    $dir = ds_dir($id);
    if (is_dir($dir)) {
        foreach (glob($dir . '*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
    db()->prepare("DELETE FROM doc_signatures WHERE id=?")->execute([$id]);
    return ['ok' => true, 'error' => null];
}
