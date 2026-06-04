<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\EuEntities\Administration;

use N1ebieski\KSEFClient\Support\AbstractValueObject;
use N1ebieski\KSEFClient\Validator\Rules\String\MaxRule;
use N1ebieski\KSEFClient\Validator\Validator;
use Stringable;

final class EuEntityName extends AbstractValueObject implements Stringable
{
    public readonly string $euSubjectName;

    public readonly ?string $euSubjectAddress;

    public function __construct(
        string $euSubjectName,
        ?string $euSubjectAddress = null
    ) {
        Validator::validate("{$euSubjectName}, {$euSubjectAddress}", [
            new MaxRule(256)
        ]);

        $this->euSubjectName = $euSubjectName;
        $this->euSubjectAddress = $euSubjectAddress;
    }

    public static function from(string $euSubjectName, ?string $euSubjectAddress = null): self
    {
        return new self($euSubjectName, $euSubjectAddress);
    }

    public function __toString(): string
    {
        $name = $this->euSubjectName;

        if ($this->euSubjectAddress !== null) {
            $name .= ", {$this->euSubjectAddress}";
        }

        return $name;
    }
}
