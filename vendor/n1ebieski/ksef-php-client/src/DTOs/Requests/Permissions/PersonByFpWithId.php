<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Permissions;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;

final class PersonByFpWithId extends AbstractDTO implements BodyInterface
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly IdentifierPeselGroup | IdentifierNipGroup $identifier,
    ) {
    }

    public function toBody(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->toArray();

        return [
            ...$data,
            'identifier' => [
                'type' => $this->identifier->getIdentifier()->getType(),
                'value' => (string) $this->identifier->getIdentifier(),
            ],
        ];
    }
}
