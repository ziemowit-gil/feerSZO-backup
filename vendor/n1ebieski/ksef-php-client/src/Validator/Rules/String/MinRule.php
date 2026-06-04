<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\String;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class MinRule extends AbstractRule
{
    public function __construct(
        private readonly int $min
    ) {
    }

    public function handle(string $value, ?string $attribute = null): void
    {
        if (mb_strlen($value) < $this->min) {
            $this->throwRuleValidationException(
                'Value must have at least %d characters.',
                $attribute,
                $this->min
            );
        }
    }
}
