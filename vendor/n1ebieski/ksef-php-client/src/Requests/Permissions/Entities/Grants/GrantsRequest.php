<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Permissions\Entities\Grants;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\Entities\EntityPermission;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\Entities\SubjectDetails;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\SubjectIdentifierNipGroup;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\ValueObjects\Requests\Description;

final class GrantsRequest extends AbstractRequest implements BodyInterface
{
    /**
     * @param array<int, EntityPermission> $permissions
     */
    public function __construct(
        public readonly SubjectIdentifierNipGroup $subjectIdentifierGroup,
        public readonly array $permissions,
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
