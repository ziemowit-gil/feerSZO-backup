#!/usr/bin/env php
<?php

declare(strict_types=1);

use N1ebieski\KSEFClient\Factories\ValinorCacheFactory;

$cachePath = sys_get_temp_dir() . '/' . ValinorCacheFactory::NAMESPACE;

if ( ! is_dir($cachePath)) {
    echo "Valinor cache does not exist: {$cachePath}" . PHP_EOL;

    exit(0);
}

try {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cachePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ( ! $item instanceof SplFileInfo) {
            continue;
        }

        $path = $item->getPathname();

        if ($item->isDir()) {
            if ( ! rmdir($path)) {
                throw new RuntimeException();
            }

            continue;
        }

        if ( ! unlink($path)) {
            throw new RuntimeException();
        }
    }

    if ( ! rmdir($cachePath)) {
        throw new RuntimeException();
    }
} catch (Throwable) {
    fwrite(STDERR, "Failed to clear Valinor cache: {$cachePath}" . PHP_EOL);

    exit(1);
}

echo "Valinor cache cleared: {$cachePath}" . PHP_EOL;
