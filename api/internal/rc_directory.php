<?php
/**
 * api/internal/rc_directory.php — katalog osób FEER dla webmaila poczta.feer.org.pl
 * (książka adresowa „Fundacja FEER”, plugin Roundcube feer_directory).
 *
 * Te same osoby i dane co /katalog (directory/index.php): aktywne konta z adresem e-mail,
 * stanowisko i jednostka z głównego przypisania w org_members, telefon TYLKO gdy osoba
 * ustawiła go jako publiczny (user_profiles.phone_public) — jak w katalogu.
 * GET → {"people":[{id,name,first_name,last_name,email,title,unit,phone}], "generated":"…"}
 *
 * Zabezpieczenie: nagłówek X-Internal-Key = ustawienie roundcube_signature_key (ten sam klucz
 * co rc_signature.php; kopia w ~/roundcube/secrets/szo-key na serwerze).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$key      = org_setting('roundcube_signature_key');
$incoming = (string)($_SERVER['HTTP_X_INTERNAL_KEY'] ?? '');
if (strlen($key) < 32 || $incoming === '' || !hash_equals($key, $incoming)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$sql = "
    SELECT u.id, u.name, u.first_name, u.last_name, u.email,
           COALESCE(up.phone_public, 0) AS phone_public,
           om_j.position_name, om_j.phone_direct, om_j.phone_mobile,
           u.phone_number, ou.name AS unit_name
    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    LEFT JOIN (
        SELECT om2.user_id, om2.unit_id, om2.position_name, om2.phone_direct, om2.phone_mobile
        FROM org_members om2
        WHERE om2.status = 'active'
        ORDER BY om2.is_primary DESC, om2.id DESC
    ) om_j ON om_j.user_id = u.id
    LEFT JOIN org_units ou ON ou.id = om_j.unit_id
    WHERE u.is_active = 1 AND COALESCE(u.email, '') <> ''
    ORDER BY u.last_name, u.first_name";

try { $rows = db_all($sql); } catch (\Throwable $e) { $rows = []; }

$people = [];
$seen = [];
foreach ($rows as $r) {
    $id = (int)$r['id'];
    if (isset($seen[$id])) continue;          // kilka przypisań → pierwsze (główne)
    $seen[$id] = true;
    $email = strtolower(trim((string)$r['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    $first = trim((string)($r['first_name'] ?? ''));
    $last  = trim((string)($r['last_name'] ?? ''));
    $name  = trim($first . ' ' . $last) ?: trim((string)$r['name']) ?: $email;
    $phone = !empty($r['phone_public'])
        ? (string)($r['phone_direct'] ?: $r['phone_mobile'] ?: ($r['phone_number'] ?? ''))
        : '';
    $people[] = [
        'id'         => $id,
        'name'       => $name,
        'first_name' => $first,
        'last_name'  => $last,
        'email'      => $email,
        'title'      => (string)($r['position_name'] ?? ''),
        'unit'       => (string)($r['unit_name'] ?? ''),
        'phone'      => $phone,
    ];
}

echo json_encode(['people' => $people, 'generated' => date('c')], JSON_UNESCAPED_UNICODE);
