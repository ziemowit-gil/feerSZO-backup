<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Testdata\Limits\Subject\Certificate;

use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\Validator\Rules\Number\MinRule;
use N1ebieski\KSEFClient\Validator\Validator;

final class Enrollment extends AbstractDTO
{
    public function __construct(
        public readonly int $maxEnrollments,
    ) {
        Validator::validate($this->toArray(), [
            'maxEnrollments' => [new MinRule(0)],
        ]);
    }
}
