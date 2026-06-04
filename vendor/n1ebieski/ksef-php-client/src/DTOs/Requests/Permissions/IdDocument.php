<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Permissions;

use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Persons\Country;

final class IdDocument extends AbstractDTO
{
    public function __construct(
        public readonly string $type,
        public readonly string $number,
        public readonly Country $country
    ) {
    }
}
