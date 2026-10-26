<?php
/**
 * modules/ti_training_contract/logic/trainingContractConsents.php — „Zgody i dokumenty” umowy szkoleniowej SZK:
 * lista wymaganych zgód / dokumentów przy umowie (podpisana umowa, klauzula RODO, pouczenie o odstąpieniu, regulamin, wizerunek),
 * stan każdej (czeka / udzielona / odmowa), kto i kiedy zdecydował, skan dokumentu. Lista jest widoczna dla uczestnika w panelu
 * kursanta i rodzica (modules/ti_consents) — tylko odczyt; decyzje zapisuje kadra (contracts/szk/consents.php).
 */
require_once __DIR__ . '/trainingContractStore.php';

final class TiSzkConsents
{
    public const TABLE = 'szk_consents';
    public const STATES = ['pending' => 'Czeka na decyzję', 'granted' => 'Udzielona', 'declined' => 'Odmowa'];
    /** kind => [tytuł, wymagana, opis dla uczestnika] */
    public const KINDS = [
        'umowa'      => ['Podpisana umowa szkoleniowa', 1, 'Skan umowy podpisanej przez obie strony.'],
        'rodo'       => ['Klauzula informacyjna RODO (Załącznik nr 2)', 1, 'Zapoznanie się z zasadami przetwarzania danych osobowych.'],
        'odstapienie'=> ['Pouczenie o prawie odstąpienia (Załącznik nr 3)', 1, 'Informacja o terminie i sposobie odstąpienia od umowy.'],
        'regulamin'  => ['Regulamin szkoleń', 1, 'Akceptacja regulaminu zajęć.'],
        'wizerunek'  => ['Zgoda na wykorzystanie wizerunku', 0, 'Dobrowolna zgoda — jej brak nie wpływa na udział w szkoleniu.'],
    ];
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const EXT = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pk = DB_TYPE === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        db()->exec("CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            id {$pk},
            contract_id INTEGER NOT NULL,
            kind VARCHAR(32) NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            required INTEGER NOT NULL DEFAULT 1,
            state VARCHAR(16) NOT NULL DEFAULT 'pending',
            decided_at VARCHAR(19),
            decided_by VARCHAR(190) NOT NULL DEFAULT '',
            via VARCHAR(16) NOT NULL DEFAULT '',
            note VARCHAR(500) NOT NULL DEFAULT '',
            file_path VARCHAR(255) NOT NULL DEFAULT '',
            file_name VARCHAR(190) NOT NULL DEFAULT '',
            created_at VARCHAR(19)
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_szk_consents_kind ON " . self::TABLE . "(contract_id, kind)");
    }

    /** Dokłada brakujące pozycje domyślne (idempotentnie). */
    public static function ensureDefaults(int $contractId): void
    {
        self::migrate();
        foreach (self::KINDS as $k => [$title, $req]) {
            db()->prepare((DB_TYPE === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . " INTO " . self::TABLE . " (contract_id, kind, title, required, state, created_at) VALUES (?,?,?,?, 'pending', ?)")
                ->execute([$contractId, $k, $title, $req, date('Y-m-d H:i:s')]);
        }
    }

    public static function list(int $contractId): array
    {
        self::migrate();
        return db_all("SELECT * FROM " . self::TABLE . " WHERE contract_id = ? ORDER BY required DESC, id", [$contractId]);
    }

    public static function get(int $id): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM " . self::TABLE . " WHERE id = ?", [$id]) ?: null;
    }

    /** Podsumowanie: [wymagane, udzielone wymagane]. */
    public static function summary(int $contractId): array
    {
        $req = $ok = 0;
        foreach (self::list($contractId) as $r) if ((int)$r['required'] === 1) { $req++; if ($r['state'] === 'granted') $ok++; }
        return [$req, $ok];
    }

    /** Decyzja kadry. Zwraca '' albo komunikat błędu. */
    public static function decide(int $id, string $state, string $by, string $via = 'kadra', string $note = ''): string
    {
        if (!isset(self::STATES[$state])) return 'Nieznany stan zgody.';
        $r = self::get($id);
        if (!$r) return 'Nie ma takiej pozycji.';
        db_update(self::TABLE, ['state' => $state, 'decided_at' => $state === 'pending' ? null : date('Y-m-d H:i:s'),
                                'decided_by' => $state === 'pending' ? '' : mb_substr($by, 0, 190), 'via' => $state === 'pending' ? '' : mb_substr($via, 0, 16),
                                'note' => mb_substr(trim($note), 0, 500)], $id);
        return '';
    }

    /** Skan: $file = element $_FILES (albo ['tmp_name','name','size','error']); $move — funkcja przenosząca (domyślnie move_uploaded_file). */
    public static function attach(int $id, array $file, ?callable $move = null): string
    {
        $r = self::get($id);
        if (!$r) return 'Nie ma takiej pozycji.';
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return 'Nie udało się wgrać pliku.';
        if ((int)$file['size'] > self::MAX_BYTES) return 'Plik jest za duży (maks. 10 MB).';
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!isset(self::EXT[$ext])) return 'Dozwolone formaty: PDF, JPG, PNG.';
        $dir = rtrim((string)UPLOAD_DIR, '/') . '/szk_private/' . (int)$r['contract_id'];
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return 'Brak miejsca zapisu plików.';
        $deny = rtrim((string)UPLOAD_DIR, '/') . '/szk_private/.htaccess';   // skany są serwowane tylko przez contracts/szk/consents.php
        if (!is_file($deny)) @file_put_contents($deny, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        $move = $move ?? 'move_uploaded_file';
        if (!$move((string)$file['tmp_name'], $dir . '/' . $name)) return 'Nie udało się zapisać pliku.';
        if ($r['file_path'] !== '') @unlink(rtrim((string)UPLOAD_DIR, '/') . '/' . $r['file_path']);
        db_update(self::TABLE, ['file_path' => 'szk_private/' . (int)$r['contract_id'] . '/' . $name, 'file_name' => mb_substr(basename((string)$file['name']), 0, 190)], $id);
        return '';
    }

    public static function absPath(array $r): string
    {
        $p = (string)$r['file_path'];
        return $p !== '' && !str_contains($p, '..') ? rtrim((string)UPLOAD_DIR, '/') . '/' . $p : '';
    }

    public static function mime(array $r): string
    {
        return self::EXT[strtolower(pathinfo((string)$r['file_name'], PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }
}
