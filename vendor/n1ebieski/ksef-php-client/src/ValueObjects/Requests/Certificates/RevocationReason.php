<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Certificates;

use N1ebieski\KSEFClient\Contracts\EnumInterface;
use N1ebieski\KSEFClient\Support\Concerns\HasEquals;

enum RevocationReason: string implements EnumInterface
{
    use HasEquals;

    case Unspecified = 'Unspecified';

    case Superseded = 'Superseded';

    case KeyCompromise = 'KeyCompromise';
}
