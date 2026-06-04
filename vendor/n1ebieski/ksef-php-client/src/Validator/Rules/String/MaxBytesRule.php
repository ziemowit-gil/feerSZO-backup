<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\String;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class MaxBytesRule extends AbstractRule
{
    public function __construct(
        private readonly int $max
    ) {
    }

    public function handle(string $value, ?string $attribute = null): void
    {
        if (strlen($value) > $this->max) {
            $this->throwRuleValidationException(
                'Value must have at most %d characters.',
                $attribute,
                $this->max
            );
        }
    }
}
