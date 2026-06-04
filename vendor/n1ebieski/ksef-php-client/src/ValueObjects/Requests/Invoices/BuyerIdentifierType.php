<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Invoices;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum BuyerIdentifierType: string implements EnumInterface
{
    case None = 'None';

    case Other = 'Other';

    case Nip = 'Nip';

    case VatUe = 'VatUe';
}
