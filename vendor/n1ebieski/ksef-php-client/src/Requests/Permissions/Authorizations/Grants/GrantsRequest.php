<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Permissions\Authorizations\Grants;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\Authorizations\SubjectDetails;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\SubjectIdentifierNipGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\SubjectIdentifierPeppolIdGroup;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\ValueObjects\Requests\Description;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Authorizations\AuthorizationPermissionType;

final class GrantsRequest extends AbstractRequest implements BodyInterface
{
    public function __construct(
        public readonly SubjectIdentifierNipGroup | SubjectIdentifierPeppolIdGroup $subjectIdentifierGroup,
        public readonly AuthorizationPermissionType $permission,
        public readonly Description $description,
        public readonly SubjectDetails $subjectDetails,
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
