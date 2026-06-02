<?php
/**
 * AJAX: Szuka konta Microsoft 365 pasującego do podanego e-maila lub imienia i nazwiska.
 * Używane podczas tworzenia użytkownika w admin/users.php.
 *
 * GET params:
 *   email  — adres e-mail nowego użytkownika
 *   name   — imię i nazwisko nowego użytkownika
 *
 * Response JSON:
 *   {configured: false}                              — M365 nie skonfigurowane
 *   {configured: true, results: [...]}               — wyniki wyszukiwania
 *
 * Każdy wynik: {source, ms_user: {id,displayName,userPrincipalName,mail}, expected_login?, already_linked?}
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/m365.php';

header('Content-Type: application/json; charset=utf-8');

// Tylko zalogowani z uprawnieniami edycji
if (!current_user() || !can_edit()) {
    http_response_code(403);
    echo json_encode(['error' => 'Brak dostępu.']);
    exit;
}

$email = trim($_GET['email'] ?? '');
$name  = trim($_GET['name']  ?? '');

try {
    $g = new M365Graph();
    if (!$g->is_configured()) {
        echo json_encode(['configured' => false]);
        exit;
    }
} catch (\Throwable $e) {
    echo json_encode(['configured' => false, 'error' => $e->getMessage()]);
    exit;
}

$results     = [];
$found_ms_ids = [];

// ── 1. Szukaj po e-mailu ────────────────────────────────────────────────────
if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    try {
        $u = $g->find_by_email_or_upn($email);
        if (!empty($u['id'])) {
            $found_ms_ids[] = $u['id'];
            $linked = db_one("SELECT id, name FROM users WHERE microsoft_id = ?", [$u['id']]);
            $results[] = [
                'source'         => 'email',
                'ms_user'        => _ms_user_safe($u),
                'already_linked' => $linked ? ['id' => (int)$linked['id'], 'name' => $linked['name']] : null,
            ];
        }
    } catch (\Throwable $e) {}
}

// ── 2. Szukaj po wygenerowanym loginie z imienia i nazwiska ─────────────────
if ($name) {
    try {
        $domain = m365_setting('m365_domain');
        if ($domain) {
            $expected_login = M365Graph::generate_login($name, $domain);
            $u = $g->find_by_email_or_upn($expected_login);
            if (!empty($u['id']) && !in_array($u['id'], $found_ms_ids, true)) {
                $found_ms_ids[] = $u['id'];
                $linked = db_one("SELECT id, name FROM users WHERE microsoft_id = ?", [$u['id']]);
                $results[] = [
                    'source'         => 'generated_login',
                    'expected_login' => $expected_login,
                    'ms_user'        => _ms_user_safe($u),
                    'already_linked' => $linked ? ['id' => (int)$linked['id'], 'name' => $linked['name']] : null,
                ];
            }
        }
    } catch (\Throwable $e) {}
}

echo json_encode(['configured' => true, 'results' => $results]);

// ── Helpers ──────────────────────────────────────────────────────────────────
function _ms_user_safe(array $u): array {
    return [
        'id'                => $u['id']                ?? '',
        'displayName'       => $u['displayName']       ?? '',
        'userPrincipalName' => $u['userPrincipalName'] ?? '',
        'mail'              => $u['mail']               ?? '',
    ];
}
