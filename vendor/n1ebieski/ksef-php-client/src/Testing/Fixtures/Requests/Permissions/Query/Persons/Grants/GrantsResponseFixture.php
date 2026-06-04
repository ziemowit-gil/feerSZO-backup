<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\Query\Persons\Grants;

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
                    'value' => '15062788702'
                ],
                'authorizedIdentifier' => [
                    'type' => 'Nip',
                    'value' => '5247677742',
                ],
                'targetIdentifier' => [
                    'type' => 'AllPartners',
                ],
                'permissionScope' => 'InvoiceWrite',
                'description' => 'praca dla klienta 9786214922; uprawniony NIP: 7762811692, Adam Abacki; pośrednik 3936518395',
                'permissionState' => 'Active',
                'startDate' => '2025-06-22T10:41:11+00:00',
                'canDelegate' => false,
            ],
        ],
        'hasMore' => false,
    ];
}
