<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts;

interface BodyInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toBody(): array;
}
