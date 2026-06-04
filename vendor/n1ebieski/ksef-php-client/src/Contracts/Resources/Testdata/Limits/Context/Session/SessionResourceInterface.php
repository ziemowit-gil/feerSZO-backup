<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Testdata\Limits\Context\Session;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Requests\Testdata\Limits\Context\Session\Limits\LimitsRequest;

interface SessionResourceInterface
{
    /**
     * @param LimitsRequest|array<string, mixed> $request
     */
    public function limits(LimitsRequest | array $request): ResponseInterface;

    public function reset(): ResponseInterface;
}
