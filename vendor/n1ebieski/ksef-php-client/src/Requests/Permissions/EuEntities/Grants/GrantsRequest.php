<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Permissions\EuEntities\Grants;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\EntityByFingerprintGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\PersonByFingerprintWithIdentifierGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\PersonByFingerprintWithoutIdentifierGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\SubjectIdentifierFingerprintGroup;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\ValueObjects\Requests\Description;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\EuEntities\EuEntityPermissionType;

final class GrantsRequest extends AbstractRequest implements BodyInterface
{
    /**
     * @param array<int, EuEntityPermissionType> $permissions
     */
    public function __construct(
        public readonly SubjectIdentifierFingerprintGroup $subjectIdentifierGroup,
        public readonly array $permissions,
        public readonly Description $description,
        public readonly PersonByFingerprintWithIdentifierGroup | PersonByFingerprintWithoutIdentifierGroup | EntityByFingerprintGroup $subjectDetails,
    ) {
    }

    public function toBody(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->toArray();

        return [
            ...$data,
            'subjectIdentifier' => [
                'type' => $this->subjectIdentifierGroup->getIdentifier()->getType(),
                'value' => (string) $this->subjectIdentifierGroup->getIdentifier(),
            ],
        ];
    }
}
