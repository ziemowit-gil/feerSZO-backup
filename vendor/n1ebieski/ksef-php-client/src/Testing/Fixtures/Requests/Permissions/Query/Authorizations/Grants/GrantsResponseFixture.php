<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\Query\Authorizations\Grants;

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
                'authorizingIdentifier' => [
                    'type' => 'Nip',
                    'value' => '3568707925',
                ],
                'authorizedIdentifier' => [
                    'type' => 'Nip',
                    'value' => '5687926712',
                ],
                'permissionScope' => 'SelfInvoicing',
                'description' => 'Opis uprawnienia',
                'permissionState' => 'Active',
                'startDate' => '2025-06-22T10:41:11+00:00',
                'canDelegate' => false,
            ],
        ],
        'hasMore' => false,
    ];
}
