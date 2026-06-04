<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Factories;

use N1ebieski\KSEFClient\DTOs\DN;
use N1ebieski\KSEFClient\ValueObjects\CSR;
use N1ebieski\KSEFClient\ValueObjects\PrivateKeyType;
use OpenSSLAsymmetricKey;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

final class CSRFactory extends AbstractFactory
{
    public static function make(DN $dn, PrivateKeyType $type = PrivateKeyType::EC): CSR
    {
        $privateKey = openssl_pkey_new($type->getOptions());

        if ($privateKey === false) {
            throw new RuntimeException('Unable to generate key');
        }

        $csr = openssl_csr_new($dn->toArray(), $privateKey, ['digest_alg' => 'sha256']);

        if ($csr === false) {
            throw new RuntimeException('Unable to generate CSR');
        }

        $raw = '';

        /** @var OpenSSLCertificateSigningRequest $csr */
        $result = openssl_csr_export($csr, $raw);

        if ($result === false) {
            throw new RuntimeException('Unable to export CSR');
        }

        /**
         * @var string $raw
         * @var OpenSSLAsymmetricKey $privateKey
         */
        return new CSR($raw, $privateKey);
    }
}
