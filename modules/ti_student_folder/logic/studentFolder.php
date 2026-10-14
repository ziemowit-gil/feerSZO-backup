<?php
/**
 * modules/ti_student_folder/logic/studentFolder.php — „Dokumenty kursanta”: teczka plików i dokumenty do akceptacji.
 *
 * Teczka (k30_ti_sfile): dowolna liczba plików przy koncie kursanta (k30_ti_student_accounts) — kategoria, tytuł, opis,
 * data ważności, widoczność dla kursanta. Dodaje kadra (od razu zatwierdzone) albo kursant / opiekun (czekają na
 * zatwierdzenie kadry; odrzucone wracają z powodem). Usuwanie miękkie (deleted_at) — plik zostaje na dysku.
 * Dotychczasowy „dokument potwierdzający” (modules/ti_student_docs) wpada do teczki jako kategoria „potwierdzenie”.
 *
 * Do akceptacji (k30_ti_srequest): kadra wystawia dokument (tytuł, treść, opcjonalny załącznik z teczki), kursant —
 * a za małoletniego opiekun — czyta i akceptuje albo odmawia z powodem. Przy akceptacji zapisujemy kto, kiedy, IP,
 * przeglądarkę i skrót SHA-256 treści; potwierdzenie w PDF trafia do teczki.
 *
 * Wygasanie: przypomnienie e-mailem N dni przed końcem ważności (cron/ti_student_folder_expiry.php), domyślnie wyłączone.
 * Pliki: UPLOAD_DIR/ti_teczka/ (zablokowane .htaccess; serwowane tylko przez dydaktyk/dokumentyKursanta.php
 * i kursant/dokumenty.php po sprawdzeniu uprawnień).
 */
final class TiStudentFolder
{
    public const MAX_BYTES  = 15 * 1024 * 1024;
    public const DIR        = 'ti_teczka';
    public const CATEGORIES = [
        'umowa'         => 'Umowa',
        'orzeczenie'    => 'Orzeczenie',
        'zaswiadczenie' => 'Zaświadczenie',
        'certyfikat'    => 'Certyfikat / dyplom',
        'zgoda'         => 'Zgoda / oświadczenie',
        'potwierdzenie' => 'Potwierdzenie',
        'inne'          => 'Inne',
    ];
    public const SOURCES  = ['staff' => 'kadra', 'student' => 'kursant', 'parent' => 'opiekun', 'legacy' => 'dokument potwierdzający', 'system' => 'system'];
    public const STATUSES = ['approved' => 'zatwierdzony', 'pending' => 'czeka na zatwierdzenie', 'rejected' => 'odrzucony'];
    private const TYPES = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.oasis.opendocument.text' => 'odt',
    ];
    public const MIME_BY_EXT = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'odt' => 'application/vnd.oasis.opendocument.text'];
    public const ACCEPT_ATTR = '.pdf,.jpg,.jpeg,.png,.docx,.odt';

    // ── Schemat ─────────────────────────────────────────────────────────────────

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('ti_student_folder_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_sfile (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL,
            category TEXT NOT NULL DEFAULT 'inne',
            title TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            file_path TEXT NOT NULL DEFAULT '',
            file_name TEXT NOT NULL DEFAULT '',
            mime TEXT NOT NULL DEFAULT '',
            size INTEGER NOT NULL DEFAULT 0,
            sha256 TEXT NOT NULL DEFAULT '',
            valid_until DATE,
            visible_student INTEGER NOT NULL DEFAULT 1,
            source TEXT NOT NULL DEFAULT 'staff',
            status TEXT NOT NULL DEFAULT 'approved',
            reject_reason TEXT NOT NULL DEFAULT '',
            created_by INTEGER,
            created_by_name TEXT NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT (datetime('now')),
            reviewed_by INTEGER,
            reviewed_at DATETIME,
            deleted_at DATETIME,
            deleted_by INTEGER,
            expiry_notified_at DATETIME
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_k30_ti_sfile_acc ON k30_ti_sfile(account_id, deleted_at)");
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_srequest (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT '',
            file_id INTEGER,
            content_hash TEXT NOT NULL DEFAULT '',
            due_date DATE,
            status TEXT NOT NULL DEFAULT 'pending',
            created_by INTEGER,
            created_by_name TEXT NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT (datetime('now')),
            decided_at DATETIME,
            decided_kind TEXT NOT NULL DEFAULT '',
            decided_name TEXT NOT NULL DEFAULT '',
            decided_ip TEXT NOT NULL DEFAULT '',
            decided_ua TEXT NOT NULL DEFAULT '',
            decline_reason TEXT NOT NULL DEFAULT '',
            confirm_file_id INTEGER
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_k30_ti_srequest_acc ON k30_ti_srequest(account_id, status)");
        self::importLegacy();
    }

    /** Jednorazowo: skany „dokumentu potwierdzającego” trafiają do teczki (plik zostaje w ti_dokumenty/, wpis wskazuje na niego). */
    private static function importLegacy(): void
    {
        if (db_one("SELECT value FROM settings WHERE key_='ti_sfold_legacy_done'")) return;
        try {
            $rows = db_all("SELECT id, requires_doc_label, doc_path, doc_name, doc_at FROM k30_ti_student_accounts WHERE doc_status='scan' AND doc_path<>''");
            foreach ($rows as $r) self::addLegacyScan((int)$r['id'], (string)$r['doc_path'], (string)$r['doc_name'], (string)$r['requires_doc_label'], (string)$r['doc_at']);
        } catch (\Throwable $e) { /* starsza baza bez kolumn doc_* — nic do przeniesienia */ }
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES ('ti_sfold_legacy_done', ?)")->execute([date('Y-m-d H:i:s')]);
    }

    /** Wpis teczki dla skanu dokumentu potwierdzającego (wołane też z TiStudentDocs::submitScan). */
    public static function addLegacyScan(int $accountId, string $path, string $name, string $label, string $at = ''): void
    {
        self::migrate();
        if (!preg_match('#^ti_dokumenty/[0-9]+_[a-f0-9]{16}\.(pdf|jpg|png)$#', $path, $m)) return;
        if (db_one("SELECT id FROM k30_ti_sfile WHERE account_id=? AND file_path=?", [$accountId, $path])) return;
        $full = rtrim(UPLOAD_DIR, '/') . '/' . $path;
        db()->prepare("INSERT INTO k30_ti_sfile (account_id, category, title, file_path, file_name, mime, size, sha256, source, status, created_at, created_by_name)
                       VALUES (?, 'potwierdzenie', ?, ?, ?, ?, ?, ?, 'legacy', 'approved', ?, 'kursant')")
           ->execute([$accountId, mb_substr(trim($label) ?: 'Dokument potwierdzający', 0, 200), $path, $name, self::MIME_BY_EXT[$m[1]],
                      is_file($full) ? (int)filesize($full) : 0, is_file($full) ? (string)hash_file('sha256', $full) : '', $at ?: date('Y-m-d H:i:s')]);
    }

    // ── Teczka ──────────────────────────────────────────────────────────────────

    /** Pliki konta. $forStudent: tylko widoczne dla kursanta i (zatwierdzone albo dodane przez kursanta/opiekuna). */
    public static function files(int $accountId, bool $forStudent = false): array
    {
        self::migrate();
        $sql = "SELECT * FROM k30_ti_sfile WHERE account_id=? AND deleted_at IS NULL";
        if ($forStudent) $sql .= " AND (source IN ('student','parent') OR (visible_student=1 AND status='approved'))";
        return db_all($sql . " ORDER BY created_at DESC, id DESC", [$accountId]);
    }

    public static function file(int $id): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM k30_ti_sfile WHERE id=? AND deleted_at IS NULL", [$id]) ?: null;
    }

    /**
     * Zapis pliku z $_FILES. $meta: category, title, note, valid_until, visible_student. $source: staff|student|parent.
     * @return array{0:int,1:string} [id wpisu, komunikat błędu albo '']
     */
    public static function upload(int $accountId, array $file, array $meta, string $source, ?int $by, string $byName): array
    {
        self::migrate();
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return [0, 'Wybierz plik.'];
        if ((int)$file['size'] > self::MAX_BYTES) return [0, 'Plik jest za duży (najwyżej 15 MB).'];
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        if (!isset(self::TYPES[$mime])) return [0, 'Dozwolone pliki: PDF, JPG, PNG, DOCX, ODT.'];
        $title = mb_substr(trim((string)($meta['title'] ?? '')), 0, 200);
        if ($title === '') $title = mb_substr(pathinfo((string)$file['name'], PATHINFO_FILENAME), 0, 200) ?: 'Dokument';
        $valid = self::date((string)($meta['valid_until'] ?? ''));
        if (($meta['valid_until'] ?? '') !== '' && $valid === null) return [0, 'Nieprawidłowa data ważności.'];
        $path = self::store((string)$file['tmp_name'], $accountId, self::TYPES[$mime], true);
        if ($path === '') return [0, 'Nie udało się zapisać pliku — spróbuj ponownie.'];
        $full = rtrim(UPLOAD_DIR, '/') . '/' . $path;
        $staff = $source === 'staff';
        db()->prepare("INSERT INTO k30_ti_sfile (account_id, category, title, note, file_path, file_name, mime, size, sha256, valid_until, visible_student,
                       source, status, created_by, created_by_name, reviewed_by, reviewed_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$accountId, self::cat((string)($meta['category'] ?? '')), $title, mb_substr(trim((string)($meta['note'] ?? '')), 0, 1000),
                      $path, mb_substr(basename((string)$file['name']), 0, 150), $mime, (int)filesize($full), (string)hash_file('sha256', $full),
                      $valid, $staff ? (!empty($meta['visible_student']) ? 1 : 0) : 1, $source, $staff ? 'approved' : 'pending',
                      $by, $byName, $staff ? $by : null, $staff ? date('Y-m-d H:i:s') : null]);
        $id = (int)db()->lastInsertId();
        self::log($accountId, 'Teczka: dodano „' . $title . '” (' . (self::SOURCES[$source] ?? $source) . ').', $staff ? $by : null, $byName);
        return [$id, ''];
    }

    /** Kadra: zmiana opisu wpisu (kategoria, tytuł, opis, ważność, widoczność). */
    public static function update(int $id, array $meta, int $by, string $byName): string
    {
        $f = self::file($id);
        if (!$f) return 'Nie znaleziono dokumentu.';
        $valid = self::date((string)($meta['valid_until'] ?? ''));
        if (($meta['valid_until'] ?? '') !== '' && $valid === null) return 'Nieprawidłowa data ważności.';
        $title = mb_substr(trim((string)($meta['title'] ?? '')), 0, 200) ?: (string)$f['title'];
        db()->prepare("UPDATE k30_ti_sfile SET category=?, title=?, note=?, valid_until=?, visible_student=?,
                       expiry_notified_at = CASE WHEN COALESCE(valid_until,'') = COALESCE(?, '') THEN expiry_notified_at ELSE NULL END WHERE id=?")
           ->execute([self::cat((string)($meta['category'] ?? '')), $title, mb_substr(trim((string)($meta['note'] ?? '')), 0, 1000), $valid,
                      !empty($meta['visible_student']) ? 1 : 0, $valid, $id]);
        self::log((int)$f['account_id'], 'Teczka: zmieniono opis „' . $title . '”.', $by, $byName);
        return '';
    }

    /** Kadra: zatwierdzenie albo odrzucenie pliku od kursanta/opiekuna. */
    public static function review(int $id, bool $approve, string $reason, int $by, string $byName): string
    {
        $f = self::file($id);
        if (!$f) return 'Nie znaleziono dokumentu.';
        if (!$approve && trim($reason) === '') return 'Podaj powód odrzucenia — kursant go zobaczy.';
        db()->prepare("UPDATE k30_ti_sfile SET status=?, reject_reason=?, reviewed_by=?, reviewed_at=datetime('now') WHERE id=?")
           ->execute([$approve ? 'approved' : 'rejected', $approve ? '' : mb_substr(trim($reason), 0, 500), $by, $id]);
        self::log((int)$f['account_id'], 'Teczka: ' . ($approve ? 'zatwierdzono' : 'odrzucono') . ' „' . $f['title'] . '”' . ($approve ? '' : ' — ' . trim($reason)) . '.', $by, $byName);
        return '';
    }

    /** Usunięcie (miękkie). Kursant/opiekun może usunąć tylko własny plik, który nie jest jeszcze zatwierdzony. */
    public static function delete(int $id, ?int $by, string $byName, ?int $onlyAccount = null): string
    {
        $f = self::file($id);
        if (!$f || ($onlyAccount !== null && (int)$f['account_id'] !== $onlyAccount)) return 'Nie znaleziono dokumentu.';
        if ($onlyAccount !== null && (!in_array($f['source'], ['student', 'parent'], true) || $f['status'] === 'approved')) return 'Tego dokumentu nie możesz usunąć.';
        if (db_one("SELECT id FROM k30_ti_srequest WHERE (file_id=? OR confirm_file_id=?) AND status<>'cancelled'", [$id, $id])) return 'Dokument jest powiązany z dokumentem do akceptacji — najpierw anuluj tamten.';
        db()->prepare("UPDATE k30_ti_sfile SET deleted_at=datetime('now'), deleted_by=? WHERE id=?")->execute([$by, $id]);
        self::log((int)$f['account_id'], 'Teczka: usunięto „' . $f['title'] . '”.', $by, $byName);
        return '';
    }

    /** Pełna ścieżka pliku wpisu (tylko w ti_teczka/ albo ti_dokumenty/) albo null. */
    public static function path(array $f): ?string
    {
        $p = (string)($f['file_path'] ?? '');
        if (!preg_match('#^(ti_teczka|ti_dokumenty)/[0-9]+_[a-f0-9]{16}\.(pdf|jpg|png|docx|odt)$#', $p)) return null;
        $full = rtrim(UPLOAD_DIR, '/') . '/' . $p;
        return is_file($full) ? $full : null;
    }

    /** Wysyła plik do przeglądarki (inline dla PDF/obrazów, załącznik dla dokumentów tekstowych) i kończy. */
    public static function send(array $f): void
    {
        $full = self::path($f);
        if (!$full) { http_response_code(404); exit('Plik niedostępny.'); }
        $ext  = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $name = preg_replace('/[^\w.\-]+/u', '_', (string)($f['file_name'] ?: ('dokument-' . $f['id'] . '.' . $ext)));
        header('Content-Type: ' . (self::MIME_BY_EXT[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: ' . (in_array($ext, ['pdf', 'jpg', 'png'], true) ? 'inline' : 'attachment') . '; filename="' . $name . '"');
        header('Content-Length: ' . filesize($full));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        readfile($full);
        exit;
    }

    /** Stan ważności: '' (bez daty), 'ok', 'soon' (≤ $days dni), 'expired'. */
    public static function validity(?string $validUntil, int $days = 30): string
    {
        if (!$validUntil) return '';
        $t = strtotime($validUntil . ' 23:59:59');
        if ($t < time()) return 'expired';
        return $t < strtotime('+' . $days . ' days') ? 'soon' : 'ok';
    }

    // ── Dokumenty do akceptacji ─────────────────────────────────────────────────

    public static function requests(int $accountId): array
    {
        self::migrate();
        return db_all("SELECT * FROM k30_ti_srequest WHERE account_id=? AND status<>'cancelled' ORDER BY (status='pending') DESC, created_at DESC", [$accountId]);
    }

    public static function request(int $id): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM k30_ti_srequest WHERE id=?", [$id]) ?: null;
    }

    public static function pendingRequestCount(int $accountId): int
    {
        try { self::migrate(); return (int)(db_one("SELECT COUNT(*) AS c FROM k30_ti_srequest WHERE account_id=? AND status='pending'", [$accountId])['c'] ?? 0); }
        catch (\Throwable $e) { return 0; }
    }

    /** Skrót treści, który akceptuje kursant: tytuł + treść + skrót załącznika. */
    public static function contentHash(string $title, string $body, ?array $file): string
    {
        return hash('sha256', $title . "\n\n" . $body . "\n\n" . ($file['sha256'] ?? ''));
    }

    /**
     * Kadra wystawia dokument do akceptacji. Załącznik: istniejący plik teczki ($fileId) albo nowy z $_FILES ($upload).
     * @return array{0:int,1:string}
     */
    public static function createRequest(int $accountId, string $title, string $body, string $due, int $fileId, ?array $upload, int $by, string $byName): array
    {
        self::migrate();
        $title = mb_substr(trim($title), 0, 200);
        $body  = mb_substr(trim(str_replace("\r\n", "\n", $body)), 0, 20000);
        if ($title === '') return [0, 'Podaj tytuł dokumentu.'];
        $dueD = self::date($due);
        if ($due !== '' && $dueD === null) return [0, 'Nieprawidłowy termin.'];
        if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            [$fileId, $err] = self::upload($accountId, $upload, ['category' => 'zgoda', 'title' => $title, 'visible_student' => 1], 'staff', $by, $byName);
            if ($err !== '') return [0, $err];
        }
        $file = $fileId ? self::file($fileId) : null;
        if ($fileId && (!$file || (int)$file['account_id'] !== $accountId)) return [0, 'Wybrany załącznik nie należy do tego kursanta.'];
        if ($body === '' && !$file) return [0, 'Wpisz treść dokumentu albo dołącz plik.'];
        if ($file && !(int)$file['visible_student']) db()->prepare("UPDATE k30_ti_sfile SET visible_student=1 WHERE id=?")->execute([$fileId]);
        db()->prepare("INSERT INTO k30_ti_srequest (account_id, title, body, file_id, content_hash, due_date, created_by, created_by_name) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$accountId, $title, $body, $file ? $fileId : null, self::contentHash($title, $body, $file), $dueD, $by, $byName]);
        $id = (int)db()->lastInsertId();
        self::log($accountId, 'Dokumenty: wystawiono do akceptacji „' . $title . '”.', $by, $byName);
        return [$id, ''];
    }

    public static function cancelRequest(int $id, int $by, string $byName): string
    {
        $r = self::request($id);
        if (!$r || $r['status'] !== 'pending') return 'Można anulować tylko dokument, który czeka na decyzję.';
        db()->prepare("UPDATE k30_ti_srequest SET status='cancelled' WHERE id=?")->execute([$id]);
        self::log((int)$r['account_id'], 'Dokumenty: anulowano „' . $r['title'] . '”.', $by, $byName);
        return '';
    }

    /** Kto decyduje: małoletni → tylko opiekun; pełnoletni → kursant (opiekun nie ma dostępu). */
    public static function canDecide(array $account, string $kind): bool
    {
        return !empty($account['is_minor']) ? $kind === 'parent' : $kind === 'student';
    }

    /**
     * Akceptacja albo odmowa przez kursanta/opiekuna. $kind: student|parent; $name: imię i nazwisko decydującego.
     * Przy akceptacji: zapis śladu + PDF potwierdzenia w teczce. Treść musi się zgadzać ze skrótem z chwili wystawienia.
     */
    public static function decide(int $id, int $accountId, bool $accept, string $reason, string $kind, string $name): string
    {
        $r = self::request($id);
        if (!$r || (int)$r['account_id'] !== $accountId) return 'Nie znaleziono dokumentu.';
        if ($r['status'] !== 'pending') return 'Ten dokument ma już decyzję.';
        $acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$accountId]) ?: [];
        if (!self::canDecide($acc, $kind)) return !empty($acc['is_minor']) ? 'Za osobę niepełnoletnią decyzję podejmuje opiekun w panelu rodzica.' : 'Decyzję podejmuje kursant.';
        $file = $r['file_id'] ? db_one("SELECT * FROM k30_ti_sfile WHERE id=?", [(int)$r['file_id']]) : null;
        if (!hash_equals((string)$r['content_hash'], self::contentHash((string)$r['title'], (string)$r['body'], $file))) {
            return 'Treść dokumentu zmieniła się od wystawienia — poproś kadrę o wystawienie go ponownie.';
        }
        if (!$accept && trim($reason) === '') return 'Napisz krótko, dlaczego nie akceptujesz — kadra to zobaczy.';
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
        $nm = mb_substr(trim($name), 0, 150);
        db()->prepare("UPDATE k30_ti_srequest SET status=?, decided_at=datetime('now'), decided_kind=?, decided_name=?, decided_ip=?, decided_ua=?, decline_reason=? WHERE id=? AND status='pending'")
           ->execute([$accept ? 'accepted' : 'declined', $kind, $nm, $ip, $ua, $accept ? '' : mb_substr(trim($reason), 0, 1000), $id]);
        if ($accept) {
            $confirmId = self::storeConfirmation(self::request($id) ?: $r, $acc);
            if ($confirmId) db()->prepare("UPDATE k30_ti_srequest SET confirm_file_id=? WHERE id=?")->execute([$confirmId, $id]);
        }
        self::log($accountId, 'Dokumenty: ' . ($accept ? 'zaakceptowano' : 'odmowa akceptacji') . ' „' . $r['title'] . '” (' . self::SOURCES[$kind] . ': ' . $nm . ').');
        return '';
    }

    /** PDF „Potwierdzenie akceptacji” do teczki; zwraca id wpisu albo 0 (brak mPDF / błąd zapisu — sama akceptacja zostaje). */
    private static function storeConfirmation(array $r, array $acc): int
    {
        try {
            require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
            $tmp = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
            if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
            $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 20, 'margin_right' => 20, 'margin_top' => 18, 'margin_bottom' => 18,
                                    'default_font' => 'dejavusans', 'tempDir' => $tmp]);
            $mpdf->SetTitle('Potwierdzenie akceptacji — ' . $r['title']);
            $mpdf->WriteHTML(self::confirmationHtml($r, $acc), \Mpdf\HTMLParserMode::DEFAULT_MODE);
            $pdf = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
            $tmpFile = tempnam(sys_get_temp_dir(), 'sfold');
            file_put_contents($tmpFile, $pdf);
            $path = self::store($tmpFile, (int)$r['account_id'], 'pdf', false);
            @unlink($tmpFile);
            if ($path === '') return 0;
            $full = rtrim(UPLOAD_DIR, '/') . '/' . $path;
            db()->prepare("INSERT INTO k30_ti_sfile (account_id, category, title, file_path, file_name, mime, size, sha256, visible_student, source, status, created_by_name)
                           VALUES (?, 'potwierdzenie', ?, ?, ?, 'application/pdf', ?, ?, 1, 'system', 'approved', 'system')")
               ->execute([(int)$r['account_id'], mb_substr('Potwierdzenie akceptacji: ' . $r['title'], 0, 200), $path,
                          'potwierdzenie-akceptacji-' . (int)$r['id'] . '.pdf', (int)filesize($full), (string)hash_file('sha256', $full)]);
            return (int)db()->lastInsertId();
        } catch (\Throwable $e) {
            error_log('TiStudentFolder::storeConfirmation: ' . $e->getMessage());
            return 0;
        }
    }

    public static function confirmationHtml(array $r, array $acc): string
    {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $client = db_one("SELECT name FROM k30_clients WHERE id=?", [(int)($acc['client_id'] ?? 0)]);
        $org = defined('ORG_NAME') ? ORG_NAME : '';
        $body = nl2br($h($r['body']));
        $att = '';
        if ($r['file_id'] && ($f = db_one("SELECT title, file_name, sha256 FROM k30_ti_sfile WHERE id=?", [(int)$r['file_id']]))) {
            $att = '<tr><th>Załącznik</th><td>' . $h($f['title']) . ' (' . $h($f['file_name']) . ')<br><span style="font-size:8pt">SHA-256: ' . $h($f['sha256']) . '</span></td></tr>';
        }
        $kind = ($r['decided_kind'] ?? '') === 'parent' ? 'opiekun (przedstawiciel ustawowy)' : 'kursant';
        return '<style>body{font-size:10pt;color:#111} h1{font-size:14pt;margin:0 0 4mm} table{border-collapse:collapse;width:100%;margin:3mm 0}
                th,td{border:1px solid #999;padding:2mm;text-align:left;vertical-align:top} th{background:#eef1f5;width:32%} .tresc{border:1px solid #ccc;padding:3mm;margin-top:3mm}</style>'
             . '<div style="font-size:9pt;color:#555">' . $h($org) . '</div>'
             . '<h1>Potwierdzenie akceptacji dokumentu</h1>'
             . '<table>'
             . '<tr><th>Dokument</th><td><strong>' . $h($r['title']) . '</strong></td></tr>'
             . '<tr><th>Kursant</th><td>' . $h($client['name'] ?? '') . ' (login ' . $h($acc['login'] ?? '') . ')</td></tr>'
             . '<tr><th>Zaakceptował(a)</th><td>' . $h($r['decided_name']) . ' — ' . $h($kind) . '</td></tr>'
             . '<tr><th>Data i godzina</th><td>' . $h(date('d.m.Y H:i:s', strtotime((string)$r['decided_at']))) . '</td></tr>'
             . '<tr><th>Adres IP / przeglądarka</th><td>' . $h($r['decided_ip']) . '<br><span style="font-size:8pt">' . $h($r['decided_ua']) . '</span></td></tr>'
             . '<tr><th>Wystawił(a)</th><td>' . $h($r['created_by_name']) . ', ' . $h(date('d.m.Y H:i', strtotime((string)$r['created_at']))) . '</td></tr>'
             . $att
             . '<tr><th>Skrót treści (SHA-256)</th><td style="font-size:8pt">' . $h($r['content_hash']) . '</td></tr>'
             . '</table>'
             . ($body !== '' ? '<div style="font-weight:bold;margin-top:4mm">Treść zaakceptowanego dokumentu</div><div class="tresc">' . $body . '</div>' : '')
             . '<p style="font-size:8pt;color:#555;margin-top:6mm">Akceptacja złożona elektronicznie w panelu kursanta po zalogowaniu na konto. Skrót treści pozwala sprawdzić, że dokument nie zmienił się po akceptacji.</p>';
    }

    // ── Adresaci i przypomnienia o ważności ─────────────────────────────────────

    /** Adresat wiadomości o koncie: małoletni → opiekun (guardian_email), inaczej kursant (e-mail z k30_clients). */
    public static function recipient(int $accountId): array
    {
        $r = db_one("SELECT a.is_minor, a.guardian_name, a.guardian_email, a.is_virtual, cl.name, cl.email
                     FROM k30_ti_student_accounts a LEFT JOIN k30_clients cl ON cl.id = a.client_id WHERE a.id=?", [$accountId]) ?: [];
        $minor = !empty($r['is_minor']) && trim((string)($r['guardian_email'] ?? '')) !== '';
        return ['email' => trim((string)($minor ? $r['guardian_email'] : ($r['email'] ?? ''))), 'name' => (string)($minor ? $r['guardian_name'] : ($r['name'] ?? '')),
                'student' => (string)($r['name'] ?? ''), 'kind' => $minor ? 'opiekun' : 'kursant', 'virtual' => !empty($r['is_virtual'])];
    }

    /** E-mail o nowym dokumencie do akceptacji (opcjonalny, na żądanie kadry przy wystawieniu). */
    public static function notifyRequest(int $requestId): bool
    {
        $r = self::request($requestId);
        if (!$r) return false;
        $to = self::recipient((int)$r['account_id']);
        self::mailLib();
        if ($to['email'] === '' || !function_exists('mail_queue_add')) return false;
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $url = rtrim(APP_URL, '/') . '/karty30/ti/kursant/' . ($to['kind'] === 'opiekun' ? 'parent.php' : 'dokumenty.php');
        $html = '<p>Dzień dobry,</p><p>w panelu ' . ($to['kind'] === 'opiekun' ? 'rodzica' : 'kursanta') . ' czeka dokument do zapoznania się i akceptacji'
              . ($to['kind'] === 'opiekun' ? ' (dotyczy: ' . $h($to['student']) . ')' : '') . ':</p><p><strong>' . $h($r['title']) . '</strong>'
              . ($r['due_date'] ? '<br>Termin: ' . $h(date('d.m.Y', strtotime((string)$r['due_date']))) : '') . '</p>'
              . '<p><a href="' . $h($url) . '">Przejdź do panelu</a> → Dokumenty.</p>';
        return mail_queue_add($to['email'], $to['name'], 'Dokument do akceptacji: ' . $r['title'], $html, '', 'ti_student_folder', (int)$r['account_id']) > 0;
    }

    /** @return array{enabled:bool,days:int} */
    public static function settings(): array
    {
        $rows = [];
        foreach (db_all("SELECT key_, value FROM settings WHERE key_ IN ('ti_sfold_remind_enabled','ti_sfold_remind_days')") as $x) $rows[$x['key_']] = (string)$x['value'];
        return ['enabled' => ($rows['ti_sfold_remind_enabled'] ?? '0') === '1', 'days' => max(1, min(180, (int)($rows['ti_sfold_remind_days'] ?? 30)))];
    }

    public static function saveSettings(bool $enabled, int $days, string $byName): void
    {
        $st = db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)");
        $st->execute(['ti_sfold_remind_enabled', $enabled ? '1' : '0']);
        $st->execute(['ti_sfold_remind_days', (string)max(1, min(180, $days))]);
        if (function_exists('audit_log')) { try { audit_log('ti_student_folder.settings', ['enabled' => $enabled, 'days' => $days, 'by' => $byName]); } catch (\Throwable $e) {} }
    }

    /** Zatwierdzone dokumenty z datą ważności, które wygasają w ciągu $days dni albo już wygasły (do przeglądu kadry). */
    public static function expiring(int $days): array
    {
        self::migrate();
        return db_all("SELECT f.*, cl.name AS client_name, a.login FROM k30_ti_sfile f
                       JOIN k30_ti_student_accounts a ON a.id = f.account_id LEFT JOIN k30_clients cl ON cl.id = a.client_id
                       WHERE f.deleted_at IS NULL AND f.status='approved' AND f.valid_until IS NOT NULL AND f.valid_until <= date('now', ?)
                       ORDER BY f.valid_until", ['+' . $days . ' days']);
    }

    /**
     * Przypomnienia o wygasającej ważności (CRON). Każdy dokument dostaje jedno przypomnienie (expiry_notified_at);
     * zmiana daty ważności przez kadrę zeruje znacznik. $dry: tylko lista, bez wysyłki.
     * @return array<int,array{file_id:int,account_id:int,title:string,valid_until:string,to:string,action:string}>
     */
    public static function runExpiry(bool $dry = false): array
    {
        self::migrate();
        $set = self::settings();
        if (!$set['enabled'] && !$dry) return [];
        $rows = db_all("SELECT f.* FROM k30_ti_sfile f JOIN k30_ti_student_accounts a ON a.id = f.account_id
                        WHERE f.deleted_at IS NULL AND f.status='approved' AND f.expiry_notified_at IS NULL AND a.is_active=1
                          AND f.valid_until IS NOT NULL AND f.valid_until >= date('now') AND f.valid_until <= date('now', ?)
                        ORDER BY f.valid_until", ['+' . $set['days'] . ' days']);
        $out = [];
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        self::mailLib();
        foreach ($rows as $f) {
            $to = self::recipient((int)$f['account_id']);
            $row = ['file_id' => (int)$f['id'], 'account_id' => (int)$f['account_id'], 'title' => (string)$f['title'], 'valid_until' => (string)$f['valid_until'], 'to' => $to['email'], 'action' => 'send'];
            if ($to['email'] === '') $row['action'] = 'skip: brak e-maila';
            elseif ($to['virtual']) $row['action'] = 'skip: kursant wirtualny';
            if (!$dry && $row['action'] === 'send') {
                $url  = rtrim(APP_URL, '/') . '/karty30/ti/kursant/dokumenty.php';
                $html = '<p>Dzień dobry,</p><p>dokument <strong>' . $h($f['title']) . '</strong>' . ($to['kind'] === 'opiekun' ? ' (dotyczy: ' . $h($to['student']) . ')' : '')
                      . ' jest ważny do <strong>' . $h(date('d.m.Y', strtotime((string)$f['valid_until']))) . '</strong>.</p>'
                      . '<p>Jeśli masz nowy dokument, wgraj go w panelu kursanta → <a href="' . $h($url) . '">Dokumenty</a>, albo przekaż go organizatorowi.</p>';
                $mid = function_exists('mail_queue_add')
                    ? mail_queue_add($to['email'], $to['name'], 'Kończy się ważność dokumentu: ' . $f['title'], $html, '', 'ti_student_folder', (int)$f['account_id'])
                    : 0;
                if ($mid > 0) {
                    db()->prepare("UPDATE k30_ti_sfile SET expiry_notified_at=datetime('now') WHERE id=?")->execute([(int)$f['id']]);
                    self::log((int)$f['account_id'], 'Teczka: wysłano przypomnienie o ważności „' . $f['title'] . '” (' . $to['kind'] . ').');
                } else $row['action'] = 'skip: kolejka poczty odrzuciła';
            }
            $out[] = $row;
        }
        return $out;
    }

    // ── Pomocnicze ──────────────────────────────────────────────────────────────

    /** Przenosi plik do UPLOAD_DIR/ti_teczka/; zwraca ścieżkę względną albo ''. */
    private static function store(string $src, int $accountId, string $ext, bool $uploaded): string
    {
        $dir = rtrim(UPLOAD_DIR, '/') . '/' . self::DIR;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        $name = $accountId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $ok = $uploaded ? move_uploaded_file($src, $dir . '/' . $name) : copy($src, $dir . '/' . $name);
        return $ok ? self::DIR . '/' . $name : '';
    }

    private static function mailLib(): void
    {
        if (!function_exists('mail_queue_add')) require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
    }

    private static function cat(string $c): string
    {
        return isset(self::CATEGORIES[$c]) ? $c : 'inne';
    }

    private static function date(string $d): ?string
    {
        $d = trim($d);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        return $d;
    }

    private static function log(int $accountId, string $msg, ?int $by = null, string $byName = ''): void
    {
        try {
            if (!function_exists('ti_account_log')) require_once dirname(__DIR__, 3) . '/includes/ti_messages.php';
            ti_account_log($accountId, 'document', $msg, $by, $byName);
        } catch (\Throwable $e) {}
    }
}
