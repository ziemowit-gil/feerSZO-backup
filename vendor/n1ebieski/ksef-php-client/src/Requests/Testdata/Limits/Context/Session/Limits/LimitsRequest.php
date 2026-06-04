<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Testdata\Limits\Context\Session\Limits;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Testdata\Limits\Context\Session\BatchSession;
use N1ebieski\KSEFClient\DTOs\Requests\Testdata\Limits\Context\Session\OnlineSession;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Concerns\HasToBody;

final class LimitsRequest extends AbstractRequest implements BodyInterface
{
    use HasToBody;

    public function __construct(
        public readonly OnlineSession $onlineSession,
        public readonly BatchSession $batchSession,
    ) {
    }
}
