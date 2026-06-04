<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Testdata\Context;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Requests\Testdata\Context\Block\BlockRequest;
use N1ebieski\KSEFClient\Requests\Testdata\Context\Unblock\UnblockRequest;

interface ContextResourceInterface
{
    /**
     * @param BlockRequest|array<string, mixed> $request
     */
    public function block(BlockRequest | array $request): ResponseInterface;

    /**
     * @param UnblockRequest|array<string, mixed> $request
     */
    public function unblock(UnblockRequest | array $request): ResponseInterface;
}
