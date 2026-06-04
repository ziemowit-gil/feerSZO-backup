<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Permissions;

use N1ebieski\KSEFClient\Support\AbstractDTO;

final class PersonById extends AbstractDTO
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
    ) {
    }
}
