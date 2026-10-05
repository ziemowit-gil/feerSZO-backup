<?php
/**
 * REST API — informacje o API i o używanym kluczu (diagnostyka / test połączenia).
 * Bearer token (Authorization: Bearer <key>) — wystarczy dowolny aktywny klucz, bez konkretnego uprawnienia.
 *
 *   GET /api/v1/info.php
 *   → 200 {
 *       "ok": true,
 *       "api":    {"version": "...", "org": "...", "server_time": "ISO-8601"},
 *       "key":    {"name": "...", "owner": "Imię Nazwisko"|null, "permissions": [...],
 *                  "expires_at": "..."|null, "last_used_at": "..."|null},
 *       "ezd":    {"max_upload_bytes": N, "allowed_ext": [...]},
 *       "endpoints": [{"path": "/api/v1/ezd_upload.php", "methods": ["POST"], "requires": "ezd:upload", "allowed": true}, ...]
 *     }
 *   Zwraca tylko dane samego klucza (nigdy skrótu ani innych kluczy) — to samo, co właściciel widzi w panelu.
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/ezd.php';

api_auth_migrate();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error('Method Not Allowed — użyj GET.', 405);
}
api_require(); // dowolny aktywny, niewygasły klucz

global $api_current_key;
$perms = json_decode((string)($api_current_key['permissions'] ?? '[]'), true);
if (!is_array($perms)) $perms = [];

$owner = null;
if (!empty($api_current_key['created_by'])) {
    $owner = db_one("SELECT name FROM users WHERE id=?", [(int)$api_current_key['created_by']])['name'] ?? null;
}

// [ścieżka, metody, wymagane uprawnienie (null = dowolny klucz)]
$catalog = [
    ['/api/v1/info.php',        ['GET'],                 null],
    ['/api/v1/ezd.php',         ['GET'],                 'ezd:read'],
    ['/api/v1/ezd_write.php',   ['GET'],                 'ezd:read'],
    ['/api/v1/ezd_write.php',   ['POST'],                'ezd:write'],
    ['/api/v1/ezd_upload.php',  ['POST'],                'ezd:upload'],
    ['/api/v1/crm.php',         ['GET'],                 'crm:read'],
    ['/api/v1/crm.php',         ['POST', 'PATCH', 'DELETE'], 'crm:write'],
];
$endpoints = array_map(static fn(array $e) => [
    'path'     => $e[0],
    'methods'  => $e[1],
    'requires' => $e[2],
    'allowed'  => $e[2] === null || in_array($e[2], $perms, true),
], $catalog);

api_json([
    'ok'  => true,
    'api' => [
        'version'     => defined('APP_VERSION') ? (string)APP_VERSION : null,
        'org'         => defined('ORG_NAME') ? (string)ORG_NAME : null,
        'server_time' => date('c'),
    ],
    'key' => [
        'name'         => (string)($api_current_key['name'] ?? ''),
        'owner'        => $owner,
        'permissions'  => array_values($perms),
        'expires_at'   => $api_current_key['expires_at'] ?? null,
        'last_used_at' => $api_current_key['last_used_at'] ?? null,
    ],
    'ezd' => [
        'max_upload_bytes' => EZD_MAX_SIZE,
        'allowed_ext'      => EZD_ALLOWED_EXT,
    ],
    'endpoints' => $endpoints,
]);
