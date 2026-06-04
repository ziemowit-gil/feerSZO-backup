<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Permissions\EuEntities;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\EuEntities\Administration\AdministrationResourceInterface;
use N1ebieski\KSEFClient\Requests\Permissions\EuEntities\Grants\GrantsRequest;

interface EuEntitiesResourceInterface
{
    public function administration(): AdministrationResourceInterface;

    /**
     * @param GrantsRequest|array<string, mixed> $request
     */
    public function grants(GrantsRequest | array $request): ResponseInterface;
}
