<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\ConvertDerToPem;

use N1ebieski\KSEFClient\Actions\AbstractAction;

final class ConvertDerToPemAction extends AbstractAction
{
    public function __construct(
        public readonly string $der,
        public readonly string $name
    ) {
    }
}
