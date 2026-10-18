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
 *
 *   q      — (opcjonalnie) dowolny fragment nazwy/UPN/adresu: wyszukiwanie w całym tenancie
 *            (source = 'search', do podpinania istniejącego konta do umowy). Wyniki mają też
 *            accountEnabled i linked_contracts — umowy wolontariackie z tym kontem (bez $_GET['except']).
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
$q     = trim($_GET['q'] ?? '');
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

// ── 0. Wyszukiwanie dowolnym fragmentem (podpinanie istniejącego konta) ─────
if ($q !== '') {
    $except = (int)($_GET['except'] ?? 0);
    foreach ($g->search_users($q, 15) as $u) {
        if (empty($u['id'])) continue;
        $linked = db_one("SELECT id, name FROM users WHERE microsoft_id = ?", [$u['id']]);
        try {
            $contracts = db_all("SELECT id, numer_umowy, imie_nazwisko FROM umowy_wolontariat
                                 WHERE m365_user_id = ? AND id != ? ORDER BY id DESC LIMIT 3", [$u['id'], $except]);
        } catch (\Throwable $e) { $contracts = []; }
        $results[] = [
            'source'           => 'search',
            'ms_user'          => _ms_user_safe($u) + ['accountEnabled' => isset($u['accountEnabled']) ? (bool)$u['accountEnabled'] : null],
            'already_linked'   => $linked ? ['id' => (int)$linked['id'], 'name' => $linked['name']] : null,
            'linked_contracts' => $contracts,
        ];
    }
    echo json_encode(['configured' => true, 'results' => $results]);
    exit;
}

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
