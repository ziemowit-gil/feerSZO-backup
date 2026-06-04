<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Permissions\EuEntities\Administration;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\EuEntities\Administration\Address;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\EuEntities\Administration\FullName;

final class EuEntityDetails extends AbstractDTO implements BodyInterface
{
    public function __construct(
        public readonly FullName $fullName,
        public readonly Address $address
    ) {
    }

    public function toBody(): array
    {
        return [
            'fullName' => (string) $this->fullName,
            'address' => (string) $this->address,
        ];
    }
}
