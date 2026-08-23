<?php
/**
 * includes/crm_attachments.php — poczekalnia załączników dla wysyłki e-mail z CRM.
 *
 * Kompozytor w modalu wysyła JSON-em (bez multipart), a import z OneDrive w ogóle
 * nie ma pliku po stronie przeglądarki. Oba źródła najpierw ZAPISUJĄ plik na dysku
 * i rejestrują go w sesji pod tokenem; formularz wysyła już tylko listę tokenów.
 *
 * Dzięki temu klient nigdy nie podaje ścieżki pliku — nie da się podpiąć obcego
 * pliku z serwera do wysyłanej wiadomości.
 *
 * Deskryptor załącznika jest zgodny z mail_queue_add(): ['path','name','mime','size'],
 * gdzie 'path' jest względne wobec UPLOAD_DIR.
 */

require_once __DIR__ . '/mail_queue.php';

const CRM_ATT_MAX_FILES = 5;
const CRM_ATT_MAX_BYTES = 15 * 1024 * 1024;   // jak w mail_queue_save_attachment()
const CRM_ATT_TTL       = 6 * 3600;           // porzucone załączniki sprzątamy po 6 h

/** Rozszerzenia dozwolone w załącznikach (spójne z mail_queue_save_attachment()). */
function crm_att_allowed_ext(): array {
    return ['pdf','doc','docx','xls','xlsx','csv','txt','png','jpg','jpeg','gif','zip','rar','7z','odt','ods'];
}

function crm_att_dir(): string {
    $dir = UPLOAD_DIR . 'crm_attachments/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** Usuwa z sesji (i z dysku) załączniki, których nikt nie wysłał w ciągu CRM_ATT_TTL. */
function crm_att_gc(): void {
    if (empty($_SESSION['crm_att'])) return;
    $now = time();
    foreach ($_SESSION['crm_att'] as $token => $a) {
        if ($now - (int)($a['ts'] ?? 0) < CRM_ATT_TTL) continue;
        $full = UPLOAD_DIR . ($a['path'] ?? '');
        if (($a['path'] ?? '') !== '' && is_file($full)) @unlink($full);
        unset($_SESSION['crm_att'][$token]);
    }
}

/** Rejestruje deskryptor w poczekalni i zwraca token dla klienta. */
function crm_att_register(array $att, string $source = 'upload'): string {
    crm_att_gc();
    $token = bin2hex(random_bytes(8));
    $_SESSION['crm_att'][$token] = [
        'path'   => $att['path'],
        'name'   => $att['name'],
        'mime'   => $att['mime'],
        'size'   => (int)$att['size'],
        'source' => $source,
        'ts'     => time(),
    ];
    return $token;
}

/** Ile załączników czeka w poczekalni tej sesji. */
function crm_att_count(): int {
    crm_att_gc();
    return count($_SESSION['crm_att'] ?? []);
}

/**
 * Przyjmuje plik z $_FILES i odkłada go w poczekalni.
 *
 * @return array ['ok'=>bool, 'error'=>string, 'token'=>string, 'att'=>array]
 */
function crm_att_stage_upload(array $file): array {
    if (crm_att_count() >= CRM_ATT_MAX_FILES) {
        return ['ok' => false, 'error' => 'Maksymalnie ' . CRM_ATT_MAX_FILES . ' załączników.'];
    }
    $name = basename((string)($file['name'] ?? ''));
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Błąd przesyłania pliku „' . $name . '".'];
    }
    if ((int)($file['size'] ?? 0) > CRM_ATT_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Plik „' . $name . '" przekracza 15 MB.'];
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, crm_att_allowed_ext(), true)) {
        return ['ok' => false, 'error' => 'Niedozwolony typ pliku: .' . $ext];
    }

    $att = mail_queue_save_attachment($file);
    if (!$att) return ['ok' => false, 'error' => 'Nie udało się zapisać pliku „' . $name . '".'];

    $token = crm_att_register($att, 'upload');
    return ['ok' => true, 'error' => '', 'token' => $token, 'att' => $att];
}

/**
 * Odkłada w poczekalni plik pobrany z zewnątrz (OneDrive) — bajty, nie upload.
 *
 * @return array ['ok'=>bool, 'error'=>string, 'token'=>string, 'att'=>array]
 */
function crm_att_stage_bytes(string $orig_name, string $bytes, string $source = 'onedrive'): array {
    if (crm_att_count() >= CRM_ATT_MAX_FILES) {
        return ['ok' => false, 'error' => 'Maksymalnie ' . CRM_ATT_MAX_FILES . ' załączników.'];
    }
    $orig_name = basename($orig_name);
    if ($orig_name === '') return ['ok' => false, 'error' => 'Brak nazwy pliku.'];
    if (strlen($bytes) === 0)                  return ['ok' => false, 'error' => 'Pusty plik „' . $orig_name . '".'];
    if (strlen($bytes) > CRM_ATT_MAX_BYTES)    return ['ok' => false, 'error' => 'Plik „' . $orig_name . '" przekracza 15 MB.'];

    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, crm_att_allowed_ext(), true)) {
        return ['ok' => false, 'error' => 'Niedozwolony typ pliku: .' . $ext];
    }

    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest   = crm_att_dir() . $stored;
    if (file_put_contents($dest, $bytes) === false) {
        return ['ok' => false, 'error' => 'Nie udało się zapisać pliku „' . $orig_name . '".'];
    }
    @chmod($dest, 0644);

    $att = [
        'path' => 'crm_attachments/' . $stored,
        'name' => $orig_name,
        'mime' => mime_content_type($dest) ?: 'application/octet-stream',
        'size' => filesize($dest) ?: strlen($bytes),
    ];
    $token = crm_att_register($att, $source);
    return ['ok' => true, 'error' => '', 'token' => $token, 'att' => $att];
}

/** Usuwa załącznik z poczekalni razem z plikiem (użytkownik zdjął go z listy). */
function crm_att_drop(string $token): bool {
    $a = $_SESSION['crm_att'][$token] ?? null;
    if (!$a) return false;
    $full = UPLOAD_DIR . $a['path'];
    if (is_file($full)) @unlink($full);
    unset($_SESSION['crm_att'][$token]);
    return true;
}

/**
 * Zamienia tokeny z formularza na deskryptory dla mail_queue_add().
 * Zużyte tokeny znikają z poczekalni, ale PLIKÓW NIE KASUJEMY — od tej chwili
 * należą do kolejki mailowej (mail_queue czyta je przy wysyłce).
 *
 * @param  array $tokens Tokeny z żądania (cokolwiek klient przysłał)
 * @return array Lista ['path','name','mime','size']
 */
function crm_att_resolve(array $tokens): array {
    $out = [];
    foreach ($tokens as $t) {
        $t = is_string($t) ? $t : '';
        if ($t === '' || empty($_SESSION['crm_att'][$t])) continue;
        $a = $_SESSION['crm_att'][$t];
        if (!is_file(UPLOAD_DIR . $a['path'])) { unset($_SESSION['crm_att'][$t]); continue; }
        $out[] = ['path' => $a['path'], 'name' => $a['name'], 'mime' => $a['mime'], 'size' => (int)$a['size']];
        unset($_SESSION['crm_att'][$t]);
        if (count($out) >= CRM_ATT_MAX_FILES) break;
    }
    return $out;
}

/**
 * Kopiuje istniejący załącznik (np. z szablonu) do NOWEGO pliku w poczekalni.
 *
 * Kopia jest konieczna: usunięcie pozycji z listy kasuje plik z dysku, a plik
 * szablonu ma przeżyć wysyłkę i kolejne wiadomości.
 */
function crm_att_stage_copy(array $att): array {
    if (crm_att_count() >= CRM_ATT_MAX_FILES) {
        return ['ok' => false, 'error' => 'Maksymalnie ' . CRM_ATT_MAX_FILES . ' załączników.'];
    }
    $src = UPLOAD_DIR . ltrim((string)($att['path'] ?? ''), '/');
    if (($att['path'] ?? '') === '' || !is_file($src)) {
        return ['ok' => false, 'error' => 'Plik „' . ($att['name'] ?? '?') . '" nie istnieje już na dysku.'];
    }
    $ext = strtolower(pathinfo((string)$att['name'], PATHINFO_EXTENSION))
        ?: strtolower(pathinfo($src, PATHINFO_EXTENSION));
    if (!in_array($ext, crm_att_allowed_ext(), true)) {
        return ['ok' => false, 'error' => 'Niedozwolony typ pliku: .' . $ext];
    }

    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest   = crm_att_dir() . $stored;
    if (!@copy($src, $dest)) {
        return ['ok' => false, 'error' => 'Nie udało się przygotować pliku „' . ($att['name'] ?? '') . '".'];
    }
    @chmod($dest, 0644);

    $new = [
        'path' => 'crm_attachments/' . $stored,
        'name' => (string)($att['name'] ?? basename($src)),
        'mime' => (string)($att['mime'] ?? (mime_content_type($dest) ?: 'application/octet-stream')),
        'size' => filesize($dest) ?: (int)($att['size'] ?? 0),
    ];
    return ['ok' => true, 'error' => '', 'token' => crm_att_register($new, 'template'), 'att' => $new];
}

// ── Załączniki szablonów wiadomości ─────────────────────────────────────────

/** Kolumna z załącznikami szablonu (samonaprawa — szablony są starsze niż ta funkcja). */
function crm_tpl_att_schema_heal(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->exec("ALTER TABLE crm_templates ADD COLUMN attachments TEXT NOT NULL DEFAULT '[]'"); }
    catch (\Throwable $e) {}
}

/** Załączniki przypięte do szablonu: [['path','name','mime','size'], …]. */
function crm_tpl_attachments(int $template_id): array {
    if ($template_id <= 0) return [];
    crm_tpl_att_schema_heal();
    try {
        $raw = (string)(db_one("SELECT attachments FROM crm_templates WHERE id=?", [$template_id])['attachments'] ?? '');
    } catch (\Throwable $e) { return []; }
    $arr = json_decode($raw ?: '[]', true);
    if (!is_array($arr)) return [];

    $out = [];
    foreach ($arr as $a) {
        if (!is_array($a) || empty($a['path']) || !is_file(UPLOAD_DIR . $a['path'])) continue;   // plik skasowany
        $out[] = ['path' => (string)$a['path'], 'name' => (string)($a['name'] ?? basename($a['path'])),
                  'mime' => (string)($a['mime'] ?? 'application/octet-stream'), 'size' => (int)($a['size'] ?? 0)];
    }
    return $out;
}

/** Zapisuje listę załączników szablonu (deskryptory jak w mail_queue_add()). */
function crm_tpl_attachments_save(int $template_id, array $atts): void {
    if ($template_id <= 0) return;
    crm_tpl_att_schema_heal();
    $clean = [];
    foreach (array_slice($atts, 0, CRM_ATT_MAX_FILES) as $a) {
        if (empty($a['path'])) continue;
        $clean[] = ['path' => (string)$a['path'], 'name' => (string)($a['name'] ?? ''),
                    'mime' => (string)($a['mime'] ?? ''), 'size' => (int)($a['size'] ?? 0)];
    }
    try {
        db()->prepare("UPDATE crm_templates SET attachments=? WHERE id=?")
            ->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), $template_id]);
    } catch (\Throwable $e) {
        error_log('[crm_tpl_attachments_save] ' . $e->getMessage());
    }
}

// ── OneDrive zalogowanego użytkownika ───────────────────────────────────────

/**
 * Czy bieżący użytkownik może zaciągać załączniki ze swojego OneDrive?
 * Warunek: konto połączone z Microsoft 365 (microsoft_id + e-mail) i skonfigurowany Graph.
 */
function crm_att_onedrive_available(?array $user = null): bool {
    $u = $user ?? current_user();
    if (empty($u['microsoft_id']) || empty($u['email'])) return false;
    require_once __DIR__ . '/m365.php';
    try {
        $g = new M365Graph();
        return $g->is_configured();
    } catch (\Throwable $e) {
        return false;
    }
}

/** UPN skrzynki bieżącego użytkownika (adres jego konta M365). */
function crm_att_onedrive_upn(?array $user = null): string {
    $u = $user ?? current_user();
    return trim((string)($u['email'] ?? ''));
}
