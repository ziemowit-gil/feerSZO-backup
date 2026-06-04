<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Query\Persons;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum AuthorIdentifierType: string implements EnumInterface
{
    case System = 'System';

    public function getType(): string
    {
        return $this->value;
    }
}
