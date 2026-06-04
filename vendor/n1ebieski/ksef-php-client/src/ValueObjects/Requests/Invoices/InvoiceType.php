<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Invoices;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum InvoiceType: string implements EnumInterface
{
    case Vat = 'Vat';

    case Zal = 'Zal';

    case Kor = 'Kor';

    case Roz = 'Roz';

    case Upr = 'Upr';

    case KorZal = 'KorZal';

    case KorRoz = 'KorRoz';

    case VatPef = 'VatPef';

    case VatPefSp = 'VatPefSp';

    case KorPef = 'KorPef';

    case VatRr = 'VatRr';

    case KorVatRr = 'KorVatRr';
}
