<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Szo\Service;

use FeerSzo\Hybrid\Dto\QualityReportDto;

/** Logika domenowa SZO: przyjmowanie raportów jakościowych z pozostałych modułów. */
final class QualityReportService
{
    private static bool $migrated = false;

    public static function migrate(): void
    {
        if (self::$migrated) {
            return;
        }
        self::$migrated = true;
        \db()->exec("CREATE TABLE IF NOT EXISTS k30_szo_quality_reports (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            source_module  TEXT    NOT NULL,
            course_id      INTEGER,
            client_id      INTEGER,
            category       TEXT    NOT NULL DEFAULT '',
            severity       TEXT    NOT NULL DEFAULT 'low',
            description    TEXT    NOT NULL DEFAULT '',
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function submit(QualityReportDto $report): int
    {
        self::migrate();
        \db()->prepare(
            "INSERT INTO k30_szo_quality_reports (source_module, course_id, client_id, category, severity, description)
             VALUES (?,?,?,?,?,?)"
        )->execute([
            $report->sourceModule,
            $report->courseId,
            $report->clientId,
            $report->category,
            $report->severity,
            $report->description,
        ]);
        return (int)\db()->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    public function list(int $limit = 100): array
    {
        self::migrate();
        return \db_all("SELECT * FROM k30_szo_quality_reports ORDER BY created_at DESC LIMIT ?", [$limit]);
    }
}
