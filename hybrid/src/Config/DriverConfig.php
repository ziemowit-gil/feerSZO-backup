<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Config;

/**
 * Przełącznik sterownika (driver) per domena — czyta zmienne środowiskowe
 * dokładnie tak, jak reszta aplikacji (patrz config.php: getenv('X') ?: default).
 *
 * Każda z trzech domen (SZO, TI, Dydaktyka) ma WŁASNY, niezależny przełącznik —
 * dowolna z nich może być "local" (wywołanie bezpośrednie w tym samym procesie)
 * albo "http" (REST, gdy ta domena jest wdrożona na osobnym serwerze).
 *
 *   SZO_DRIVER=local|http           (domyślnie: local)
 *   SZO_API_BASE_URL=https://...    (wymagane gdy SZO_DRIVER=http)
 *   SZO_API_KEY=...                 (Bearer token do api/v1/hybrid_szo.php)
 *
 *   TI_DRIVER=local|http
 *   TI_API_BASE_URL=...
 *   TI_API_KEY=...
 *
 *   DYDAKTYKA_DRIVER=local|http
 *   DYDAKTYKA_API_BASE_URL=...
 *   DYDAKTYKA_API_KEY=...
 */
final class DriverConfig
{
    private function __construct()
    {
    }

    public static function isHttp(string $driver): bool
    {
        return $driver === 'http';
    }

    public static function szoDriver(): string
    {
        return self::normalize(getenv('SZO_DRIVER'));
    }

    public static function szoBaseUrl(): string
    {
        return (string)(getenv('SZO_API_BASE_URL') ?: '');
    }

    public static function szoApiKey(): string
    {
        return (string)(getenv('SZO_API_KEY') ?: '');
    }

    public static function tiDriver(): string
    {
        return self::normalize(getenv('TI_DRIVER'));
    }

    public static function tiBaseUrl(): string
    {
        return (string)(getenv('TI_API_BASE_URL') ?: '');
    }

    public static function tiApiKey(): string
    {
        return (string)(getenv('TI_API_KEY') ?: '');
    }

    public static function dydaktykaDriver(): string
    {
        return self::normalize(getenv('DYDAKTYKA_DRIVER'));
    }

    public static function dydaktykaBaseUrl(): string
    {
        return (string)(getenv('DYDAKTYKA_API_BASE_URL') ?: '');
    }

    public static function dydaktykaApiKey(): string
    {
        return (string)(getenv('DYDAKTYKA_API_KEY') ?: '');
    }

    /** @param string|false $value */
    private static function normalize($value): string
    {
        $v = strtolower(trim((string)($value ?: 'local')));
        return $v === 'http' ? 'http' : 'local';
    }
}
