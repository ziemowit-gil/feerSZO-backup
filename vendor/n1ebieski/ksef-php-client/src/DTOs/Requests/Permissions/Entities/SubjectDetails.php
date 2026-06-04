<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Permissions\Entities;

use N1ebieski\KSEFClient\Support\AbstractDTO;

final class SubjectDetails extends AbstractDTO
{
    public function __construct(
        public readonly string $fullName,
    ) {
    }
}
