<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Permissions;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum SubjectDetailsType: string implements EnumInterface
{
    case PersonByIdentifier = 'PersonByIdentifier';

    case PersonByFingerprintWithIdentifier = 'PersonByFingerprintWithIdentifier';

    case PersonByFingerprintWithoutIdentifier = 'PersonByFingerprintWithoutIdentifier';

    case EntityByFingerprint = 'EntityByFingerprint';
}
