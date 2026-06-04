<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\Number;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class MinRule extends AbstractRule
{
    public function __construct(
        private readonly float $min
    ) {
    }

    public function handle(float $value, ?string $attribute = null): void
    {
        if ($value < $this->min) {
            $this->throwRuleValidationException(
                'Value must have at least %d.',
                $attribute,
                $this->min
            );
        }
    }
}
