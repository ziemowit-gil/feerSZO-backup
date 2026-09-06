<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Dydaktyka\Service;

use FeerSzo\Hybrid\Dto\EnrollmentDto;

/** Logika domenowa Dydaktyka: zapisy. Czyta z k30_ti_enrollments (dane istniejące). */
final class EnrollmentService
{
    /** @return EnrollmentDto[] */
    public function listForCourse(int $courseId): array
    {
        $rows = \db_all(
            "SELECT e.course_id, e.client_id, e.status, cl.name AS client_name
               FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id = e.client_id
              WHERE e.course_id = ? ORDER BY cl.name COLLATE NOCASE",
            [$courseId]
        );
        return array_map(static fn(array $r) => EnrollmentDto::fromArray($r), $rows);
    }

    public function isEnrolled(int $courseId, int $clientId): bool
    {
        return (bool)\db_one(
            "SELECT 1 FROM k30_ti_enrollments WHERE course_id=? AND client_id=? AND status='active'",
            [$courseId, $clientId]
        );
    }
}
