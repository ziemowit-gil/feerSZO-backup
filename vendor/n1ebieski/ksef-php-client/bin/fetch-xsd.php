#!/usr/bin/env php
<?php

declare(strict_types=1);

use N1ebieski\KSEFClient\Support\Utility;

/* ================== CONFIG ================== */

$config = [
    'xsds' => [
        'faktura' => 'http://crd.gov.pl/wzor/2025/06/25/13775/schemat.xsd',
        'faktura-rr' => 'http://crd.gov.pl/wzor/2026/03/06/14189/schemat.xsd',
    ],

    'resourcesPath' => Utility::basePath('resources/xsd'),
];

/* ============================================ */

main($config);

/**
 * @param array{xsds: array<string, string>, resourcesPath: string} $config
 */
function main(array $config): void
{
    foreach ($config['xsds'] as $name => $url) {
        $targetPath = Utility::normalizePath($config['resourcesPath'] . DIRECTORY_SEPARATOR . $name);

        printf("[TASK] %s => %s" . PHP_EOL, $name, $url);

        download($url, $targetPath);
    }
}

function download(string $url, string $targetPath): void
{
    $content = file_get_contents($url);

    if ($content === false) {
        throw new RuntimeException("Failed to download XSD from {$url}");
    }

    $imports = getImports($content);

    foreach ($imports as $importUrl) {
        download($importUrl, $targetPath);
    }

    $locations = getLocations($imports);

    $filename = getFilename($url);
    $filenamePath = Utility::normalizePath($targetPath . DIRECTORY_SEPARATOR . $filename);

    $content = updateSchemaLocations($content, $locations);

    save($content, $filenamePath);

    printf('[DONE] Downloaded %s to %s' . PHP_EOL, $url, $filenamePath);
}

function save(string $content, string $targetPath): void
{
    if ( ! is_dir(dirname($targetPath))) {
        mkdir(dirname($targetPath), 0755, true);
    }

    file_put_contents($targetPath, $content);
}

/**
 * @param array<string, string> $locations
 */
function updateSchemaLocations(string $content, array $locations): string
{
    $dom = new DOMDocument();

    if ( ! $dom->loadXML($content)) {
        throw new RuntimeException("Failed to parse XSD content.");
    }

    $xpath = new DOMXPath($dom);

    $nodes = $xpath->query('//*[local-name()="import" or local-name()="include"]');

    if ($nodes === false) {
        throw new RuntimeException("Failed to query XSD for imports/includes.");
    }

    foreach ($nodes as $node) {
        if ( ! $node instanceof DOMElement) {
            continue;
        }

        $schemaLocation = $node->getAttribute('schemaLocation');

        if ( ! isset($locations[$schemaLocation])) {
            continue;
        }

        $node->setAttribute('schemaLocation', $locations[$schemaLocation]);

        printf('[DONE] Updated schemaLocation from %s to %s' . PHP_EOL, $schemaLocation, $locations[$schemaLocation]);
    }

    $content = $dom->saveXML();

    if ($content === false) {
        throw new RuntimeException("Failed to save updated XSD content.");
    }

    return $content;
}

/**
 * @param array<int, string> $imports
 * @return array<string, string>
 */
function getLocations(array $imports): array
{
    $locations = [];

    foreach ($imports as $importUrl) {
        $locations[$importUrl] = getFilename($importUrl);
    }

    return $locations;
}

/**
 * @param string $content
 * @return array<int, string>
 */
function getImports(string $content): array
{
    $dom = new DOMDocument();

    if ( ! $dom->loadXML($content)) {
        throw new RuntimeException("Failed to parse XSD content.");
    }

    $xpath = new DOMXPath($dom);

    $nodes = $xpath->query('//xsd:import | //xsd:include');

    if ($nodes === false) {
        throw new RuntimeException("Failed to query XSD for imports/includes.");
    }

    $imports = [];

    if ($nodes->length > 0) {
        foreach ($nodes as $node) {
            if ( ! $node instanceof DOMElement) {
                continue;
            }

            $schemaLocation = $node->getAttribute('schemaLocation');

            if ( ! is_string(parse_url($schemaLocation, PHP_URL_SCHEME))) {
                continue;
            }

            $imports[] = $schemaLocation;
        }
    }

    return $imports;
}

function getFilename(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);

    if ( ! is_string($path)) {
        throw new RuntimeException(sprintf("Invalid URL: %s", $url));
    }

    return basename($path);
}
