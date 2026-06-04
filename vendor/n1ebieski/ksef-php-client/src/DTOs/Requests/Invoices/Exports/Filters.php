<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Invoices\Exports;

use N1ebieski\KSEFClient\DTOs\Requests\Invoices\Amount;
use N1ebieski\KSEFClient\DTOs\Requests\Invoices\BuyerIdentifier;
use N1ebieski\KSEFClient\DTOs\Requests\Invoices\DateRange;
use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\NIP;
use N1ebieski\KSEFClient\ValueObjects\Requests\InvoiceNumber;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\CurrencyCode;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\FormType;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\InvoiceType;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\InvoicingMode;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\SubjectType;
use N1ebieski\KSEFClient\ValueObjects\Requests\KsefNumber;

final class Filters extends AbstractDTO
{
    /**
     * @param Optional|array<int, CurrencyCode> $currencyCodes
     * @param Optional|array<int, InvoiceType> $invoiceTypes
     */
    public function __construct(
        public readonly SubjectType $subjectType,
        public readonly DateRange $dateRange,
        public readonly Optional | KsefNumber $ksefNumber = new Optional(),
        public readonly Optional | InvoiceNumber $invoiceNumber = new Optional(),
        public readonly Optional | Amount $amount = new Optional(),
        public readonly Optional | NIP $sellerNip = new Optional(),
        public readonly Optional | BuyerIdentifier $buyerIdentifier = new Optional(),
        public readonly Optional | array $currencyCodes = new Optional(),
        public readonly Optional | InvoicingMode $invoicingMode = new Optional(),
        public readonly Optional | bool $isSelfInvoicing = new Optional(),
        public readonly Optional | FormType $formType = new Optional(),
        public readonly Optional | array $invoiceTypes = new Optional(),
        public readonly Optional | bool $hasAttachment = new Optional(),
    ) {
    }
}
