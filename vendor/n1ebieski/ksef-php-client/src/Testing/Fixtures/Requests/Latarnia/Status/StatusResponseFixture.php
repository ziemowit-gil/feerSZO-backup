<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Latarnia\Status;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class StatusResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'status' => 'TOTAL_FAILURE',
        'messages' => [
            [
                'id' => 'K/2026/AWR/02',
                'category' => 'TOTAL_FAILURE',
                'type' => 'FAILURE_START',
                'title' => 'Wystąpiła całkowita awaria',
                'text' => 'Od godziny 13:27 31 stycznia trwa całkowita awaria...',
                'start' => '2026-01-31T11:27:00+00:00',
                'published' => '2026-01-31T11:35:00+00:00',
                'eventId' => 1003,
                'version' => 1,
            ],
            [
                'id' => 'K/2026/AWR/01',
                'category' => 'FAILURE',
                'type' => 'FAILURE_START',
                'title' => 'Wystąpiła awaria',
                'text' => 'Od godziny 12:13 31 stycznia trwa awaria...',
                'start' => '2026-01-31T10:13:00+00:00',
                'published' => '2026-01-31T10:13:00+00:00',
                'eventId' => 1002,
                'version' => 1,
            ],
        ],
    ];
}
