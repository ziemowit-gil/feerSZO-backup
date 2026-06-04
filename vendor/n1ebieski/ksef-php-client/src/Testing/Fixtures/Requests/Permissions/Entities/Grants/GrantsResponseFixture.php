<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Permissions\Entities\Grants;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class GrantsResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 202;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'referenceNumber' => '20250626-EG-333C814000-C529F710D8-D2'
    ];
}
