<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Auth\XadesSignature\Concerns;

use N1ebieski\KSEFClient\Support\Optional;

/**
 * @property-read Optional | bool $enforceXadesCompliance
 */
trait HasToHeaders
{
    public function toHeaders(): array
    {
        if ($this->enforceXadesCompliance === true) {
            return [
                'X-KSeF-Feature' => 'enforce-xades-compliance',
            ];
        }

        return [];
    }
}
