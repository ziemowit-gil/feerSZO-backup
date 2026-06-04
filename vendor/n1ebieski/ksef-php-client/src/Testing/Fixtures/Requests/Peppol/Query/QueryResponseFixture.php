<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Peppol\Query;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class QueryResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'peppolProviders' => [
            [
                'id' => 'P123456789',
                'name' => 'Dostawca usług Peppol',
                'dateCreated' => '2025-07-11T12:23:56.0154302+00:00',
            ],
        ],
        'hasMore' => false,
    ];
}
