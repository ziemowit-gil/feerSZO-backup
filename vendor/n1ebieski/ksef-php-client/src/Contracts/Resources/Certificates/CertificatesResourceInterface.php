<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Certificates;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Certificates\Enrollments\EnrollmentsResourceInterface;
use N1ebieski\KSEFClient\Requests\Certificates\Query\QueryRequest;
use N1ebieski\KSEFClient\Requests\Certificates\Retrieve\RetrieveRequest;
use N1ebieski\KSEFClient\Requests\Certificates\Revoke\RevokeRequest;

interface CertificatesResourceInterface
{
    public function limits(): ResponseInterface;

    public function enrollments(): EnrollmentsResourceInterface;

    /**
     * @param QueryRequest|array<string, mixed> $request
     */
    public function query(QueryRequest | array $request): ResponseInterface;

    /**
     * @param RevokeRequest|array<string, mixed> $request
     */
    public function revoke(RevokeRequest | array $request): ResponseInterface;

    /**
     * @param RetrieveRequest|array<string, mixed> $request
     */
    public function retrieve(RetrieveRequest | array $request): ResponseInterface;
}
