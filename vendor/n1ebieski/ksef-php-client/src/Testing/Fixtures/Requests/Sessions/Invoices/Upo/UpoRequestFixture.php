<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Sessions\Invoices\Upo;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractRequestFixture;

final class UpoRequestFixture extends AbstractRequestFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'referenceNumber' => '20250625-EE-319D7EE000-B67F415CDC-2C',
        'invoiceReferenceNumber' => '20250625-EE-319D7EE000-B67F415CDC-CA'
    ];
}
