<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Tokens\List;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractRequestFixture;

final class ListRequestFixture extends AbstractRequestFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'continuationToken' => 'continuationToken',
        'status' => [
            'Active'
        ],
        'description' => 'description',
        'authorIdentifier' => 'authorIdentifier',
        'authorIdentifierType' => 'Nip',
        'pageSize' => 10,
    ];
}
