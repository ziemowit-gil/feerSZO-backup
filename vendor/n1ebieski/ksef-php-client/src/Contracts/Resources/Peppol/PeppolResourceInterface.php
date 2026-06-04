<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Peppol;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Requests\Peppol\Query\QueryRequest;

interface PeppolResourceInterface
{
    /**
     * @param QueryRequest|array<string, mixed> $request
     */
    public function query(QueryRequest | array $request): ResponseInterface;
}
