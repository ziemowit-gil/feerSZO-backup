<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\Query\Entities\Roles;

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
                'role' => 'EnforcementAuthority',
                'description' => 'Organ egzekucyjny',
                'startDate' => '2025-06-22T10:41:11+00:00',
            ],
        ],
        'hasMore' => false,
    ];
}
