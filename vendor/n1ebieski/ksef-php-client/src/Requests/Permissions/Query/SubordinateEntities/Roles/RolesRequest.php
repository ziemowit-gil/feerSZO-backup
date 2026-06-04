<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Permissions\Query\SubordinateEntities\Roles;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Contracts\ParametersInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\SubordinateEntityIdentifierNipGroup;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\Requests\PageOffset;
use N1ebieski\KSEFClient\ValueObjects\Requests\PageSize;

final class RolesRequest extends AbstractRequest implements BodyInterface, ParametersInterface
{
    public function __construct(
        public readonly Optional | SubordinateEntityIdentifierNipGroup $subordinateEntityIdentifierGroup = new Optional(),
        public readonly Optional | PageOffset $pageOffset = new Optional(),
        public readonly Optional | PageSize $pageSize = new Optional(),
    ) {
    }

    public function toBody(): array
    {
        /** @var array<string, mixed> $data */
        $data = [];

        if ( ! $this->subordinateEntityIdentifierGroup instanceof Optional) {
            $data['subordinateEntityIdentifier'] = [
                'type' => $this->subordinateEntityIdentifierGroup->getIdentifier()->getType(),
                'value' => (string) $this->subordinateEntityIdentifierGroup->getIdentifier(),
            ];
        }

        return $data;
    }

    public function toParameters(): array
    {
        /** @var array<string, mixed> */
        return $this->toArray(only: [
            'pageOffset',
            'pageSize',
        ]);
    }
}
