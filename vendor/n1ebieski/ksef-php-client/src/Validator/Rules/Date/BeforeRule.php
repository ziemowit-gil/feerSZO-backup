<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Validator\Rules\Date;

use DateTimeInterface;
use N1ebieski\KSEFClient\Validator\Rules\AbstractRule;

final class BeforeRule extends AbstractRule
{
    public function __construct(private readonly DateTimeInterface $before)
    {
    }

    public function handle(DateTimeInterface $value, ?string $attribute = null): void
    {
        if ($value > $this->before) {
            $this->throwRuleValidationException(
                'Date must be before %s.',
                $attribute,
                $this->before->format('Y-m-d H:i:s')
            );
        }
    }
}
