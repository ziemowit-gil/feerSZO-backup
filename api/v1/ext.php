<?php
/**
 * REST API — Materiały zewnętrzne (katalog i dziennik dostępu).
 * Bearer token (Authorization: Bearer <key>) albo ?api_key=<key>. Scope: ext:read.
 *
 * Routing:
 *   GET ?resource=titles                → katalog (filtry: q, publisher, category; paginacja)
 *   GET ?resource=titles&id=N           → tytuł ze spisem wydań i zasobów
 *   GET ?resource=publishers            → wydawcy wraz ze stanem umów
 *   GET ?resource=categories            → drzewo kategorii
 *   GET ?resource=licenses              → licencje (także wygasające)
 *   GET ?resource=log                   → dziennik dostępu (filtry: decision, from, to)
 *
 * API JEST TYLKO DO ODCZYTU — tak samo jak w EZD. Powód nie jest techniczny:
 * bilet do treści jest OSOBOWY (przypisany do konta i przeglądarki, zapisany
 * w dzienniku), a klucz API uwierzytelnia maszynę, nie człowieka. Wystawianie
 * biletów kluczem oznaczałoby, że dowolny integrator otwiera książki „za kogoś",
 * a dziennik przestaje być dowodem wykonania umowy z wydawcą. Treści pilnuje
 * karty30/ti/ext/file.php i sesja użytkownika.
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/ext_access.php';

api_auth_migrate();
try { ext_migrate(); } catch (\Throwable $e) { /* schemat naprawi się przy wejściu w moduł */ }

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET') {
    api_error('To API jest tylko do odczytu — treści i bilety wymagają sesji użytkownika.', 405);
}
api_require('ext:read');

$resource = (string)($_GET['resource'] ?? '');
$id       = (int)($_GET['id'] ?? 0);
$limit    = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$offset   = max(0, (int)($_GET['offset'] ?? 0));

if ($resource === '') {
    api_json(['data' => ['resources' => ['titles', 'publishers', 'categories', 'licenses', 'log']],
              'meta' => ['hint' => 'Podaj ?resource=<nazwa>. API tylko do odczytu.']]);
}

switch ($resource) {

case 'titles':
    if ($id) {
        $t = ext_title_get($id);
        if (!$t) api_error('Nie znaleziono tytułu.', 404);

        $editions = [];
        foreach (ext_editions($id, false) as $e) {
            $res = [];
            foreach (ext_resources((int)$e['id'], false) as $r) {
                // Skrótu pliku ani ścieżki NIE wystawiamy — z checksumu da się
                // odtworzyć nazwę w magazynie, a magazyn ma pozostać niewidoczny.
                $res[] = [
                    'id' => (int)$r['id'], 'name' => $r['name'], 'kind' => $r['kind'],
                    'mime' => $r['mime'], 'size_bytes' => (int)$r['size_bytes'],
                    'pages' => $r['pages'] !== null ? (int)$r['pages'] : null,
                    'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
                    'is_active' => (bool)$r['is_active'],
                ];
            }
            $editions[] = ['id' => (int)$e['id'], 'name' => $e['name'], 'year' => $e['year'],
                           'isbn' => $e['isbn'], 'resources' => $res];
        }

        api_json(['data' => [
            'id' => (int)$t['id'], 'title' => $t['title'], 'subtitle' => $t['subtitle'],
            'authors' => $t['authors'], 'publisher' => $t['publisher_name'],
            'access_level' => $t['access_level'], 'rights_note' => $t['rights_note'],
            'allow_download' => (bool)$t['allow_download'], 'allow_print' => (bool)$t['allow_print'],
            'watermark_policy' => $t['watermark_policy'],
            'embargo_until' => $t['embargo_until'], 'editions' => $editions,
        ]]);
    }

    $where = ['t.is_active = 1']; $p = [];
    if (($q = trim((string)($_GET['q'] ?? ''))) !== '') {
        $where[] = '(t.title LIKE ? OR t.authors LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%";
    }
    if ($pub = (int)($_GET['publisher'] ?? 0)) { $where[] = 't.publisher_id = CAST(? AS INTEGER)'; $p[] = $pub; }
    if ($cat = (int)($_GET['category'] ?? 0)) {
        $where[] = "EXISTS (SELECT 1 FROM k30_ext_category_title ct
                            JOIN k30_ext_categories c ON c.id = ct.category_id
                            JOIN k30_ext_categories a ON c.path LIKE a.path || '%'
                            WHERE ct.title_id = t.id AND a.id = CAST(? AS INTEGER))";
        $p[] = $cat;
    }

    $rows = db_all("SELECT t.id, t.title, t.authors, t.access_level, t.embargo_until,
                           p.name AS publisher
                    FROM k30_ext_titles t
                    JOIN k30_ext_publishers p ON p.id = t.publisher_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY t.title COLLATE NOCASE LIMIT $limit OFFSET $offset", $p);
    $total = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ext_titles t WHERE " . implode(' AND ', $where), $p)['n'] ?? 0);
    api_json(['data' => $rows, 'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]]);

case 'publishers':
    api_json(['data' => array_map(fn($p) => [
        'id' => (int)$p['id'], 'name' => $p['name'], 'contract_no' => $p['contract_no'],
        'contract_to' => $p['contract_to'], 'is_active' => (bool)$p['is_active'],
        'default_rules' => ext_json_decode($p['default_rules']),
    ], ext_publishers(false))]);

case 'categories':
    api_json(['data' => array_map(fn($c) => [
        'id' => (int)$c['id'], 'name' => $c['name'],
        'parent_id' => $c['parent_id'] !== null ? (int)$c['parent_id'] : null,
        'depth' => (int)$c['depth'], 'path' => $c['path'],
    ], ext_categories())]);

case 'licenses':
    $rows = db_all("SELECT l.*, p.name AS publisher
                    FROM k30_ext_licenses l JOIN k30_ext_publishers p ON p.id = l.publisher_id
                    ORDER BY l.valid_to DESC LIMIT $limit OFFSET $offset");
    api_json(['data' => array_map(fn($l) => [
        'id' => (int)$l['id'], 'name' => $l['name'], 'publisher' => $l['publisher'],
        'kind' => $l['kind'], 'valid_from' => $l['valid_from'], 'valid_to' => $l['valid_to'],
        'seats' => $l['seats'] !== null ? (int)$l['seats'] : null,
        'seats_used' => (int)(db_one("SELECT COUNT(*) AS n FROM k30_ext_license_seats
                                      WHERE license_id=? AND released_at IS NULL", [(int)$l['id']])['n'] ?? 0),
        'expires_in_days' => (int)floor((strtotime($l['valid_to']) - time()) / 86400),
        'is_active' => (bool)$l['is_active'],
    ], $rows)]);

case 'log':
    $where = []; $p = [];
    if (in_array($_GET['decision'] ?? '', ['granted','denied','served'], true)) {
        $where[] = 'decision = ?'; $p[] = $_GET['decision'];
    }
    if (!empty($_GET['from'])) { $where[] = 'created_at >= ?'; $p[] = (string)$_GET['from']; }
    if (!empty($_GET['to']))   { $where[] = 'created_at <= ?'; $p[] = (string)$_GET['to']; }

    api_json(['data' => db_all(
        "SELECT id, subject_type, subject_name, resource_id, title_id, ability, decision, reason, created_at
         FROM k30_ext_access_log"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY id DESC LIMIT $limit OFFSET $offset", $p)]);

default:
    api_error('Nieznany zasób. Dostępne: titles, publishers, categories, licenses, log.', 404);
}
