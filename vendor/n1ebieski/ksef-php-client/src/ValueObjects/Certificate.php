<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects;

use BCMathExtended\BC;
use N1ebieski\KSEFClient\Support\AbstractValueObject;
use OpenSSLAsymmetricKey;
use RuntimeException;

final class Certificate extends AbstractValueObject
{
    /**
     * @param array{issuer: array<string, string>, serialNumberHex: string, extensions: array{keyUsage: string}} $info
     */
    public function __construct(
        public readonly string $certificate,
        public readonly array $info,
        public readonly OpenSSLAsymmetricKey $privateKey,
    ) {
    }

    /**
     * @param array{issuer: array<string, string>, serialNumberHex: string, extensions: array{keyUsage: string}} $info
     */
    public static function from(
        string $certificate,
        array $info,
        OpenSSLAsymmetricKey $privateKey
    ): self {
        return new self($certificate, $info, $privateKey);
    }

    /**
     * @return array{bits: int, key: string, rsa: array, dsa: array, dh: array, ec: array, type: int}
     */
    //@phpstan-ignore-next-line
    private function getPrivateKeyDetails(): array
    {
        $details = openssl_pkey_get_details($this->privateKey);

        if ($details === false) {
            throw new RuntimeException('Unable to get key details');
        }

        return $details; //@phpstan-ignore-line
    }

    public function getPrivateKeyType(): PrivateKeyType
    {
        return PrivateKeyType::fromType($this->getPrivateKeyDetails()['type']);
    }

    public function getAlgorithm(): int | string
    {
        return match ($this->getPrivateKeyType()) {
            PrivateKeyType::RSA => 'sha256WithRSAEncryption',
            PrivateKeyType::EC => OPENSSL_ALGO_SHA256
        };
    }

    public function getFingerPrint(): string
    {
        return base64_encode(hash('sha256', base64_decode($this->getRaw()), true));
    }

    public function getSerialNumber(): string
    {
        return BC::hexdec($this->getSerialNumberHex());
    }

    public function getSerialNumberHex(): string
    {
        return $this->info['serialNumberHex'];
    }

    public function getIssuer(): string
    {
        $issuer = [];

        foreach ($this->info['issuer'] as $key => $value) {
            $issuer[] = $key . '=' . $value;
        }

        return implode(', ', array_reverse($issuer));
    }

    public function getRaw(): string
    {
        return trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n"], '', $this->certificate));
    }
}
