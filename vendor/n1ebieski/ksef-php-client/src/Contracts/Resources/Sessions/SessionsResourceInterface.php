<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Sessions;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Sessions\Batch\BatchResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Sessions\Invoices\InvoicesResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Sessions\Online\OnlineResourceInterface;
use N1ebieski\KSEFClient\Requests\Sessions\List\ListRequest;
use N1ebieski\KSEFClient\Requests\Sessions\Status\StatusRequest;
use N1ebieski\KSEFClient\Requests\Sessions\Upo\UpoRequest;

interface SessionsResourceInterface
{
    public function online(): OnlineResourceInterface;

    public function batch(): BatchResourceInterface;

    /**
     * @param StatusRequest|array<string, mixed> $request
     */
    public function status(StatusRequest | array $request): ResponseInterface;

    /**
     * @param ListRequest|array<string, mixed> $request
     */
    public function list(ListRequest | array $request): ResponseInterface;

    public function invoices(): InvoicesResourceInterface;

    /**
     * @param UpoRequest|array<string, mixed> $request
     */
    public function upo(UpoRequest | array $request): ResponseInterface;
}
