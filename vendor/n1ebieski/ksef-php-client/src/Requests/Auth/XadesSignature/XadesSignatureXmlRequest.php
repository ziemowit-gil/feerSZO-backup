<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Auth\XadesSignature;

use N1ebieski\KSEFClient\Contracts\HeadersInterface;
use N1ebieski\KSEFClient\Contracts\ParametersInterface;
use N1ebieski\KSEFClient\Contracts\XmlSerializableInterface;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Requests\Auth\XadesSignature\Concerns\HasToHeaders;
use N1ebieski\KSEFClient\Requests\Auth\XadesSignature\Concerns\HasToParameters;
use N1ebieski\KSEFClient\Support\Optional;

final class XadesSignatureXmlRequest extends AbstractRequest implements XmlSerializableInterface, ParametersInterface, HeadersInterface
{
    use HasToParameters;
    use HasToHeaders;

    public function __construct(
        public readonly string $xadesSignature,
        public readonly Optional | bool $verifyCertificateChain = new Optional(),
        public readonly Optional | bool $enforceXadesCompliance = new Optional(),
    ) {
    }

    public function toXml(): string
    {
        return $this->xadesSignature;
    }
}
