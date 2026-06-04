<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs;

use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\QRCode;

final class QRCodes extends AbstractDTO
{
    public function __construct(
        public readonly QRCode $code1,
        public readonly ?QRCode $code2 = null,
    ) {
    }
}
