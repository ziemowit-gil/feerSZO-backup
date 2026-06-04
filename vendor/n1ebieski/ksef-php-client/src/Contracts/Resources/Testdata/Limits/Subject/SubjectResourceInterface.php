<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Testdata\Limits\Subject;

use N1ebieski\KSEFClient\Contracts\Resources\Testdata\Limits\Subject\Certificate\CertificateResourceInterface;

interface SubjectResourceInterface
{
    public function certificate(): CertificateResourceInterface;
}
