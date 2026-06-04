<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\String;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class EmailRule extends AbstractRule
{
    public function handle(string $value, ?string $attribute = null): void
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->throwRuleValidationException('Invalid email format.', $attribute);
        }
    }
}
