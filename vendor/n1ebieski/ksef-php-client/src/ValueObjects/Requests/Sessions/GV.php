<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Sessions;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum GV: string implements EnumInterface
{
    case Yes = '1';

    case No = '2';
}
