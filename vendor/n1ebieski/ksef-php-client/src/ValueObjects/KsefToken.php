<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects;

use SensitiveParameter;
use N1ebieski\KSEFClient\Contracts\ValueAwareInterface;
use N1ebieski\KSEFClient\Support\AbstractValueObject;
use Stringable;

final class KsefToken extends AbstractValueObject implements ValueAwareInterface, Stringable
{
    public function __construct(
        #[SensitiveParameter] public readonly string $value
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function from(#[SensitiveParameter] string $value): self
    {
        return new self($value);
    }
}
