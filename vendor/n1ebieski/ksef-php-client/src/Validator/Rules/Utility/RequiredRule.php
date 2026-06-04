<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\Utility;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class RequiredRule extends AbstractRule
{
    public function handle(mixed $value, ?string $attribute = null): void
    {
        if (empty($value)) {
            $this->throwRuleValidationException('The value is required.', $attribute);
        }
    }
}
