<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Testdata\Subject\Remove;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Concerns\HasToBody;
use N1ebieski\KSEFClient\ValueObjects\NIP;

final class RemoveRequest extends AbstractRequest implements BodyInterface
{
    use HasToBody;

    public function __construct(
        public readonly NIP $subjectNip,
    ) {
    }
}
