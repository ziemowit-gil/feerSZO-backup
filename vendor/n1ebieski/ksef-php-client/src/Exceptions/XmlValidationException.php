<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Exceptions;

use LibXMLError;

/**
 * @property-read string $document
 * @property-read array<int, LibXMLError> $errors
 */
final class XmlValidationException extends RuleValidationException
{
}
