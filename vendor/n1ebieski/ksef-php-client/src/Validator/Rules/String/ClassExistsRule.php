<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\String;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class ClassExistsRule extends AbstractRule
{
    public function handle(string $value, ?string $attribute = null): void
    {
        if ( ! class_exists($value)) {
            $this->throwRuleValidationException('Class does not exist.', $attribute);
        }
    }
}
