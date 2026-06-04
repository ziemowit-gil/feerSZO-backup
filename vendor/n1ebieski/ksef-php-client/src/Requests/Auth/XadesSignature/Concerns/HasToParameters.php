<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Auth\XadesSignature\Concerns;

use N1ebieski\KSEFClient\Support\Optional;

/**
 * @property-read Optional | bool $verifyCertificateChain
 */
trait HasToParameters
{
    public function toParameters(): array
    {
        $parameters = [];

        if ( ! $this->verifyCertificateChain instanceof Optional) {
            $parameters['verifyCertificateChain'] = $this->verifyCertificateChain ? "true" : "false";
        }

        return $parameters;
    }
}
