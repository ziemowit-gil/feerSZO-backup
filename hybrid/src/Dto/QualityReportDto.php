<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Dto;

/**
 * Raport jakościowy zgłaszany DO SZO przez dowolny moduł (Dydaktyka, TI, ...).
 * SZO = System Wspomagania Zarządzania Organizacją — centralny odbiorca
 * zgłoszeń jakościowych/audytowych z pozostałych domen.
 */
final class QualityReportDto
{
    public function __construct(
        public readonly string $sourceModule,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $description,
        public readonly ?int $courseId = null,
        public readonly ?int $clientId = null,
        public readonly ?int $id = null,
        public readonly ?string $createdAt = null,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceModule: (string)($data['source_module'] ?? $data['sourceModule'] ?? ''),
            category: (string)($data['category'] ?? ''),
            severity: (string)($data['severity'] ?? 'low'),
            description: (string)($data['description'] ?? ''),
            courseId: isset($data['course_id']) ? (int)$data['course_id'] : (isset($data['courseId']) ? (int)$data['courseId'] : null),
            clientId: isset($data['client_id']) ? (int)$data['client_id'] : (isset($data['clientId']) ? (int)$data['clientId'] : null),
            id: isset($data['id']) ? (int)$data['id'] : null,
            createdAt: isset($data['created_at']) ? (string)$data['created_at'] : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'source_module'  => $this->sourceModule,
            'course_id'      => $this->courseId,
            'client_id'      => $this->clientId,
            'category'       => $this->category,
            'severity'       => $this->severity,
            'description'    => $this->description,
            'created_at'     => $this->createdAt,
        ];
    }
}
