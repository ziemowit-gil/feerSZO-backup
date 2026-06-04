<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Permissions;

use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Attachments\AttachmentsResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Authorizations\AuthorizationsResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Common\CommonResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Entities\EntitiesResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\EuEntities\EuEntitiesResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Indirect\IndirectResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Operations\OperationsResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Persons\PersonsResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Query\QueryResourceInterface;
use N1ebieski\KSEFClient\Contracts\Resources\Permissions\Subunits\SubunitsResourceInterface;

interface PermissionsResourceInterface
{
    public function common(): CommonResourceInterface;

    public function persons(): PersonsResourceInterface;

    public function entities(): EntitiesResourceInterface;

    public function authorizations(): AuthorizationsResourceInterface;

    public function indirect(): IndirectResourceInterface;

    public function subunits(): SubunitsResourceInterface;

    public function euEntities(): EuEntitiesResourceInterface;

    public function operations(): OperationsResourceInterface;

    public function query(): QueryResourceInterface;

    public function attachments(): AttachmentsResourceInterface;
}
