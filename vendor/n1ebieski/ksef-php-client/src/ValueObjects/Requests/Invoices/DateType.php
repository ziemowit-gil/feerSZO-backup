<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Invoices;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum DateType: string implements EnumInterface
{
    case Issue = 'Issue';

    case Invoicing = 'Invoicing';

    case PermanentStorage = 'PermanentStorage';
}
