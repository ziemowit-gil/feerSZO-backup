<?php
/**
 * crm/mobile/manifest.php — manifest PWA dla mobilnego dialera CRM.
 *
 * Osobny scope (/crm/mobile/) niż manifest panelu wolontariusza, żeby dodanie
 * dialera do ekranu głównego nie kolidowało z instalacją panelu.
 */

declare(strict_types=1);

if (!defined('APP_INSTALLED')) require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/includes/mobile.php';

$org_name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'CRM');
$base     = APP_URL;
// Scope musi odpowiadać adresowi, pod którym aplikacja została otwarta
// (kanonicznym /crm/mobile/ albo krótkim /mobilna/), inaczej instalacja PWA
// wyjdzie poza swój zakres.
$app      = crm_mobile_base();

$icons = [];
foreach ([['icon-192.svg', '192x192'], ['icon-512.svg', '512x512']] as [$file, $size]) {
    if (is_file(dirname(__DIR__, 2) . '/assets/img/' . $file)) {
        $icons[] = ['src' => $base . '/assets/img/' . $file, 'sizes' => $size, 'type' => 'image/svg+xml', 'purpose' => 'any'];
    }
}
foreach ([['icon-192.png', '192x192'], ['icon-512.png', '512x512']] as [$file, $size]) {
    if (is_file(dirname(__DIR__, 2) . '/assets/img/' . $file)) {
        $icons[] = ['src' => $base . '/assets/img/' . $file, 'sizes' => $size, 'type' => 'image/png', 'purpose' => 'any'];
    }
}

$manifest = [
    'name'             => 'Dzwoń — ' . $org_name,
    'short_name'       => 'Dzwoń',
    'description'      => 'Szybkie dzwonienie do kontaktów CRM — ' . $org_name,
    'id'               => $app . '/',
    'scope'            => $app . '/',
    'start_url'        => $app . '/',
    'display'          => 'standalone',
    'background_color' => '#FFFFFF',
    'theme_color'      => '#2E844A',
    'orientation'      => 'portrait-primary',
    'icons'            => $icons,
];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
