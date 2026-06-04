<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\EuEntities;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum EuEntityPermissionType: string implements EnumInterface
{
    case InvoiceWrite = 'InvoiceWrite';

    case InvoiceRead = 'InvoiceRead';
}
