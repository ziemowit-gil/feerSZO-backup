<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Tokens;

use N1ebieski\KSEFClient\Contracts\EnumInterface;
use N1ebieski\KSEFClient\Support\Concerns\HasEquals;

enum AuthorIdentifierType: string implements EnumInterface
{
    use HasEquals;

    case Nip = 'Nip';

    case Pesel = 'Pesel';

    case Fingerprint = 'Fingerprint';
}
