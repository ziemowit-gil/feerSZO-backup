<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Security;

use N1ebieski\KSEFClient\Contracts\Requests\Security\PublicKeyCertificates\PublicKeyCertificatesResponseInterface;

interface SecurityResourceInterface
{
    public function publicKeyCertificates(): PublicKeyCertificatesResponseInterface;
}
