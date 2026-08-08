<?php
/**
 * REST API — SZO Planner
 * Bearer token (Authorization: Bearer <key>) lub ?api_key=<key>.
 * Uprawnienia: planner:read (odczyt), planner:write (zapis).
 *
 * Routing: ?r=<zasób>&id=<N>&action=<akcja>  + metoda HTTP
 *
 * Zasoby (r):
 *   rooms            — sale
 *   laptops          — pula laptopów
 *   meetings         — linki konferencyjne (id = session_id)
 *   tech-paths       — ścieżki technologiczne
 *   sessions         — sesje/lekcje (rozszerzone o room_id, mode, block_type, draft_id)
 *   instructors      — obciążenie i dostępność kadry (id = user_id)
 *   check-conflicts  — batch-walidacja sesji
 *   drafts           — wersjonowanie planów
 *   cycle-templates  — szablony cykli
 *   audit            — log zmian
 *   wallet           — portfele żetonów
 *   prices           — cennik żetonów
 *   basket           — koszyk uczestnika
 *   szo-blocks       — biblioteka bloków modułowych (k30_szo_blocks)
 *   szo-schedules    — harmonogramy SZO (k30_szo_schedules + days)
 *   szo-solve        — mostek do Python solvera (FastAPI :8765)
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/ti_planner_ext.php';

// ── CORS (JavaClient i aplikacje zewnętrzne) ──────────────────────────────────
$cors_origins = defined('PLANNER_CORS_ORIGINS') ? PLANNER_CORS_ORIGINS : '*';
header('Access-Control-Allow-Origin: '    . $cors_origins);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-HTTP-Method-Override');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Metoda HTTP (obsługa nadpisania) ───────────────────────────────────────────
$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$override = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ($_GET['_method'] ?? ''));
if ($override !== '') $method = $override;

// ── Auth ───────────────────────────────────────────────────────────────────────
api_auth_migrate();
try { ti_planner_ext_migrate(); } catch (\Throwable $e) { api_error('Błąd inicjalizacji bazy: ' . $e->getMessage(), 500); }

if ($method === 'GET') { api_require('planner:read'); }
else                   { api_require('planner:write'); }

// ── Parametry routingu ─────────────────────────────────────────────────────────
$resource = strtolower(trim($_GET['r'] ?? ''));
$id       = (int)($_GET['id'] ?? 0);
$action   = strtolower(trim($_GET['action'] ?? ''));

if ($resource === '') {
    api_json(['data' => ['resources' => ['rooms','laptops','meetings','tech-paths','sessions','instructors','check-conflicts','drafts','cycle-templates','audit','wallet','prices','basket']]]);
}

/** Body żądania: JSON lub form-encoded. */
function pl_input(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') return [];
        $j = json_decode($raw, true);
        if (!is_array($j)) api_error('Nieprawidłowe ciało JSON.', 400);
        return $j;
    }
    return $_POST;
}

/** Rzutuje typy bool/int z input na właściwe wartości PHP. */
function pl_i(string $key, mixed $default = null): mixed {
    $d = pl_input();
    return $d[$key] ?? ($_GET[$key] ?? $default);
}

// ── ROUTER ─────────────────────────────────────────────────────────────────────

match ($resource) {

    /* ── SALE ──────────────────────────────────────────────────────────── */
    'rooms' => (function () use ($method, $id, $action) {
        if ($method === 'GET' && $id && $action === 'availability') {
            $date = trim($_GET['date'] ?? date('Y-m-d'));
            $from = trim($_GET['from'] ?? '00:00');
            $to   = trim($_GET['to']   ?? '23:59');
            api_json(['data' => pl_room_availability($id, $date, $from, $to)]);
        }
        if ($method === 'GET' && $id)   api_json(['data' => pl_room_get($id) ?? api_error('Nie znaleziono sali.', 404)]);
        if ($method === 'GET')          api_json(['data' => pl_rooms_list(['is_active' => isset($_GET['all']) ? null : 1, 'mode' => $_GET['mode'] ?? null])]);
        if ($method === 'POST')         { $new_id = pl_room_save(pl_input()); api_json(['data' => pl_room_get($new_id)], 201); }
        if ($method === 'PUT' && $id)   { pl_room_save(pl_input(), $id); api_json(['data' => pl_room_get($id)]); }
        api_error('Nieobsługiwana metoda.', 405);
    })(),

    /* ── LAPTOPY ───────────────────────────────────────────────────────── */
    'laptops' => (function () use ($method, $id, $action) {
        if ($method === 'GET')                      api_json(['data' => pl_laptops_list()]);
        if ($method === 'POST' && $id && $action === 'loan') {
            $d = pl_input();
            try { $loan_id = pl_laptop_loan($id, (int)($d['session_id'] ?? 0), ($d['client_id'] ?? null) ? (int)$d['client_id'] : null); }
            catch (\RuntimeException $e) { api_error($e->getMessage(), 409); }
            api_json(['data' => ['loan_id' => $loan_id]], 201);
        }
        if ($method === 'PUT' && $id && $action === 'return') {
            $ok = pl_laptop_return($id);
            api_json(['data' => ['returned' => $ok]]);
        }
        api_error('Nieobsługiwana metoda lub brak parametrów.', 405);
    })(),

    /* ── SPOTKANIA ONLINE (id = session_id) ────────────────────────────── */
    'meetings' => (function () use ($method, $id) {
        if (!$id) api_error('Wymagany parametr ?id= (session_id).', 400);
        if ($method === 'GET')    api_json(['data' => pl_meeting_get($id)]);
        if ($method === 'POST')   api_json(['data' => ['meeting_id' => pl_meeting_save($id, pl_input())]], 201);
        if ($method === 'DELETE') { pl_meeting_delete($id); api_json(['data' => ['deleted' => true]]); }
        api_error('Nieobsługiwana metoda.', 405);
    })(),

    /* ── ŚCIEŻKI TECHNOLOGICZNE ────────────────────────────────────────── */
    'tech-paths' => (function () use ($method, $id) {
        if ($method === 'GET')        api_json(['data' => pl_tech_paths_list()]);
        if ($method === 'POST')       { $new_id = pl_tech_path_save(pl_input()); api_json(['data' => ['id' => $new_id]], 201); }
        if ($method === 'PUT' && $id) { pl_tech_path_save(pl_input(), $id); api_json(['data' => ['id' => $id]]); }
        api_error('Nieobsługiwana metoda.', 405);
    })(),

    /* ── SESJE ─────────────────────────────────────────────────────────── */
    'sessions' => (function () use ($method, $id, $action) {
        if ($method === 'GET' && $id && $action === 'conflicts') {
            $p = array_merge((array)pl_input(), $_GET);
            $s = db_one("SELECT * FROM k30_ti_sessions WHERE id=?", [$id]);
            if ($s) $p = array_merge(['lesson_date' => $s['lesson_date'], 'time_from' => $s['time_from'], 'time_to' => $s['time_to'], 'course_id' => $s['course_id'], 'room_id' => $s['room_id'] ?? 0, 'skip_id' => $id], $p);
            api_json(['data' => pl_check_conflicts($p)]);
        }
        if ($method === 'POST' && $id && $action === 'staff') {
            $d    = pl_input();
            $uid  = (int)($d['user_id'] ?? 0);
            $role = $d['role'] ?? 'mentor';
            if (!$uid) api_error('Wymagany user_id.', 400);
            pl_session_staff_add($id, $uid, $role, (bool)($d['is_primary'] ?? false));
            api_json(['data' => pl_session_staff_list($id)]);
        }
        if ($method === 'DELETE' && $id && $action === 'staff') {
            $uid = (int)(pl_input()['user_id'] ?? $_GET['user_id'] ?? 0);
            if (!$uid) api_error('Wymagany user_id.', 400);
            pl_session_staff_remove($id, $uid);
            api_json(['data' => pl_session_staff_list($id)]);
        }
        if ($method === 'GET') {
            $where = ['1=1']; $params = [];
            if (!empty($_GET['draft']))     { $where[] = 'draft_id=?';      $params[] = (int)$_GET['draft']; }
            elseif (!isset($_GET['draft'])) { $where[] = 'draft_id IS NULL'; }
            if (!empty($_GET['course']))    { $where[] = 'course_id=?';     $params[] = (int)$_GET['course']; }
            if (!empty($_GET['room']))      { $where[] = 'room_id=?';       $params[] = (int)$_GET['room']; }
            if (!empty($_GET['mode']))      { $where[] = 'mode=?';          $params[] = $_GET['mode']; }
            if (!empty($_GET['date_from'])) { $where[] = 'lesson_date>=?';  $params[] = $_GET['date_from']; }
            if (!empty($_GET['date_to']))   { $where[] = 'lesson_date<=?';  $params[] = $_GET['date_to']; }
            $limit  = min(500, max(1, (int)($_GET['limit'] ?? 100)));
            $cursor = (int)($_GET['cursor'] ?? 0);
            if ($cursor) { $where[] = 'id>?'; $params[] = $cursor; }
            $rows = db_all("SELECT * FROM k30_ti_sessions WHERE " . implode(' AND ', $where) . " ORDER BY lesson_date,time_from LIMIT " . ($limit + 1), $params);
            $next = count($rows) > $limit ? array_pop($rows)['id'] : null;
            api_json(['data' => $rows, 'meta' => ['next_cursor' => $next]]);
        }
        if ($method === 'POST') {
            $d = pl_input();
            // Walidacja HARD
            $conflicts = pl_check_conflicts($d);
            if ($conflicts['hard']) api_error(json_encode(['ok' => false, 'error' => $conflicts['hard'][0]['code'] ?? 'CONFLICT', 'msg' => $conflicts['hard'][0]['msg'] ?? 'Konflikt.', 'details' => $conflicts['hard']]), 409);
            // Wstaw sesję
            $allowed = ['course_id','lesson_date','time_from','time_to','duration_min','status','topic','notes','room_id','mode','block_type','draft_id','meeting_url'];
            $fields  = array_filter(array_intersect_key($d, array_flip($allowed)), fn($v) => $v !== null && $v !== '');
            $fields['created_by'] = $GLOBALS['api_current_key']['created_by'] ?? null;
            $cols = implode(',', array_keys($fields));
            $phs  = implode(',', array_fill(0, count($fields), '?'));
            db_exec("INSERT INTO k30_ti_sessions ($cols) VALUES ($phs)", array_values($fields));
            $new_id = (int)db()->lastInsertId();
            pl_audit('session', $new_id, 'create', [], $d, $fields['created_by'] ?? null);
            api_json(['data' => db_one("SELECT * FROM k30_ti_sessions WHERE id=?", [$new_id])], 201);
        }
        if ($method === 'PUT' && $id) {
            $d        = pl_input();
            $existing = db_one("SELECT * FROM k30_ti_sessions WHERE id=?", [$id]);
            if (!$existing) api_error('Nie znaleziono sesji.', 404);
            $conflicts = pl_check_conflicts(array_merge($existing, $d, ['skip_id' => $id]));
            if ($conflicts['hard']) api_error(json_encode(['ok' => false, 'error' => $conflicts['hard'][0]['code'] ?? 'CONFLICT', 'details' => $conflicts['hard']]), 409);
            $allowed = ['lesson_date','time_from','time_to','duration_min','status','topic','notes','room_id','mode','block_type','draft_id','meeting_url'];
            $fields  = array_filter(array_intersect_key($d, array_flip($allowed)));
            if (!$fields) api_error('Brak pól do aktualizacji.', 400);
            $fields['updated_at'] = date('Y-m-d H:i:s');
            $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
            db_exec("UPDATE k30_ti_sessions SET $sets WHERE id=?", [...array_values($fields), $id]);
            pl_audit('session', $id, 'update', $existing, $d);
            api_json(['data' => db_one("SELECT * FROM k30_ti_sessions WHERE id=?", [$id])]);
        }
        api_error('Nieobsługiwana metoda lub brak id.', 405);
    })(),

    /* ── PROWADZĄCY ─────────────────────────────────────────────────────── */
    'instructors' => (function () use ($method, $id, $action) {
        if (!$id) api_error('Wymagany ?id= (user_id).', 400);
        $week = trim($_GET['week'] ?? date('o-\WW'));
        if ($method === 'GET' && $action === 'workload')      api_json(['data' => pl_instructor_workload($id, $week)]);
        if ($method === 'GET' && $action === 'availability')  api_json(['data' => pl_instructor_availability($id, $week)]);
        api_error('Wymagany ?action=workload lub ?action=availability.', 400);
    })(),

    /* ── BATCH WALIDACJA KONFLIKTÓW ─────────────────────────────────────── */
    'check-conflicts' => (function () use ($method) {
        if ($method !== 'POST') api_error('Tylko POST.', 405);
        $body = pl_input();
        if (!is_array($body) || !isset($body[0])) {
            // Pojedynczy obiekt
            api_json(['data' => pl_check_conflicts($body)]);
        }
        // Tablica sesji
        $results = [];
        foreach ($body as $p) {
            $results[] = ['params' => $p, 'result' => pl_check_conflicts($p)];
        }
        api_json(['data' => $results]);
    })(),

    /* ── DRAFTY ─────────────────────────────────────────────────────────── */
    'drafts' => (function () use ($method, $id, $action) {
        $by = (int)($GLOBALS['api_current_key']['created_by'] ?? 0) ?: null;
        if ($method === 'GET' && $id && $action === 'diff') {
            $base = (int)($_GET['base_id'] ?? 0);
            api_json(['data' => pl_draft_diff($id, $base)]);
        }
        if ($method === 'GET' && $id)     api_json(['data' => pl_draft_get($id) ?? api_error('Nie znaleziono draftu.', 404)]);
        if ($method === 'GET')            api_json(['data' => pl_drafts_list()]);
        if ($method === 'POST' && !$id)   { $new_id = pl_draft_create(pl_input(), $by ?? 0); api_json(['data' => pl_draft_get($new_id)], 201); }
        if ($method === 'PUT'  && $id)    { pl_draft_update($id, pl_input()); api_json(['data' => pl_draft_get($id)]); }
        if ($method === 'POST' && $id && $action === 'fork') {
            try { $new_id = pl_draft_fork($id, $by ?? 0); api_json(['data' => pl_draft_get($new_id)], 201); }
            catch (\RuntimeException $e) { api_error($e->getMessage(), 422); }
        }
        if ($method === 'POST' && $id && $action === 'publish') {
            try { pl_draft_publish($id, $by ?? 0); api_json(['data' => pl_draft_get($id)]); }
            catch (\RuntimeException $e) { api_error($e->getMessage(), 422); }
        }
        api_error('Nieobsługiwana kombinacja metoda/id/akcja.', 405);
    })(),

    /* ── SZABLONY CYKLI ─────────────────────────────────────────────────── */
    'cycle-templates' => (function () use ($method, $id, $action) {
        $by = (int)($GLOBALS['api_current_key']['created_by'] ?? 0) ?: null;
        if ($method === 'GET')        api_json(['data' => pl_cycle_templates_list()]);
        if ($method === 'POST' && !$id) { $new_id = pl_cycle_template_save(pl_input(), null, $by); api_json(['data' => ['id' => $new_id]], 201); }
        if ($method === 'PUT'  && $id)  { pl_cycle_template_save(pl_input(), $id); api_json(['data' => ['id' => $id]]); }
        if ($method === 'POST' && $id && $action === 'expand') {
            $d = array_merge(pl_input(), $_GET);
            $start  = trim($d['start'] ?? date('Y-m-d'));
            $course = (int)($d['course'] ?? 0);
            if (!$course) api_error('Wymagany parametr ?course=<course_id>.', 400);
            try { api_json(['data' => pl_cycle_template_expand($id, $course, $start)]); }
            catch (\RuntimeException $e) { api_error($e->getMessage(), 422); }
        }
        api_error('Nieobsługiwana metoda.', 405);
    })(),

    /* ── AUDIT ──────────────────────────────────────────────────────────── */
    'audit' => (function () use ($method) {
        if ($method !== 'GET') api_error('Tylko GET.', 405);
        api_json(['data' => pl_audit_list([
            'entity'     => $_GET['entity'] ?? null,
            'entity_id'  => $_GET['entity_id'] ?? null,
            'changed_by' => $_GET['changed_by'] ?? null,
            'from'       => $_GET['from'] ?? null,
            'to'         => $_GET['to'] ?? null,
        ])]);
    })(),

    /* ── PORTFEL ŻETONÓW ─────────────────────────────────────────────────── */
    'wallet' => (function () use ($method, $action) {
        $client_id = (int)($_GET['client_id'] ?? pl_input()['client_id'] ?? 0);
        if (!$client_id) api_error('Wymagany client_id.', 400);

        if ($method === 'GET') {
            $w = pl_wallet_get_or_create($client_id);
            $txs = pl_wallet_transactions((int)$w['id'], (int)($_GET['limit'] ?? 20));
            api_json(['data' => array_merge($w, ['transactions' => $txs])]);
        }
        if ($method === 'POST' && $action === 'grant') {
            $d = pl_input();
            $amount = (int)($d['amount'] ?? 0);
            $reason = trim($d['reason'] ?? 'grant');
            if ($amount <= 0) api_error('amount musi być > 0.', 400);
            $by = (int)($GLOBALS['api_current_key']['created_by'] ?? 0) ?: null;
            try { pl_tokens_grant($client_id, $amount, $reason, $by); }
            catch (\RuntimeException $e) { api_error($e->getMessage(), 422); }
            api_json(['data' => pl_wallet_get_or_create($client_id)]);
        }
        api_error('Nieobsługiwana metoda.', 405);
    })(),

    /* ── CENNIK ŻETONÓW ─────────────────────────────────────────────────── */
    'prices' => (function () use ($method, $id) {
        if ($method === 'GET') api_json(['data' => pl_prices_list([
            'course_id' => $_GET['course'] ?? null,
            'path_id'   => $_GET['path'] ?? null,
            'mentor_id' => $_GET['mentor'] ?? null,
        ])]);
        if ($method === 'POST')       { $new_id = pl_price_save(pl_input()); api_json(['data' => ['id' => $new_id]], 201); }
        if ($method === 'PUT' && $id) { pl_price_save(pl_input(), $id); api_json(['data' => ['id' => $id]]); }
        if ($method === 'DELETE' && $id) {
            db_exec("DELETE FROM k30_pl_token_prices WHERE id=?", [$id]);
            api_json(['data' => ['deleted' => true]]);
        }
        api_error('Nieobsługiwana metoda.', 405);
    })(),

    /* ── KOSZYK ─────────────────────────────────────────────────────────── */
    'basket' => (function () use ($method, $id, $action) {
        $client_id = (int)(pl_input()['client_id'] ?? $_GET['client_id'] ?? 0);
        if (!$client_id) api_error('Wymagany client_id.', 400);

        if ($method === 'GET') {
            api_json(['data' => pl_basket_get($client_id)]);
        }
        if ($method === 'POST' && $action === 'add-item') {
            $d = pl_input();
            $session_id = (int)($d['session_id'] ?? 0);
            $mentor_id  = !empty($d['mentor_id']) ? (int)$d['mentor_id'] : null;
            if (!$session_id) api_error('Wymagany session_id.', 400);
            try { api_json(['data' => pl_basket_add_item($client_id, $session_id, $mentor_id)], 201); }
            catch (\RuntimeException $e) { api_error($e->getMessage(), 409); }
        }
        if ($method === 'DELETE' && $id) {
            try { pl_basket_remove_item($client_id, $id); api_json(['data' => pl_basket_get($client_id)]); }
            catch (\RuntimeException $e) { api_error($e->getMessage(), 404); }
        }
        if ($method === 'POST' && $action === 'checkout') {
            try { api_json(['data' => pl_basket_checkout($client_id)]); }
            catch (\RuntimeException $e) { api_error($e->getMessage(), match ($e->getMessage()) {
                'EMPTY_BASKET'          => 422,
                'INSUFFICIENT_TOKENS'   => 402,
                'SLOT_FULL_AT_CHECKOUT' => 409,
                default                 => 422,
            }); }
        }
        if ($method === 'POST' && $action === 'cancel') {
            try { pl_basket_cancel($client_id); api_json(['data' => ['cancelled' => true]]); }
            catch (\RuntimeException $e) { api_error($e->getMessage(), 422); }
        }
        api_error('Nieobsługiwana kombinacja metoda/akcja.', 405);
    })(),

    /* ── BIBLIOTEKA BLOKÓW SZO ──────────────────────────────────────────── */
    'szo-blocks' => (function () use ($method, $id) {
        require_once dirname(__DIR__, 2) . '/includes/ti_planner.php';
        ti_planner_migrate();
        $uid = (int)($GLOBALS['api_current_key']['created_by'] ?? 0);

        if ($method === 'GET' && $id) {
            $b = szo_block_get($id);
            if (!$b) api_error('Blok nie istnieje.', 404);
            $b['tags']      = json_decode($b['tags']      ?? '[]', true);
            $b['resources'] = json_decode($b['resources'] ?? '[]', true);
            api_json(['data' => $b]);
        }
        if ($method === 'GET') {
            $instr = $uid ?: (int)($_GET['instructor_id'] ?? 0);
            if (!$instr) api_error('Wymagany instructor_id lub token właściciela.', 400);
            $blocks = szo_blocks_list($instr);
            foreach ($blocks as &$b) {
                $b['tags']      = json_decode($b['tags']      ?? '[]', true);
                $b['resources'] = json_decode($b['resources'] ?? '[]', true);
            }
            api_json(['data' => $blocks]);
        }
        if ($method === 'POST') {
            $d = pl_input();
            if (!$uid && !isset($d['instructor_id'])) api_error('Wymagany instructor_id.', 400);
            $d['instructor_id'] = $uid ?: (int)$d['instructor_id'];
            if (empty($d['title'])) api_error('Pole title jest wymagane.', 400);
            if (isset($d['tags']) && is_string($d['tags'])) {
                $d['tags'] = array_filter(array_map('trim', explode(',', $d['tags'])));
            }
            $new_id = szo_block_save($d);
            api_json(['data' => szo_block_get($new_id)], 201);
        }
        if ($method === 'PUT' && $id) {
            $existing = szo_block_get($id);
            if (!$existing) api_error('Blok nie istnieje.', 404);
            if ($uid && (int)$existing['instructor_id'] !== $uid) api_error('Brak dostępu.', 403);
            $d = pl_input();
            if (isset($d['tags']) && is_string($d['tags'])) {
                $d['tags'] = array_filter(array_map('trim', explode(',', $d['tags'])));
            }
            szo_block_save($d, $id);
            $b = szo_block_get($id);
            $b['tags']      = json_decode($b['tags']      ?? '[]', true);
            $b['resources'] = json_decode($b['resources'] ?? '[]', true);
            api_json(['data' => $b]);
        }
        if ($method === 'DELETE' && $id) {
            $existing = szo_block_get($id);
            if (!$existing) api_error('Blok nie istnieje.', 404);
            if ($uid && (int)$existing['instructor_id'] !== $uid) api_error('Brak dostępu.', 403);
            szo_block_delete($id, (int)$existing['instructor_id']);
            api_json(['data' => ['deleted' => true]]);
        }
        api_error('Nieobsługiwana metoda.', 405);
    })(),

    /* ── HARMONOGRAMY SZO ───────────────────────────────────────────────── */
    'szo-schedules' => (function () use ($method, $id, $action) {
        require_once dirname(__DIR__, 2) . '/includes/ti_planner.php';
        ti_planner_migrate();
        $uid = (int)($GLOBALS['api_current_key']['created_by'] ?? 0);
        if (!$uid && !isset($_GET['instructor_id'])) api_error('Wymagany token właściciela lub ?instructor_id=.', 400);
        $instr = $uid ?: (int)$_GET['instructor_id'];

        if ($method === 'GET' && $id) {
            $s = szo_schedule_get($id, $instr);
            if (!$s) api_error('Harmonogram nie istnieje.', 404);
            $s['settings'] = json_decode($s['settings_json'] ?? '{}', true);
            api_json(['data' => $s]);
        }
        if ($method === 'GET') {
            $list = szo_schedules_list($instr);
            foreach ($list as &$s) {
                $s['settings'] = json_decode($s['settings_json'] ?? '{}', true);
            }
            api_json(['data' => $list]);
        }
        if ($method === 'POST' && !$id) {
            $d = pl_input();
            $title    = trim($d['title'] ?? '');
            $num_days = max(2, min(7, (int)($d['num_days'] ?? 3)));
            if ($title === '') api_error('Pole title jest wymagane.', 400);
            $sid = szo_schedule_create($instr, $title, $num_days);
            api_json(['data' => szo_schedule_get($sid, $instr)], 201);
        }
        if ($method === 'PUT' && $id && $action === 'days') {
            $d    = pl_input();
            $days = $d['days'] ?? [];
            if (!is_array($days)) api_error('Pole days musi być tablicą.', 400);
            if (!szo_schedule_save_days($id, $instr, $days)) api_error('Harmonogram nie istnieje.', 404);
            api_json(['data' => szo_schedule_get($id, $instr)]);
        }
        if ($method === 'DELETE' && $id) {
            if (!db_one("SELECT id FROM k30_szo_schedules WHERE id=? AND instructor_id=?", [$id, $instr])) {
                api_error('Harmonogram nie istnieje.', 404);
            }
            szo_schedule_delete($id, $instr);
            api_json(['data' => ['deleted' => true]]);
        }
        api_error('Nieobsługiwana metoda lub parametry.', 405);
    })(),

    /* ── SOLVER (mostek do FastAPI Python :8765) ────────────────────────── */
    'szo-solve' => (function () use ($method) {
        if ($method !== 'POST') api_error('Tylko POST.', 405);
        require_once dirname(__DIR__, 2) . '/includes/ti_planner.php';
        ti_planner_migrate();

        $d = pl_input();
        $schedule_id = (int)($d['schedule_id'] ?? 0);
        $mode        = in_array($d['mode'] ?? '', ['auto','mpp'], true) ? $d['mode'] : 'auto';
        $uid         = (int)($GLOBALS['api_current_key']['created_by'] ?? 0);

        if (!$schedule_id) api_error('Wymagany schedule_id.', 400);

        $schedule = $uid
            ? szo_schedule_get($schedule_id, $uid)
            : db_one("SELECT * FROM k30_szo_schedules WHERE id=?", [$schedule_id]);
        if (!$schedule) api_error('Harmonogram nie istnieje.', 404);

        $instr = (int)$schedule['instructor_id'];
        $blocks = szo_blocks_list($instr);

        // Zbuduj payload dla Python solvera
        $payload_blocks = [];
        foreach ($blocks as $b) {
            $payload_blocks[] = [
                'id'              => (int)$b['id'],
                'title'           => $b['title'],
                'category'        => $b['category'],
                'duration_min'    => (int)$b['duration_min'],
                'difficulty'      => (int)$b['difficulty'],
                'energy_impact'   => (int)$b['energy_impact'],
                'min_break_after' => (int)$b['min_break_after'],
                'locked'          => (bool)$b['locked'],
            ];
        }

        $num_days = (int)$schedule['num_days'];
        $phases   = ['foundation','intensive','synthesis','continuation','continuation','continuation','continuation'];
        $payload_days = [];
        foreach ($schedule['days'] as $day) {
            $payload_days[] = [
                'day_number' => (int)$day['day_number'],
                'phase'      => $day['phase'] ?? ($phases[$day['day_number'] - 1] ?? 'continuation'),
                'day_date'   => $day['day_date'] ?? null,
            ];
        }

        // Istniejące sloty (z block_order w dniach) → existing_slots dla MPP
        $existing_slots = [];
        if ($mode === 'mpp') {
            $start_time = '09:00';
            $settings   = json_decode($schedule['settings_json'] ?? '{}', true) ?: [];
            $start_min  = 0;
            if (!empty($settings['dailyStartTime'])) {
                [$h, $m] = explode(':', $settings['dailyStartTime']);
                $start_min = (int)$h * 60 + (int)$m;
            }
            foreach ($schedule['days'] as $day) {
                $cursor = $start_min ?: 540; // 09:00
                foreach ($day['block_order'] as $bid) {
                    $bid = (int)$bid;
                    $blk = null;
                    foreach ($blocks as $b) { if ((int)$b['id'] === $bid) { $blk = $b; break; } }
                    if (!$blk) continue;
                    $existing_slots[] = [
                        'block_id'   => $bid,
                        'day_number' => (int)$day['day_number'],
                        'start_min'  => $cursor,
                        'locked'     => false,
                    ];
                    $cursor += (int)$blk['duration_min'] + 10;
                }
            }
        }

        $settings_raw = json_decode($schedule['settings_json'] ?? '{}', true) ?: [];
        $daily_settings = [
            'start_time'         => $settings_raw['dailyStartTime']  ?? '09:00',
            'end_time'           => $settings_raw['dailyEndTime']    ?? '17:00',
            'max_minutes'        => (int)($settings_raw['maxDailyMinutes'] ?? 480),
            'lunch_at'           => $settings_raw['lunchAt']         ?? '12:30',
            'lunch_duration'     => (int)($settings_raw['lunchDuration'] ?? 60),
            'break_interval_max' => (int)($settings_raw['breakIntervalMax'] ?? 90),
            'auto_buffer_min'    => (int)($settings_raw['autoBufferMin'] ?? 10),
        ];

        $python_payload = json_encode([
            'blocks'         => $payload_blocks,
            'days'           => $payload_days,
            'daily_settings' => $daily_settings,
            'b2b_rules'      => [],
            'mode'           => $mode,
            'existing_slots' => $existing_slots,
        ]);

        $engine_url = defined('SZOPLANNER_ENGINE') ? SZOPLANNER_ENGINE : 'http://127.0.0.1:8765';
        $ctx = stream_context_create([
            'http' => [
                'method'         => 'POST',
                'header'         => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content'        => $python_payload,
                'timeout'        => 30,
                'ignore_errors'  => true,
            ],
        ]);
        $resp = @file_get_contents($engine_url . '/solve', false, $ctx);
        if ($resp === false) {
            api_error('Python engine niedostępny. Uruchom: uvicorn main:app --port 8765 w engine-python/.', 502);
        }
        $result = json_decode($resp, true);
        if (!is_array($result)) api_error('Nieprawidłowa odpowiedź solvera.', 502);

        // Przetłumacz sloty solvera na block_order per dzień i zapisz
        if (!empty($result['ok']) && !empty($result['slots'])) {
            $day_orders = [];
            foreach ($result['slots'] as $slot) {
                $dn  = (int)$slot['day_number'];
                $bid = (int)$slot['block_id'];
                $day_orders[$dn][] = ['bid' => $bid, 'start' => (int)$slot['start_min']];
            }
            $days_to_save = [];
            foreach ($schedule['days'] as $day) {
                $dn = (int)$day['day_number'];
                $order_raw = $day_orders[$dn] ?? [];
                usort($order_raw, fn($a, $b) => $a['start'] - $b['start']);
                $days_to_save[] = [
                    'day_number'  => $dn,
                    'phase'       => $day['phase'],
                    'day_date'    => $day['day_date'] ?? null,
                    'block_order' => array_column($order_raw, 'bid'),
                ];
            }
            szo_schedule_save_days($schedule_id, $instr, $days_to_save);
        }

        // Dołącz pełny harmonogram do odpowiedzi
        $result['schedule'] = szo_schedule_get($schedule_id, $instr);
        api_json(['data' => $result]);
    })(),

    default => api_error("Nieznany zasób '$resource'. Dostępne: rooms, laptops, meetings, tech-paths, sessions, instructors, check-conflicts, drafts, cycle-templates, audit, wallet, prices, basket, szo-blocks, szo-schedules, szo-solve.", 404),
};
