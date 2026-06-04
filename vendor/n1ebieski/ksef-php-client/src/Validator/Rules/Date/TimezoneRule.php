<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\Date;

use DateTimeInterface;
use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class TimezoneRule extends AbstractRule
{
    /**
     * @param array<int, string> $timezones
     */
    public function __construct(private readonly array $timezones)
    {
    }

    public function handle(DateTimeInterface $value, ?string $attribute = null): void
    {
        if ( ! in_array($value->getTimezone()->getName(), $this->timezones)) {
            $this->throwRuleValidationException(
                'Date must be in timezone: %s.',
                $attribute,
                implode(', ', $this->timezones)
            );
        }
    }
}
