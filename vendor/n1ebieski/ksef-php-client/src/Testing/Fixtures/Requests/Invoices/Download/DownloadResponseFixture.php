<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Invoices\Download;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class DownloadResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    public string $data = 'invoice';

    public function toContents(): string
    {
        return $this->data;
    }
}
