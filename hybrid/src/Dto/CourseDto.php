<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Dto;

/** Kurs (domena Dydaktyka) — rzutowany z k30_ti_courses (dane już istniejące w SZO). */
final class CourseDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $instructorName = '',
        public readonly bool $isOnline = false,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int)($data['id'] ?? 0),
            name: (string)($data['name'] ?? ''),
            instructorName: (string)($data['instructor_name'] ?? $data['instructorName'] ?? ''),
            isOnline: (bool)($data['is_online'] ?? $data['isOnline'] ?? false),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'instructor_name'  => $this->instructorName,
            'is_online'        => $this->isOnline,
        ];
    }
}
