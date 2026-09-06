<?php
/**
 * api/v1/srs.php — REST API Systemu Rezerwacji Sal (SRS).
 * Bearer token (Authorization: Bearer <key>) lub ?api_key=<key>.
 * Uprawnienia: srs:read (odczyt), srs:write (zgłoszenie rezerwacji).
 *
 * GET  ?resource=resources                                    → lista zasobów aktywnych
 * GET  ?resource=resources&id=N                                → pojedynczy zasób
 * GET  ?resource=reservations&from=&to=&resource_id=&status=   → lista rezerwacji (filtry opcjonalne)
 * POST ?resource=reservations                                  → nowa prośba o rezerwację (JSON body)
 *
 * Zasilenie zewnętrznych wdrożeń TI/Dydaktyka (gdy nie działają na tym samym
 * serwerze co SZO — "połączenie po API", równolegle do bezpośredniego
 * wywołania res_k30_available() w karty30/schedules/add.php, gdy wszystko
 * działa na jednym serwerze).
 */
declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/modules/srs/logic/srs.php';

api_auth_migrate();
resources_migrate();

$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$resource = (string)($_GET['resource'] ?? '');

if ($method === 'GET') { api_require('srs:read'); }
else                   { api_require('srs:write'); }

if ($resource === 'resources') {
    if ($method !== 'GET') api_error('Nieobsługiwana metoda dla resources.', 405);

    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $r = res_get($id);
        if (!$r || !$r['is_active']) api_error('Nie znaleziono zasobu.', 404);
        api_json(['data' => $r]);
    }
    api_json(['data' => res_list(0, true)]);
}

if ($resource === 'reservations') {
    if ($method === 'GET') {
        $status      = (string)($_GET['status'] ?? '');
        $date_from   = (string)($_GET['from'] ?? '');
        $date_to     = (string)($_GET['to'] ?? '');
        $resource_id = (int)($_GET['resource_id'] ?? 0);

        $where = []; $params = [];
        if ($status !== '' && isset(RES_STATUSES[$status])) { $where[] = 'rr.status=?'; $params[] = $status; }
        if ($date_from !== '') { $where[] = 'rr.date_to>=?';   $params[] = $date_from; }
        if ($date_to !== '')   { $where[] = 'rr.date_from<=?'; $params[] = $date_to; }
        if ($resource_id)      { $where[] = 'rr.resource_id=?'; $params[] = $resource_id; }
        $sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = db_all(
            "SELECT rr.id, rr.resource_id, r.name AS resource_name, rr.date_from, rr.date_to,
                    rr.time_from, rr.time_to, rr.status, rr.purpose, rr.created_at
             FROM resource_reservations rr
             JOIN resources r ON r.id = rr.resource_id
             $sql_where ORDER BY rr.date_from, rr.time_from",
            $params
        );
        api_json(['data' => $rows]);
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($body)) api_error('Nieprawidłowy JSON.', 400);

        $resource_id = (int)($body['resource_id'] ?? 0);
        $date_from   = trim((string)($body['date_from'] ?? ''));
        $date_to     = trim((string)($body['date_to']   ?? ''));
        $requester   = (int)($body['requester_user_id'] ?? 0);

        if (!$resource_id || !res_get($resource_id)) api_error('Podaj poprawne resource_id.', 400);
        if (!$date_from || !$date_to)                api_error('Podaj date_from i date_to (YYYY-MM-DD).', 400);
        if ($date_to < $date_from)                   api_error('date_to nie może być wcześniejsze niż date_from.', 400);
        if (!$requester || !db_one("SELECT id FROM users WHERE id=? AND is_active=1", [$requester])) {
            api_error('Podaj requester_user_id — istniejącego, aktywnego użytkownika SZO w imieniu którego składana jest prośba.', 400);
        }

        $time_from = trim((string)($body['time_from'] ?? ''));
        $time_to   = trim((string)($body['time_to']   ?? ''));

        $conflicts = res_conflicts($resource_id, $date_from, $date_to);
        if ($conflicts) api_error('Zasób jest już zajęty w tym terminie.', 409);

        $status         = (res_get($resource_id)['requires_approval'] ?? 0) ? 'pending_admin' : 'rezerwacja';
        $reservation_id = db_insert('resource_reservations', [
            'resource_id'  => $resource_id,
            'user_id'      => $requester,
            'date_from'    => $date_from,
            'date_to'      => $date_to,
            'time_from'    => $time_from,
            'time_to'      => $time_to,
            'purpose'      => trim((string)($body['purpose'] ?? '')),
            'extra_fields' => json_encode([], JSON_UNESCAPED_UNICODE),
            'status'       => $status,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        res_log($reservation_id, 0, '', $status, 'Wniosek złożony przez API (' . ($api_current_key['name'] ?? '?') . ')');
        res_notify_event($reservation_id, $status);

        api_json(['data' => res_reservation_get($reservation_id)], 201);
    }

    api_error('Nieobsługiwana metoda.', 405);
}

api_error('Nieznany zasób. Dostępne: resources, reservations', 404);
