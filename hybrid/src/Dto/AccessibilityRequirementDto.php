<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Dto;

/**
 * Wymaganie dostępności publikowane przez SZO (System Wspomagania Zarządzania
 * Organizacją) — standard, do którego mają się dostosować inne moduły
 * (np. Dydaktyka przy adaptacji materiałów).
 */
final class AccessibilityRequirementDto
{
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly string $description = '',
        public readonly string $wcagLevel = '',
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            code: (string)($data['code'] ?? ''),
            title: (string)($data['title'] ?? ''),
            description: (string)($data['description'] ?? ''),
            wcagLevel: (string)($data['wcag_level'] ?? $data['wcagLevel'] ?? ''),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'code'        => $this->code,
            'title'       => $this->title,
            'description' => $this->description,
            'wcag_level'  => $this->wcagLevel,
        ];
    }
}
