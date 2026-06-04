<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Exceptions;

use Exception;
use N1ebieski\KSEFClient\Contracts\ArrayableInterface;
use N1ebieski\KSEFClient\Contracts\ContextInterface;
use N1ebieski\KSEFClient\Support\Arr;
use N1ebieski\KSEFClient\ValueObjects\Support\KeyType;
use Throwable;

abstract class AbstractException extends Exception implements ArrayableInterface, ContextInterface
{
    /**
     * @param object|array<string, mixed>|null $context
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        public readonly object|array|null $context = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function toArray(KeyType $keyType = KeyType::Camel, array $only = []): array
    {
        /** @var array<string, mixed> */
        return Arr::normalize([
            'code' => $this->getCode(),
            'message' => $this->getMessage(),
            'context' => $this->context,
        ], $keyType, $only);
    }
}
