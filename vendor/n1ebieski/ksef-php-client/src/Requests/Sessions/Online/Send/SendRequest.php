<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Sessions\Online\Send;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Contracts\XmlSerializableInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Sessions\Faktura;
use N1ebieski\KSEFClient\DTOs\Requests\Sessions\FakturaRR\Faktura as FakturaRR;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Optional;
use N1ebieski\KSEFClient\ValueObjects\Requests\ReferenceNumber;
use N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\FormCode;
use N1ebieski\KSEFClient\ValueObjects\Requests\Sessions\HashOfCorrectedInvoice;

final class SendRequest extends AbstractRequest implements XmlSerializableInterface, BodyInterface
{
    public function __construct(
        public readonly ReferenceNumber $referenceNumber,
        public readonly Faktura | FakturaRR $faktura,
        public readonly FormCode $formCode = FormCode::Fa3,
        public readonly Optional | bool $offlineMode = new Optional(),
        public readonly Optional | HashOfCorrectedInvoice $hashOfCorrectedInvoice = new Optional()
    ) {
    }

    public function toBody(): array
    {
        /** @var array<string, mixed> */
        return $this->toArray(only: ['offlineMode', 'hashOfCorrectedInvoice']);
    }

    public function toXml(): string
    {
        return $this->faktura->toXml();
    }
}
