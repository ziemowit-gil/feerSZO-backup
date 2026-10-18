<?php
declare(strict_types=1);
/** Encja słownika (tylko do odczytu — zmiany idą przez DictionaryService). */
final class Dictionary
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description,
        public readonly DictionaryKind $kind,
        public readonly bool $isProtected,
        public readonly int $sortOrder,
        public readonly ?string $updatedAt,
        public readonly ?string $deletedAt,
    ) {}

    public static function fromRow(array $r): self
    {
        return new self(
            (int)$r['id'],
            $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            (string)$r['code'],
            (string)$r['name'],
            $r['description'] !== null ? (string)$r['description'] : null,
            DictionaryKind::tryFrom((string)$r['kind']) ?? DictionaryKind::Business,
            (bool)$r['is_protected'],
            (int)$r['sort_order'],
            $r['updated_at'] !== null ? (string)$r['updated_at'] : null,
            $r['deleted_at'] !== null ? (string)$r['deleted_at'] : null,
        );
    }

    public function isDeleted(): bool { return $this->deletedAt !== null; }

    /** Postać do eksportu / API / historii. */
    public function toArray(): array
    {
        return [
            'code' => $this->code, 'name' => $this->name, 'description' => $this->description,
            'kind' => $this->kind->value, 'is_protected' => $this->isProtected, 'sort_order' => $this->sortOrder,
        ];
    }
}
