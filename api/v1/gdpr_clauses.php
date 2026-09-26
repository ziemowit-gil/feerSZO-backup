<?php
/**
 * REST API — Klauzule RODO (modules/gdpr_clauses).
 *
 * Odczyt opublikowanych klauzul jest PUBLICZNY (to treść publiczna — ta sama co
 * /klauzula/{slug}), bez klucza, z CORS *. Rejestr akceptacji — tylko z kluczem
 * (Authorization: Bearer <key>), bo zawiera dane osobowe.
 *
 *   GET  /api/v1/gdpr_clauses.php                         → lista opublikowanych (slug, lang, tytuł, wersja)
 *   GET  /api/v1/gdpr_clauses.php?slug=X[&lang=en]        → klauzula: html, text, wersja, języki, url, pdf_url
 *   POST /api/v1/gdpr_clauses.php?resource=acceptances    → zapis akceptacji         (gdpr:write)
 *        body: slug, lang?, version? (domyślnie bieżąca), subject_name, subject_email,
 *              ref_type?, ref_id?, context? (domyślnie "api"),
 *              subject_ip?, subject_user_agent? (dane osoby — żądanie idzie z serwera systemu zewnętrznego)
 *   GET  /api/v1/gdpr_clauses.php?resource=acceptances    → akceptacje, filtry: email, slug, from, to  (gdpr:read)
 *
 * Wersja w POST pozwala zapisać dokładnie tę wersję, którą system zewnętrzny
 * pobrał i pokazał osobie (pole "version" z GET), a nie bieżącą z chwili zapisu.
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/modules/gdpr_clauses/logic/gdpr_clauses.php';

api_auth_migrate();
$svc = new GdprClauseService();

$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$resource = (string)($_GET['resource'] ?? '');

/** Tekst bez HTML (akapity i punkty w osobnych wierszach) — dla SMS/PDF/aplikacji mobilnych. */
function gdpr_api_plain(string $html): string {
    $t = preg_replace(['~</(p|h2|h3|li)>~', '~<br\s*/?>~', '~<li>~'], ["\n\n", "\n", '• '], $html);
    $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", $t));
}

// ── Publiczny odczyt ─────────────────────────────────────────────────────────
if ($resource === '') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Cache-Control: no-cache, must-revalidate');
    if ($method === 'OPTIONS') { http_response_code(204); exit; }
    if ($method !== 'GET') api_error('Method not allowed', 405);

    $slug = strtolower(trim((string)($_GET['slug'] ?? '')));
    if ($slug === '') {
        $rows = db_all("SELECT slug, lang, tytul, version, updated_at FROM gdpr_clauses WHERE is_published = 1 ORDER BY slug, lang");
        api_json(['data' => array_map(fn($r) => [
            'slug' => $r['slug'], 'lang' => $r['lang'], 'title' => $r['tytul'], 'version' => (int)$r['version'],
            'updated_at' => $r['updated_at'], 'url' => gdpr_clauses_public_url($r['slug'], false, $r['lang']),
        ], $rows)]);
    }

    $c = $svc->getBySlug($slug, true, (string)($_GET['lang'] ?? GDPR_DEFAULT_LANG));
    if (!$c) api_error('Not found', 404);
    $svc->trackView((int)$c['id'], 'api');
    $html = $svc->renderClause($c);
    api_json(['data' => [
        'slug'       => $c['slug'],
        'lang'       => $c['lang'],
        'languages'  => array_keys($svc->languagesFor($c['slug'])),
        'title'      => $c['tytul'],
        'version'    => (int)$c['version'],
        'updated_at' => $c['updated_at'],
        'html'       => $html,
        'text'       => gdpr_api_plain($html),
        'url'        => gdpr_clauses_public_url($c['slug'], false, $c['lang']),
        'embed_url'  => gdpr_clauses_public_url($c['slug'], true, $c['lang']),
        'pdf_url'    => gdpr_clauses_pdf_url($c['slug'], $c['lang']),
    ]]);
}

// ── Rejestr akceptacji (klucz API) ───────────────────────────────────────────
if ($resource !== 'acceptances') api_error('Unknown resource', 404);

if ($method === 'POST') {
    api_require('gdpr:write');
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    $in = stripos($ct, 'application/json') !== false ? (json_decode(file_get_contents('php://input') ?: '', true) ?: []) : $_POST;

    $slug = strtolower(trim((string)($in['slug'] ?? '')));
    $cur  = $svc->getBySlug($slug, true, (string)($in['lang'] ?? GDPR_DEFAULT_LANG));
    if (!$cur) api_error('Clause not found or not published', 404);
    $row = $cur;
    if (isset($in['version']) && (int)$in['version'] !== (int)$cur['version']) {
        $row = $svc->version((int)$cur['id'], (int)$in['version']);
        if (!$row) api_error('Unknown version for this clause', 422);
    }
    $email = trim((string)($in['subject_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('Invalid subject_email', 422);
    if ($email === '' && trim((string)($in['subject_name'] ?? '')) === '') api_error('subject_name or subject_email required', 422);

    $context = (string)($in['context'] ?? 'api');
    if (!isset(GdprClauseService::CONTEXTS[$context])) $context = 'api';
    $ref = !empty($in['ref_type']) ? [mb_substr((string)$in['ref_type'], 0, 40), (int)($in['ref_id'] ?? 0)] : null;

    $client = [];
    if (!empty($in['subject_ip'])) {
        if (!filter_var((string)$in['subject_ip'], FILTER_VALIDATE_IP)) api_error('Invalid subject_ip', 422);
        $client['ip'] = (string)$in['subject_ip'];
    }
    if (!empty($in['subject_user_agent'])) $client['user_agent'] = (string)$in['subject_user_agent'];

    $id  = $svc->recordAcceptance($row, $context, ['name' => (string)($in['subject_name'] ?? ''), 'email' => $email], $ref, $client);
    $acc = $svc->acceptanceById($id);
    api_audit('create', 'gdpr_acceptance', $id, ['slug', 'version', 'subject_email'], 201);
    api_json(['data' => ['id' => $id, 'slug' => $acc['slug'], 'lang' => $acc['lang'], 'version' => (int)$acc['version'],
                         'accepted_at' => $acc['accepted_at'], 'snapshot_hash' => $acc['snapshot_hash']]], 201);
}

if ($method === 'GET') {
    api_require('gdpr:read');
    $f = [
        'q'    => trim((string)($_GET['email'] ?? '')),
        'slug' => strtolower(trim((string)($_GET['slug'] ?? ''))),
        'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '',
        'to'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '',
    ];
    $limit  = max(1, min(500, (int)($_GET['limit'] ?? 100)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $rows = $svc->acceptances($f, $limit, $offset);
    api_audit('list', 'gdpr_acceptance', null, [], 200);
    api_json([
        'data' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'slug' => $r['slug'], 'lang' => $r['lang'], 'version' => (int)$r['version'],
            'context' => $r['context'], 'ref_type' => $r['ref_type'], 'ref_id' => $r['ref_id'] !== null ? (int)$r['ref_id'] : null,
            'subject_name' => $r['subject_name'], 'subject_email' => $r['subject_email'],
            'accepted_at' => $r['accepted_at'], 'snapshot_hash' => $r['snapshot_hash'],
        ], $rows),
        'meta' => ['total' => $svc->acceptanceCount($f), 'limit' => $limit, 'offset' => $offset],
    ]);
}

api_error('Method not allowed', 405);
