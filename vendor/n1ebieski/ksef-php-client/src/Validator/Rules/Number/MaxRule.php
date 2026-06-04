<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\Number;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class MaxRule extends AbstractRule
{
    public function __construct(
        private readonly float $max
    ) {
    }

    public function handle(float $value, ?string $attribute = null): void
    {
        if ($value > $this->max) {
            $this->throwRuleValidationException(
                'Value must have at most %d.',
                $attribute,
                $this->max
            );
        }
    }
}
