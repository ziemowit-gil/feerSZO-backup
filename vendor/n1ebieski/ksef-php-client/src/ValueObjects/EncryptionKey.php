<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects;

use SensitiveParameter;
use N1ebieski\KSEFClient\Support\AbstractValueObject;
use N1ebieski\KSEFClient\Validator\Rules\String\MaxBytesRule;
use N1ebieski\KSEFClient\Validator\Rules\String\MinBytesRule;
use N1ebieski\KSEFClient\Validator\Validator;

final class EncryptionKey extends AbstractValueObject
{
    public readonly string $key;

    public readonly string $iv;

    public function __construct(
        #[SensitiveParameter] string $key,
        #[SensitiveParameter] string $iv
    ) {
        Validator::validate([
            'key' => $key,
            'iv' => $iv
        ], [
            'key' => [new MinBytesRule(32), new MaxBytesRule(32)],
            'iv' => [new MinBytesRule(16), new MaxBytesRule(16)]
        ]);

        $this->key = $key;
        $this->iv = $iv;
    }

    public static function from(#[SensitiveParameter] string $key, #[SensitiveParameter] string $iv): self
    {
        return new self($key, $iv);
    }
}
