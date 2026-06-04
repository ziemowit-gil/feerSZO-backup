<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\Subunits\Grants;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractRequestFixture;

final class GrantsRequestFixture extends AbstractRequestFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'subjectIdentifierGroup' => [
            'pesel' => '15062788702',
        ],
        'contextIdentifierGroup' => [
            'internalId' => '7762811692-12345',
        ],
        'description' => 'Opis uprawnienia',
        'subunitName' => 'Jednostka 014',
        'subjectDetails' => [
            'personById' => [
                'firstName' => 'Jan',
                'lastName' => 'Kowalski',
            ]
        ]
    ];
}
