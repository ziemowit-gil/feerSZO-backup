<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Sessions;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum ZnacznikZaplatyCzesciowej: string implements EnumInterface
{
    case Default = '1';

    case WCalosci = '2';
}
