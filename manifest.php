<?php
/**
 * manifest.php — Dynamic PWA Web App Manifest for the volunteer panel.
 * Outputs application/manifest+json with org-specific branding.
 */

if (!defined('APP_INSTALLED')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$org_name    = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Panel wolontariusza');
$theme_color = org_setting('volunteer_color') ?: '#1D4ED8';
$base        = APP_URL;

$manifest = [
    'name'             => $org_name,
    'short_name'       => 'Panel',
    'description'      => 'Panel wolontariusza — ' . $org_name,
    'start_url'        => $base . '/panel/index.php',
    'display'          => 'standalone',
    'background_color' => '#ffffff',
    'theme_color'      => $theme_color,
    'orientation'      => 'portrait-primary',
    'icons'            => [
        [
            'src'   => $base . '/assets/img/icon-192.png',
            'sizes' => '192x192',
            'type'  => 'image/png',
        ],
        [
            'src'   => $base . '/assets/img/icon-512.png',
            'sizes' => '512x512',
            'type'  => 'image/png',
        ],
    ],
    'shortcuts' => [
        [
            'name'  => 'Wiadomości',
            'url'   => $base . '/panel/messages.php',
            'icons' => [
                ['src' => $base . '/assets/img/icon-192.png', 'sizes' => '192x192'],
            ],
        ],
        [
            'name'  => 'Moje zadania',
            'url'   => $base . '/tasks/index.php',
            'icons' => [
                ['src' => $base . '/assets/img/icon-192.png', 'sizes' => '192x192'],
            ],
        ],
    ],
];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
