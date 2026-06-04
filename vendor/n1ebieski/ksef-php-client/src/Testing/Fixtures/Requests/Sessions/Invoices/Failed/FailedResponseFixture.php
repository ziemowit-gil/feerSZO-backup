<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Sessions\Invoices\Failed;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class FailedResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'continuationToken' => 'W34idG9rZW4iOiIrUklEOn4xUE5BQU5hcXJVOUFBQUFBQUFBQUFBPT0jUlQ6MSNUUkM6MTAjSVNWOjIjSUVPOjY1NTY3I1FDRjo4I0ZQQzpBVUFBQUFBQUFBQUFRZ0FBQUFBQUFBQT0iLCJyYW5nZSI6eyJtaW4iOiIiLCJtYXgiOiJGRiJ9fV0=',
        'invoices' => [
            [
                'ordinalNumber' => 2,
                'referenceNumber' => '20250626-EE-2F20AD2000-242386DF86-52',
                'invoiceHash' => 'mkht+3m5trnfxlTYhq3QFn74LkEO69MFNlsMAkCDSPA=',
                'invoiceFileName' => 'invoice1.xml',
                'invoicingDate' => '2025-07-11T12:23:56.0154302+00:00',
                'status' => [
                    'code' => 440,
                    'description' => 'Duplikat faktury',
                    'details' => [
                        'Duplikat faktury. Faktura o numerze KSeF: 5265877635-20250626-010080DD2B5E-26 została już prawidłowo przesłana do systemu w sesji: 20250626-SO-2F14610000-242991F8C9-B4',
                    ],
                ],
            ],
        ],
    ];
}
