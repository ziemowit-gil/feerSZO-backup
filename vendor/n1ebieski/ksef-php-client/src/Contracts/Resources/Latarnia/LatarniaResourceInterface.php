<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Latarnia;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;

interface LatarniaResourceInterface
{
    public function status(): ResponseInterface;

    public function messages(): ResponseInterface;
}
