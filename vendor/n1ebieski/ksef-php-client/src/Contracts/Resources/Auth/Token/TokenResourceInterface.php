<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Auth\Token;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;

interface TokenResourceInterface
{
    public function redeem(): ResponseInterface;

    public function refresh(): ResponseInterface;
}
