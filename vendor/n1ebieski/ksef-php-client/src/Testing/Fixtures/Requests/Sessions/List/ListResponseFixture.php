<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Sessions\List;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class ListResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'continuationToken' => 'W3sidG9rZW4iOiIrUklEOn4zeHd0QU1SM3dYYjRCd0FBQUFBQUNBPT0jUlQ6MSNUUkM6MTAjSVNWOjIjSUVPOjY1NTY3I1FDRjo4I0ZQQzpBZ2dBQUFBQUFDQUFBQVlBQUFBQUlBQUFBQUFBQUFBZ0FBQVVBUEVIQUVGdGdJUUFFUUJBQUJBRUFCQVVoZ1NBQXdBQUFBQWdBQUFHQUhFa0NFQWxnQVFBQUFBQUlBQUFGZ0F5Q0FVZ0VBRC9nRE9BRFlFdWdIcUF5SXBEZ0IrQUJnQUFBQUFnQUFBQ0FPNlYiLCJyYW5nZSI6eyJtaW4iOiIiLCJtYXgiOiIwNUMxREYyQjVGMzU5OCJ9fV0=',
        'sessions' => [
            [
                'referenceNumber' => '20250925-SO-2F67776000-97273B191A-65',
                'status' => [
                    'code' => 200,
                    'description' => 'Sesja interaktywna przetworzona pomyślnie',
                ],
                'dateCreated' => '2025-09-25T13:48:26.8700925+00:00',
                'dateUpdated' => '2025-09-26T02:16:07+00:00',
                'validUntil' => '2025-09-26T01:48:26.8700925+00:00',
                'totalInvoiceCount' => 2,
                'successfulInvoiceCount' => 2,
                'failedInvoiceCount' => 0,
            ],
            [
                'referenceNumber' => '20250928-SO-494B541000-3AD87C01BA-5D',
                'status' => [
                    'code' => 200,
                    'description' => 'Sesja interaktywna przetworzona pomyślnie',
                ],
                'dateCreated' => '2025-09-28T21:20:54.5936927+00:00',
                'dateUpdated' => '2025-09-29T10:19:28+00:00',
                'validUntil' => '2025-09-29T09:20:54.5936927+00:00',
                'totalInvoiceCount' => 3,
                'successfulInvoiceCount' => 3,
                'failedInvoiceCount' => 0,
            ],
        ],
    ];
}
