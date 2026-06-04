<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Testdata\Limits;

use N1ebieski\KSEFClient\Contracts\Resources\Testdata\Limits\Context\ContextResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Testdata\Limits\Subject\SubjectResourceInterface;

interface LimitsResourceInterface
{
    public function context(): ContextResourceInterface;

    public function subject(): SubjectResourceInterface;
}
