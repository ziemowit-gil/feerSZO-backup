<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Dydaktyka\Service;

use FeerSzo\Hybrid\Dto\MaterialAdaptationRequestDto;

/** Logika domenowa Dydaktyka: zgłoszenia zapotrzebowania na adaptację materiałów. */
final class MaterialAdaptationService
{
    private static bool $migrated = false;

    public static function migrate(): void
    {
        if (self::$migrated) {
            return;
        }
        self::$migrated = true;
        \db()->exec("CREATE TABLE IF NOT EXISTS k30_dyd_material_adaptation_requests (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id         INTEGER NOT NULL,
            client_id         INTEGER NOT NULL,
            requested_format  TEXT    NOT NULL DEFAULT '',
            reason            TEXT    NOT NULL DEFAULT '',
            created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function create(MaterialAdaptationRequestDto $request): int
    {
        self::migrate();
        \db()->prepare(
            "INSERT INTO k30_dyd_material_adaptation_requests (course_id, client_id, requested_format, reason)
             VALUES (?,?,?,?)"
        )->execute([$request->courseId, $request->clientId, $request->requestedFormat, $request->reason]);
        return (int)\db()->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    public function listForCourse(int $courseId): array
    {
        self::migrate();
        return \db_all(
            "SELECT * FROM k30_dyd_material_adaptation_requests WHERE course_id=? ORDER BY created_at DESC",
            [$courseId]
        );
    }
}
