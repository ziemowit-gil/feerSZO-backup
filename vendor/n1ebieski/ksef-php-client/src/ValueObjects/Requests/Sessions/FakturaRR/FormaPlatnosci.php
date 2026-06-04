<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\FakturaRR;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum FormaPlatnosci: string implements EnumInterface
{
    case Przelew = '1';
}
