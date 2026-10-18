<?php
declare(strict_types=1);
/** Encja wpisu słownika (tylko do odczytu). $labels = tłumaczenia [locale => etykieta]. */
final class DictionaryEntry
{
    public function __construct(
        public readonly int $id,
        public readonly int $dictionaryId,
        public readonly ?int $parentId,
        public readonly string $code,
        public readonly string $label,
        public readonly ?string $description,
        public readonly array $attributes,
        public readonly int $sortOrder,
        public readonly bool $isActive,
        public readonly ?string $validFrom,
        public readonly ?string $validTo,
        public readonly int $version,
        public readonly ?string $updatedAt,
        public readonly ?string $deletedAt,
        public readonly array $labels = [],
    ) {}

    public static function fromRow(array $r, array $labels = []): self
    {
        $attrs = json_decode((string)($r['attributes'] ?? ''), true);
        return new self(
            (int)$r['id'],
            (int)$r['dictionary_id'],
            $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            (string)$r['code'],
            (string)$r['label'],
            $r['description'] !== null ? (string)$r['description'] : null,
            is_array($attrs) ? $attrs : [],
            (int)$r['sort_order'],
            (bool)$r['is_active'],
            $r['valid_from'] !== null && $r['valid_from'] !== '' ? (string)$r['valid_from'] : null,
            $r['valid_to'] !== null && $r['valid_to'] !== '' ? (string)$r['valid_to'] : null,
            (int)$r['version'],
            $r['updated_at'] !== null ? (string)$r['updated_at'] : null,
            $r['deleted_at'] !== null ? (string)$r['deleted_at'] : null,
            $labels,
        );
    }

    public function isDeleted(): bool { return $this->deletedAt !== null; }

    /** Czy wpis obowiązuje w dniu $date (Y-m-d). */
    public function isValidOn(string $date): bool
    {
        return ($this->validFrom === null || $this->validFrom <= $date)
            && ($this->validTo === null || $this->validTo >= $date);
    }

    public function labelIn(?string $locale): string
    {
        return $locale !== null && isset($this->labels[$locale]) && $this->labels[$locale] !== '' ? $this->labels[$locale] : $this->label;
    }

    /** Postać do eksportu / historii; $parentCode podaje wywołujący (kod rodzica, nie id — przenośne między bazami). */
    public function toArray(?string $parentCode = null): array
    {
        return [
            'code' => $this->code, 'label' => $this->label, 'parent_code' => $parentCode, 'description' => $this->description,
            'attributes' => $this->attributes ?: new \stdClass(), 'sort_order' => $this->sortOrder, 'is_active' => $this->isActive,
            'valid_from' => $this->validFrom, 'valid_to' => $this->validTo, 'labels' => $this->labels ?: new \stdClass(),
        ];
    }
}
