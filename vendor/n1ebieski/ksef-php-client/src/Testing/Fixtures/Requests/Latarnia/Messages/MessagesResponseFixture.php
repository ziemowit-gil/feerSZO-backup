<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Latarnia\Messages;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class MessagesResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $data = [
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
        [
            'id' => 'K/2026/NI/01',
            'category' => 'MAINTENANCE',
            'type' => 'MAINTENANCE_ANNOUNCEMENT',
            'title' => 'Niedostępność',
            'text' => 'W dniu 2026-01-26 od godz. 12:00 do 00:00 nastąpi niedostępność KSeF związana z planowanymi pracami serwisowymi',
            'start' => '2026-01-26T10:00:00+00:00',
            'end' => '2026-01-26T22:00:00+00:00',
            'published' => '2026-01-22T08:00:00+00:00',
            'eventId' => 1000,
            'version' => 1,
        ],
    ];
}
