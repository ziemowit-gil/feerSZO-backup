<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Permissions;

use N1ebieski\KSEFClient\Contracts\Requests\Permissions\IdentifierInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\PeppolId;

final class SubjectIdentifierPeppolIdGroup extends AbstractDTO implements IdentifierInterface
{
    public function __construct(
        public readonly PeppolId $peppolId,
    ) {
    }

    public function getIdentifier(): PeppolId
    {
        return $this->peppolId;
    }
}
