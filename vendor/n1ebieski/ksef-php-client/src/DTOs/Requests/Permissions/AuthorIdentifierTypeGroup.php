<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Permissions;

use N1ebieski\KSEFClient\Contracts\Requests\Permissions\IdentifierInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Query\Persons\AuthorIdentifierType;

final class AuthorIdentifierTypeGroup extends AbstractDTO implements IdentifierInterface
{
    public function __construct(
        public readonly AuthorIdentifierType $type,
    ) {
    }

    public function getIdentifier(): AuthorIdentifierType
    {
        return $this->type;
    }
}
