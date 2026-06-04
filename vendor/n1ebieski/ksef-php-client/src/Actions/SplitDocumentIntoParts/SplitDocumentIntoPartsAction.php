<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\SplitDocumentIntoParts;

use N1ebieski\KSEFClient\Actions\AbstractAction;

final class SplitDocumentIntoPartsAction extends AbstractAction
{
    public function __construct(
        public readonly string $document,
        public readonly int $partSize
    ) {
    }
}
