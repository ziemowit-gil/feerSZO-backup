<?php
/**
 * REST API — Karty 30 (Dydaktyka: konsultacje/wizyty + zajęcia TI)
 * Bearer token (Authorization: Bearer <key>) lub ?api_key=<key>.
 * Uprawnienia: karty30:read (odczyt), karty30:write (zapis).
 *
 * Routing (jeden plik, sterowanie metodą HTTP + parametrem ?resource=):
 *   GET    /api/v1/karty30.php?resource=R               → lista (filtry + paginacja)
 *   GET    /api/v1/karty30.php?resource=R&id=N          → pojedynczy rekord
 *   POST   /api/v1/karty30.php?resource=R               → utwórz (JSON body)
 *   PATCH  /api/v1/karty30.php?resource=R&id=N          → aktualizuj (JSON body)
 *   DELETE /api/v1/karty30.php?resource=R&id=N          → usuń (kursy: soft-delete)
 *
 * Zasoby (R): clients, schedules, consultations, waiting,
 *             courses, enrollments, lessons, homework, materials, grades, tests
 *
 * Metodę można nadpisać nagłówkiem X-HTTP-Method-Override lub ?_method=PATCH.
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/karty30.php';

api_auth_migrate();
if (function_exists('karty30_migrate')) { try { karty30_migrate(); } catch (\Throwable $e) {} }

// ── Konfiguracja zasobów ─────────────────────────────────────────────────────
// Każdy zasób: tabela, dozwolone pola (whitelist), pola wymagane (create),
// sortowanie listy, filtry (param GET → kolumna), pola do wyszukiwania (q),
// flagi created_at/updated_at/created_by, ewentualny soft-delete i domyślne wartości.
$RESOURCES = [
    'clients' => [
        'table'    => 'k30_clients',
        'fields'   => ['name','email','phone','status','problem','equipment','date_of_birth',
                       'gender','address','notes','preferred_contact_method','consent','available_hours'],
        'required' => ['name'],
        'order'    => 'name ASC',
        'filters'  => ['status'],
        'search'   => ['name','email','phone'],
        'created_at' => true, 'updated_at' => true, 'created_by' => true,
        'defaults' => ['status' => 'enrolled', 'preferred_contact_method' => 'email'],
    ],
    'schedules' => [
        'table'    => 'k30_schedules',
        'fields'   => ['client_id','assigned_to','start_time','duration_minutes','status','description',
                       'is_remote','billing_type','pfron_contract_id','resource_id','cancel_reason'],
        'required' => ['client_id','start_time'],
        'order'    => 'start_time DESC',
        'filters'  => ['client_id','status','assigned_to'],
        'created_at' => true, 'updated_at' => true, 'created_by' => true,
        'defaults' => ['status' => 'preliminary', 'duration_minutes' => 60, 'billing_type' => 'free'],
    ],
    'consultations' => [
        'table'    => 'k30_consultations',
        'fields'   => ['schedule_id','client_id','consultant_id','consultation_datetime','duration_minutes',
                       'description','next_action','status'],
        'required' => ['client_id','consultation_datetime'],
        'order'    => 'consultation_datetime DESC',
        'filters'  => ['client_id','status','consultant_id'],
        'created_at' => true, 'updated_at' => true, 'created_by' => true,
        'defaults' => ['status' => 'draft'],
    ],
    'waiting' => [
        'table'    => 'k30_waiting_list',
        'fields'   => ['client_id','priority','reason','notes','status'],
        'required' => ['client_id'],
        'order'    => 'created_at DESC',
        'filters'  => ['client_id','status','priority'],
        'created_at' => true, 'updated_at' => true, 'created_by' => true,
        'defaults' => ['priority' => 'zwykly', 'status' => 'waiting'],
    ],
    'courses' => [
        'table'    => 'k30_ti_courses',
        'fields'   => ['name','description','instructor_id','location','is_active','status',
                       'grades_enabled','billing_model','billing_amount','default_meeting_url'],
        'required' => ['name'],
        'order'    => 'name ASC',
        'filters'  => ['status','instructor_id'],
        'search'   => ['name'],
        'created_at' => true, 'updated_at' => false, 'created_by' => true,
        'defaults' => ['is_active' => 1, 'status' => 'active', 'grades_enabled' => 1],
        'soft_delete' => ['column' => 'status', 'value' => 'cancelled'],
    ],
    'enrollments' => [
        'table'    => 'k30_ti_enrollments',
        'fields'   => ['course_id','client_id','hourly_rate','start_date','end_date','status','notes',
                       'billing_model','billing_amount'],
        'required' => ['course_id','client_id'],
        'order'    => 'id DESC',
        'filters'  => ['course_id','client_id','status'],
        'created_at' => true, 'updated_at' => false, 'created_by' => false,
        'defaults' => ['status' => 'active'],
    ],
    'lessons' => [
        'table'    => 'k30_ti_sessions',
        'fields'   => ['course_id','lesson_date','time_from','time_to','duration_min','status','topic',
                       'notes','instructor_notes','has_homework','self_prep_remote','meeting_url'],
        'required' => ['course_id','lesson_date'],
        'order'    => 'lesson_date DESC, id DESC',
        'filters'  => ['course_id','status'],
        'created_at' => true, 'updated_at' => true, 'created_by' => true,
        'defaults' => ['status' => 'planned', 'duration_min' => 60],
    ],
    'homework' => [
        'table'    => 'k30_ti_homework',
        'fields'   => ['course_id','session_id','title','description','due_at','open_at','close_at','hint','is_active'],
        'required' => ['course_id','title'],
        'order'    => 'created_at DESC',
        'filters'  => ['course_id','session_id','is_active'],
        'search'   => ['title'],
        'created_at' => true, 'updated_at' => false, 'created_by' => true,
        'defaults' => ['is_active' => 1],
    ],
    'materials' => [
        'table'    => 'k30_ti_materials',
        'fields'   => ['course_id','session_id','type','title','description','url','open_at','close_at','is_active'],
        'required' => ['course_id','title'],
        'order'    => 'created_at DESC',
        'filters'  => ['course_id','session_id','type','is_active'],
        'search'   => ['title'],
        'created_at' => true, 'updated_at' => false, 'created_by' => true,
        'defaults' => ['type' => 'inne', 'is_active' => 1],
    ],
    'grades' => [
        'table'    => 'k30_ti_grades',
        'fields'   => ['course_id','client_id','session_id','category','value_text','value_num','weight','description'],
        'required' => ['course_id','client_id','value_text'],
        'order'    => 'graded_at DESC, id DESC',
        'filters'  => ['course_id','client_id','category'],
        'created_at' => true, 'updated_at' => false, 'created_by' => false,
        'defaults' => ['category' => 'inne', 'weight' => 1],
    ],
    'tests' => [
        'table'    => 'k30_ti_tests',
        'fields'   => ['course_id','title','description','time_limit_min','pass_pct','shuffle','is_active','sync_grade'],
        'required' => ['course_id','title'],
        'order'    => 'created_at DESC',
        'filters'  => ['course_id','is_active'],
        'search'   => ['title'],
        'created_at' => true, 'updated_at' => true, 'created_by' => true,
        'defaults' => ['is_active' => 0],
    ],
];

// Mapowanie kluczy obcych → tabela rodzica (walidacja istnienia przy create).
$FK_PARENTS = [
    'client_id'  => 'k30_clients',
    'course_id'  => 'k30_ti_courses',
    'session_id' => 'k30_ti_sessions',
    'schedule_id'=> 'k30_schedules',
    'test_id'    => 'k30_ti_tests',
    'homework_id'=> 'k30_ti_homework',
];

// Pola traktowane jako liczby całkowite / data-czas (normalizacja wejścia).
$INT_FIELDS = ['is_active','consent','has_homework','self_prep_remote','grades_enabled','billing_model',
               'duration_minutes','duration_min','time_limit_min','pass_pct','shuffle','sync_grade'];
$DT_FIELDS  = ['start_time','consultation_datetime','due_at','open_at','close_at'];

// ── Routing ──────────────────────────────────────────────────────────────────
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ($_GET['_method'] ?? '');
if ($override !== '') $method = strtoupper((string)$override);

if ($method === 'GET') { api_require('karty30:read'); }
else                   { api_require('karty30:write'); }

$resource = (string)($_GET['resource'] ?? '');
$id       = (int)($_GET['id'] ?? 0);

if ($resource === '') {
    api_json(['data' => ['resources' => array_keys($RESOURCES)],
              'meta' => ['hint' => 'Podaj ?resource=<nazwa> — patrz lista resources.']]);
}
if (!isset($RESOURCES[$resource])) {
    api_error('Nieznany zasób. Dostępne: ' . implode(', ', array_keys($RESOURCES)), 404);
}
$cfg   = $RESOURCES[$resource];
$table = $cfg['table'];

// ── Helpery ──────────────────────────────────────────────────────────────────

/** Body żądania jako tablica (JSON lub form-encoded). */
function k30api_input(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input') ?: '';
        $j   = json_decode($raw, true);
        if ($raw !== '' && !is_array($j)) api_error('Invalid JSON body', 400);
        return is_array($j) ? $j : [];
    }
    return $_POST;
}

/** Normalizuje wartość pola (bool→0/1, _id→int, data-czas→spacja). */
function k30api_norm(string $field, $v) {
    global $INT_FIELDS, $DT_FIELDS;
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_string($v)) $v = trim($v);
    if (str_ends_with($field, '_id') || in_array($field, $INT_FIELDS, true)) {
        if ($v === '' || $v === null) return null;
        return (int)$v;
    }
    if (in_array($field, $DT_FIELDS, true) && is_string($v) && $v !== '') {
        $v = str_replace('T', ' ', $v);
        if (strlen($v) === 16) $v .= ':00';
    }
    return $v;
}

/** Buduje dane do zapisu z wejścia wg whitelisty zasobu. */
function k30api_build(array $cfg, array $in): array {
    $data = [];
    foreach ($cfg['fields'] as $f) {
        if (array_key_exists($f, $in)) $data[$f] = k30api_norm($f, $in[$f]);
    }
    return $data;
}

/** Serializuje wiersz: id jako int, reszta bez zmian. */
function k30api_row(array $r): array {
    if (isset($r['id'])) $r['id'] = (int)$r['id'];
    return $r;
}

// ══════════════════════════════════════════════════════════════════════════════
// POJEDYNCZY REKORD (?id=N)
// ══════════════════════════════════════════════════════════════════════════════
if ($id > 0) {
    $row = db_one("SELECT * FROM {$table} WHERE id=?", [$id]);
    if (!$row) api_error('Nie znaleziono rekordu.', 404);

    if ($method === 'GET') {
        api_json(['data' => k30api_row($row)]);
    }

    if ($method === 'PATCH' || $method === 'PUT') {
        $data = k30api_build($cfg, k30api_input());
        if (!$data) api_error('Brak pól do aktualizacji.', 400);
        if (!empty($cfg['updated_at'])) $data['updated_at'] = date('Y-m-d H:i:s');
        db_update($table, $data, $id);
        api_json(['data' => k30api_row(db_one("SELECT * FROM {$table} WHERE id=?", [$id]))]);
    }

    if ($method === 'DELETE') {
        if (!empty($cfg['soft_delete'])) {
            db_update($table, [$cfg['soft_delete']['column'] => $cfg['soft_delete']['value']], $id);
            api_json(['data' => ['id' => $id, 'deleted' => true, 'soft' => true]]);
        }
        db()->prepare("DELETE FROM {$table} WHERE id=?")->execute([$id]);
        api_json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    api_error('Method Not Allowed', 405);
}

// ══════════════════════════════════════════════════════════════════════════════
// KOLEKCJA (bez id)
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    global $api_current_key, $FK_PARENTS;
    $in   = k30api_input();
    $data = k30api_build($cfg, $in);

    // Domyślne wartości (gdy nie podano)
    foreach (($cfg['defaults'] ?? []) as $k => $v) {
        if (!array_key_exists($k, $data) || $data[$k] === null || $data[$k] === '') $data[$k] = $v;
    }

    // Pola wymagane
    $missing = [];
    foreach ($cfg['required'] as $f) {
        if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') $missing[] = $f;
    }
    if ($missing) api_error('Brak wymaganych pól: ' . implode(', ', $missing), 422);

    // Walidacja kluczy obcych (istnienie rodzica)
    foreach ($cfg['required'] as $f) {
        if (str_ends_with($f, '_id') && isset($FK_PARENTS[$f]) && !empty($data[$f])) {
            $pt = $FK_PARENTS[$f];
            if (!db_one("SELECT 1 FROM {$pt} WHERE id=?", [(int)$data[$f]])) {
                api_error("Nie znaleziono rekordu nadrzędnego ({$f}={$data[$f]}).", 422);
            }
        }
    }

    // Pola specjalne
    if (!empty($cfg['created_by'])) {
        $data['created_by'] = (int)($api_current_key['created_by'] ?? 0) ?: null;
    }
    if ($resource === 'grades') {
        $data['graded_by']  = (int)($api_current_key['created_by'] ?? 0) ?: null;
        $data['graded_at']  = date('Y-m-d H:i:s');
        if ((!isset($data['value_num']) || $data['value_num'] === null || $data['value_num'] === '')
            && !empty($data['value_text']) && function_exists('k30_ti_grade_parse_num')) {
            $data['value_num'] = k30_ti_grade_parse_num((string)$data['value_text']);
        }
    }
    if (!empty($cfg['created_at'])) $data['created_at'] = date('Y-m-d H:i:s');
    if (!empty($cfg['updated_at'])) $data['updated_at'] = date('Y-m-d H:i:s');

    try {
        $new_id = db_insert($table, $data);
    } catch (\Throwable $e) {
        api_error('Nie udało się utworzyć rekordu: ' . $e->getMessage(), 422);
    }
    api_json(['data' => k30api_row(db_one("SELECT * FROM {$table} WHERE id=?", [$new_id]))], 201);
}

if ($method !== 'GET') api_error('Method Not Allowed', 405);

// ── GET: lista z filtrami + wyszukiwaniem + paginacją ─────────────────────────
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
$offset   = ($page - 1) * $per_page;

$where  = [];
$params = [];

foreach (($cfg['filters'] ?? []) as $param) {
    if (isset($_GET[$param]) && $_GET[$param] !== '') {
        $where[]  = "{$param} = ?";
        $params[] = str_ends_with($param, '_id') ? (int)$_GET[$param] : $_GET[$param];
    }
}
if (!empty($_GET['q']) && !empty($cfg['search'])) {
    $like = '%' . $_GET['q'] . '%';
    $ors  = [];
    foreach ($cfg['search'] as $col) { $ors[] = "{$col} LIKE ?"; $params[] = $like; }
    $where[] = '(' . implode(' OR ', $ors) . ')';
}

$sql_where = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$total = (int)(db_one("SELECT COUNT(*) AS c FROM {$table} {$sql_where}", $params)['c'] ?? 0);

$rows = db_all(
    "SELECT * FROM {$table} {$sql_where} ORDER BY {$cfg['order']} LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $offset])
);

api_json([
    'data' => array_map('k30api_row', $rows),
    'meta' => [
        'resource' => $resource,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'pages'    => max(1, (int)ceil($total / $per_page)),
    ],
]);
