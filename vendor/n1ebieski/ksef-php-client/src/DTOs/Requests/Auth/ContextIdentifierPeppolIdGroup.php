<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Auth;

use DOMDocument;
use N1ebieski\KSEFClient\Contracts\DomSerializableInterface;
use N1ebieski\KSEFClient\Contracts\Requests\Auth\IdentifierInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\PeppolId;
use N1ebieski\KSEFClient\ValueObjects\Requests\XmlNamespace;

final class ContextIdentifierPeppolIdGroup extends AbstractDTO implements DomSerializableInterface, IdentifierInterface
{
    public function __construct(
        public readonly PeppolId $peppolId,
    ) {
    }

    public function getIdentifier(): PeppolId
    {
        return $this->peppolId;
    }

    public function toDom(): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $contextIdentifierPeppolIdGroup = $dom->createElementNS((string) XmlNamespace::Auth->value, 'ContextIdentifierPeppolIdGroup');
        $dom->appendChild($contextIdentifierPeppolIdGroup);

        $peppolId = $dom->createElementNS((string) XmlNamespace::Auth->value, 'PeppolId');
        $peppolId->appendChild($dom->createTextNode((string) $this->peppolId));

        $contextIdentifierPeppolIdGroup->appendChild($peppolId);

        return $dom;
    }
}
