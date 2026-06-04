<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\RateLimits;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class RateLimitsResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'onlineSession' => [
            'perSecond' => 100,
            'perMinute' => 300,
            'perHour' => 1200,
        ],
        'batchSession' => [
            'perSecond' => 100,
            'perMinute' => 200,
            'perHour' => 1200,
        ],
        'invoiceSend' => [
            'perSecond' => 100,
            'perMinute' => 300,
            'perHour' => 1800,
        ],
        'invoiceStatus' => [
            'perSecond' => 300,
            'perMinute' => 1200,
            'perHour' => 7200,
        ],
        'sessionList' => [
            'perSecond' => 50,
            'perMinute' => 100,
            'perHour' => 600,
        ],
        'sessionInvoiceList' => [
            'perSecond' => 100,
            'perMinute' => 200,
            'perHour' => 2000,
        ],
        'sessionMisc' => [
            'perSecond' => 100,
            'perMinute' => 1200,
            'perHour' => 7200,
        ],
        'invoiceMetadata' => [
            'perSecond' => 80,
            'perMinute' => 160,
            'perHour' => 200,
        ],
        'invoiceExport' => [
            'perSecond' => 40,
            'perMinute' => 80,
            'perHour' => 200,
        ],
        'invoiceDownload' => [
            'perSecond' => 80,
            'perMinute' => 160,
            'perHour' => 640,
        ],
        'other' => [
            'perSecond' => 100,
            'perMinute' => 300,
            'perHour' => 1200,
        ],
    ];
}
