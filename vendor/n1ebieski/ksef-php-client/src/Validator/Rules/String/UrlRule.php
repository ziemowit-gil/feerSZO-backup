<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\String;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class UrlRule extends AbstractRule
{
    public function handle(string $value, ?string $attribute = null): void
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            $this->throwRuleValidationException('Invalid url format.', $attribute);
        }
    }
}
