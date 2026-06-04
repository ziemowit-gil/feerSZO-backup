<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Sessions\Online\Open;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class OpenResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 201;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'referenceNumber' => '20250625-EE-319D7EE000-B67F415CDC-2C',
        'validUntil' => '2025-07-11T12:23:56.0154302+00:00'
    ];
}
