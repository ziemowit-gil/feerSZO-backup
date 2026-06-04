<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Invoices;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum AmountType: string implements EnumInterface
{
    case Brutto = 'Brutto';

    case Netto = 'Netto';

    case Vat = 'Vat';
}
