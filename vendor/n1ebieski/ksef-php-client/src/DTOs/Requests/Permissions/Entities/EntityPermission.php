<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Permissions\Entities;

use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Entities\EntityPermissionType;

final class EntityPermission extends AbstractDTO
{
    public function __construct(
        public readonly EntityPermissionType $type,
        public readonly Optional | bool $canDelegate = new Optional(),
    ) {
    }
}
