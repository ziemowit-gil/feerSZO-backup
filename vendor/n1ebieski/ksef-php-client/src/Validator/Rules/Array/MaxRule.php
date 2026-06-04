<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\Array;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class MaxRule extends AbstractRule
{
    public function __construct(
        private readonly int $max
    ) {
    }

    /**
     * @param array<int, mixed> $value
     */
    public function handle(array $value, ?string $attribute = null): void
    {
        if (count($value) > $this->max) {
            $this->throwRuleValidationException(
                'Value must have at most %d elements.',
                $attribute,
                $this->max
            );
        }
    }
}
