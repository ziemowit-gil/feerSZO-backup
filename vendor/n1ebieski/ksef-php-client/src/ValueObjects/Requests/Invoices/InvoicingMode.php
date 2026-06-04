<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Invoices;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum InvoicingMode: string implements EnumInterface
{
    case Online = 'Online';

    case Offline = 'Offline';
}
