<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Query\Persons;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum QueryType: string implements EnumInterface
{
    case PermissionsInCurrentContext = 'PermissionsInCurrentContext';

    case PermissionsGrantedInCurrentContext = 'PermissionsGrantedInCurrentContext';
}
