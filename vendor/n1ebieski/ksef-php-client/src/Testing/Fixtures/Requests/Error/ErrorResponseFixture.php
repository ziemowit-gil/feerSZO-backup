<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Error;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class ErrorResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 400;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'exception' => [
            'exceptionDetailList' => [
                [
                    'exceptionCode' => 12345,
                    'exceptionDescription' => 'Opis błędu.',
                    'details' => [
                        'Sesja o numerze referencyjnym {referenceNumber} nie została odnaleziona'
                    ]
                ]
            ],
            'referenceNumber' => '20211001-SE-FFFFFFFFFF-FFFFFFFFFF-FF',
            'serviceCode' => '20211001-EX-FFFFFFFFFF-FFFFFFFFFF-FF',
            'serviceCtx' => 'srvDVAKA',
            'serviceName' => 'online.session.authorisation.challenge',
            'timestamp' => '2019-08-24T14:15:22Z'
        ]
    ];
}
