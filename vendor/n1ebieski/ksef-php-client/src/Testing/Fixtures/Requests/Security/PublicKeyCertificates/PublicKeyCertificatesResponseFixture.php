<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Security\PublicKeyCertificates;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class PublicKeyCertificatesResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<int, array{certificate: string, validFrom: string, validTo: string, usage: array<int, string>}>
     */
    public array $data = [
        [
            'certificate' => 'certificate',
            'validFrom' => '2024-01-01T00:00:00Z',
            'validTo' => '2030-01-01T00:00:00Z',
            'usage' => ['SymmetricKeyEncryption'],
        ],
    ];
}
