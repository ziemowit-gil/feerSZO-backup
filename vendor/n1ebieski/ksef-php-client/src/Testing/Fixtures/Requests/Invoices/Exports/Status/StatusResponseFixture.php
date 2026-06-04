<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Invoices\Exports\Status;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class StatusResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'status' => [
            'code' => 200,
            'description' => 'Eksport faktur zakończony sukcesem',
        ],
        'completedDate' => '2025-09-16T16:09:40.901091+00:00',
        'package' => [
            'invoiceCount' => 10000,
            'size' => 22425060,
            'parts' => [
                [
                    'ordinalNumber' => 1,
                    'partName' => '20250925-EH-2D2C11B000-E9C9ED8340-EE-001.zip.aes',
                    'method' => 'GET',
                    'url' => 'https://ksef-api-storage/storage/00/20250626-eh-2d2c11b000-e9c9ed8340-ee/invoice-part/20250925-EH-2D2C11B000-E9C9ED8340-EE-001.zip.aes?skoid=1ad7cfe8-2cb2-406b-b96c-6eefb55794db&sktid=647754c7-3974-4442-a425-c61341b61c69&skt=2025-06-26T09%3A40%3A54Z&ske=2025-06-26T10%3A10%3A54Z&sks=b&skv=2025-01-05&sv=2025-01-05&se=2025-06-26T10%3A10%3A54Z&sr=b&sp=w&sig=8mKZEU8Reuz%2Fn7wHi4T%2FY8BzLeD5l8bR2xJsBxIgDEY%3D',
                    'partSize' => 22425060,
                    'partHash' => 'BKH9Uy1CjBFXiQdDUM2CJYk5LxWTm4fE1lljnl83Ajw=',
                    'encryptedPartSize' => 22425072,
                    'encryptedPartHash' => 'HlvwRLc59EJH7O5GoeHEZxTQO5TJ/WP1QH0aFi4x2Ss=',
                    'expirationDate' => '2025-09-16T17:09:40.901091+00:00',
                ],
            ],
            'isTruncated' => true,
            'lastPermanentStorageDate' => '2025-09-11T11:40:40.266578+00:00',
        ],
    ];

}
