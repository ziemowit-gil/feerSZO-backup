<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Security\PublicKeyCertificates;

use N1ebieski\KSEFClient\Contracts\ConfigInterface;
use N1ebieski\KSEFClient\Contracts\HttpClient\HttpClientInterface;
use N1ebieski\KSEFClient\DTOs\Config;
use N1ebieski\KSEFClient\DTOs\HttpClient\Request;
use N1ebieski\KSEFClient\Requests\AbstractHandler;
use N1ebieski\KSEFClient\ValueObjects\HttpClient\Method;
use N1ebieski\KSEFClient\ValueObjects\HttpClient\Uri;
use Psr\SimpleCache\CacheInterface;

final class PublicKeyCertificatesHandler extends AbstractHandler
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly Config $config,
        private readonly ?CacheInterface $cache = null
    ) {
    }

    public function handle(): PublicKeyCertificatesResponse
    {
        if ( ! $this->cache instanceof CacheInterface) {
            return $this->getPublicKeyCertificatesResponse();
        }

        $response = $this->cache->get($this->getCacheKeyForMode());

        if ($response instanceof PublicKeyCertificatesResponse) {
            return $response;
        }

        $response = $this->getPublicKeyCertificatesResponse();

        $this->cache->set(
            $this->getCacheKeyForMode(),
            $response,
            $this->config->cacheTTL
        );

        return $response;
    }

    private function getCacheKeyForMode(): string
    {
        return sprintf(ConfigInterface::PUBLIC_KEY_CERTIFICATES_CACHE_KEY, $this->config->mode->value);
    }

    private function getPublicKeyCertificatesResponse(): PublicKeyCertificatesResponse
    {
        $response = $this->client
            ->withoutAccessToken()
            ->sendRequest(new Request(
                method: Method::Get,
                uri: Uri::from('security/public-key-certificates')
            ));

        return new PublicKeyCertificatesResponse($response);
    }
}
