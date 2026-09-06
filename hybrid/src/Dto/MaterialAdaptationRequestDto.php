<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Dto;

/**
 * Zgłoszenie zapotrzebowania na adaptację materiałów dla uczestnika
 * (domena Dydaktyka) — powstaje na podstawie AccessibilityProfileDto
 * pobranego z TI, a jego rejestracja jest jednocześnie zgłaszana do SZO
 * jako QualityReportDto (patrz MaterialAdaptationController).
 */
final class MaterialAdaptationRequestDto
{
    public function __construct(
        public readonly int $courseId,
        public readonly int $clientId,
        public readonly string $requestedFormat,
        public readonly string $reason = '',
        public readonly ?int $id = null,
        public readonly ?string $createdAt = null,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            courseId: (int)($data['course_id'] ?? $data['courseId'] ?? 0),
            clientId: (int)($data['client_id'] ?? $data['clientId'] ?? 0),
            requestedFormat: (string)($data['requested_format'] ?? $data['requestedFormat'] ?? ''),
            reason: (string)($data['reason'] ?? ''),
            id: isset($data['id']) ? (int)$data['id'] : null,
            createdAt: isset($data['created_at']) ? (string)$data['created_at'] : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'course_id'         => $this->courseId,
            'client_id'         => $this->clientId,
            'requested_format'  => $this->requestedFormat,
            'reason'            => $this->reason,
            'created_at'        => $this->createdAt,
        ];
    }
}
