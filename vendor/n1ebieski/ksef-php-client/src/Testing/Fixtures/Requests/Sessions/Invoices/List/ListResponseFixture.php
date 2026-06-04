<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Sessions\Invoices\List;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class ListResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'continuationToken' => 'W34idG9rZW4iOiIrUklEOn4xUE5BQU5hcXJVOUFBQUFBQUFBQUFBPT0jUlQ6MSNUUkM6MTAjSVNWOjIjSUVPOjY1NTY3I1FDRjo4I0ZQQzpBVUFBQUFBQUFBQUFRZ0FBQUFBQUFBQT0iLCJyYW5nZSI6eyJtaW4iOiIiLCJtYXgiOiJGRiJ9fV0=',
        'invoices' => [
            [
                'ordinalNumber' => 1,
                'invoiceNumber' => 'FA/XPWIC-7900685789/06/2025',
                'ksefNumber' => '5265877635-20250626-010080DD2B5E-26',
                'referenceNumber' => '20250918-EE-2F15D39000-242207E5C4-1B',
                'invoiceHash' => 'mkht+3m5trnfxlTYhq3QFn74LkEO69MFNlsMAkCDSPA=',
                'acquisitionDate' => '2025-09-18T12:24:16.0154302+00:00',
                'invoicingDate' => '2025-09-18T12:23:56.0154302+00:00',
                'permanentStorageDate' => '2025-09-18T12:24:01.0154302+00:00',
                'upoDownloadUrl' => 'https://ksef-test.mf.gov.pl/storage/01/20250918-SB-3789A40000-20373E1269-A3/invoice-upo/upo_5265877635-20250626-010080DD2B5E-26.xml?sv=2025-01-05&st=2025-09-18T14%3A49%3A20Z&se=2025-09-21T14%3A54%3A20Z&sr=b&sp=r&sig=%2BUWFPA10gS580VhngGKW%2FZiOOtiHPOiTyMlxhG6ZvWs%3D',
                'upoDownloadUrlExpirationDate' => '2025-09-21T14:54:20+00:00',
                'status' => [
                    'code' => 200,
                    'description' => 'Sukces',
                ],
            ],
            [
                'ordinalNumber' => 2,
                'referenceNumber' => '20250918-EE-2F20AD2000-242386DF86-52',
                'invoiceHash' => 'mkht+3m5trnfxlTYhq3QFn74LkEO69MFNlsMAkCDSPA=',
                'invoicingDate' => '2025-09-18T12:23:56.0154302+00:00',
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
