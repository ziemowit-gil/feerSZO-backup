<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Permissions;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum TargetIdentifierType: string implements EnumInterface
{
    case AllPartners = 'AllPartners';

    public function getType(): string
    {
        return $this->value;
    }
}
