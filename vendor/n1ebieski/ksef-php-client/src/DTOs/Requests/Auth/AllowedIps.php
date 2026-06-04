<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Auth;

use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\Support\Optional;

final class AllowedIps extends AbstractDTO
{
    /**
     * @param Optional|array<int, string> $ip4Addresses
     * @param Optional|array<int, string> $ip4Ranges
     * @param Optional|array<int, string> $ip4Masks
     */
    public function __construct(
        public readonly Optional | array $ip4Addresses,
        public readonly Optional | array $ip4Ranges,
        public readonly Optional | array $ip4Masks
    ) {
    }
}
