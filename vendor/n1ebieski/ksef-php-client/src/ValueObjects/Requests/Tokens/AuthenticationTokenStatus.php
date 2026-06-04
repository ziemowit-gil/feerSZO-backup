<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Tokens;

use N1ebieski\KSEFClient\Contracts\EnumInterface;
use N1ebieski\KSEFClient\Support\Concerns\HasEquals;

enum AuthenticationTokenStatus: string implements EnumInterface
{
    use HasEquals;

    case Pending = 'Pending';

    case Active = 'Active';

    case Revoking = 'Revoking';

    case Revoked = 'Revoked';

    case Failed = 'Failed';
}
