<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Sessions;

use N1ebieski\KSEFClient\Contracts\ValueAwareInterface;
use N1ebieski\KSEFClient\Support\AbstractValueObject;
use N1ebieski\KSEFClient\Validator\Rules\Number\MaxDigitsRule;
use N1ebieski\KSEFClient\Validator\Validator;
use Stringable;

final class P_NrWierszaNST extends AbstractValueObject implements ValueAwareInterface, Stringable
{
    public readonly int $value;

    public function __construct(int $value)
    {
        Validator::validate((string) $value, [
            new MaxDigitsRule(14)
        ]);

        $this->value = $value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    public static function from(int $value): self
    {
        return new self($value);
    }
}
