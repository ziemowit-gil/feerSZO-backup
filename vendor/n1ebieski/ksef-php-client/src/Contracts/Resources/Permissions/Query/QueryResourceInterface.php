<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Permissions\Query;

use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Query\Authorizations\AuthorizationsResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Query\Entities\EntitiesResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Query\EuEntities\EuEntitiesResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Query\Personal\PersonalResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Query\Persons\PersonsResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Query\SubordinateEntities\SubordinateEntitiesResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Query\Subunits\SubunitsResourceInterface;

interface QueryResourceInterface
{
    public function authorizations(): AuthorizationsResourceInterface;

    public function entities(): EntitiesResourceInterface;

    public function euEntities(): EuEntitiesResourceInterface;

    public function personal(): PersonalResourceInterface;

    public function persons(): PersonsResourceInterface;

    public function subunits(): SubunitsResourceInterface;

    public function subordinateEntities(): SubordinateEntitiesResourceInterface;
}
