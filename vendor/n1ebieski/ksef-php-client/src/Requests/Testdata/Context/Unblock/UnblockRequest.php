<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Testdata\Context\Unblock;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Auth\ContextIdentifierGroup;
use N1ebieski\KSEFClient\Requests\AbstractRequest;

final class UnblockRequest extends AbstractRequest implements BodyInterface
{
    public function __construct(
        public readonly ContextIdentifierGroup $contextIdentifierGroup,
    ) {
    }

    public function toBody(): array
    {
        return [
            'contextIdentifier' => $this->contextIdentifierGroup->toBody(),
        ];
    }
}
