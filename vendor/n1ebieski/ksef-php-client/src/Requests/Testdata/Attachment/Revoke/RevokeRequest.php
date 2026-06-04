<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Testdata\Attachment\Revoke;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\NIP;
use N1ebieski\KSEFClient\ValueObjects\Requests\Testdata\Attachment\ExpectedEndDate;

final class RevokeRequest extends AbstractRequest implements BodyInterface
{
    public function __construct(
        public readonly NIP $nip,
        public readonly Optional | ExpectedEndDate $expectedEndDate = new Optional(),
    ) {
    }

    public function toBody(): array
    {
        /** @var array<string, mixed> */
        return $this->toArray();
    }
}
