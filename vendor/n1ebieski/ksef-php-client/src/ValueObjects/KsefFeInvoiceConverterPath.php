<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects;

use N1ebieski\KSEFClient\Contracts\FromInterface;
use N1ebieski\KSEFClient\Contracts\ValueAwareInterface;
use N1ebieski\KSEFClient\Support\AbstractValueObject;
use N1ebieski\KSEFClient\Validator\Rules\File\ExistsRule;
use N1ebieski\KSEFClient\Validator\Rules\File\ExtensionsRule;
use N1ebieski\KSEFClient\Validator\Validator;
use Stringable;

final class KsefFeInvoiceConverterPath extends AbstractValueObject implements FromInterface, ValueAwareInterface, Stringable
{
    public readonly string $value;

    public function __construct(
        string $value,
    ) {
        Validator::validate($value, [
            new ExistsRule(),
            new ExtensionsRule(['js', 'exe']),
        ]);

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function from(string $path): self
    {
        return new self($path);
    }
}
