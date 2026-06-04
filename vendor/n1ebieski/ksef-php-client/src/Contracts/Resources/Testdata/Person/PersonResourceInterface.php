<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Testdata\Person;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Requests\Testdata\Person\Create\CreateRequest;
use N1ebieski\KSEFClient\Requests\Testdata\Person\Remove\RemoveRequest;

interface PersonResourceInterface
{
    /**
     * @param CreateRequest|array<string, mixed> $request
     */
    public function create(CreateRequest | array $request): ResponseInterface;

    /**
     * @param RemoveRequest|array<string, mixed> $request
     */
    public function remove(RemoveRequest | array $request): ResponseInterface;
}
