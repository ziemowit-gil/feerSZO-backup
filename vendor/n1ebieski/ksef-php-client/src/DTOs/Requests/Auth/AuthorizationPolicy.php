<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Auth;

use N1ebieski\KSEFClient\Support\AbstractDTO;

final class AuthorizationPolicy extends AbstractDTO
{
    public function __construct(
        public readonly AllowedIps $allowedIps,
    ) {
    }
}
