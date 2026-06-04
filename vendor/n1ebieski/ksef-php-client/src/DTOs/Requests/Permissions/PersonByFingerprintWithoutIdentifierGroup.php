<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Permissions;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\Support\Concerns\HasToBody;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\SubjectDetailsType;

final class PersonByFingerprintWithoutIdentifierGroup extends AbstractDTO implements BodyInterface
{
    use HasToBody;

    public readonly SubjectDetailsType $subjectDetailsType;

    public function __construct(
        public readonly PersonByFpNoId $personByFpNoId,
    ) {
        $this->subjectDetailsType = SubjectDetailsType::PersonByFingerprintWithoutIdentifier;
    }
}
