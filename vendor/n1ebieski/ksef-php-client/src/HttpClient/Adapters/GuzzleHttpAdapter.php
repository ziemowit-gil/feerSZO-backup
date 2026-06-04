<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\HttpClient\Adapters;

use GuzzleHttp\ClientInterface as BaseClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class GuzzleHttpAdapter extends AbstractAdapter
{
    public function __construct(private readonly BaseClientInterface $client)
    {
    }

    public function sendAsyncRequest(array $requests, int $concurrency = 8): array
    {
        $responses = [];

        $pool = new Pool($this->client, $requests, [
            'concurrency' => $concurrency,
            'fulfilled' => function (ResponseInterface $response, int $index) use (&$responses): void {
                $responses[$index] = $response;
            },
            'rejected' => function (RequestException $exception, int $index) use (&$responses): void {
                $responses[$index] = $exception->getResponse();
            },
        ]);

        $pool->promise()->wait();

        return $responses;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->send($request, [
            'http_errors' => false
        ]);
    }
}
