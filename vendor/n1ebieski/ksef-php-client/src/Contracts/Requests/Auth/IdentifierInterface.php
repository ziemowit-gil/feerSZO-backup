<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Requests\Auth;

use N1ebieski\KSEFClient\ValueObjects\InternalId;
use N1ebieski\KSEFClient\ValueObjects\NIP;
use N1ebieski\KSEFClient\ValueObjects\NipVatUe;
use N1ebieski\KSEFClient\ValueObjects\PeppolId;

interface IdentifierInterface
{
    public function getIdentifier(): NIP | NipVatUe | InternalId | PeppolId;
}
