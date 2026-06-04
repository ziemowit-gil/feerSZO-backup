<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Security\PublicKeyCertificates;

use N1ebieski\KSEFClient\Contracts\EnumInterface;
use N1ebieski\KSEFClient\Support\Concerns\HasEquals;

enum PublicKeyCertificateUsage: string implements EnumInterface
{
    use HasEquals;

    case KsefTokenEncryption = 'KsefTokenEncryption';

    case SymmetricKeyEncryption = 'SymmetricKeyEncryption';
}
