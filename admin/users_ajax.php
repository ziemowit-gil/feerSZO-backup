<?php
/**
 * admin/users_ajax.php — AJAX endpointy dla strony zarządzania użytkownikami
 *
 * GET ?action=delete_impact&uid=N → JSON {ok, data: {cascade, set_null, contracts}}
 * GET ?action=ad_directory[&q=fraza] → JSON {ok, users: [{id,name,email,upn,existing}]}
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/user_delete.php';

auth_start();
header('Content-Type: application/json; charset=utf-8');

if (!current_user() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Brak dostępu.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'delete_impact') {
    $uid = (int)($_GET['uid'] ?? 0);
    if (!$uid) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $impact = user_delete_impact($uid);
    echo json_encode(['ok' => true, 'data' => $impact]);
    exit;
}

// Katalog AD (Entra ID) — lista kont M365 z oznaczeniem, które mają już konto w SZO
if ($action === 'ad_directory') {
    require_once dirname(__DIR__) . '/includes/m365.php';
    $q = mb_strtolower(trim($_GET['q'] ?? ''));
    try {
        $g = new M365Graph();
        if (!$g->is_configured()) {
            echo json_encode(['ok' => false, 'msg' => 'Integracja Microsoft 365 nie jest skonfigurowana.']);
            exit;
        }
        $ad_users = $g->get_users(999);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'Błąd połączenia z Microsoft Graph.']);
        exit;
    }

    $by_ms = []; $by_email = [];
    foreach (db_all("SELECT id, name, role, microsoft_id, email FROM users") as $u) {
        if (!empty($u['microsoft_id'])) $by_ms[$u['microsoft_id']] = $u;
        if (!empty($u['email']))        $by_email[mb_strtolower($u['email'])] = $u;
    }

    $out = [];
    foreach ($ad_users as $mu) {
        $id    = $mu['id'] ?? '';
        $name  = $mu['displayName'] ?? '';
        $upn   = $mu['userPrincipalName'] ?? '';
        $email = ($mu['mail'] ?? '') ?: $upn;
        if (!$id) continue;
        if ($q !== '' && mb_strpos(mb_strtolower($name . ' ' . $email . ' ' . $upn), $q) === false) continue;
        $ex = $by_ms[$id] ?? $by_email[mb_strtolower($email)] ?? null;
        $out[] = [
            'id'       => $id,
            'name'     => $name,
            'email'    => $email,
            'upn'      => $upn,
            'existing' => $ex ? ['id' => (int)$ex['id'], 'name' => $ex['name'], 'role' => $ex['role']] : null,
        ];
    }
    echo json_encode(['ok' => true, 'users' => $out]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'msg' => 'Nieznana akcja.']);
