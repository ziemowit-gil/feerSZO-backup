<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Testdata\Permissions;

use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\Requests\Description;
use N1ebieski\KSEFClient\ValueObjects\Requests\Testdata\Permissions\PermissionType;

final class Permission extends AbstractDTO
{
    public function __construct(
        public readonly Description $description,
        public readonly PermissionType $permissionType,
    ) {
    }
}
