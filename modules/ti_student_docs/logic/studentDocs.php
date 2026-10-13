<?php
/**
 * modules/ti_student_docs/logic/studentDocs.php — „Wymaga dokumentu potwierdzającego” przy koncie kursanta.
 *
 * Kierownik zaznacza przy koncie (dydaktyk/konta.php), że osoba musi potwierdzić uprawnienie dokumentem
 * (np. orzeczenie o niepełnosprawności — nazwa dokumentu do wpisania). Po zalogowaniu do panelu kursanta taka osoba
 * widzi tylko ekran kursant/dokument.php: wgrywa skan (PDF/JPG/PNG, do 10 MB) albo składa oświadczenie, że dokument
 * posiada — dopiero potem wchodzi do panelu (bramka w student_require()). Kadra widzi stan i skan; może poprosić o nowy.
 * Kolumny k30_ti_student_accounts: requires_doc, requires_doc_label, doc_status ('' | 'scan' | 'declared'),
 * doc_path, doc_name, doc_at, doc_decl_text. Pliki: UPLOAD_DIR/ti_dokumenty/ (serwowane tylko przez konta.php).
 */
final class TiStudentDocs
{
    public const DEFAULT_LABEL = 'dokument potwierdzający uprawnienie (np. orzeczenie o niepełnosprawności)';
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('ti_student_docs_migrate', __FILE__)) return;
        foreach (["requires_doc INTEGER NOT NULL DEFAULT 0", "requires_doc_label TEXT NOT NULL DEFAULT ''", "doc_status TEXT NOT NULL DEFAULT ''",
                  "doc_path TEXT NOT NULL DEFAULT ''", "doc_name TEXT NOT NULL DEFAULT ''", "doc_at DATETIME", "doc_decl_text TEXT NOT NULL DEFAULT ''"] as $c) {
            try { db()->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN $c"); } catch (\Throwable $e) {}
        }
    }

    public static function get(int $accountId): ?array
    {
        self::migrate();
        return db_one("SELECT id, requires_doc, requires_doc_label, doc_status, doc_path, doc_name, doc_at, doc_decl_text FROM k30_ti_student_accounts WHERE id=?", [$accountId]) ?: null;
    }

    /** Czy konto musi jeszcze przedstawić dokument / oświadczenie. */
    public static function pending(int $accountId): bool
    {
        static $c = [];
        if (!array_key_exists($accountId, $c)) {
            try { $r = self::get($accountId); $c[$accountId] = $r && (int)$r['requires_doc'] === 1 && (string)$r['doc_status'] === ''; }
            catch (\Throwable $e) { $c[$accountId] = false; }
        }
        return $c[$accountId];
    }

    public static function label(array $r): string
    {
        return trim((string)($r['requires_doc_label'] ?? '')) ?: self::DEFAULT_LABEL;
    }

    public static function declarationText(array $r): string
    {
        return 'Oświadczam, że posiadam ' . self::label($r) . ' i na prośbę organizatora okażę ten dokument albo przekażę jego kopię.';
    }

    /** Skan dokumentu z $_FILES['…']. @return string komunikat błędu albo '' */
    public static function submitScan(int $accountId, array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return 'Wybierz plik ze skanem dokumentu.';
        if ((int)$file['size'] > self::MAX_BYTES) return 'Plik jest za duży (najwyżej 10 MB).';
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        if (!isset(self::TYPES[$mime])) return 'Dozwolone pliki: PDF, JPG, PNG.';
        $dir = rtrim(UPLOAD_DIR, '/') . '/ti_dokumenty';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        $name = $accountId . '_' . bin2hex(random_bytes(8)) . '.' . self::TYPES[$mime];
        if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) return 'Nie udało się zapisać pliku — spróbuj ponownie.';
        db()->prepare("UPDATE k30_ti_student_accounts SET doc_status='scan', doc_path=?, doc_name=?, doc_at=datetime('now'), doc_decl_text='', updated_at=datetime('now') WHERE id=?")
           ->execute(['ti_dokumenty/' . $name, mb_substr(basename((string)$file['name']), 0, 150), $accountId]);
        self::log($accountId, 'Przesłano skan dokumentu potwierdzającego.');
        return '';
    }

    public static function submitDeclaration(int $accountId): void
    {
        $r = self::get($accountId) ?: [];
        db()->prepare("UPDATE k30_ti_student_accounts SET doc_status='declared', doc_decl_text=?, doc_at=datetime('now'), updated_at=datetime('now') WHERE id=?")
           ->execute([self::declarationText($r), $accountId]);
        self::log($accountId, 'Złożono oświadczenie o posiadaniu dokumentu potwierdzającego.');
    }

    /** Kadra: włącza / wyłącza wymóg (z nazwą dokumentu) albo prosi o ponowne złożenie ($reset). */
    public static function setRequired(int $accountId, bool $on, string $label, bool $reset, int $by, string $byName): void
    {
        self::migrate();
        db()->prepare("UPDATE k30_ti_student_accounts SET requires_doc=?, requires_doc_label=?, updated_at=datetime('now') WHERE id=?")
           ->execute([$on ? 1 : 0, mb_substr(trim($label), 0, 200), $accountId]);
        if ($reset) db()->prepare("UPDATE k30_ti_student_accounts SET doc_status='', doc_at=NULL, doc_decl_text='' WHERE id=?")->execute([$accountId]);
        self::log($accountId, ($on ? 'Wymóg dokumentu potwierdzającego włączony' : 'Wymóg dokumentu potwierdzającego wyłączony') . ($reset ? ' — prośba o ponowne złożenie' : '') . '.', $by, $byName);
    }

    /** Pełna ścieżka pliku skanu albo null. */
    public static function filePath(array $r): ?string
    {
        $p = (string)($r['doc_path'] ?? '');
        if ($p === '' || !preg_match('#^ti_dokumenty/[0-9]+_[a-f0-9]{16}\.(pdf|jpg|png)$#', $p)) return null;
        $f = rtrim(UPLOAD_DIR, '/') . '/' . $p;
        return is_file($f) ? $f : null;
    }

    private static function log(int $accountId, string $msg, ?int $by = null, string $byName = ''): void
    {
        try {
            if (!function_exists('ti_account_log')) require_once dirname(__DIR__, 3) . '/includes/ti_messages.php';
            ti_account_log($accountId, 'document', $msg, $by, $byName);
        } catch (\Throwable $e) {}
    }
}
