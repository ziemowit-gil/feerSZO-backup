<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Ti;

use FeerSzo\Hybrid\Contracts\TyfloProfileClientInterface;
use FeerSzo\Hybrid\Dto\AccessibilityProfileDto;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Remote Driver — TI jest wdrożone na osobnym serwerze. Ten sam kontrakt
 * (TyfloProfileClientInterface) co InternalTyfloProfileClient, ale dane
 * płyną przez REST zamiast wywołania w procesie.
 *
 * Woła api/v1/hybrid_ti.php (patrz ten plik po stronie serwera TI).
 */
final class HttpTyfloProfileClient implements TyfloProfileClientInterface
{
    private readonly Client $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => 10]);
    }

    public function getProfile(int $clientId): AccessibilityProfileDto
    {
        try {
            $response = $this->http->request('GET', rtrim($this->baseUrl, '/') . '/api/v1/hybrid_ti.php', [
                'query'   => ['resource' => 'accessibility_profile', 'client_id' => $clientId],
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
            ]);
            $data = json_decode((string)$response->getBody(), true) ?: [];
            return AccessibilityProfileDto::fromArray($data);
        } catch (GuzzleException $e) {
            // Sieć/serwis TI niedostępny: zwracamy "pusty" profil zamiast wyjątku —
            // wołający (Dydaktyka) traktuje to tak samo jak "brak profilu", żeby
            // przejściowa awaria zdalnego TI nie blokowała całego przepływu.
            return new AccessibilityProfileDto(clientId: $clientId, notes: 'TI niedostępne: ' . $e->getMessage());
        }
    }
}
