<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Support;

use Closure;
use N1ebieski\KSEFClient\Exceptions\RetryTimeoutException;

final class Utility
{
    public static function retry(Closure $closure, int $backoff = 10, int $retryUntil = 120): mixed
    {
        $seconds = 0;
        $attempts = 0;

        while (true) {
            $attempts++;

            $result = $closure($attempts);

            if ($result !== null) {
                return $result;
            }

            $seconds += $backoff;

            if ($seconds > $retryUntil) {
                throw new RetryTimeoutException("Operation did not return a result after retrying for {$retryUntil} seconds.");
            }

            sleep($backoff);
        }
    }

    /**
     * Get the path to the base of the install.
     */
    public static function basePath(string $path = ''): string
    {
        $basePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        return Utility::normalizePath($basePath . $path);
    }

    /**
     * Get normalized path, like realpath() for non-existing path or file
     */
    public static function normalizePath(string $path): string
    {
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $absolutes = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }

        $normalized = implode(DIRECTORY_SEPARATOR, $absolutes);

        if (preg_match('/^[A-Za-z]:$/', $absolutes[0] ?? '')) {
            return $normalized;
        }

        return DIRECTORY_SEPARATOR . $normalized;
    }

    /**
     * Return the default value of the given value.
     *
     * @template TValue
     * @template TArgs
     *
     * @param TValue|Closure(TArgs):TValue $value
     * @param  TArgs  ...$args
     * @return TValue
     */
    public static function value($value, ...$args)
    {
        return $value instanceof Closure ? $value(...$args) : $value;
    }
}
