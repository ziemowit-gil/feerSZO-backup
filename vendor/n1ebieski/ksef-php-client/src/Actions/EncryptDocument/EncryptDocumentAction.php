<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\EncryptDocument;

use N1ebieski\KSEFClient\Actions\AbstractAction;
use N1ebieski\KSEFClient\ValueObjects\EncryptionKey;

final class EncryptDocumentAction extends AbstractAction
{
    public function __construct(
        public readonly EncryptionKey $encryptionKey,
        public readonly string $document,
    ) {
    }
}
