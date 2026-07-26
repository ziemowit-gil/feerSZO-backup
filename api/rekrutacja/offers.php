<?php
/**
 * api/rekrutacja/offers.php — Publiczny feed JSON ogłoszeń rekrutacyjnych wolontariuszy.
 * ─────────────────────────────────────────────────────────────────────────────
 * Bez autoryzacji, CORS: *. Do osadzania listy aktualnych naborów na zewnętrznych
 * stronach WWW. Zwraca WYŁĄCZNIE ogłoszenia o statusie 'active' i tylko pola
 * publiczne — nigdy danych kandydatów ani zgłoszeń.
 *
 * Parametry (GET):
 *   limit  — 1..50 (domyślnie 20); ignorowany przy pobieraniu pojedynczego ogłoszenia
 *   id     — pobierz jedno ogłoszenie po ID
 *   slug   — pobierz jedno ogłoszenie po slug (alternatywa dla id)
 *   q      — filtr po tytule (fraza)
 *
 * Odpowiedź:
 *   { "ok": true, "org": "...", "count": N, "offers": [ {...} ] }
 * albo dla pojedynczego ogłoszenia (id/slug):
 *   { "ok": true, "org": "...", "offer": {...} }
 */
declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/rekrutacja.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

/**
 * Zredukuj wiersz ogłoszenia do bezpiecznego, publicznego kształtu.
 */
function rekrutacja_public_offer(array $o): array
{
    $content = (string)($o['content'] ?? '');
    $excerpt = strip_tags($content);
    if (mb_strlen($excerpt) > 240) {
        $excerpt = mb_substr($excerpt, 0, 240) . '…';
    }

    // Udostępniamy tylko definicję pól formularza (etykiety/typy), bez wartości.
    $fields = [];
    foreach (($o['custom_fields'] ?? []) as $f) {
        if (!is_array($f)) continue;
        $fields[] = [
            'name'     => (string)($f['name']  ?? ($f['label'] ?? '')),
            'label'    => (string)($f['label'] ?? ($f['name']  ?? '')),
            'type'     => (string)($f['type']  ?? 'text'),
            'required' => !empty($f['required']),
        ];
    }

    return [
        'id'             => (int)$o['id'],
        'slug'           => (string)($o['slug'] ?? ''),
        'title'          => (string)($o['title'] ?? ''),
        'excerpt'        => $excerpt,
        'content_html'   => $content,
        'avail_from'     => $o['avail_from'] ?? null,
        'avail_to'       => $o['avail_to'] ?? null,
        'max_candidates' => isset($o['max_candidates']) ? (int)$o['max_candidates'] : null,
        'applications'   => (int)($o['app_count'] ?? 0),
        'published_at'   => $o['published_at'] ?? null,
        'form_fields'    => $fields,
        'apply_url'      => APP_URL . '/contracts/rekrutacja/apply.php?id=' . (int)$o['id'],
    ];
}

$rm  = new VolunteerModuleManager();
$org = defined('ORG_NAME') ? ORG_NAME : '';

// ── Pojedyncze ogłoszenie po id lub slug ─────────────────────────────────────
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$slug = trim((string)($_GET['slug'] ?? ''));

if ($id > 0 || $slug !== '') {
    $offer = $id > 0
        ? $rm->getOffer($id)
        : (function () use ($slug) {
            $row = db_one("SELECT * FROM volunteer_offers WHERE slug = ?", [$slug]);
            if (!$row) return null;
            $row['custom_fields'] = json_decode($row['custom_fields'] ?? '[]', true) ?: [];
            $row['app_count'] = (int)db_one(
                "SELECT COUNT(*) AS c FROM volunteer_applications WHERE volunteer_offer_id = ?",
                [(int)$row['id']]
            )['c'];
            return $row;
        })();

    if (!$offer || ($offer['status'] ?? '') !== 'active') {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Nie znaleziono aktywnego ogłoszenia.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok'    => true,
        'org'   => $org,
        'offer' => rekrutacja_public_offer($offer),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Lista aktywnych ogłoszeń ─────────────────────────────────────────────────
$limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$q      = trim((string)($_GET['q'] ?? ''));
$offers = $rm->listOffers('active', $q, $limit, 0);

$out = array_map('rekrutacja_public_offer', $offers);

echo json_encode([
    'ok'     => true,
    'org'    => $org,
    'count'  => count($out),
    'offers' => $out,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
