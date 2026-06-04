<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Sessions;

use N1ebieski\KSEFClient\Contracts\ValueAwareInterface;
use N1ebieski\KSEFClient\Support\AbstractValueObject;
use N1ebieski\KSEFClient\Validator\Rules\Number\DecimalRule;
use N1ebieski\KSEFClient\Validator\Rules\Number\MaxDigitsRule;
use N1ebieski\KSEFClient\Validator\Rules\Number\MaxRule;
use N1ebieski\KSEFClient\Validator\Rules\Number\MinRule;
use N1ebieski\KSEFClient\Validator\Validator;
use Stringable;

final class P_12_XII extends AbstractValueObject implements ValueAwareInterface, Stringable
{
    public readonly string $value;

    public function __construct(float | string $value)
    {
        Validator::validate((string) $value, [
            new DecimalRule(0, 6),
            new MaxDigitsRule(9),
        ]);

        Validator::validate((float) $value, [
            new MinRule(0),
            new MaxRule(100),
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
