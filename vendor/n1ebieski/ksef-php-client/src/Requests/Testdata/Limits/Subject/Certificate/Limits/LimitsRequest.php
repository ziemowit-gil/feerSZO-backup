<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Testdata\Limits\Subject\Certificate\Limits;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Testdata\Limits\Subject\Certificate\Certificate;
use N1ebieski\KSEFClient\DTOs\Requests\Testdata\Limits\Subject\Certificate\Enrollment;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Concerns\HasToBody;
use N1ebieski\KSEFClient\ValueObjects\Requests\Testdata\Limits\Subject\Certificate\SubjectIdentifierType;

final class LimitsRequest extends AbstractRequest implements BodyInterface
{
    use HasToBody;

    public function __construct(
        public readonly SubjectIdentifierType $subjectIdentifierType,
        public readonly Enrollment $enrollment,
        public readonly Certificate $certificate,
    ) {
    }
}
