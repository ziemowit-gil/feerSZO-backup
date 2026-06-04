<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Authorizations;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum AuthorizationPermissionType: string implements EnumInterface
{
    case SelfInvoicing = 'SelfInvoicing';

    case RRInvoicing = 'RRInvoicing';

    case TaxRepresentative = 'TaxRepresentative';

    case PefInvoicing = 'PefInvoicing';
}
