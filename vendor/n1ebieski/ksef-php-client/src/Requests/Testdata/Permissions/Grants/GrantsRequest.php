<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Testdata\Permissions\Grants;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Testdata\Permissions\AuthorizedIdentifier;
use N1ebieski\KSEFClient\DTOs\Requests\Testdata\Permissions\ContextIdentifier;
use N1ebieski\KSEFClient\DTOs\Requests\Testdata\Permissions\Permission;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Concerns\HasToBody;

final class GrantsRequest extends AbstractRequest implements BodyInterface
{
    use HasToBody;

    /**
     * @param array<int, Permission> $permissions
     */
    public function __construct(
        public readonly ContextIdentifier $contextIdentifier,
        public readonly AuthorizedIdentifier $authorizedIdentifier,
        public readonly array $permissions,
    ) {
    }
}
