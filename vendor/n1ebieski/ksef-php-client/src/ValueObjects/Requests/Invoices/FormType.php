<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Invoices;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum FormType: string implements EnumInterface
{
    case FA = 'FA';

    case PEF = 'PEF';

    case FA_RR = 'FA_RR';
}
