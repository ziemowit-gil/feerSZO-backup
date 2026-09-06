<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Ti;

use FeerSzo\Hybrid\Contracts\TyfloProfileClientInterface;
use FeerSzo\Hybrid\Dto\AccessibilityProfileDto;
use FeerSzo\Hybrid\Ti\Service\AccessibilityProfileService;

/**
 * Local Driver — TI działa w tym samym procesie/serwerze co wołający.
 * Wywołanie bezpośrednie w kodzie (DTO w obie strony), bez sieci.
 */
final class InternalTyfloProfileClient implements TyfloProfileClientInterface
{
    public function __construct(
        private readonly AccessibilityProfileService $service = new AccessibilityProfileService(),
    ) {
    }

    public function getProfile(int $clientId): AccessibilityProfileDto
    {
        return $this->service->getProfile($clientId);
    }
}
