<?php
/**
 * REST API — EZD: rejestracja z aplikacji desktopowej (EZD CRM Desktop).
 * Bearer token. Uprawnienia: ezd:write (POST), ezd:read (GET listy RPW).
 *
 * Osobne od ezd.php (tylko odczyt) i ezd_upload.php (tylko pliki). Wywołuje te same
 * funkcje co UI (numeracja, log metryki, powiadomienia), autorem jest właściciel klucza.
 * Zakres jest świadomie wąski: nowa koszulka, nowe pismo, nowy wpis RPW. Dekretacje,
 * podpisy, zamykanie i zmiany etapów pozostają w UI sesyjnym.
 *
 *   GET  ?resource=rpw[&q=&status=&page=&per_page=]   → lista przesyłek RPW (ezd:read)
 *   POST ?resource=sprawy  {teczka_id, title, description?, priority?, owner_id?, deadline?}
 *        → 201 {"ok":true,"id":N,"znak_sprawy":"..."}
 *   POST ?resource=pisma   {sprawa_id, title, kierunek?, nadawca?, odbiorca?, tresc?,
 *                           data_pisma?, data_wplywu?, rodzaj_medium?}
 *        → 201 {"ok":true,"id":N}
 *   POST ?resource=rpw     {typ?, nadawca?, opis?, znak_obcy?, uwagi?, data_wplywu?}
 *        → 201 {"ok":true,"id":N,"rpw_nr":N,"rok":YYYY}
 *   Body: JSON (Content-Type: application/json) albo form-data.
 *   Błąd walidacji/biznesowy → 422 {"error":"..."}.
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/ezd.php';

api_auth_migrate();

$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$resource = (string)($_GET['resource'] ?? '');

if ($method === 'GET') {
    api_require('ezd:read');
    if ($resource !== 'rpw') api_error('Nieznany resource (GET: rpw).', 404);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
    $where = []; $params = [];
    if (!empty($_GET['status'])) { $where[] = 'status = ?'; $params[] = (string)$_GET['status']; }
    if (!empty($_GET['q'])) {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$_GET['q']) . '%';
        $where[] = "(nadawca LIKE ? ESCAPE '\\' OR opis LIKE ? ESCAPE '\\' OR znak_obcy LIKE ? ESCAPE '\\')";
        array_push($params, $like, $like, $like);
    }
    $sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int)(db_one("SELECT COUNT(*) AS cnt FROM ezd_rpw $sql_where", $params)['cnt'] ?? 0);
    $rows  = db_all(
        "SELECT id, rpw_nr, rok, data_wplywu, typ, nadawca, znak_obcy, opis, status, sprawa_id
         FROM ezd_rpw $sql_where ORDER BY rok DESC, rpw_nr DESC LIMIT ? OFFSET ?",
        array_merge($params, [$per, ($page - 1) * $per])
    );
    api_json(['data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per,
                                          'pages' => max(1, (int)ceil($total / $per))]]);
}

if ($method !== 'POST') api_error('Method Not Allowed.', 405);
api_require('ezd:write');

global $api_current_key;
$user_id = (int)($api_current_key['created_by'] ?? 0);
if ($user_id <= 0) api_error('Klucz nie ma przypisanego użytkownika (created_by).', 403);

$in = $_POST;
if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $json = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($json)) api_error('Nieprawidłowy JSON.', 400);
    $in = $json;
}
$str = static fn(string $k): string => trim((string)($in[$k] ?? ''));

try {
    if ($resource === 'sprawy') {
        if ($str('title') === '' || (int)($in['teczka_id'] ?? 0) <= 0) api_error('Wymagane: teczka_id, title.', 422);
        if (mb_strlen($str('title')) > 300) api_error('Tytuł za długi (max 300).', 422);
        $prio = in_array($str('priority'), ['low', 'normal', 'high', 'urgent'], true) ? $str('priority') : 'normal';
        $id = ezd_sprawa_create([
            'teczka_id' => (int)$in['teczka_id'], 'title' => $str('title'), 'description' => $str('description'),
            'priority' => $prio, 'owner_id' => (int)($in['owner_id'] ?? 0) ?: $user_id,
            'deadline' => $str('deadline'), 'ref_type' => null, 'ref_id' => null,
        ], $user_id);
        $znak = (string)(db_one("SELECT znak_sprawy FROM ezd_sprawy WHERE id=?", [$id])['znak_sprawy'] ?? '');
        api_audit('create', 'ezd_sprawy', $id);
        api_json(['ok' => true, 'id' => $id, 'znak_sprawy' => $znak], 201);
    }

    if ($resource === 'pisma') {
        if ($str('title') === '' || (int)($in['sprawa_id'] ?? 0) <= 0) api_error('Wymagane: sprawa_id, title.', 422);
        $kier = in_array($str('kierunek'), ['przychodzace', 'wychodzace', 'wewnetrzne'], true) ? $str('kierunek') : 'przychodzace';
        $id = ezd_pismo_create([
            'sprawa_id' => (int)$in['sprawa_id'], 'title' => $str('title'), 'kierunek' => $kier,
            'tresc' => $str('tresc'), 'nadawca' => $str('nadawca'), 'odbiorca' => $str('odbiorca'),
            'data_pisma' => $str('data_pisma'), 'data_wplywu' => $str('data_wplywu'),
            'rodzaj_medium' => $str('rodzaj_medium'), 'owner_id' => $user_id,
        ], $user_id);
        api_audit('create', 'ezd_pisma', $id);
        api_json(['ok' => true, 'id' => $id], 201);
    }

    if ($resource === 'rpw') {
        $typ = array_key_exists($str('typ'), EZD_RPW_TYPY) ? $str('typ') : 'list';
        $data = $str('data_wplywu');
        if ($data !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) api_error('data_wplywu: format YYYY-MM-DD.', 422);
        $r = ezd_rpw_create([
            'typ' => $typ, 'nadawca' => $str('nadawca'), 'opis' => $str('opis'),
            'znak_obcy' => $str('znak_obcy'), 'uwagi' => $str('uwagi'), 'data_wplywu' => $data,
        ], $user_id);
        api_audit('create', 'ezd_rpw', (int)$r['id']);
        api_json(['ok' => true] + $r, 201);
    }
} catch (\RuntimeException $e) {
    api_error($e->getMessage(), 422);
}

api_error('Nieznany resource (POST: sprawy, pisma, rpw).', 404);
