<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Permissions;

use DateTimeInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;

final class PersonByFpNoId extends AbstractDTO
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly DateTimeInterface $birthDate,
        public readonly IdDocument $idDocument
    ) {
    }
}
