<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\Indirect\Grants;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractRequestFixture;

final class GrantsRequestFixture extends AbstractRequestFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'subjectIdentifierGroup' => [
            'pesel' => '22271569167',
        ],
        'targetIdentifierGroup' => [
            'nip' => '5687926712',
        ],
        'permissions' => [
            'InvoiceWrite',
            'InvoiceRead',
        ],
        'description' => 'praca dla klienta 5687926712; uprawniony PESEL: 22271569167, Adam Abacki; pośrednik 3936518395',
        'subjectDetails' => [
            'personById' => [
                'firstName' => 'Adam',
                'lastName' => 'Abacki'
            ]
        ]
    ];
}
