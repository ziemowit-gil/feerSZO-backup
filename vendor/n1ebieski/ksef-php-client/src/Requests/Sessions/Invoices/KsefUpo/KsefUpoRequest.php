<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Sessions\Invoices\KsefUpo;

use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\ValueObjects\Requests\KsefNumber;
use N1ebieski\KSEFClient\ValueObjects\Requests\ReferenceNumber;

final class KsefUpoRequest extends AbstractRequest
{
    public function __construct(
        public readonly ReferenceNumber $referenceNumber,
        public readonly KsefNumber $ksefNumber
    ) {
    }
}
