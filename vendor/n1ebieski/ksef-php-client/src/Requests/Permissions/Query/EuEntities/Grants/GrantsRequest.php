<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Permissions\Query\EuEntities\Grants;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Contracts\ParametersInterface;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\Fingerprint;
use N1ebieski\KSEFClient\ValueObjects\Requests\PageOffset;
use N1ebieski\KSEFClient\ValueObjects\Requests\PageSize;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Query\EuEntities\EuEntityPermissionType;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Query\EuEntities\VatUe;

final class GrantsRequest extends AbstractRequest implements BodyInterface, ParametersInterface
{
    /**
     * @param Optional|array<int, EuEntityPermissionType> $permissionTypes
     */
    public function __construct(
        public readonly Optional | VatUe $vatUeIdentifier = new Optional(),
        public readonly Optional | Fingerprint $authorizedFingerprintIdentifier = new Optional(),
        public readonly Optional | array $permissionTypes = new Optional(),
        public readonly Optional | PageOffset $pageOffset = new Optional(),
        public readonly Optional | PageSize $pageSize = new Optional(),
    ) {
    }

    public function toBody(): array
    {
        /** @var array<string, mixed> */
        return $this->toArray(only: [
            'vatUeIdentifier',
            'authorizedFingerprintIdentifier',
            'permissionTypes',
        ]);
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
