<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Authorizations;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum QueryType: string implements EnumInterface
{
    case Granted = 'Granted';

    case Received = 'Received';
}
