<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Support\Concerns;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Support\Str;

trait HasToBody
{
    use HasToArray;

    /**
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        $toArray = $this->toArray();

        $newArray = [];

        foreach (get_object_vars($this) as $key => $value) {
            $name = Str::camel($key);

            if ($value instanceof BodyInterface) {
                $newArray[$name] = $value->toBody();
            }
        }

        /** @var array<string, mixed> */
        return array_merge($toArray, $newArray);
    }
}
