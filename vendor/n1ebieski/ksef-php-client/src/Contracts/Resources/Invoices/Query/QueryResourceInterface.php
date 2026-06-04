<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Invoices\Query;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Requests\Invoices\Query\Metadata\MetadataRequest;

interface QueryResourceInterface
{
    /**
     * @param MetadataRequest|array<string, mixed> $request
     */
    public function metadata(MetadataRequest | array $request): ResponseInterface;
}
