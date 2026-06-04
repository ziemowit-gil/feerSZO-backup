<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Auth\Sessions\Revoke;

use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\ValueObjects\Requests\ReferenceNumber;

final class RevokeRequest extends AbstractRequest
{
    public function __construct(
        public readonly ReferenceNumber $referenceNumber,
    ) {
    }
}
