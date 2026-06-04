<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Testdata\RateLimits\Limits;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Testdata\RateLimits\RateLimits;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Concerns\HasToBody;

final class LimitsRequest extends AbstractRequest implements BodyInterface
{
    use HasToBody;

    public function __construct(
        public readonly RateLimits $rateLimits
    ) {
    }
}
