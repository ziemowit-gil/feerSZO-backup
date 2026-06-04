<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\ConvertCertificateToPkcs12;

use SensitiveParameter;
use N1ebieski\KSEFClient\Actions\AbstractAction;
use N1ebieski\KSEFClient\ValueObjects\Certificate;

final class ConvertCertificateToPkcs12Action extends AbstractAction
{
    public function __construct(
        public readonly Certificate $certificate,
        #[SensitiveParameter] public readonly string $passphrase
    ) {
    }
}
