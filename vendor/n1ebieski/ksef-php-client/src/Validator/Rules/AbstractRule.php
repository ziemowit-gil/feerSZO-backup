<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules;

use N1ebieski\KSEFClient\Exceptions\RuleValidationException;

/**
 * @method void handle(mixed $value, ?string $attribute = null)
 */
abstract class AbstractRule
{
    protected function getMessage(string $message, ?string $attribute = null): string
    {
        if ($attribute !== null) {
            $pos = strrpos($message, '.');
            $replacement = " for attribute %s.";

            return match (true) {
                $pos === false => $message . $replacement,
                default => substr_replace($message, $replacement, $pos, 1),
            };
        }

        return $message;
    }

    protected function throwRuleValidationException(string $message, ?string $attribute = null, mixed ...$values): void
    {
        $message = $this->getMessage($message, $attribute);

        /** @var array<int, bool|float|int|string|null> */
        $values = array_filter([...$values, $attribute]);

        throw new RuleValidationException(
            sprintf($message, ...$values),
            context: [
                'message' => $message,
                'values' => $values,
            ]
        );
    }
}
