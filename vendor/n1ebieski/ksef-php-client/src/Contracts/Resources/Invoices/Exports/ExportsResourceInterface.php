<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Invoices\Exports;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Requests\Invoices\Exports\Init\InitRequest;
use N1ebieski\KSEFClient\Requests\Invoices\Exports\Status\StatusRequest;

interface ExportsResourceInterface
{
    /**
     * @param InitRequest|array<string, mixed> $request
     */
    public function init(InitRequest | array $request): ResponseInterface;

    /**
     * @param StatusRequest|array<string, mixed> $request
     */
    public function status(StatusRequest | array $request): ResponseInterface;
}
