<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Auth;

use DOMDocument;
use DOMElement;
use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Contracts\DomSerializableInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\InternalId;
use N1ebieski\KSEFClient\ValueObjects\NIP;
use N1ebieski\KSEFClient\ValueObjects\NipVatUe;
use N1ebieski\KSEFClient\ValueObjects\PeppolId;
use N1ebieski\KSEFClient\ValueObjects\Requests\XmlNamespace;

final class ContextIdentifierGroup extends AbstractDTO implements DomSerializableInterface, BodyInterface
{
    public function __construct(
        public readonly ContextIdentifierNipGroup | ContextIdentifierNipVatUeGroup | ContextIdentifierInternalIdGroup | ContextIdentifierPeppolIdGroup $identifierGroup
    ) {
    }

    public static function fromIdentifier(NIP | NipVatUe | InternalId | PeppolId $identifier): self
    {
        return match (true) {
            $identifier instanceof NIP => new self(new ContextIdentifierNipGroup($identifier)),
            $identifier instanceof NipVatUe => new self(new ContextIdentifierNipVatUeGroup($identifier)),
            $identifier instanceof InternalId => new self(new ContextIdentifierInternalIdGroup($identifier)),
            $identifier instanceof PeppolId => new self(new ContextIdentifierPeppolIdGroup($identifier)),
        };
    }

    public function toBody(): array
    {
        return [
            'type' => $this->identifierGroup->getIdentifier()->getType(),
            'value' => (string) $this->identifierGroup->getIdentifier()
        ];
    }

    public function toDom(): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $contextIdentifierGroup = $dom->createElementNS((string) XmlNamespace::Auth->value, 'ContextIdentifierGroup');
        $dom->appendChild($contextIdentifierGroup);

        /** @var DOMElement $identifierGroup */
        $identifierGroup = $dom->importNode($this->identifierGroup->toDom()->documentElement, true);

        foreach ($identifierGroup->childNodes as $child) {
            $contextIdentifierGroup->appendChild($dom->importNode($child, true));
        }

        return $dom;
    }
}
