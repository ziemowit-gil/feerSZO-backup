<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\ConvertPemToDer;

use N1ebieski\KSEFClient\Actions\AbstractAction;

final class ConvertPemToDerAction extends AbstractAction
{
    public function __construct(
        public readonly string $pem
    ) {
    }
}
