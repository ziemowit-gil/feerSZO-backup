<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Testdata\Limits\Subject\Certificate;

use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\Validator\Rules\Number\MinRule;
use N1ebieski\KSEFClient\Validator\Validator;

final class Certificate extends AbstractDTO
{
    public function __construct(
        public readonly int $maxCertificates,
    ) {
        Validator::validate($this->toArray(), [
            'maxCertificates' => [new MinRule(0)],
        ]);
    }
}
