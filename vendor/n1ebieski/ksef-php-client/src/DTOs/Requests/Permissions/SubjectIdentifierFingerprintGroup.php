<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Permissions;

use N1ebieski\KSEFClient\Contracts\Requests\Permissions\IdentifierInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\Fingerprint;

final class SubjectIdentifierFingerprintGroup extends AbstractDTO implements IdentifierInterface
{
    public function __construct(
        public readonly Fingerprint $fingerprint,
    ) {
    }

    public function getIdentifier(): Fingerprint
    {
        return $this->fingerprint;
    }
}
