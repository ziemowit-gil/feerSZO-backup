<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Szo;

use FeerSzo\Hybrid\Contracts\SzoQualityReportClientInterface;
use FeerSzo\Hybrid\Dto\QualityReportDto;
use FeerSzo\Hybrid\Szo\Service\QualityReportService;
use FeerSzo\Hybrid\Szo\Service\QualityRequirementService;

/** Local Driver — SZO działa w tym samym procesie co wołający. */
final class InternalSzoQualityReportClient implements SzoQualityReportClientInterface
{
    public function __construct(
        private readonly QualityReportService $reports = new QualityReportService(),
        private readonly QualityRequirementService $requirements = new QualityRequirementService(),
    ) {
    }

    public function submitReport(QualityReportDto $report): int
    {
        return $this->reports->submit($report);
    }

    public function getAccessibilityRequirements(): array
    {
        return $this->requirements->list();
    }
}
