<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Invoices;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Invoices\Exports\ExportsResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Invoices\Query\QueryResourceInterface;
use N1ebieski\KSEFClient\Requests\Invoices\Download\DownloadRequest;

interface InvoicesResourceInterface
{
    /**
     * @param DownloadRequest|array<string, mixed> $request
     */
    public function download(DownloadRequest | array $request): ResponseInterface;

    public function query(): QueryResourceInterface;

    public function exports(): ExportsResourceInterface;
}
