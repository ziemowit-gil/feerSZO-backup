<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\Authorizations\Grants;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractRequestFixture;

final class GrantsRequestFixture extends AbstractRequestFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'subjectIdentifierGroup' => [
            'nip' => '7762811692',
        ],
        'permission' => 'SelfInvoicing',
        'description' => 'działanie w imieniu 3393244202 w kontekście 7762811692, Firma sp. z o.o.',
        'subjectDetails' => [
            'fullName' => 'Firma sp. z o.o.',
        ]
    ];
}
