<?php
/**
 * REST API — Moodle Sync
 * GET /api/v1/moodle_sync.php
 *
 * Zwraca listę wolontariuszy przystosowaną do synchronizacji z Moodle.
 * Wymagane uprawnienie klucza API: volunteers:read
 *
 * Query params:
 *   since      — zwróć tylko rekordy zmienione po tej dacie (YYYY-MM-DD HH:MM:SS)
 *               przydatne do synchronizacji przyrostowej
 *   include    — 'active' (domyślnie), 'ended', 'all'
 *   per_page   — max 200 (domyślnie 100)
 *   page       — strona (domyślnie 1)
 *
 * Każdy rekord zawiera:
 *   id, email, firstname, lastname, fullname,
 *   status, contract_status_group (active|ended|pending),
 *   project, action_id, action_name,
 *   start_date, end_date,
 *   updated_at
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';

api_auth_migrate();
api_require('volunteers:read');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method Not Allowed', 405);
}

// ── Grupowanie statusów ──────────────────────────────────────────────────────
const MOODLE_ACTIVE_STATUSES = ['podpisana', 'w realizacji', 'obowiązująca', 'projekt'];
const MOODLE_ENDED_STATUSES  = ['zakończona', 'rozwiązana', 'anulowana'];

function moodle_status_group(string $status): string {
    if (in_array($status, MOODLE_ACTIVE_STATUSES, true)) return 'active';
    if (in_array($status, MOODLE_ENDED_STATUSES,  true)) return 'ended';
    return 'pending';
}

// ── Parametry ─────────────────────────────────────────────────────────────────
$include            = in_array($_GET['include'] ?? 'active', ['active', 'ended', 'all']) ? ($_GET['include'] ?? 'active') : 'active';
$since              = trim($_GET['since']              ?? '');
$include_standalone = !empty($_GET['include_standalone']); // konta bez umowy
$page               = max(1, (int)($_GET['page']     ?? 1));
$per_page           = min(200, max(1, (int)($_GET['per_page'] ?? 100)));
$offset             = ($page - 1) * $per_page;

// ── WHERE ────────────────────────────────────────────────────────────────────
$where  = ["w.email IS NOT NULL", "TRIM(w.email) != ''"];
$params = [];

if ($include === 'active') {
    $ph      = implode(',', array_fill(0, count(MOODLE_ACTIVE_STATUSES), '?'));
    $where[] = "w.status IN ({$ph})";
    $params  = array_merge($params, MOODLE_ACTIVE_STATUSES);
} elseif ($include === 'ended') {
    $ph      = implode(',', array_fill(0, count(MOODLE_ENDED_STATUSES), '?'));
    $where[] = "w.status IN ({$ph})";
    $params  = array_merge($params, MOODLE_ENDED_STATUSES);
}
// 'all' — bez filtrowania statusu

if ($since !== '') {
    $where[]  = "w.updated_at >= ?";
    $params[] = $since;
}

$sql_where = 'WHERE ' . implode(' AND ', $where);

// ── Liczba ────────────────────────────────────────────────────────────────────
$total = (int)(db_one(
    "SELECT COUNT(*) AS c
     FROM umowy_wolontariat w
     LEFT JOIN actions a ON a.id = w.action_id
     {$sql_where}",
    $params
)['c'] ?? 0);

// ── Dane ──────────────────────────────────────────────────────────────────────
$rows = db_all(
    "SELECT
        w.id,
        LOWER(TRIM(w.email))        AS email,
        w.imie_nazwisko             AS fullname,
        w.status,
        w.data_zawarcia             AS start_date,
        w.data_zakonczenia          AS end_date,
        w.projekt_program           AS project,
        w.action_id,
        a.nazwa                     AS action_name,
        w.updated_at
     FROM umowy_wolontariat w
     LEFT JOIN actions a ON a.id = w.action_id
     {$sql_where}
     ORDER BY w.updated_at DESC, w.id DESC
     LIMIT {$per_page} OFFSET {$offset}",
    $params
);

// ── Formatuj odpowiedź ────────────────────────────────────────────────────────
$data = [];
foreach ($rows as $r) {
    // Rozdziel imię i nazwisko z fullname
    $parts     = preg_split('/\s+/', trim($r['fullname'] ?? ''), 2);
    $firstname = $parts[0] ?? '';
    $lastname  = $parts[1] ?? '.';

    $data[] = [
        'id'                   => (int)$r['id'],
        'email'                => $r['email'],
        'firstname'            => $firstname,
        'lastname'             => $lastname,
        'fullname'             => $r['fullname'],
        'status'               => $r['status'],
        'contract_status_group'=> moodle_status_group($r['status']),
        'project'              => $r['project'] ?? null,
        'action_id'            => $r['action_id'] ? (int)$r['action_id'] : null,
        'action_name'          => $r['action_name'] ?? null,
        'start_date'           => $r['start_date'],
        'end_date'             => $r['end_date'] ?? null,
        'updated_at'           => $r['updated_at'],
    ];
}

// ── Standalone volunteers (konta bez umowy) ───────────────────────────────────
if ($include_standalone && in_array($include, ['active', 'all'], true)) {
    $sv_rows = db_all(
        "SELECT u.id AS uid, LOWER(TRIM(u.email)) AS email,
                u.first_name, u.last_name, u.name AS fullname_raw,
                u.phone_number AS telefon, u.is_active,
                u.m365_login, u.m365_security_group_name,
                u.created_at AS updated_at
         FROM users u
         WHERE u.is_standalone_volunteer = 1 AND u.is_active = 1
           AND u.email IS NOT NULL AND TRIM(u.email) != ''
         ORDER BY u.created_at DESC"
    );
    foreach ($sv_rows as $sv) {
        $fn  = trim($sv['first_name'] ?? '');
        $ln  = trim($sv['last_name']  ?? '');
        $fln = $fn || $ln ? trim("{$fn} {$ln}") : ($sv['fullname_raw'] ?? '');
        $data[] = [
            'id'                    => 'sv_' . $sv['uid'],   // prefiks odróżnia od umów
            'email'                 => $sv['email'],
            'firstname'             => $fn ?: $fln,
            'lastname'              => $ln ?: '.',
            'fullname'              => $fln,
            'status'                => 'standalone',
            'contract_status_group' => 'active',
            'project'               => null,
            'action_id'             => null,
            'action_name'           => null,
            'start_date'            => null,
            'end_date'              => null,
            'updated_at'            => $sv['updated_at'],
            'is_standalone'         => true,
            'source_user_id'        => (int)$sv['uid'],     // ID z tabeli users
        ];
        $total++;
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'data'       => $data,
    'meta'       => [
        'total'              => $total,
        'page'               => $page,
        'per_page'           => $per_page,
        'pages'              => (int)ceil($total / $per_page),
        'include'            => $include,
        'include_standalone' => $include_standalone,
        'since'              => $since ?: null,
        'generated_at'       => date('Y-m-d H:i:s'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
