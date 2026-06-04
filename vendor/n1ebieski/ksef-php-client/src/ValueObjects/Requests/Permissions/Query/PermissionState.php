<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Query;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum PermissionState: string implements EnumInterface
{
    case Active = 'Active';

    case Inactive = 'Inactive';
}
