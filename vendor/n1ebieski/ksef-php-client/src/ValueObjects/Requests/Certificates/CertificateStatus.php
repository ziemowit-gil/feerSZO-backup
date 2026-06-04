<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Certificates;

use N1ebieski\KSEFClient\Contracts\EnumInterface;
use N1ebieski\KSEFClient\Support\Concerns\HasEquals;

enum CertificateStatus: string implements EnumInterface
{
    use HasEquals;

    case Active = 'Active';

    case Blocked = 'Blocked';

    case Revoked = 'Revoked';

    case Expired = 'Expired';
}
