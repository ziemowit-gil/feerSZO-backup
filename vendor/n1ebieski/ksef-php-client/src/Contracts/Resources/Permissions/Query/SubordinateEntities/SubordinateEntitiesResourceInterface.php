<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Permissions\Query\SubordinateEntities;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;
use N1ebieski\KSEFClient\Requests\Permissions\Query\SubordinateEntities\Roles\RolesRequest;

interface SubordinateEntitiesResourceInterface
{
    /**
     * @param RolesRequest|array<string, mixed> $request
     */
    public function roles(RolesRequest | array $request): ResponseInterface;
}
