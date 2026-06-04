<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Actions\GenerateQRCodes;

interface InvoiceHashInterface
{
    public function getInvoiceHash(): string;
}
