<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs;

use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\Support\Optional;

final class DN extends AbstractDTO
{
    public function __construct(
        public readonly string $commonName,
        public readonly string $countryName,
        public readonly Optional | string | null $givenName = new Optional(),
        public readonly Optional | string | null $surname = new Optional(),
        public readonly Optional | string | null $serialNumber = new Optional(),
        public readonly Optional | string | null $uniqueIdentifier = new Optional(),
        public readonly Optional | string | null $organizationName = new Optional(),
        public readonly Optional | string | null $organizationIdentifier = new Optional(),
    ) {
    }
}
