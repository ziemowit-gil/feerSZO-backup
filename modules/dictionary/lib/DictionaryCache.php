<?php
declare(strict_types=1);
/**
 * Cache odczytów Słownika Centralnego — dwie warstwy:
 *   1) pamięć procesu (tablica statyczna) — zawsze, także bez Redisa i w CLI;
 *   2) Redis przez modules/redis_cache (szo_cache_*), gdy włączony w panelu admina — grupa kluczy 'dict'.
 * Unieważnienie przy KAŻDYM zapisie: czyści pamięć procesu i podbija pokolenie grupy 'dict'
 * (szo_cache_bump) — stare klucze w Redisie przestają być czytane i same wygasają po TTL.
 */
final class DictionaryCache
{
    public const GROUP = 'dict';
    private const TTL  = 3600;

    /** @var array<string, mixed> */
    private static array $memory = [];

    public function remember(string $key, callable $compute): mixed
    {
        if (array_key_exists($key, self::$memory)) return self::$memory[$key];
        $value = null;
        $hit   = false;
        if (function_exists('szo_cache_get')) $value = szo_cache_get(szo_cache_gkey(self::GROUP, $key), $hit);
        if (!$hit) {
            $value = $compute();
            if (function_exists('szo_cache_set')) szo_cache_set(szo_cache_gkey(self::GROUP, $key), $value, self::TTL);
        }
        return self::$memory[$key] = $value;
    }

    public function invalidate(): void
    {
        self::$memory = [];
        if (function_exists('szo_cache_bump')) szo_cache_bump(self::GROUP);
    }
}
