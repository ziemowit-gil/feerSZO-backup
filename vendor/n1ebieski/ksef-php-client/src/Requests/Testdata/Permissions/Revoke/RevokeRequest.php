<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Testdata\Permissions\Revoke;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\DTOs\Requests\Testdata\Permissions\AuthorizedIdentifier;
use N1ebieski\KSEFClient\DTOs\Requests\Testdata\Permissions\ContextIdentifier;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Concerns\HasToBody;

final class RevokeRequest extends AbstractRequest implements BodyInterface
{
    use HasToBody;

    public function __construct(
        public readonly ContextIdentifier $contextIdentifier,
        public readonly AuthorizedIdentifier $authorizedIdentifier,
    ) {
    }
}
