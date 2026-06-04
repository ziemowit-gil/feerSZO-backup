<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Permissions;

use N1ebieski\KSEFClient\Contracts\Requests\Permissions\IdentifierInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\NipVatUe;

final class ContextIdentifierNipVatUeGroup extends AbstractDTO implements IdentifierInterface
{
    public function __construct(
        public readonly NipVatUe $nipVatUe,
    ) {
    }

    public function getIdentifier(): NipVatUe
    {
        return $this->nipVatUe;
    }
}
