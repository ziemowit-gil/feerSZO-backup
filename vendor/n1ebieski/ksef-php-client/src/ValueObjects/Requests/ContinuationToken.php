<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests;

use N1ebieski\KSEFClient\Contracts\FromInterface;
use N1ebieski\KSEFClient\Contracts\ValueAwareInterface;
use N1ebieski\KSEFClient\Support\AbstractValueObject;
use Stringable;

final class ContinuationToken extends AbstractValueObject implements ValueAwareInterface, Stringable, FromInterface
{
    public function __construct(public readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function from(string $value): self
    {
        return new self($value);
    }
}
