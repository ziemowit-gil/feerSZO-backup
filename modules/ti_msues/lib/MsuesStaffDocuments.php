<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/MsuesStaffDocuments.php — dokumenty kadry do checklisty MSUES (msues_staff_documents):
 * CV, dyplomy/świadectwa, certyfikaty/zaświadczenia, umowy spoza Rejestru Umów, inne. Usunięcie = kosz (deleted_at).
 */
require_once __DIR__ . '/msuesSchema.php';
require_once __DIR__ . '/MsuesStorage.php';
require_once __DIR__ . '/MsuesChecklist.php';

final class MsuesStaffDocuments
{
    public function __construct()
    {
        msuesMigrate();
    }

    public function list(int $trainerId): array
    {
        return db_all("SELECT * FROM msues_staff_documents WHERE trainer_id = ? AND deleted_at IS NULL ORDER BY kind, created_at DESC", [$trainerId]);
    }

    public function get(int $id): ?array
    {
        return db_one("SELECT * FROM msues_staff_documents WHERE id = ? AND deleted_at IS NULL", [$id]) ?: null;
    }

    public function add(int $trainerId, string $kind, string $title, string $issuedOn, string $validTo, string $tmp, string $origName, array $actor, bool $uploaded = true): int
    {
        if (!isset(MsuesChecklist::documentKinds()[$kind])) throw new InvalidArgumentException('Nieznany rodzaj dokumentu.');
        $date = fn(string $v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        [$path, $name, $mime, $size, $sha] = MsuesStorage::store($tmp, $origName, MsuesStorage::DOC_EXT, 'doc' . $trainerId, $uploaded);
        $id = db_insert('msues_staff_documents', [
            'trainer_id' => $trainerId, 'kind' => $kind, 'title' => mb_substr(trim($title), 0, 255) ?: $name,
            'issued_on' => $date($issuedOn), 'valid_to' => $date($validTo),
            'file_path' => $path, 'file_name' => $name, 'mime' => $mime, 'size' => $size, 'sha256' => $sha,
            'uploaded_by' => $actor['id'] ?: null, 'uploaded_name' => (string)$actor['name'], 'created_at' => msuesNow(),
        ]);
        if (function_exists('audit_log')) { try { audit_log('msues.document.add', ['id' => $id, 'trainer_id' => $trainerId, 'kind' => $kind], $actor['id'] ?: null); } catch (\Throwable $e) {} }
        return $id;
    }

    public function delete(int $id, array $actor): void
    {
        db_exec("UPDATE msues_staff_documents SET deleted_at = ?, deleted_by = ? WHERE id = ?", [msuesNow(), $actor['id'] ?: null, $id]);
        if (function_exists('audit_log')) { try { audit_log('msues.document.delete', ['id' => $id], $actor['id'] ?: null); } catch (\Throwable $e) {} }
    }

    public static function isValid(array $doc, ?string $at = null): bool
    {
        $at ??= date('Y-m-d');
        return (string)$doc['valid_to'] === '' || (string)$doc['valid_to'] >= $at;
    }
}
