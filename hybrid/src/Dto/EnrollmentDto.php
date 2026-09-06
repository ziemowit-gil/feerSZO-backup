<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Dto;

/** Zapis uczestnika na kurs (domena Dydaktyka) — rzutowany z k30_ti_enrollments. */
final class EnrollmentDto
{
    public function __construct(
        public readonly int $courseId,
        public readonly int $clientId,
        public readonly string $clientName = '',
        public readonly string $status = 'active',
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            courseId: (int)($data['course_id'] ?? $data['courseId'] ?? 0),
            clientId: (int)($data['client_id'] ?? $data['clientId'] ?? 0),
            clientName: (string)($data['client_name'] ?? $data['clientName'] ?? ''),
            status: (string)($data['status'] ?? 'active'),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'course_id'   => $this->courseId,
            'client_id'   => $this->clientId,
            'client_name' => $this->clientName,
            'status'      => $this->status,
        ];
    }
}
