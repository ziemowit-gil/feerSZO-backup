<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Auth;

use DOMDocument;
use N1ebieski\KSEFClient\Contracts\DomSerializableInterface;
use N1ebieski\KSEFClient\Contracts\Requests\Auth\IdentifierInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\NIP;
use N1ebieski\KSEFClient\ValueObjects\Requests\XmlNamespace;

final class ContextIdentifierNipGroup extends AbstractDTO implements DomSerializableInterface, IdentifierInterface
{
    public function __construct(
        public readonly NIP $nip,
    ) {
    }

    public function getIdentifier(): NIP
    {
        return $this->nip;
    }

    public function toDom(): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $contextIdentifierNipGroup = $dom->createElementNS((string) XmlNamespace::Auth->value, 'ContextIdentifierNipGroup');
        $dom->appendChild($contextIdentifierNipGroup);

        $nip = $dom->createElementNS((string) XmlNamespace::Auth->value, 'Nip');
        $nip->appendChild($dom->createTextNode((string) $this->nip));

        $contextIdentifierNipGroup->appendChild($nip);

        return $dom;
    }
}
