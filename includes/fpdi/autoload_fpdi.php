<?php
/**
 * Simple PSR-4 autoloader for setasign\Fpdi namespace.
 * Maps setasign\Fpdi\ → this directory.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'setasign\\Fpdi\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($file)) require_once $file;
});
