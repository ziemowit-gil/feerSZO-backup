<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests;

use N1ebieski\KSEFClient\Contracts\EnumInterface;
use N1ebieski\KSEFClient\Support\Concerns\HasEquals;

enum SortOrder: string implements EnumInterface
{
    use HasEquals;

    case Asc = 'Asc';

    case Desc = 'Desc';
}
