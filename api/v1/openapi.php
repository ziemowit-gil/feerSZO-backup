<?php
/**
 * api/v1/openapi.php — Specyfikacja OpenAPI 3.0 dla API Karty 30.
 * Generowana z includes/karty30_api.php (jedno źródło prawdy → brak rozjazdu).
 * Zwraca JSON do importu w Postman / Swagger UI.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/karty30_api.php';

$base       = rtrim(APP_URL, '/') . '/api/v1';
$resources  = k30_api_resources();
$int_fields = k30_api_int_fields();
$dt_fields  = k30_api_dt_fields();

// ── Schematy komponentów (po jednym na zasób) ────────────────────────────────
$schemas = [];
foreach ($resources as $name => $cfg) {
    $props = ['id' => ['type' => 'integer', 'readOnly' => true]];
    foreach ($cfg['fields'] as $f) {
        if (in_array($f, $int_fields, true))      $props[$f] = ['type' => 'integer'];
        elseif (in_array($f, $dt_fields, true))   $props[$f] = ['type' => 'string', 'format' => 'date-time'];
        elseif (str_ends_with($f, '_id'))         $props[$f] = ['type' => 'integer'];
        else                                      $props[$f] = ['type' => 'string'];
    }
    $props['created_at'] = ['type' => 'string', 'readOnly' => true];
    if (!empty($cfg['updated_at'])) $props['updated_at'] = ['type' => 'string', 'readOnly' => true];
    $schemas[ucfirst($name)] = [
        'type'       => 'object',
        'required'   => $cfg['required'],
        'properties' => $props,
    ];
}

$resource_names = array_keys($resources);

// Filtry dostępne per zasób (do opisu parametru)
$filter_note = [];
foreach ($resources as $name => $cfg) {
    $fl = array_merge($cfg['filters'] ?? [], !empty($cfg['search']) ? ['q'] : []);
    if ($fl) $filter_note[] = "$name: " . implode(', ', $fl);
}

$spec = [
    'openapi' => '3.0.3',
    'info' => [
        'title'       => 'Karty 30 API (Dydaktyka)',
        'version'     => '1.0',
        'description' => "REST API modułu Karty 30 — System Obsługi Organizacji (FEER).\n\n"
                       . "Routing przez parametr `resource` (jeden plik). Uwierzytelnianie: Bearer token "
                       . "(`Authorization: Bearer <klucz>`) lub `?api_key=`. Odczyt: scope `karty30:read`, "
                       . "zapis: `karty30:write`. Limit zapytań per klucz (429 + Retry-After). "
                       . "Zapisy audytowane (RODO).\n\nFiltry list per zasób — " . implode('; ', $filter_note) . '.',
    ],
    'servers' => [['url' => $base]],
    'security' => [['bearerAuth' => []]],
    'tags' => [['name' => 'karty30', 'description' => 'Zasoby modułu Karty 30']],
    'paths' => [
        '/karty30.php' => [
            'get' => [
                'tags' => ['karty30'], 'summary' => 'Lista lub pojedynczy rekord (z ?id=N)',
                'security' => [['bearerAuth' => []]],
                'parameters' => [
                    ['name' => 'resource', 'in' => 'query', 'required' => true,
                     'schema' => ['type' => 'string', 'enum' => $resource_names]],
                    ['name' => 'id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer'],
                     'description' => 'Gdy podane — zwraca jeden rekord.'],
                    ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
                    ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 50, 'maximum' => 100]],
                    ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Wyszukiwanie (zasoby z polem search).'],
                ],
                'responses' => [
                    '200' => ['description' => 'OK'],
                    '401' => ['description' => 'Brak/nieprawidłowy klucz lub uprawnienie'],
                    '404' => ['description' => 'Nieznany zasób / nie znaleziono rekordu'],
                    '429' => ['description' => 'Przekroczono limit zapytań'],
                ],
            ],
            'post' => [
                'tags' => ['karty30'], 'summary' => 'Utwórz rekord',
                'security' => [['bearerAuth' => []]],
                'parameters' => [
                    ['name' => 'resource', 'in' => 'query', 'required' => true,
                     'schema' => ['type' => 'string', 'enum' => $resource_names]],
                ],
                'requestBody' => ['required' => true, 'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'additionalProperties' => true]]]],
                'responses' => [
                    '201' => ['description' => 'Utworzono'],
                    '401' => ['description' => 'Brak uprawnienia (karty30:write)'],
                    '422' => ['description' => 'Brak wymaganych pól / błędny klucz obcy'],
                    '429' => ['description' => 'Przekroczono limit zapytań'],
                ],
            ],
            'patch' => [
                'tags' => ['karty30'], 'summary' => 'Aktualizuj rekord',
                'security' => [['bearerAuth' => []]],
                'parameters' => [
                    ['name' => 'resource', 'in' => 'query', 'required' => true,
                     'schema' => ['type' => 'string', 'enum' => $resource_names]],
                    ['name' => 'id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
                ],
                'requestBody' => ['required' => true, 'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'additionalProperties' => true]]]],
                'responses' => [
                    '200' => ['description' => 'Zaktualizowano'],
                    '401' => ['description' => 'Brak uprawnienia'],
                    '404' => ['description' => 'Nie znaleziono'],
                ],
            ],
            'delete' => [
                'tags' => ['karty30'], 'summary' => 'Usuń rekord (kursy: soft-delete)',
                'security' => [['bearerAuth' => []]],
                'parameters' => [
                    ['name' => 'resource', 'in' => 'query', 'required' => true,
                     'schema' => ['type' => 'string', 'enum' => $resource_names]],
                    ['name' => 'id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
                ],
                'responses' => [
                    '200' => ['description' => 'Usunięto'],
                    '401' => ['description' => 'Brak uprawnienia'],
                    '404' => ['description' => 'Nie znaleziono'],
                ],
            ],
        ],
    ],
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Klucz API (admin → Klucze API)'],
        ],
        'schemas' => $schemas,
    ],
];

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // import w Swagger/Postman
echo json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
