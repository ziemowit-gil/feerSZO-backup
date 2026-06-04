<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Tokens;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Requests\Tokens\Create\CreateRequest;
use N1ebieski\KSEFClient\Requests\Tokens\List\ListRequest;
use N1ebieski\KSEFClient\Requests\Tokens\Revoke\RevokeRequest;
use N1ebieski\KSEFClient\Requests\Tokens\Status\StatusRequest;

interface TokensResourceInterface
{
    /**
     * @param CreateRequest|array<string, mixed> $request
     */
    public function create(CreateRequest | array $request): ResponseInterface;

    /**
     * @param ListRequest|array<string, mixed> $request
     */
    public function list(ListRequest | array $request): ResponseInterface;

    /**
     * @param StatusRequest|array<string, mixed> $request
     */
    public function status(StatusRequest | array $request): ResponseInterface;

    /**
     * @param RevokeRequest|array<string, mixed> $request
     */
    public function revoke(RevokeRequest | array $request): ResponseInterface;
}
