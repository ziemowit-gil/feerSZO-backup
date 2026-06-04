<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Testdata\Permissions;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;

final class AuthorizedIdentifier extends AbstractDTO implements BodyInterface
{
    public function __construct(
        public readonly AuthorizedIdentifierNipGroup | AuthorizedIdentifierPeselGroup | AuthorizedIdentifierFingerprintGroup $identifierGroup,
    ) {
    }

    public function toBody(): array
    {
        return [
            'type' => $this->identifierGroup->getIdentifier()->getType(),
            'value' => (string) $this->identifierGroup->getIdentifier(),
        ];
    }
}
