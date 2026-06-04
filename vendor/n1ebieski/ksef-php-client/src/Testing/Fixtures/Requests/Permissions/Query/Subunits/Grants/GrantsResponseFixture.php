<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\Query\Subunits\Grants;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class GrantsResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'permissions' => [
            [
                'id' => '7d3b5a8e-1f24-4e2e-9a3c-4f1c2b7e5d11',
                'authorizedIdentifier' => [
                    'type' => 'Fingerprint',
                    'value' => 'CEB3643BAC2C111ADDE971BDA5A80163441867D65389FC0BC0DFF8B4C1CD4E59',
                ],
                'subunitIdentifier' => [
                    'type' => 'InternalId',
                    'value' => '7762811692-12345'
                ],
                'authorIdentifier' => [
                    'type' => 'Pesel',
                    'value' => '85010112345'
                ],
                'permissionScope' => 'CredentialsManage',
                'description' => 'Opis uprawnienia dla subunitu',
                'startDate' => '2025-06-22T10:41:11+00:00',
            ],
        ],
        'hasMore' => false,
    ];
}
