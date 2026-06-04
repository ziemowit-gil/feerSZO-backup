<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Sessions\List;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractRequestFixture;

final class ListRequestFixture extends AbstractRequestFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'sessionType' => 'Online',
        'dateCreatedFrom' => '2025-06-25',
        'dateCreatedTo' => '2025-06-25',
        'dateModifiedFrom' => '2025-06-25',
        'dateModifiedTo' => '2025-06-25',
        'dateClosedFrom' => '2025-06-25',
        'dateClosedTo' => '2025-06-25',
        'statuses' => [
            'InProgress',
            'Succeeded',
            'Failed',
            'Cancelled',
        ],
        'referenceNumber' => '20250625-EE-319D7EE000-B67F415CDC-2C',
        'continuationToken' => 'continuationToken',
        'pageSize' => 10,
    ];
}
