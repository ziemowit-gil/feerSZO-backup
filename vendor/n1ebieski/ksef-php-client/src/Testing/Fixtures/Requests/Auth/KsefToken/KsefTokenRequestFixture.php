<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Auth\KsefToken;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractRequestFixture;

final class KsefTokenRequestFixture extends AbstractRequestFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'challenge' => '20250625-CR-2FDC223000-C2BFC98A9C-4E',
        'contextIdentifierGroup' => [
            'identifierGroup' => [
                'nip' => '1234567890',
            ]
        ],
        'encryptedToken' => 'EncryptedToken',
    ];
}
