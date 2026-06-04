<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Requests\Sessions\Batch\OpenAndSend;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use Psr\Http\Message\ResponseInterface as BaseResponseInterface;

/**
 * @property-read BaseResponseInterface $baseOpenResponse
 * @property-read array<int, ResponseInterface|null> $partUploadResponses
 */
interface OpenAndSendResponseInterface extends ResponseInterface
{
}
