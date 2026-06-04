<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\Directory;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class ExistsRule extends AbstractRule
{
    public function handle(string $value, ?string $attribute = null): void
    {
        if ( ! is_dir($value)) {
            $this->throwRuleValidationException('Directory does not exist.', $attribute);
        }
    }
}
