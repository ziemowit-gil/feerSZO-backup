<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\Query\EuEntities\Grants;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractRequestFixture;

final class GrantsRequestFixture extends AbstractRequestFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'vatUeIdentifier' => 'DE123456789',
        'authorizedFingerprintIdentifier' => 'CEB3643BAC2C111ADDE971BDA5A80163441867D65389FC0BC0DFF8B4C1CD4E59',
        'permissionTypes' => [
            'VatUeManage',
            'Introspection',
        ],
        'pageOffset' => 0,
        'pageSize' => 10,
    ];
}
