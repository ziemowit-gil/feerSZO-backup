<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\Number;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class DecimalRule extends AbstractRule
{
    public function __construct(
        private readonly int $min,
        private readonly int $max
    ) {
    }

    public function handle(string $value, ?string $attribute = null): void
    {
        $fraction = strrchr($value, '.');

        $fractionLength = match (true) {
            $fraction === false => 0,
            default => strlen(substr($fraction, 1)),
        };

        if ($fractionLength > $this->max) {
            $this->throwRuleValidationException(
                'Value must have at most %d decimal places.',
                $attribute,
                $this->max
            );
        }

        if ($fractionLength < $this->min) {
            $this->throwRuleValidationException(
                'Value must have at least %d decimal places.',
                $attribute,
                $this->min
            );
        }
    }
}
