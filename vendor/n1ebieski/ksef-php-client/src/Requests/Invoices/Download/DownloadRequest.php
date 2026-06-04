<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Invoices\Download;

use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\ValueObjects\Requests\KsefNumber;

final class DownloadRequest extends AbstractRequest
{
    public function __construct(
        public readonly KsefNumber $ksefNumber
    ) {
    }
}
