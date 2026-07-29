<?php
/**
 * api/komunikaty/list.php — Publiczny feed JSON komunikatów (ogłoszeń organizacji).
 * ─────────────────────────────────────────────────────────────────────────────
 * Bez autoryzacji, CORS: *. Do osadzania aktualnych komunikatów na zewnętrznych
 * stronach WWW. Zwraca WYŁĄCZNIE komunikaty publiczne (audience='public'),
 * aktywne i nieprzeterminowane — nigdy treści wewnętrznych.
 *
 * Parametry (GET):
 *   limit      — 1..50 (domyślnie 20); ignorowany przy pobieraniu pojedynczego
 *   id         — pobierz jeden komunikat po ID
 *   kategoria  — filtr kategorii (ogolne|pilne|wydarzenie|techniczne|rodo|administracyjne)
 *   q          — filtr po tytule (fraza)
 *
 * Odpowiedź (lista):
 *   { "ok": true, "org": "...", "count": N, "items": [ {...} ] }
 * Odpowiedź (pojedynczy, gdy id):
 *   { "ok": true, "org": "...", "item": {...} }
 */
declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Zredukuj wiersz komunikatu do bezpiecznego, publicznego kształtu.
 */
function komunikat_public_item(array $a): array
{
    $body    = (string)($a['body'] ?? '');
    $excerpt = trim(preg_replace('/\s+/u', ' ', strip_tags($body)));
    if (mb_strlen($excerpt) > 240) {
        $excerpt = mb_substr($excerpt, 0, 240) . '…';
    }
    $kat = (string)($a['kategoria'] ?? 'ogolne');

    return [
        'id'              => (int)$a['id'],
        'title'           => (string)($a['title'] ?? ''),
        'kategoria'       => $kat,
        'kategoria_label' => ann_kategoria_label($kat),
        'kategoria_color' => ann_kategoria_color($kat),
        'is_pinned'       => !empty($a['is_pinned']),
        'excerpt'         => $excerpt,
        'body_html'       => $body,
        'published_at'    => $a['created_at'] ?? null,
        'expires_at'      => $a['expires_at'] ?? null,
    ];
}

$org = defined('ORG_NAME') ? ORG_NAME : '';

// Wspólny warunek publiczności: aktywne, publiczne, nieprzeterminowane.
$where  = "is_active = 1 AND audience = 'public'
           AND (expires_at IS NULL OR expires_at >= date('now'))";

// ── Pojedynczy komunikat po id ───────────────────────────────────────────────
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    try {
        $row = db_one("SELECT * FROM announcements WHERE id = ? AND $where", [$id]);
    } catch (\Throwable $e) {
        $row = null;
    }
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Nie znaleziono komunikatu.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'ok'   => true,
        'org'  => $org,
        'item' => komunikat_public_item($row),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Lista komunikatów ────────────────────────────────────────────────────────
$limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$kat    = trim((string)($_GET['kategoria'] ?? ''));
$q      = trim((string)($_GET['q'] ?? ''));

$sql    = "SELECT * FROM announcements WHERE $where";
$params = [];
if ($kat !== '' && isset(ann_kategoria_options()[$kat])) {
    $sql .= " AND kategoria = ?";
    $params[] = $kat;
}
if ($q !== '') {
    $sql .= " AND title LIKE ?";
    $params[] = '%' . $q . '%';
}
$sql .= " ORDER BY is_pinned DESC, created_at DESC LIMIT " . (int)$limit;

try {
    $rows = db_all($sql, $params);
} catch (\Throwable $e) {
    $rows = [];
}

$out = array_map('komunikat_public_item', $rows);

echo json_encode([
    'ok'    => true,
    'org'   => $org,
    'count' => count($out),
    'items' => $out,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
