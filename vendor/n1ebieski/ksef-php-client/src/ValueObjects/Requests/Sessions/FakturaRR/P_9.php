<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\FakturaRR;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum P_9: string implements EnumInterface
{
    case Tax7 = '7';

    case Tax6_5 = '6.5';
}
