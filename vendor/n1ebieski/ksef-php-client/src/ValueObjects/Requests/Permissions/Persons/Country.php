<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Persons;

use N1ebieski\KSEFClient\Contracts\FromInterface;
use N1ebieski\KSEFClient\Support\AbstractValueObject;
use N1ebieski\KSEFClient\Validator\Rules\String\CountryRule;
use N1ebieski\KSEFClient\Validator\Validator;
use Stringable;

final class Country extends AbstractValueObject implements Stringable, FromInterface
{
    public readonly string $value;

    public function __construct(string $value)
    {
        Validator::validate($value, [
            new CountryRule(),
        ]);

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function from(string $value): self
    {
        return new self($value);
    }
}
