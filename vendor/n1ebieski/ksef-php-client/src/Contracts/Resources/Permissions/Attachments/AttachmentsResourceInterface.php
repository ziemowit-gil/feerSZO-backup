<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Contracts\Resources\Permissions\Attachments;

use N1ebieski\KSEFClient\Contracts\HttpClient\ResponseInterface;

interface AttachmentsResourceInterface
{
    public function status(): ResponseInterface;
}
