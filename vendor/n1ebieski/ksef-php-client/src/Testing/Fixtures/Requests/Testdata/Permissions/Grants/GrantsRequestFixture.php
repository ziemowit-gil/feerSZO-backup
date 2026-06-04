<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Testdata\Permissions\Grants;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractRequestFixture;

final class GrantsRequestFixture extends AbstractRequestFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'contextIdentifier' => [
            'identifierGroup' => [
                'nip' => '1234567890',
            ],
        ],
        'authorizedIdentifier' => [
            'identifierGroup' => [
                'nip' => '1234567890',
            ],
        ],
        'permissions' => [
            [
                'description' => 'Opis testowy',
                'permissionType' => 'InvoiceRead',
            ],
        ],
    ];
}
