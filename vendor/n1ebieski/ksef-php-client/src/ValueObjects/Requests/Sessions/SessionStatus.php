<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Sessions;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum SessionStatus: string implements EnumInterface
{
    case InProgress = 'InProgress';

    case Succeeded = 'Succeeded';

    case Failed = 'Failed';

    case Cancelled = 'Cancelled';
}
