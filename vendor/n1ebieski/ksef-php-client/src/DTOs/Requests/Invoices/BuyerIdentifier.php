<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Invoices;

use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\BuyerIdentifierType;

final class BuyerIdentifier extends AbstractDTO
{
    public function __construct(
        public readonly BuyerIdentifierType $type,
        public readonly Optional | string $value = new Optional(),
    ) {
    }
}
