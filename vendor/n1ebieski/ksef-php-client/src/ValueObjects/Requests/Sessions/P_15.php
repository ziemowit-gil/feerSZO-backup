<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Sessions;

use N1ebieski\KSEFClient\Contracts\ValueAwareInterface;
use N1ebieski\KSEFClient\Support\AbstractValueObject;
use N1ebieski\KSEFClient\Validator\Rules\Number\DecimalRule;
use N1ebieski\KSEFClient\Validator\Rules\Number\MaxDigitsRule;
use N1ebieski\KSEFClient\Validator\Rules\String\RegexRule;
use N1ebieski\KSEFClient\Validator\Validator;
use Stringable;

final class P_15 extends AbstractValueObject implements ValueAwareInterface, Stringable
{
    public readonly string $value;

    public function __construct(float | string $value)
    {
        Validator::validate((string) $value, [
            new RegexRule('/^-?([1-9]\d{0,15}|0)(\.\d{1,2})?$/'),
            new DecimalRule(0, 2),
            new MaxDigitsRule(18),
        ]);

        $this->value = (string) $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function from(float | string $value): self
    {
        return new self($value);
    }
}
