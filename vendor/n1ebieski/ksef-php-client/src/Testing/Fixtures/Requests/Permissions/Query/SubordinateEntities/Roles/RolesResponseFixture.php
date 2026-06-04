<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\Query\SubordinateEntities\Roles;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class RolesResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'roles' => [
            [
                'subordinateEntityIdentifier' => [
                    'type' => 'Nip',
                    'value' => '7762811692',
                ],
                'role' => 'VatGroupSubUnit',
                'description' => 'Członek grupy VAT 8373740478',
                'startDate' => '2025-06-22T10:41:11+00:00',
            ],
        ],
        'hasMore' => false,
    ];
}
