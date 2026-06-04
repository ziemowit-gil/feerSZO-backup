<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\ConvertDerToPem;

use N1ebieski\KSEFClient\Actions\AbstractHandler;

final class ConvertDerToPemHandler extends AbstractHandler
{
    public function handle(ConvertDerToPemAction $action): string
    {
        return "-----BEGIN {$action->name}-----\n"
            . chunk_split(base64_encode($action->der), 64, "\n")
            . "-----END {$action->name}-----\n";
        ;
    }
}
