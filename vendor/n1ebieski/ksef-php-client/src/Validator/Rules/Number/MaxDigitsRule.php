<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\Number;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class MaxDigitsRule extends AbstractRule
{
    public function __construct(
        private readonly int $max
    ) {
    }

    public function handle(string $value, ?string $attribute = null): void
    {
        $length = strlen(str_replace('.', '', $value));

        if ($length > $this->max) {
            $this->throwRuleValidationException(
                'Value must have at most %d digits.',
                $attribute,
                $this->max
            );
        }
    }
}
