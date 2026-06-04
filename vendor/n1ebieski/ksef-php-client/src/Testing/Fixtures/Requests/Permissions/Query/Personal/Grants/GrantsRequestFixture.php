<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\Query\Personal\Grants;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractRequestFixture;

final class GrantsRequestFixture extends AbstractRequestFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'contextIdentifierGroup' => [
            'nip' => '3568707925',
        ],
        'targetIdentifierGroup' => [
            'nip' => '5687926712',
        ],
        'permissionTypes' => [
            'InvoiceWrite',
            'InvoiceRead',
        ],
        'permissionState' => 'Active'
    ];
}
