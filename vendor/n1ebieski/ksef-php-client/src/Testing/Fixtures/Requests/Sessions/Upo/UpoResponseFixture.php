<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Sessions\Upo;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class UpoResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    public string $data = 'upo';

    public function toContents(): string
    {
        return $this->data;
    }
}
