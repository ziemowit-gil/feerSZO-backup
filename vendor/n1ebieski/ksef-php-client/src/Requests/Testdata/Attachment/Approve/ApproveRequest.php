<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Testdata\Attachment\Approve;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\ValueObjects\NIP;

final class ApproveRequest extends AbstractRequest implements BodyInterface
{
    public function __construct(
        public readonly NIP $nip,
    ) {
    }

    public function toBody(): array
    {
        /** @var array<string, mixed> */
        return $this->toArray();
    }
}
