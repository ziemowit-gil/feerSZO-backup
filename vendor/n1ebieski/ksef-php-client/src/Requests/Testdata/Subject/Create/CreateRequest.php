<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Testdata\Subject\Create;

use DateTime;
use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Testdata\Subject\Subunit;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Concerns\HasToBody;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\NIP;
use N1ebieski\KSEFClient\ValueObjects\Requests\Description;
use N1ebieski\KSEFClient\ValueObjects\Requests\Testdata\Subject\SubjectType;

final class CreateRequest extends AbstractRequest implements BodyInterface
{
    use HasToBody;

    /**
     * @param Optional|array<int, Subunit> $subunits
     */
    public function __construct(
        public readonly NIP $subjectNip,
        public readonly SubjectType $subjectType,
        public readonly Description $description,
        public readonly Optional | array $subunits = new Optional(),
        public readonly Optional | DateTime | null $createdDate = new Optional(),
    ) {
    }
}
