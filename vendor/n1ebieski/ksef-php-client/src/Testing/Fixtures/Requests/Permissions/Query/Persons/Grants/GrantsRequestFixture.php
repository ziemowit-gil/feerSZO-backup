<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\Query\Persons\Grants;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractRequestFixture;

final class GrantsRequestFixture extends AbstractRequestFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'queryType' => 'PermissionsInCurrentContext',
        'authorIdentifierGroup' => [
            'type' => 'System',
        ],
        'authorizedIdentifierGroup' => [
            'nip' => '5247677742',
        ],
        'contextIdentifierGroup' => [
            'nip' => '3568707925',
        ],
        'targetIdentifierGroup' => [
            'internalId' => '3568707925-12345',
        ],
        'permissionTypes' => [
            'InvoiceWrite',
            'InvoiceRead',
        ],
        'permissionState' => 'Active',
        'pageOffset' => 0,
        'pageSize' => 10,
    ];
}
