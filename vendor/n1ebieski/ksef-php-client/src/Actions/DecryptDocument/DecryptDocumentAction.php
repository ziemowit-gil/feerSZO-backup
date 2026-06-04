<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\DecryptDocument;

use N1ebieski\KSEFClient\Actions\AbstractAction;
use N1ebieski\KSEFClient\ValueObjects\EncryptionKey;

final class DecryptDocumentAction extends AbstractAction
{
    public function __construct(
        public readonly EncryptionKey $encryptionKey,
        public readonly string $document,
    ) {
    }
}
