<?php
/**
 * REST API — lista kont z uprawnieniem „Wdrożenie MSUES” (do importu osób w aplikacji Wdrożenie MSUES).
 *
 *   GET /api/v1/msues_users.php   (Bearer, scope msues:write)
 *       → 200 {"ok":true,"count":N,"users":[{"id":1,"name":"Imię Nazwisko","email":"…","can_write":true,"active":true}]}
 *
 * Tylko odczyt. Zwraca aktywne konta (is_active=1), które mają efektywny odczyt modułu msues_wdrozenie
 * (rola ∪ user_permissions, admin zawsze) — reguły bierzemy z desktop_scopes_for_user(), tych samych, które
 * wyznaczają scope’y SSO. Minimalizacja danych: id, imię i nazwisko, e-mail, can_write, active. Do 500 rekordów,
 * sortowanie po nazwisku i imieniu. Wpis w dzienniku audytu API (klucz/konto + liczba zwróconych rekordów).
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/desktop_auth.php';

api_auth_migrate();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') api_error('Method Not Allowed — użyj GET.', 405);
api_require('msues:write');

const MSUES_USERS_MAX = 500;

$rows = db_all("SELECT id, name, first_name, last_name, email, role FROM users WHERE is_active = 1
                ORDER BY LOWER(CASE WHEN TRIM(last_name) <> '' THEN last_name ELSE name END), LOWER(first_name), id");
$users = [];
foreach ($rows as $u) {
    $scopes = desktop_scopes_for_user($u);
    if (!in_array('msues:read', $scopes, true)) continue;
    $email = strtolower(trim((string)$u['email']));
    if ($email === '') continue;
    $name = trim(trim((string)$u['first_name']) . ' ' . trim((string)$u['last_name']));
    if ($name === '') $name = trim((string)$u['name']);
    $users[] = [
        'id'        => (int)$u['id'],
        'name'      => $name,
        'email'     => $email,
        'can_write' => in_array('msues:write', $scopes, true),
        'active'    => true,
    ];
    if (count($users) >= MSUES_USERS_MAX) break;
}

api_audit('list', 'msues_users', null, ['count=' . count($users)]);
api_json(['ok' => true, 'count' => count($users), 'users' => $users]);
