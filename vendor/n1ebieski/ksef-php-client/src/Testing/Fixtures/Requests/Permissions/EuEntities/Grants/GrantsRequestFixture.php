<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\EuEntities\Grants;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractRequestFixture;

final class GrantsRequestFixture extends AbstractRequestFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'subjectIdentifierGroup' => [
            'fingerprint' => 'CEB3643BAC2C111ADDE971BDA5A80163441867D65389FC0BC0DFF8B4C1CD4E59',
        ],
        'permissions' => [
            'InvoiceRead',
            'InvoiceWrite',
        ],
        'description' => 'Opis uprawnienia',
        'subjectDetails' => [
            'personByFpWithId' => [
                'firstName' => 'Adam',
                'lastName' => 'Kowalski',
                'identifier' => [
                    'nip' => '1234567890',
                ]
            ],
         ],
    ];
}
