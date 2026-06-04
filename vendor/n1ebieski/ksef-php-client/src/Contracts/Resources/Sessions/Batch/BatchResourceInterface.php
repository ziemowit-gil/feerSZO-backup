<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Sessions\Batch;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Contracts\Requests\Sessions\Batch\OpenAndSend\OpenAndSendResponseInterface;
use N1ebieski\KSEFClient\Requests\Sessions\Batch\Close\CloseRequest;
use N1ebieski\KSEFClient\Requests\Sessions\Batch\OpenAndSend\OpenAndSendRequest;
use N1ebieski\KSEFClient\Requests\Sessions\Batch\OpenAndSend\OpenAndSendXmlRequest;
use N1ebieski\KSEFClient\Requests\Sessions\Batch\OpenAndSend\OpenAndSendZipRequest;

interface BatchResourceInterface
{
    /**
     * @param OpenAndSendRequest|OpenAndSendXmlRequest|OpenAndSendZipRequest|array<string, mixed> $request
     */
    public function openAndSend(OpenAndSendRequest | OpenAndSendXmlRequest | OpenAndSendZipRequest | array $request): OpenAndSendResponseInterface;

    /**
     * @param CloseRequest|array<string, mixed> $request
     */
    public function close(CloseRequest | array $request): ResponseInterface;
}
