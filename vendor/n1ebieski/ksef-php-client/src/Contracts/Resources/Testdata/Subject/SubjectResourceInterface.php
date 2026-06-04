<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Testdata\Subject;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Requests\Testdata\Subject\Create\CreateRequest;
use N1ebieski\KSEFClient\Requests\Testdata\Subject\Remove\RemoveRequest;

interface SubjectResourceInterface
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
