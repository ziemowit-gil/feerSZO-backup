<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\HttpClient\Adapters;

use N1ebieski\KSEFClient\Exceptions\HttpClient\AsyncClientNotSupportedException;
use Psr\Http\Client\ClientInterface as BaseClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class DefaultHttpAdapter extends AbstractAdapter
{
    public function __construct(private readonly BaseClientInterface $client)
    {
    }

    public function sendAsyncRequest(array $requests, int $concurrency = 8): array
    {
        throw new AsyncClientNotSupportedException('Async client is not supported for this http adapter.');
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->sendRequest($request);
    }
}
