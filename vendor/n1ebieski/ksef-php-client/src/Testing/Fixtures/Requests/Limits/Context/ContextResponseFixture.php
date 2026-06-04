<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Limits\Context;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class ContextResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'onlineSession' => [
            'maxInvoiceSizeInMib' => 0,
            'maxInvoiceSizeInMB' => 0,
            'maxInvoiceWithAttachmentSizeInMib' => 0,
            'maxInvoiceWithAttachmentSizeInMB' => 0,
            'maxInvoices' => 0
        ],
        'batchSession' => [
            'maxInvoiceSizeInMib' => 0,
            'maxInvoiceSizeInMB' => 0,
            'maxInvoiceWithAttachmentSizeInMib' => 0,
            'maxInvoiceWithAttachmentSizeInMB' => 0,
            'maxInvoices' => 0
        ]
    ];
}
