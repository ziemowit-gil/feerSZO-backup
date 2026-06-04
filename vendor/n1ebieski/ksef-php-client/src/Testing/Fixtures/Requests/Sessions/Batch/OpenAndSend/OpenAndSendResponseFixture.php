<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Sessions\Batch\OpenAndSend;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class OpenAndSendResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 201;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'referenceNumber' => '20250626-SB-213D593000-4DE10D80A5-E9',
        'partUploadRequests' => [
            [
                'ordinalNumber' => 1,
                'method' => 'PUT',
                'url' => 'https://ksef-api-storage/storage/00/20250626-sb-213d593000-4de10d80a5-e9/batch-parts/92cca5be-7f37-4d36-98bb-1f5369841038.zip.aes?skoid=1ad7cfe8-2cb2-406b-b96c-6eefb55794db&sktid=647754c7-3974-4442-a425-c61341b61c69&skt=2025-06-26T09%3A40%3A54Z&ske=2025-06-26T10%3A10%3A54Z&sks=b&skv=2025-01-05&sv=2025-01-05&se=2025-06-26T10%3A10%3A54Z&sr=b&sp=w&sig=8mKZEU8Reuz%2Fn7wHi4T%2FY8BzLeD5l8bR2xJsBxIgDEY%3D',
                'headers' => [
                    'x-ms-blob-type' => 'BlockBlob',
                ],
            ],
        ],
    ];
}
