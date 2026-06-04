<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Permissions;

use N1ebieski\KSEFClient\Contracts\Requests\Permissions\IdentifierInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\Pesel;

final class SubjectIdentifierPeselGroup extends AbstractDTO implements IdentifierInterface
{
    public function __construct(
        public readonly Pesel $pesel,
    ) {
    }

    public function getIdentifier(): Pesel
    {
        return $this->pesel;
    }
}
