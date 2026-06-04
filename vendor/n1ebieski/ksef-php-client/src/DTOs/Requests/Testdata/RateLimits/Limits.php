<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Testdata\RateLimits;

use N1ebieski\KSEFClient\Support\AbstractDTO;

final class Limits extends AbstractDTO
{
    public function __construct(
        public readonly int $perSecond,
        public readonly int $perMinute,
        public readonly int $perHour
    ) {
    }
}
