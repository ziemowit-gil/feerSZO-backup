<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseStorage.php — pliki dokumentów AM5 w UPLOAD_DIR/licencje_am5/ (katalog zablokowany
 * .htaccess, wydawane wyłącznie przez kontroler po sprawdzeniu uprawnień albo tokenu) oraz kopie w profilu osoby:
 *   kursant (k30_clients)  → teczka „Dokumenty kursanta” (k30_ti_sfile, kategoria zgoda, źródło system, widoczna dla kursanta),
 *   współpracownik (users) → bez kopii (dokumenty kadry MSUES wyniesiono do osobnej mikroaplikacji).
 * Kopia w profilu jest „najlepszą próbą”: błąd nie przerywa złożenia oświadczeń (zwraca komunikat do dziennika).
 */
final class LicenseStorage
{
    public const DIR = 'licencje_am5';

    /** Zapisuje bajty dokumentu. Zwraca [ścieżka względna, sha256]. */
    public static function put(string $bytes, string $ext, string $prefix): array
    {
        $dir = rtrim(UPLOAD_DIR, '/') . '/' . self::DIR;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($prefix)) . '_' . bin2hex(random_bytes(8)) . '.' . ($ext === 'pdf' ? 'pdf' : 'html');
        if (@file_put_contents($dir . '/' . $name, $bytes) === false) throw new RuntimeException('Nie udało się zapisać dokumentu ' . LD_SYMBOL . '.');
        return [self::DIR . '/' . $name, hash('sha256', $bytes)];
    }

    /** Plik od użytkownika (dowód zakupu AM12): PDF / JPG / PNG do 15 MB, typ sprawdzany po zawartości. Zwraca [ścieżka względna, nazwa]. */
    public static function putUpload(array $file, string $prefix): array
    {
        if ((int)($file['size'] ?? 0) <= 0) throw new InvalidArgumentException('Plik jest pusty.');
        if ((int)$file['size'] > 15 * 1024 * 1024) throw new InvalidArgumentException('Plik jest za duży (maks. 15 MB).');
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
        if (!$ext) throw new InvalidArgumentException('Dowód zakupu: dozwolone PDF, JPG, PNG.');
        $dir = rtrim(UPLOAD_DIR, '/') . '/' . self::DIR;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($prefix)) . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $ok = is_uploaded_file((string)$file['tmp_name']) ? move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name) : copy((string)$file['tmp_name'], $dir . '/' . $name);
        if (!$ok) throw new RuntimeException('Nie udało się zapisać pliku.');
        return [self::DIR . '/' . $name, mb_substr(preg_replace('/[\x00-\x1F\/\\\\]/u', '_', basename((string)$file['name'])), 0, 180)];
    }

    public static function fullPath(string $rel): ?string
    {
        if (!preg_match('#^' . self::DIR . '/[a-z0-9_]+\.(pdf|html|jpg|png)$#', $rel)) return null;
        $p = rtrim(UPLOAD_DIR, '/') . '/' . $rel;
        return is_file($p) ? $p : null;
    }

    public static function send(string $rel, string $downloadName): ?string
    {
        $p = self::fullPath($rel);
        if (!$p) { http_response_code(404); return 'Brak pliku dokumentu.'; }
        while (ob_get_level() > 0) ob_end_clean();
        $pdf = str_ends_with($p, '.pdf');
        $img = ['jpg' => 'image/jpeg', 'png' => 'image/png'][strtolower(pathinfo($p, PATHINFO_EXTENSION))] ?? null;
        if ($img) {
            header('Content-Type: ' . $img); header('Content-Length: ' . filesize($p)); header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
            readfile($p); return null;
        }
        header('Content-Type: ' . ($pdf ? 'application/pdf' : 'text/html; charset=utf-8'));
        header('Content-Length: ' . filesize($p));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . ($pdf ? '.pdf' : '.html') . '"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        if (!$pdf) header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
        readfile($p);
        return null;
    }

    /** Kopia dokumentu w profilu osoby (teczka kursanta). Zwraca opis wyniku. */
    public static function archiveToProfile(string $rel, string $title, string $holderKind, ?int $holderId): string
    {
        $src = self::fullPath($rel);
        if (!$src || !$holderId) return 'bez kopii w profilu (osoba spoza SZO)';
        if (!str_ends_with($src, '.pdf')) return 'bez kopii w profilu (dokument w wersji HTML)';
        try {
            if ($holderKind === 'client') {
                $acc = db_one("SELECT id FROM k30_ti_student_accounts WHERE client_id = ? ORDER BY id LIMIT 1", [$holderId]);
                if (!$acc) return 'kursant bez konta — kopia tylko w module';
                require_once dirname(__DIR__, 2) . '/ti_student_folder/logic/studentFolder.php';
                TiStudentFolder::migrate();
                $aid = (int)$acc['id'];
                $dir = rtrim(UPLOAD_DIR, '/') . '/' . TiStudentFolder::DIR;
                if (!is_dir($dir)) @mkdir($dir, 0750, true);
                $relT = TiStudentFolder::DIR . '/' . $aid . '_' . bin2hex(random_bytes(8)) . '.pdf';
                if (!@copy($src, rtrim(UPLOAD_DIR, '/') . '/' . $relT)) return 'nie udało się skopiować do teczki';
                db_insert('k30_ti_sfile', ['account_id' => $aid, 'category' => 'zgoda', 'title' => mb_substr($title, 0, 200),
                    'note' => 'Dokument ' . LD_SYMBOL . ' złożony w portalu licencji dydaktycznych.', 'file_path' => $relT,
                    'file_name' => mb_substr(preg_replace('/[^\w.\-]+/u', '_', $title), 0, 140) . '.pdf', 'mime' => 'application/pdf',
                    'size' => (int)filesize($src), 'sha256' => (string)hash_file('sha256', $src), 'visible_student' => 1,
                    'source' => 'system', 'status' => 'approved', 'created_by_name' => 'system (' . LD_SYMBOL . ')', 'created_at' => ldNow()]);
                return 'kopia w teczce „Dokumenty kursanta”';
            }
        } catch (\Throwable $e) {
            error_log('am5 archive: ' . $e->getMessage());
            return 'kopia w profilu nieudana: ' . $e->getMessage();
        }
        return 'bez kopii w profilu';
    }
}
