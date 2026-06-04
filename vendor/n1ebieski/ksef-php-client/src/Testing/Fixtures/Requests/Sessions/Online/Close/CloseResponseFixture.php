<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Sessions\Online\Close;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class CloseResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 204;

    public string $data = "";
}
