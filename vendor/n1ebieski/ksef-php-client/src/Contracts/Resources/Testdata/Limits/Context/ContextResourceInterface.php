<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Testdata\Limits\Context;

use N1ebieski\KSEFClient\Contracts\Resources\Testdata\Limits\Context\Session\SessionResourceInterface;

interface ContextResourceInterface
{
    public function session(): SessionResourceInterface;
}
