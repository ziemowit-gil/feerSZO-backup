<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\File;

use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class ExistsRule extends AbstractRule
{
    public function handle(string $value, ?string $attribute = null): void
    {
        if ( ! is_file($value)) {
            $this->throwRuleValidationException('File does not exist.', $attribute);
        }
    }
}
