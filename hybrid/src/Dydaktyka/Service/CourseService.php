<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Dydaktyka\Service;

use FeerSzo\Hybrid\Dto\CourseDto;

/**
 * Logika domenowa Dydaktyka: kursy. Czyta z k30_ti_courses — danych, które
 * w tej aplikacji już istnieją (moduł TI/Dydaktyka to jeden i ten sam system),
 * więc warstwa hybrydowa ich nie duplikuje, tylko rzutuje na CourseDto.
 */
final class CourseService
{
    public function get(int $courseId): ?CourseDto
    {
        $row = \db_one(
            "SELECT c.id, c.name, c.is_online, u.name AS instructor_name
               FROM k30_ti_courses c LEFT JOIN users u ON u.id = c.instructor_id
              WHERE c.id = ?",
            [$courseId]
        );
        return $row ? CourseDto::fromArray($row) : null;
    }

    /** @return CourseDto[] */
    public function list(): array
    {
        $rows = \db_all(
            "SELECT c.id, c.name, c.is_online, u.name AS instructor_name
               FROM k30_ti_courses c LEFT JOIN users u ON u.id = c.instructor_id
              ORDER BY c.name COLLATE NOCASE"
        );
        return array_map(static fn(array $r) => CourseDto::fromArray($r), $rows);
    }
}
