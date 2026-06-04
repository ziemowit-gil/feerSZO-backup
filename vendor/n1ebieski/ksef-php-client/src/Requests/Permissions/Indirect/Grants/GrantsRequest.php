<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Permissions\Indirect\Grants;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\PersonByFingerprintWithIdentifierGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\PersonByFingerprintWithoutIdentifierGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\PersonByIdentifierGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\SubjectIdentifierFingerprintGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\SubjectIdentifierNipGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\SubjectIdentifierPeselGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\TargetIdentifierInternalIdGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\TargetIdentifierNipGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\TargetIdentifierTypeGroup;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Concerns\HasToBody;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\Requests\Description;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Indirect\IndirectPermissionType;

final class GrantsRequest extends AbstractRequest implements BodyInterface
{
    use HasToBody {
        HasToBody::toBody as baseToBody;
    }

    /**
     * @param array<int, IndirectPermissionType> $permissions
     */
    public function __construct(
        public readonly SubjectIdentifierNipGroup | SubjectIdentifierPeselGroup | SubjectIdentifierFingerprintGroup $subjectIdentifierGroup,
        public readonly array $permissions,
        public readonly Description $description,
        public readonly PersonByIdentifierGroup | PersonByFingerprintWithIdentifierGroup | PersonByFingerprintWithoutIdentifierGroup $subjectDetails,
        public readonly Optional | TargetIdentifierNipGroup | TargetIdentifierInternalIdGroup | TargetIdentifierTypeGroup $targetIdentifierGroup = new Optional(),
    ) {
    }

    public function toBody(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->baseToBody();

        if ( ! $this->targetIdentifierGroup instanceof Optional) {
            $data['targetIdentifier'] = [
                'type' => $this->targetIdentifierGroup->getIdentifier()->getType(),
                ...($this->targetIdentifierGroup instanceof TargetIdentifierTypeGroup ? [] : [
                    'value' => (string) $this->targetIdentifierGroup->getIdentifier()
                ]),
            ];
        }

        return [
            ...$data,
            'subjectIdentifier' => [
                'type' => $this->subjectIdentifierGroup->getIdentifier()->getType(),
                'value' => (string) $this->subjectIdentifierGroup->getIdentifier(),
            ]
        ];
    }
}
