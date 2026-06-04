<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Auth;

use DOMDocument;
use DOMElement;
use N1ebieski\KSEFClient\Contracts\DomSerializableInterface;
use N1ebieski\KSEFClient\Contracts\XmlSerializableInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\Requests\Auth\Challenge;
use N1ebieski\KSEFClient\ValueObjects\Requests\Auth\SubjectIdentifierType;
use N1ebieski\KSEFClient\ValueObjects\Requests\XmlNamespace;
use RuntimeException;

final class XadesSignature extends AbstractDTO implements XmlSerializableInterface, DomSerializableInterface
{
    public function __construct(
        public readonly Challenge $challenge,
        public readonly ContextIdentifierGroup $contextIdentifierGroup,
        public readonly SubjectIdentifierType $subjectIdentifierType,
    ) {
    }

    public function toXml(): string
    {
        return $this->toDom()->saveXML() ?: throw new RuntimeException(
            'Unable to serialize to XML'
        );
    }

    public function toDom(): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $authTokenRequest = $dom->createElementNS((string) XmlNamespace::Auth->value, 'AuthTokenRequest');
        $authTokenRequest->setAttribute('xmlns:xsi', (string) XmlNamespace::Xsi->value);

        $dom->appendChild($authTokenRequest);

        $challenge = $dom->createElementNS((string) XmlNamespace::Auth->value, 'Challenge', (string) $this->challenge);
        $authTokenRequest->appendChild($challenge);

        $contextIdentifier = $dom->createElementNS((string) XmlNamespace::Auth->value, 'ContextIdentifier');

        /** @var DOMElement $contextIdentifierGroup */
        $contextIdentifierGroup = $this->contextIdentifierGroup->toDom()->documentElement;

        foreach ($contextIdentifierGroup->childNodes as $child) {
            $contextIdentifier->appendChild($dom->importNode($child, true));
        }

        $authTokenRequest->appendChild($contextIdentifier);

        $subjectIdentifierType = $dom->createElementNS((string) XmlNamespace::Auth->value, 'SubjectIdentifierType', (string) $this->subjectIdentifierType->value);
        $authTokenRequest->appendChild($subjectIdentifierType);

        return $dom;
    }
}
