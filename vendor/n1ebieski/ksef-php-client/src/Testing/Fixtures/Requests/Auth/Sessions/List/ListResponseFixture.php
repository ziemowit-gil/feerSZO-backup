<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Auth\Sessions\List;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class ListResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'continuationToken' => 'string',
        'items' => [
            [
                'startDate' => '2019-08-24T14:15:22Z',
                'authenticationMethod' => 'Token',
                'status' => [
                    'code' => 0,
                    'description' => 'string',
                    'details' => [
                        'string',
                    ],
                ],
                'isTokenRedeemed' => true,
                'lastTokenRefreshDate' => '2019-08-24T14:15:22Z',
                'refreshTokenValidUntil' => '2019-08-24T14:15:22Z',
                'referenceNumber' => 'string',
                'isCurrent' => true,
            ],
        ],
    ];
}
