<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\String;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class RegexRule extends AbstractRule
{
    public function __construct(
        private readonly string $pattern
    ) {
    }

    public function handle(string $value, ?string $attribute = null): void
    {
        if (in_array(preg_match($this->pattern, $value), [0, false], true)) {
            $this->throwRuleValidationException('Invalid regex format.', $attribute);
        }
    }
}
