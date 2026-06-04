<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects;

use SensitiveParameter;
use DateTimeInterface;
use N1ebieski\KSEFClient\Support\AbstractValueObject;
use N1ebieski\KSEFClient\ValueObjects\Concerns\HasExpired;
use Stringable;

final class RefreshToken extends AbstractValueObject implements Stringable
{
    use HasExpired;

    public function __construct(
        #[SensitiveParameter] public readonly string $token,
        public readonly ?DateTimeInterface $validUntil = null
    ) {
    }

    public function __toString(): string
    {
        return $this->token;
    }

    public static function from(#[SensitiveParameter] string $token, ?DateTimeInterface $validUntil = null): self
    {
        return new self($token, $validUntil);
    }
}
