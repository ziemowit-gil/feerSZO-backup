<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\ConvertCertificateToPkcs12;

use N1ebieski\KSEFClient\Actions\AbstractHandler;
use RuntimeException;

final class ConvertCertificateToPkcs12Handler extends AbstractHandler
{
    public function handle(ConvertCertificateToPkcs12Action $action): string
    {
        $pkcs12 = '';

        $result = openssl_pkcs12_export(
            certificate: $action->certificate->certificate,
            output: $pkcs12,
            private_key: $action->certificate->privateKey,
            passphrase: $action->passphrase
        );

        if ($result === false) {
            throw new RuntimeException('Unable to export certificate to PKCS12');
        }

        /** @var string */
        return $pkcs12;
    }
}
