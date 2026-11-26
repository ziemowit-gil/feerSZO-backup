<?php
/**
 * api/internal/nc_komunikaty.php — komunikaty SZO dla kafla „Komunikaty z SZO” na pulpicie
 * Nextclouda (aplikacja docker/nextcloud/apps/feer_poczta, widżet KomunikatyWidget).
 *
 * GET ?uid=<uid w chmurze>&email=<e-mail konta w chmurze>&limit=7
 * → {"items":[{id,title,date,kategoria,pinned,read,url}], "all_url":"…", "unread":N}
 *
 * Osoba: sso_accounts.cloud_uid = uid, a gdy brak — users.email = email (aktywne konto).
 * Widoczność jak w /komunikaty (ann_list_for_user: audytorium, wygaśnięcie), bez komunikatów
 * tylko dla prowadzących TI i publicznych. Nieznana osoba → pusta lista.
 *
 * Zabezpieczenie: nagłówek X-Internal-Key = ustawienie roundcube_signature_key (ten sam klucz
 * co rc_signature.php / rc_directory.php; w chmurze: occ config:app:set feer_poczta szo_key).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$key      = org_setting('roundcube_signature_key');
$incoming = (string)($_SERVER['HTTP_X_INTERNAL_KEY'] ?? '');
if (strlen($key) < 32 || $incoming === '' || !hash_equals($key, $incoming)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$uid   = trim((string)($_GET['uid'] ?? ''));
$email = strtolower(trim((string)($_GET['email'] ?? '')));
$limit = max(1, min(20, (int)($_GET['limit'] ?? 7)));

$allUrl = rtrim(APP_URL, '/') . '/komunikaty/index.php';
$out = ['items' => [], 'all_url' => $allUrl, 'unread' => 0];

$user = null;
try {
    if ($uid !== '') {
        $user = db_one(
            "SELECT u.id, u.role FROM sso_accounts s JOIN users u ON u.id = s.user_id
             WHERE s.cloud_uid = ? AND u.is_active = 1 LIMIT 1", [$uid]);
    }
    if (!$user && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $user = db_one(
            "SELECT id, role FROM users WHERE LOWER(email) = ? AND is_active = 1 LIMIT 1", [$email]);
    }
} catch (\Throwable $e) {
    $user = null;
}

if ($user) {
    $list = array_values(array_filter(
        ann_list_for_user((int)$user['id'], (string)($user['role'] ?? 'viewer')),
        fn($a) => !in_array($a['audience'] ?? 'all', ['ti:prowadzacy', 'public'], true)
    ));
    foreach ($list as $a) {
        if (!(int)($a['is_read_by_me'] ?? 0)) $out['unread']++;
    }
    foreach (array_slice($list, 0, $limit) as $a) {
        $kat = (string)($a['kategoria'] ?? 'ogolne');
        $out['items'][] = [
            'id'        => (int)$a['id'],
            'title'     => (string)$a['title'],
            'date'      => substr((string)($a['created_at'] ?? ''), 0, 10),
            'kategoria' => ann_kategoria_label($kat),
            'pinned'    => (bool)(int)($a['is_pinned'] ?? 0),
            'read'      => (bool)(int)($a['is_read_by_me'] ?? 0),
            'url'       => rtrim(APP_URL, '/') . '/komunikaty/announcement.php?id=' . (int)$a['id'],
        ];
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
