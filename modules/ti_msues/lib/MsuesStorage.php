<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/MsuesStorage.php — pliki modułu MSUES w UPLOAD_DIR/msues/ (katalog zablokowany .htaccess,
 * pliki wydawane wyłącznie przez kontroler po sprawdzeniu uprawnień).
 */
final class MsuesStorage
{
    public const DIR = 'msues';
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const SCAN_EXT = ['pdf', 'jpg', 'jpeg', 'png'];
    public const DOC_EXT  = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'doc', 'odt'];

    /**
     * Zapisuje plik (z formularza — $uploaded=true — albo kopię z dysku). Zwraca [ścieżka względna, nazwa, mime, rozmiar, sha256].
     * @throws InvalidArgumentException przy złym typie / rozmiarze
     */
    public static function store(string $src, string $origName, array $allowedExt, string $prefix, bool $uploaded = true): array
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) throw new InvalidArgumentException('Niedozwolony typ pliku (dozwolone: ' . implode(', ', $allowedExt) . ').');
        $size = (int)@filesize($src);
        if ($size <= 0) throw new InvalidArgumentException('Plik jest pusty.');
        if ($size > self::MAX_BYTES) throw new InvalidArgumentException('Plik jest za duży (maks. 20 MB).');
        $dir = rtrim(UPLOAD_DIR, '/') . '/' . self::DIR;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($prefix)) . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $ok = $uploaded ? move_uploaded_file($src, $dir . '/' . $name) : copy($src, $dir . '/' . $name);
        if (!$ok) throw new RuntimeException('Nie udało się zapisać pliku.');
        $full = $dir . '/' . $name;
        $mime = function_exists('mime_content_type') ? (string)@mime_content_type($full) : '';
        $clean = mb_substr(preg_replace('/[\x00-\x1F\/\\\\]/u', '_', basename($origName)), 0, 180);
        return [self::DIR . '/' . $name, $clean, $mime ?: 'application/octet-stream', $size, (string)hash_file('sha256', $full)];
    }

    public static function fullPath(string $rel): ?string
    {
        if ($rel === '' || str_contains($rel, '..')) return null;
        $p = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($rel, '/');
        return is_file($p) ? $p : null;
    }

    /** Wysyła plik do przeglądarki (inline dla PDF/obrazów). */
    public static function send(string $rel, string $name, string $mime = ''): ?string
    {
        $p = self::fullPath($rel);
        if (!$p) { http_response_code(404); return 'Brak pliku.'; }
        while (ob_get_level() > 0) ob_end_clean();
        $mime = $mime !== '' ? $mime : 'application/octet-stream';
        $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($p));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        readfile($p);
        return null;
    }
}
