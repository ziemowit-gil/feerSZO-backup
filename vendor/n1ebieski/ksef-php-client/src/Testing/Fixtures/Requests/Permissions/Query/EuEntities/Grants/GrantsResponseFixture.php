<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\Query\EuEntities\Grants;

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
                'id' => '0c9a72e8-f344-457f-9c16-7c640eb60242',
                'authorIdentifier' => [
                    'type' => 'Pesel',
                    'value' => '15062788702',
                ],
                'vatUeIdentifier' => 'DE123456789',
                'euEntityName' => 'Podmiot unijny',
                'authorizedFingerprintIdentifier' => 'CEB3643BAC2C111ADDE971BDA5A80163441867D65389FC0BC0DFF8B4C1CD4E59',
                'permissionScope' => 'VatUeManage',
                'description' => 'Opis uprawnienia',
                'startDate' => '2025-06-22T10:41:11+00:00',
            ],
        ],
        'hasMore' => false,
    ];
}
