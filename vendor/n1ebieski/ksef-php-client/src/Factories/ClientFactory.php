<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Factories;

use GuzzleHttp\ClientInterface as GuzzleHttpClientInterface;
use N1ebieski\KSEFClient\Contracts\HttpClient\ClientInterface;
use N1ebieski\KSEFClient\HttpClient\Adapters\DefaultHttpAdapter;
use N1ebieski\KSEFClient\HttpClient\Adapters\GuzzleHttpAdapter;
use Psr\Http\Client\ClientInterface as BaseClientInterface;

final class ClientFactory extends AbstractFactory
{
    public static function make(BaseClientInterface $baseClient): ClientInterface
    {
        $clientAdapter = match (true) {
            $baseClient instanceof GuzzleHttpClientInterface => GuzzleHttpAdapter::class,
            default => DefaultHttpAdapter::class
        };

        //@phpstan-ignore-next-line
        return new $clientAdapter($baseClient);
    }
}
