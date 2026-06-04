<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Factories;

use N1ebieski\KSEFClient\Validator\Rules\String\RegexRule;
use N1ebieski\KSEFClient\Validator\Validator;
use N1ebieski\KSEFClient\ValueObjects\InternalId;
use N1ebieski\KSEFClient\ValueObjects\NIP;

final class InternalIdFactory extends AbstractFactory
{
    public static function make(NIP $nip, string $id): InternalId
    {
        Validator::validate(['id' => $id], [
            'id' => [
                new RegexRule('/^\d{4}$/')
            ]
        ]);

        $base = $nip->value . $id;

        $sum = 0;
        $length = strlen($base);

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $base[$i];
            $weight = $i % 2 === 0 ? 1 : 3;
            $sum += $digit * $weight;
        }

        $checksum = self::checksum($nip->value, $id);

        return InternalId::from(sprintf('%s-%s%d', $nip->value, $id, $checksum));
    }

    private static function checksum(string $nip, string $id): int
    {
        $base = $nip . $id;

        $sum = 0;
        $length = strlen($base);

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $base[$i];
            $weight = $i % 2 === 0 ? 1 : 3;
            $sum += $digit * $weight;
        }

        return $sum % 10;
    }
}
