<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\EncryptDocument;

use N1ebieski\KSEFClient\Actions\AbstractHandler;
use N1ebieski\KSEFClient\Support\Str;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class EncryptDocumentHandler extends AbstractHandler
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function handle(EncryptDocumentAction $action): string
    {
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->debug('Encrypting document', [
                'document' => Str::isBinary($action->document) ? '[binary data]' : $action->document
            ]);
        }

        $encryptedDocument = openssl_encrypt(
            $action->document,
            'AES-256-CBC',
            $action->encryptionKey->key,
            OPENSSL_RAW_DATA,
            $action->encryptionKey->iv
        );

        if ($encryptedDocument === false) {
            throw new RuntimeException('Unable to encrypt document');
        }

        return $encryptedDocument;
    }
}
