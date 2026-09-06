<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Contracts;

use FeerSzo\Hybrid\Dto\AccessibilityRequirementDto;
use FeerSzo\Hybrid\Dto\QualityReportDto;

/**
 * Granica komunikacji z domeną SZO (System Wspomagania Zarządzania
 * Organizacją).
 *
 * Ma dokładnie dwie implementacje:
 *   - InternalSzoQualityReportClient — wywołanie bezpośrednie (SZO w tym samym procesie).
 *   - HttpSzoQualityReportClient     — wywołanie REST (SZO jako osobny serwis).
 */
interface SzoQualityReportClientInterface
{
    /** Zgłasza raport jakościowy do SZO. Zwraca ID nadany przez SZO. */
    public function submitReport(QualityReportDto $report): int;

    /** @return AccessibilityRequirementDto[] Wymagania dostępności publikowane przez SZO. */
    public function getAccessibilityRequirements(): array;
}
