<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\HttpClient;

use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\Support\Arr;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\Support\Str;
use N1ebieski\KSEFClient\ValueObjects\HttpClient\Method;
use N1ebieski\KSEFClient\ValueObjects\HttpClient\Uri;
use N1ebieski\KSEFClient\ValueObjects\Support\KeyType;

final class Request extends AbstractDTO
{
    /**
     * @param array<string, string|array<int, string>> $headers
     * @param array<string, mixed> $parameters
     * @param string|array<string, mixed>|null $body
     */
    public function __construct(
        public readonly Method $method = Method::Get,
        public readonly Uri $uri = new Uri('/'),
        public readonly array $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        public readonly array $parameters = [],
        public readonly array | string | null $body = null,
    ) {
    }

    public function withUri(Uri $uri): self
    {
        return new self($this->method, $uri, $this->headers, $this->parameters, $this->body);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->method, $this->uri, array_merge($this->headers, [$name => $value]), $this->parameters, $this->body);
    }

    public function getParametersAsString(): string
    {
        $parameters = Arr::filterRecursive($this->parameters, fn (mixed $value): bool => ! $value instanceof Optional);

        return $parameters === [] ? '' : '?' . http_build_query($parameters);
    }

    public function getBodyAsString(): string
    {
        if (is_string($this->body)) {
            return $this->body;
        }

        if (is_array($this->body)) {
            $body = Arr::filterRecursive($this->body, fn (mixed $value): bool => ! $value instanceof Optional);

            return $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR);
        }

        return '';
    }

    public function toArray(
        KeyType $keyType = KeyType::Camel,
        array $keyTypeExcept = [],
        array $only = []
    ): array {
        $array = get_object_vars($this);

        $array['body'] = Str::isBinary($this->body) ? '[binary data]' : $this->body;

        return Arr::normalize($array, $keyType, $keyTypeExcept, $only);
    }
}
