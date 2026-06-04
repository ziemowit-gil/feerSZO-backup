<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Testdata\Subject;

use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\NIP;
use N1ebieski\KSEFClient\ValueObjects\Requests\Description;

final class Subunit extends AbstractDTO
{
    public function __construct(
        public readonly NIP $subjectNip,
        public readonly Description $description,
    ) {
    }
}
