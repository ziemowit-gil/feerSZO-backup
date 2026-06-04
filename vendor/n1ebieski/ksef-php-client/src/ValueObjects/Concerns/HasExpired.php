<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Concerns;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * @property-read DateTimeInterface | null $validUntil
 */
trait HasExpired
{
    public function isExpired(string $datetime = 'now'): bool
    {
        return $this->validUntil instanceof DateTimeInterface
            && $this->validUntil < new DateTimeImmutable($datetime, timezone: new DateTimeZone('UTC'));
    }
}
