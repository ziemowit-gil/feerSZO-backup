<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Sessions\Online;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Requests\Sessions\Online\Close\CloseRequest;
use N1ebieski\KSEFClient\Requests\Sessions\Online\Send\SendRequest;
use N1ebieski\KSEFClient\Requests\Sessions\Online\Send\SendXmlRequest;
use N1ebieski\KSEFClient\Requests\Sessions\Online\Open\OpenRequest;

interface OnlineResourceInterface
{
    /**
     * @param OpenRequest|array<string, mixed> $request
     */
    public function open(OpenRequest | array $request): ResponseInterface;

    /**
     * @param CloseRequest|array<string, mixed> $request
     */
    public function close(CloseRequest | array $request): ResponseInterface;

    /**
     * @param SendRequest|SendXmlRequest|array<string, mixed> $request
     */
    public function send(SendRequest | SendXmlRequest | array $request): ResponseInterface;
}
