<?php
/**
 * REST API — Rejestr pełnomocnictw
 * Bearer token (Authorization: Bearer <key>) lub ?api_key=<key>.
 * Uprawnienia: pelnomocnictwa:read (odczyt), pelnomocnictwa:write (zapis).
 *
 * Routing (jeden plik, bez rewrite — sterowanie metodą HTTP + parametrem id):
 *   GET    /api/v1/pelnomocnictwa.php                → lista (filtry q, status, rok + paginacja)
 *   GET    /api/v1/pelnomocnictwa.php?id=N           → pojedynczy wpis
 *   POST   /api/v1/pelnomocnictwa.php                → utwórz wpis (JSON body)
 *   PATCH  /api/v1/pelnomocnictwa.php?id=N           → aktualizuj wpis (JSON body)
 *   DELETE /api/v1/pelnomocnictwa.php?id=N           → usuń wpis
 *
 * Metodę można nadpisać nagłówkiem X-HTTP-Method-Override lub ?_method=PATCH
 * (dla klientów bez wsparcia PATCH/DELETE).
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/pelnomocnictwa.php';

api_auth_migrate();

if (!module_enabled('pelnomocnictwa_enabled')) {
    api_error('Rejestr pełnomocnictw jest wyłączony przez administratora.', 404);
}

// ── Routing ────────────────────────────────────────────────────────────────
$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ($_GET['_method'] ?? '');
if ($override !== '') $method = strtoupper((string)$override);

$id = (int)($_GET['id'] ?? 0);

if ($method === 'GET') {
    api_require('pelnomocnictwa:read');
} else {
    api_require('pelnomocnictwa:write');
}

/** Body żądania jako tablica (JSON lub form-encoded). */
function peln_api_input(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input') ?: '';
        $j   = json_decode($raw, true);
        if ($raw !== '' && !is_array($j)) {
            api_error('Invalid JSON body', 400);
        }
        return is_array($j) ? $j : [];
    }
    return $_POST;
}

/** Serializuje wiersz do publicznej postaci (dołącza wyliczony status). */
function peln_api_row(array $r): array {
    return [
        'id'              => (int)$r['id'],
        'numer'           => $r['numer'],
        'mocodawca'       => $r['mocodawca'],
        'pelnomocnik'     => $r['pelnomocnik'],
        'pelnomocnik_pesel' => $r['pelnomocnik_pesel'],
        'pelnomocnik_user_id' => $r['pelnomocnik_user_id'] !== null ? (int)$r['pelnomocnik_user_id'] : null,
        'zakres'          => $r['zakres'],
        'forma'           => $r['forma'],
        'data_udzielenia' => $r['data_udzielenia'],
        'data_waznosci'   => $r['data_waznosci'],
        'data_odwolania'  => $r['data_odwolania'],
        'status'          => pelnomocnictwo_status($r),
        'uwagi'           => $r['uwagi'],
        'podpisujacy'         => $r['podpisujacy'],
        'podpisujacy_funkcja' => $r['podpisujacy_funkcja'],
        'dokument_zalaczony'  => $r['dokument_plik'] !== '',
        'created_at'      => $r['created_at'],
        'updated_at'      => $r['updated_at'],
    ];
}

/**
 * Waliduje i buduje payload zapisu z wejścia.
 * @return array{0:array,1:array}  [$data, $errors]
 */
function peln_api_build(array $in, bool $require_fields): array {
    $errors = [];
    $data   = [];

    $fields = ['numer','mocodawca','pelnomocnik','pelnomocnik_pesel','pelnomocnik_user_id','zakres','forma','data_udzielenia','data_waznosci','data_odwolania','uwagi','podpisujacy','podpisujacy_funkcja'];
    foreach ($fields as $f) {
        if (array_key_exists($f, $in)) {
            $v = $in[$f];
            $data[$f] = is_string($v) ? trim($v) : $v;
        }
    }

    if ($require_fields) {
        if (empty($data['mocodawca']))   $errors[] = 'Pole mocodawca jest wymagane.';
        if (empty($data['pelnomocnik'])) $errors[] = 'Pole pelnomocnik jest wymagane.';
    }

    foreach (['data_udzielenia','data_waznosci','data_odwolania'] as $df) {
        if (!empty($data[$df]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data[$df])) {
            $errors[] = "Pole $df musi być datą w formacie RRRR-MM-DD.";
        }
    }

    return [$data, $errors];
}

// ══════════════════════════════════════════════════════════════════════════════
// WPIS POJEDYNCZY (?id=N)
// ══════════════════════════════════════════════════════════════════════════════
if ($id > 0) {
    $row = pelnomocnictwo_get($id);

    if ($method === 'GET') {
        if (!$row) api_error('Nie znaleziono wpisu.', 404);
        api_json(['data' => peln_api_row($row)]);
    }

    if ($method === 'PATCH' || $method === 'PUT') {
        if (!$row) api_error('Nie znaleziono wpisu.', 404);
        [$data, $errors] = peln_api_build(peln_api_input(), false);
        if ($errors) api_error(implode(' ', $errors), 422);
        if (!$data) api_error('Brak pól do aktualizacji.', 400);
        global $api_current_key;
        $author = (int)($api_current_key['created_by'] ?? 0) ?: null;
        pelnomocnictwo_save($id, array_merge($row, $data), $author);
        api_json(['data' => peln_api_row(pelnomocnictwo_get($id))]);
    }

    if ($method === 'DELETE') {
        if (!$row) api_error('Nie znaleziono wpisu.', 404);
        pelnomocnictwo_delete($id);
        api_json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    api_error('Method Not Allowed', 405);
}

// ══════════════════════════════════════════════════════════════════════════════
// LISTA / TWORZENIE
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = min(100, max(1, (int)($_GET['per_page'] ?? 50)));

    $rows_all = pelnomocnictwa_all([
        'q'      => trim((string)($_GET['q'] ?? '')),
        'status' => (string)($_GET['status'] ?? ''),
        'rok'    => !empty($_GET['rok']) ? (int)$_GET['rok'] : null,
    ]);

    $total  = count($rows_all);
    $rows   = array_slice($rows_all, ($page - 1) * $per_page, $per_page);
    $pages  = max(1, (int)ceil($total / $per_page));

    api_json([
        'data' => array_map('peln_api_row', $rows),
        'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per_page, 'pages' => $pages],
    ]);
}

if ($method === 'POST') {
    [$data, $errors] = peln_api_build(peln_api_input(), true);
    if ($errors) api_error(implode(' ', $errors), 422);
    global $api_current_key;
    $author = (int)($api_current_key['created_by'] ?? 0) ?: 0;
    $newId  = pelnomocnictwo_save(0, $data, $author);
    api_json(['data' => peln_api_row(pelnomocnictwo_get($newId))], 201);
}

api_error('Method Not Allowed', 405);
