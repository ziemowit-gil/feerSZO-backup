<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\FakturaRR;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum RodzajFaktury: string implements EnumInterface
{
    case VatRr = 'VAT_RR';

    case KorVatRr = 'KOR_VAT_RR';
}
