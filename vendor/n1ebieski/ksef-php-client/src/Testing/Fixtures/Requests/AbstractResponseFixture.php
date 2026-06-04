<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests;

use N1ebieski\KSEFClient\Testing\Fixtures\AbstractFixture;

/**
 * @property int $statusCode
 */
abstract class AbstractResponseFixture extends AbstractFixture
{
    public function toContents(): string
    {
        return json_encode($this->data, JSON_THROW_ON_ERROR);
    }
}
