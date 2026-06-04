<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Tokens\Create;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\ValueObjects\Requests\Tokens\TokenPermissionType;

final class CreateRequest extends AbstractRequest implements BodyInterface
{
    /**
     * @param array<int, TokenPermissionType> $permissions
     */
    public function __construct(
        public readonly array $permissions,
        public readonly string $description,
    ) {
    }

    public function toBody(): array
    {
        /** @var array<string, mixed> */
        return $this->toArray();
    }
}
