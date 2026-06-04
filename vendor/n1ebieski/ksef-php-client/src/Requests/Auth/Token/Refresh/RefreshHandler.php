<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Auth\Token\Refresh;

use InvalidArgumentException;
use N1ebieski\KSEFClient\Contracts\HttpClient\HttpClientInterface;
use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\DTOs\Config;
use N1ebieski\KSEFClient\DTOs\HttpClient\Request;
use N1ebieski\KSEFClient\Requests\AbstractHandler;
use N1ebieski\KSEFClient\ValueObjects\AccessToken;
use N1ebieski\KSEFClient\ValueObjects\HttpClient\Method;
use N1ebieski\KSEFClient\ValueObjects\HttpClient\Uri;
use N1ebieski\KSEFClient\ValueObjects\RefreshToken;

final class RefreshHandler extends AbstractHandler
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly Config $config
    ) {
    }

    public function handle(): ResponseInterface
    {
        if ( ! $this->config->refreshToken instanceof RefreshToken) {
            throw new InvalidArgumentException('Refresh token is empty');
        }

        return $this->client
            ->withAccessToken(AccessToken::from($this->config->refreshToken->token))
            ->sendRequest(new Request(
                method: Method::Post,
                uri: Uri::from('auth/token/refresh')
            ));
    }
}
