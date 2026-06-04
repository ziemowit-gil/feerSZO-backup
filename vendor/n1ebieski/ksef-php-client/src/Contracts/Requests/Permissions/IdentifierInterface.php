<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Requests\Permissions;

use N1ebieski\KSEFClient\ValueObjects\Fingerprint;
use N1ebieski\KSEFClient\ValueObjects\InternalId;
use N1ebieski\KSEFClient\ValueObjects\NIP;
use N1ebieski\KSEFClient\ValueObjects\NipVatUe;
use N1ebieski\KSEFClient\ValueObjects\PeppolId;
use N1ebieski\KSEFClient\ValueObjects\Pesel;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\Query\Persons\AuthorIdentifierType;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\TargetIdentifierType;

interface IdentifierInterface
{
    public function getIdentifier(): NIP | NipVatUe | Pesel | Fingerprint | PeppolId | InternalId | TargetIdentifierType | AuthorIdentifierType;
}
