<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Szo;

use FeerSzo\Hybrid\Contracts\SzoQualityReportClientInterface;
use FeerSzo\Hybrid\Dto\AccessibilityRequirementDto;
use FeerSzo\Hybrid\Dto\QualityReportDto;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Remote Driver — SZO jest wdrożone na osobnym serwerze. Woła
 * api/v1/hybrid_szo.php (patrz ten plik po stronie serwera SZO).
 */
final class HttpSzoQualityReportClient implements SzoQualityReportClientInterface
{
    private readonly Client $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => 10]);
    }

    public function submitReport(QualityReportDto $report): int
    {
        try {
            $response = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/api/v1/hybrid_szo.php', [
                'query'   => ['resource' => 'quality_report'],
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'json'    => $report->toArray(),
            ]);
            $data = json_decode((string)$response->getBody(), true) ?: [];
            return (int)($data['id'] ?? 0);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Zgłoszenie do SZO nie powiodło się: ' . $e->getMessage(), previous: $e);
        }
    }

    public function getAccessibilityRequirements(): array
    {
        try {
            $response = $this->http->request('GET', rtrim($this->baseUrl, '/') . '/api/v1/hybrid_szo.php', [
                'query'   => ['resource' => 'accessibility_requirements'],
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
            ]);
            $data = json_decode((string)$response->getBody(), true) ?: [];
            return array_map(static fn(array $r) => AccessibilityRequirementDto::fromArray($r), $data);
        } catch (GuzzleException $e) {
            return [];
        }
    }
}
