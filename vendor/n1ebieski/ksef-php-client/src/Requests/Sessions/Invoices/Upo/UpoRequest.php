<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Sessions\Invoices\Upo;

use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\ValueObjects\Requests\ReferenceNumber;

final class UpoRequest extends AbstractRequest
{
    public function __construct(
        public readonly ReferenceNumber $referenceNumber,
        public readonly ReferenceNumber $invoiceReferenceNumber
    ) {
    }
}
