<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Permissions\EuEntities\Administration\Grants;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\ContextIdentifierNipVatUeGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\EntityByFingerprintGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\EuEntities\Administration\EuEntityDetails;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\PersonByFingerprintWithIdentifierGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\PersonByFingerprintWithoutIdentifierGroup;
use N1ebieski\KSEFClient\DTOs\Requests\Permissions\SubjectIdentifierFingerprintGroup;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\ValueObjects\Requests\Description;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\EuEntities\Administration\EuEntityName;

final class GrantsRequest extends AbstractRequest implements BodyInterface
{
    public function __construct(
        public readonly SubjectIdentifierFingerprintGroup $subjectIdentifierGroup,
        public readonly ContextIdentifierNipVatUeGroup $contextIdentifierGroup,
        public readonly Description $description,
        public readonly EuEntityName $euEntityName,
        public readonly PersonByFingerprintWithIdentifierGroup | PersonByFingerprintWithoutIdentifierGroup | EntityByFingerprintGroup $subjectDetails,
        public readonly EuEntityDetails $euEntityDetails
    ) {
    }

    public function toBody(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->toArray();

        return [
            ...$data,
            'euEntityName' => (string) $this->euEntityName,
            'euEntityDetails' => $this->euEntityDetails->toBody(),
            'subjectIdentifier' => [
                'type' => $this->subjectIdentifierGroup->getIdentifier()->getType(),
                'value' => (string) $this->subjectIdentifierGroup->getIdentifier(),
            ],
            'contextIdentifier' => [
                'type' => $this->contextIdentifierGroup->getIdentifier()->getType(),
                'value' => (string) $this->contextIdentifierGroup->getIdentifier(),
            ],
        ];
    }
}
