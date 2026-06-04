<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Invoices\Exports\Init;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class InitResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 201;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'referenceNumber' => 'string'
    ];
}
