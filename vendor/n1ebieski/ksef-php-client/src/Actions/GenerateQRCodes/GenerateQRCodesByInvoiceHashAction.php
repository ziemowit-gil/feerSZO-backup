<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\GenerateQRCodes;

use DateTimeInterface;
use N1ebieski\KSEFClient\Actions\AbstractAction;
use N1ebieski\KSEFClient\Contracts\Actions\GenerateQRCodes\InvoiceHashInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Auth\ContextIdentifierGroup;
use N1ebieski\KSEFClient\ValueObjects\Certificate;
use N1ebieski\KSEFClient\ValueObjects\CertificateSerialNumber;
use N1ebieski\KSEFClient\ValueObjects\Mode;
use N1ebieski\KSEFClient\ValueObjects\NIP;
use N1ebieski\KSEFClient\ValueObjects\Requests\KsefNumber;

final class GenerateQRCodesByInvoiceHashAction extends AbstractAction implements InvoiceHashInterface
{
    /**
     * @param null|CertificateSerialNumber $certificateSerialNumber {@deprecated} This parameter is no longer used and will be removed in the next major version. The certificate serial number is now obtained directly from the Certificate object.
     */
    public function __construct(
        public readonly NIP $nip,
        public readonly DateTimeInterface $invoiceCreatedAt,
        public readonly string $invoiceHash,
        public readonly Mode $mode = Mode::Production,
        public readonly ?KsefNumber $ksefNumber = null,
        public readonly ?Certificate $certificate = null,
        public readonly ?CertificateSerialNumber $certificateSerialNumber = null,
        public readonly ?ContextIdentifierGroup $contextIdentifierGroup = null,
        public readonly bool $captions = true
    ) {
    }

    public function getInvoiceHash(): string
    {
        return $this->invoiceHash;
    }
}
