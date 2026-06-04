<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Permissions\Query\Persons\Grants;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Contracts\ParametersInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\AuthorIdentifierFingerprintGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\AuthorIdentifierNipGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\AuthorIdentifierPeselGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\AuthorIdentifierTypeGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\AuthorizedIdentifierFingerprintGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\AuthorizedIdentifierNipGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\AuthorizedIdentifierPeselGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\ContextIdentifierInternalIdGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\ContextIdentifierNipGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\TargetIdentifierInternalIdGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\TargetIdentifierNipGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\TargetIdentifierTypeGroup;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\Requests\PageOffset;
use N1ebieski\KSEFClient\ValueObjects\Requests\PageSize;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Persons\PersonPermissionType;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Query\PermissionState;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Query\Persons\QueryType;

final class GrantsRequest extends AbstractRequest implements BodyInterface, ParametersInterface
{
    /**
     * @param Optional|array<int, PersonPermissionType> $permissionTypes
     */
    public function __construct(
        public readonly QueryType $queryType,
        public readonly Optional | AuthorIdentifierTypeGroup | AuthorIdentifierNipGroup | AuthorIdentifierPeselGroup | AuthorIdentifierFingerprintGroup $authorIdentifierGroup = new Optional(),
        public readonly Optional | AuthorizedIdentifierNipGroup | AuthorizedIdentifierPeselGroup | AuthorizedIdentifierFingerprintGroup $authorizedIdentifierGroup = new Optional(),
        public readonly Optional | ContextIdentifierNipGroup | ContextIdentifierInternalIdGroup $contextIdentifierGroup = new Optional(),
        public readonly Optional | TargetIdentifierNipGroup | TargetIdentifierInternalIdGroup | TargetIdentifierTypeGroup $targetIdentifierGroup = new Optional(),
        public readonly Optional | array $permissionTypes = new Optional(),
        public readonly Optional | PermissionState $permissionState = new Optional(),
        public readonly Optional | PageOffset $pageOffset = new Optional(),
        public readonly Optional | PageSize $pageSize = new Optional(),
    ) {
    }

    public function toBody(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->toArray(only: [
            'queryType',
            'permissionTypes',
            'permissionState',
        ]);

        if ( ! $this->authorIdentifierGroup instanceof Optional) {
            $data['authorIdentifier'] = [
                'type' => $this->authorIdentifierGroup->getIdentifier()->getType(),
                ...($this->authorIdentifierGroup instanceof AuthorIdentifierTypeGroup ? [] : [
                    'value' => (string) $this->authorIdentifierGroup->getIdentifier()
                ]),
            ];
        }

        if ( ! $this->authorizedIdentifierGroup instanceof Optional) {
            $data['authorizedIdentifier'] = [
                'type' => $this->authorizedIdentifierGroup->getIdentifier()->getType(),
                'value' => (string) $this->authorizedIdentifierGroup->getIdentifier(),
            ];
        }

        if ( ! $this->contextIdentifierGroup instanceof Optional) {
            $data['contextIdentifier'] = [
                'type' => $this->contextIdentifierGroup->getIdentifier()->getType(),
                'value' => (string) $this->contextIdentifierGroup->getIdentifier(),
            ];
        }

        if ( ! $this->targetIdentifierGroup instanceof Optional) {
            $data['targetIdentifier'] = [
                'type' => $this->targetIdentifierGroup->getIdentifier()->getType(),
                ...($this->targetIdentifierGroup instanceof TargetIdentifierTypeGroup ? [] : [
                    'value' => (string) $this->targetIdentifierGroup->getIdentifier()
                ]),
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
