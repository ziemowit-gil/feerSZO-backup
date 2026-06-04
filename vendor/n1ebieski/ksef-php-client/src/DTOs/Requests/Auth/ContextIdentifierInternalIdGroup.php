<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Auth;

use DOMDocument;
use N1ebieski\KSEFClient\Contracts\DomSerializableInterface;
use N1ebieski\KSEFClient\Contracts\Requests\Auth\IdentifierInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\InternalId;
use N1ebieski\KSEFClient\ValueObjects\Requests\XmlNamespace;

final class ContextIdentifierInternalIdGroup extends AbstractDTO implements DomSerializableInterface, IdentifierInterface
{
    public function __construct(
        public readonly InternalId $internalId,
    ) {
    }

    public function getIdentifier(): InternalId
    {
        return $this->internalId;
    }

    public function toDom(): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $contextIdentifierInternalIdGroup = $dom->createElementNS((string) XmlNamespace::Auth->value, 'ContextIdentifierInternalIdGroup');
        $dom->appendChild($contextIdentifierInternalIdGroup);

        $internalId = $dom->createElementNS((string) XmlNamespace::Auth->value, 'InternalId');
        $internalId->appendChild($dom->createTextNode((string) $this->internalId));

        $contextIdentifierInternalIdGroup->appendChild($internalId);

        return $dom;
    }
}
