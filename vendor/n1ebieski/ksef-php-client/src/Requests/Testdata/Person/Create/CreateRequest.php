<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Testdata\Person\Create;

use DateTime;
use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\NIP;
use N1ebieski\KSEFClient\ValueObjects\Pesel;

final class CreateRequest extends AbstractRequest implements BodyInterface
{
    public function __construct(
        public readonly NIP $nip,
        public readonly Pesel $pesel,
        public readonly string $description,
        public readonly bool $isBailiff = false,
        public readonly Optional | DateTime | null $createdDate = new Optional(),
    ) {
    }

    public function toBody(): array
    {
        /** @var array<string, mixed> */
        return $this->toArray();
    }
}
