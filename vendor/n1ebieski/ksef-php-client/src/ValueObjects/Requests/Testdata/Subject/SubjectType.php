<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Testdata\Subject;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum SubjectType: string implements EnumInterface
{
    case EnforcementAuthority = 'EnforcementAuthority';

    case VatGroup = 'VatGroup';

    case JST = 'JST';
}
