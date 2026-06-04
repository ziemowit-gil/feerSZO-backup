<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\ConvertEcdsaDerToRaw;

use N1ebieski\KSEFClient\Actions\AbstractAction;

final class ConvertEcdsaDerToRawAction extends AbstractAction
{
    public function __construct(
        public readonly string $der,
        public readonly int $keySize = 32,
    ) {
    }
}
