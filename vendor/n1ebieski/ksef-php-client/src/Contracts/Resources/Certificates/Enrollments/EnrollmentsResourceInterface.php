<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Certificates\Enrollments;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Requests\Certificates\Enrollments\Send\SendRequest;
use N1ebieski\KSEFClient\Requests\Certificates\Enrollments\Status\StatusRequest;

interface EnrollmentsResourceInterface
{
    public function data(): ResponseInterface;

    /**
     * @param SendRequest|array<string, mixed> $request
     */
    public function send(SendRequest | array $request): ResponseInterface;

    /**
     * @param StatusRequest|array<string, mixed> $request
     */
    public function status(StatusRequest | array $request): ResponseInterface;
}
