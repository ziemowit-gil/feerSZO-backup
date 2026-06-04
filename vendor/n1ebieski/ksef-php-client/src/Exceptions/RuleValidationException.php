<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Exceptions;

use N1ebieski\KSEFClient\Exceptions\AbstractException;

/**
 * @property-read array{message: string, values: array<int, mixed>} $context
 */
class RuleValidationException extends AbstractException
{
}
