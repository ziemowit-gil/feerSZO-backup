<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Limits;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;

interface LimitsResourceInterface
{
    public function context(): ResponseInterface;

    public function subject(): ResponseInterface;
}
