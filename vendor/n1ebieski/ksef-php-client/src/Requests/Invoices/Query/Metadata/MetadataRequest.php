<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Invoices\Query\Metadata;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Contracts\ParametersInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Invoices\Amount;
use N1ebieski\KSEFClient\DTOs\Requests\Invoices\BuyerIdentifier;
use N1ebieski\KSEFClient\DTOs\Requests\Invoices\DateRange;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\NIP;
use N1ebieski\KSEFClient\ValueObjects\Requests\InvoiceNumber;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\CurrencyCode;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\FormType;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\InvoiceType;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\InvoicingMode;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\PageSize;
use N1ebieski\KSEFClient\ValueObjects\Requests\Invoices\SubjectType;
use N1ebieski\KSEFClient\ValueObjects\Requests\KsefNumber;
use N1ebieski\KSEFClient\ValueObjects\Requests\PageOffset;
use N1ebieski\KSEFClient\ValueObjects\Requests\SortOrder;

final class MetadataRequest extends AbstractRequest implements ParametersInterface, BodyInterface
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
        public readonly Optional | SortOrder $sortOrder = new Optional(),
        public readonly Optional | PageSize $pageSize = new Optional(),
        public readonly Optional | PageOffset $pageOffset = new Optional(),
    ) {
    }

    public function toParameters(): array
    {
        /** @var array<string, mixed> */
        return $this->toArray(only: ['pageSize', 'pageOffset', 'sortOrder']);
    }

    public function toBody(): array
    {
        /** @var array<string, mixed> */
        return $this->toArray(only: [
            'subjectType',
            'dateRange',
            'ksefNumber',
            'invoiceNumber',
            'amount',
            'sellerNip',
            'buyerIdentifier',
            'currencyCodes',
            'invoicingMode',
            'isSelfInvoicing',
            'formType',
            'invoiceTypes',
            'hasAttachment'
        ]);
    }
}
