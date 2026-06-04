<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Permissions\Common\Revoke;

use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\ValueObjects\Requests\Permissions\PermissionId;

final class RevokeRequest extends AbstractRequest
{
    public function __construct(
        public readonly PermissionId $permissionId
    ) {
    }
}
