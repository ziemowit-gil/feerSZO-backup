<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Sessions;

use N1ebieski\KSEFClient\Contracts\EnumInterface;
use N1ebieski\KSEFClient\Support\Utility;

enum FormCode: string implements EnumInterface
{
    case Fa3 = 'FA (3)';

    case FaRr1 = 'FA_RR (1)';

    case Pef3 = 'PEF (3)';

    case KorPef3 = 'KOR_PEF (3)';

    public function getSchemaVersion(): string
    {
        return match ($this) {
            self::Fa3 => '1-0E',
            self::FaRr1 => '1-1E',
            self::Pef3, self::KorPef3 => '2-1',
        };
    }

    public function getSchemaPath(): string
    {
        return match ($this) {
            self::Fa3, self::Pef3, self::KorPef3 => Utility::basePath('resources/xsd/faktura/schemat.xsd'),
            self::FaRr1 => Utility::basePath('resources/xsd/faktura-rr/schemat.xsd'),
        };
    }

    public function getValue(): string
    {
        return match ($this) {
            self::Fa3 => 'FA',
            self::FaRr1 => 'FA_RR',
            self::Pef3, self::KorPef3 => 'PEF',
        };
    }

    public function getWariantFormularza(): string
    {
        return match ($this) {
            self::Fa3, self::Pef3, self::KorPef3 => '3',
            self::FaRr1 => '1',
        };
    }
}
