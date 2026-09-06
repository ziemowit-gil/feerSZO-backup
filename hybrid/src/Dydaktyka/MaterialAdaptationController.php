<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Dydaktyka;

use FeerSzo\Hybrid\ClientFactory;
use FeerSzo\Hybrid\Contracts\SzoQualityReportClientInterface;
use FeerSzo\Hybrid\Contracts\TyfloProfileClientInterface;
use FeerSzo\Hybrid\Dto\MaterialAdaptationRequestDto;
use FeerSzo\Hybrid\Dto\QualityReportDto;
use FeerSzo\Hybrid\Dydaktyka\Service\CourseService;
use FeerSzo\Hybrid\Dydaktyka\Service\EnrollmentService;
use FeerSzo\Hybrid\Dydaktyka\Service\MaterialAdaptationService;
use RuntimeException;

/**
 * Przykładowy kontroler domeny Dydaktyka: zgłoszenie zapotrzebowania na
 * adaptację materiału dla uczestnika kursu.
 *
 * Przepływ:
 *   1. Sprawdza, że uczestnik faktycznie jest zapisany na kurs (Dydaktyka).
 *   2. Pobiera jego profil dostępności z TI — przez TyfloProfileClientInterface.
 *   3. Zapisuje zgłoszenie po stronie Dydaktyki.
 *   4. Zgłasza fakt do SZO jako QualityReportDto — przez SzoQualityReportClientInterface.
 *
 * Ani ten kontroler, ani żaden z kroków 2 i 4 NIE WIE, czy TI/SZO działają
 * w tym samym procesie, czy na osobnym serwerze — o tym decyduje wyłącznie
 * ClientFactory (na podstawie DriverConfig / zmiennych środowiskowych).
 * Dzięki temu ten sam kod działa bez zmian w obu trybach: Monolit Modularny
 * i Niezależny Serwis.
 */
final class MaterialAdaptationController
{
    public function __construct(
        private readonly TyfloProfileClientInterface $tyfloProfileClient,
        private readonly SzoQualityReportClientInterface $szoClient,
        private readonly CourseService $courses = new CourseService(),
        private readonly EnrollmentService $enrollments = new EnrollmentService(),
        private readonly MaterialAdaptationService $adaptations = new MaterialAdaptationService(),
    ) {
    }

    /** Domyślna konstrukcja — klienci dobrani przez ClientFactory wg konfiguracji driverów. */
    public static function withDefaultClients(): self
    {
        return new self(
            tyfloProfileClient: ClientFactory::tyfloProfileClient(),
            szoClient: ClientFactory::szoQualityReportClient(),
        );
    }

    /**
     * @return array{request_id:int, profile_used: array<string,mixed>, quality_report_id:int}
     */
    public function requestAdaptation(int $courseId, int $clientId, string $requestedFormat, string $reason = ''): array
    {
        $course = $this->courses->get($courseId);
        if (!$course) {
            throw new RuntimeException("Kurs #{$courseId} nie istnieje.");
        }
        if (!$this->enrollments->isEnrolled($courseId, $clientId)) {
            throw new RuntimeException("Kursant #{$clientId} nie jest aktywnie zapisany na kurs #{$courseId}.");
        }

        // (2) TI — profil dostępności (Local albo Http, w zależności od TI_DRIVER)
        $profile = $this->tyfloProfileClient->getProfile($clientId);

        // (3) Dydaktyka — zapis własnego zgłoszenia
        $requestId = $this->adaptations->create(new MaterialAdaptationRequestDto(
            courseId: $courseId,
            clientId: $clientId,
            requestedFormat: $requestedFormat,
            reason: $reason !== '' ? $reason : ('Preferowane formaty z profilu TI: ' . implode(', ', $profile->preferredMaterialFormats)),
        ));

        // (4) SZO — zgłoszenie jakościowe (Local albo Http, w zależności od SZO_DRIVER)
        $reportId = $this->szoClient->submitReport(new QualityReportDto(
            sourceModule: 'dydaktyka',
            category: 'adaptacja_materialow',
            severity: 'medium',
            description: sprintf(
                'Zgłoszenie #%d: adaptacja materiału (%s) dla kursanta #%d na kursie „%s".',
                $requestId,
                $requestedFormat,
                $clientId,
                $course->name
            ),
            courseId: $courseId,
            clientId: $clientId,
        ));

        return [
            'request_id'        => $requestId,
            'profile_used'      => $profile->toArray(),
            'quality_report_id' => $reportId,
        ];
    }
}
