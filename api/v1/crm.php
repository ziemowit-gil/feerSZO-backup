<?php
/**
 * REST API — CRM (kontakty)
 * Bearer token (Authorization: Bearer <key>) lub ?api_key=<key>.
 * Uprawnienia: crm:read (odczyt), crm:write (zapis).
 *
 * Routing (jeden plik, bez rewrite — sterowanie metodą HTTP + parametrami):
 *   GET    /api/v1/crm.php                      → lista kontaktów (filtry + paginacja)
 *   GET    /api/v1/crm.php?id=N                 → pojedynczy kontakt (tagi/notatki/grupy)
 *   POST   /api/v1/crm.php                      → utwórz kontakt (JSON body)
 *   PATCH  /api/v1/crm.php?id=N                 → aktualizuj kontakt (JSON body)
 *   DELETE /api/v1/crm.php?id=N                 → soft-delete kontaktu
 *   POST   /api/v1/crm.php?id=N&resource=notes  → dodaj notatkę  (body: {note, pinned?})
 *   POST   /api/v1/crm.php?id=N&resource=tags   → dodaj tag      (body: {tag})
 *   DELETE /api/v1/crm.php?id=N&resource=tags&tag=X → usuń tag
 *
 * Metodę można nadpisać nagłówkiem X-HTTP-Method-Override lub ?_method=PATCH
 * (dla klientów bez wsparcia PATCH/DELETE).
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/crm.php';

api_auth_migrate();
crm_migrate();

// ── Routing ────────────────────────────────────────────────────────────────
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ($_GET['_method'] ?? '');
if ($override !== '') $method = strtoupper((string)$override);

$id       = (int)($_GET['id'] ?? 0);
$resource = (string)($_GET['resource'] ?? '');

// Autoryzacja zależna od metody
if ($method === 'GET') {
    api_require('crm:read');
} else {
    api_require('crm:write');
}

// ── Helpery ──────────────────────────────────────────────────────────────────

/** Body żądania jako tablica (JSON lub form-encoded). */
function crm_api_input(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input') ?: '';
        $j   = json_decode($raw, true);
        if ($raw !== '' && !is_array($j)) {
            api_error('Invalid JSON body', 400);
        }
        return is_array($j) ? $j : [];
    }
    return $_POST;
}

/** Serializuje wiersz kontaktu do publicznej postaci. */
function crm_api_contact(array $r): array {
    return [
        'id'               => (int)$r['id'],
        'type'             => $r['type'] ?? null,
        'status'           => $r['status'] ?? null,
        'imie_nazwisko'    => $r['imie_nazwisko'] ?? null,
        'imie'             => $r['imie'] ?? null,
        'nazwisko'         => $r['nazwisko'] ?? null,
        'email'            => $r['email'] ?? null,
        'telefon'          => $r['telefon'] ?? null,
        'organizacja'      => $r['organizacja'] ?? null,
        'stanowisko'       => $r['stanowisko'] ?? null,
        'nip'              => $r['nip'] ?? null,
        'krs'              => $r['krs'] ?? null,
        'regon'            => $r['regon'] ?? null,
        'forma_prawna'     => $r['forma_prawna'] ?? null,
        'branza'           => $r['branza'] ?? null,
        'strona_www'       => $r['strona_www'] ?? null,
        'osoba_kontaktowa' => $r['osoba_kontaktowa'] ?? null,
        'adres'            => $r['adres'] ?? null,
        'addr_street'      => $r['addr_street'] ?? null,
        'addr_house'       => $r['addr_house'] ?? null,
        'addr_flat'        => $r['addr_flat'] ?? null,
        'addr_postal'      => $r['addr_postal'] ?? null,
        'addr_city'        => $r['addr_city'] ?? null,
        'addr_country'     => $r['addr_country'] ?? null,
        'wojewodztwo'      => $r['wojewodztwo'] ?? null,
        'powiat'           => $r['powiat'] ?? null,
        'gmina'            => $r['gmina'] ?? null,
        'source'           => $r['source'] ?? null,
        'created_at'       => $r['created_at'] ?? null,
        'updated_at'       => $r['updated_at'] ?? null,
    ];
}

/** Pełny kontakt (z tagami/notatkami/grupami) lub null. */
function crm_api_get_full(int $id): ?array {
    $r = db_one("SELECT * FROM crm_contacts WHERE id=? AND crm_active=1", [$id]);
    if (!$r) return null;
    $out = crm_api_contact($r);
    $out['tags'] = array_map(
        fn($t) => $t['tag'],
        db_all("SELECT tag FROM crm_tags WHERE contact_id=? ORDER BY tag", [$id])
    );
    $out['notes'] = array_map(fn($n) => [
        'id'         => (int)$n['id'],
        'body'       => $n['body'],
        'is_pinned'  => (bool)$n['is_pinned'],
        'author'     => $n['author_name'] ?? null,
        'created_at' => $n['created_at'],
    ], db_all(
        "SELECT n.*, u.name AS author_name FROM crm_notes n
         LEFT JOIN users u ON u.id=n.created_by
         WHERE n.contact_id=? ORDER BY n.is_pinned DESC, n.created_at DESC",
        [$id]
    ));
    $out['groups'] = array_map(fn($g) => [
        'id'   => (int)$g['id'],
        'name' => $g['name'],
    ], CrmManager::getContactGroups($id));
    return $out;
}

/** Lista tagów kontaktu (płaska). */
function crm_api_tags(int $id): array {
    return array_map(
        fn($t) => $t['tag'],
        db_all("SELECT tag FROM crm_tags WHERE contact_id=? ORDER BY tag", [$id])
    );
}

/**
 * Waliduje i buduje payload zapisu z wejścia.
 * @param bool $require_name  Czy imie_nazwisko jest wymagane (create).
 * @return array{0:array,1:array}  [$data, $errors]
 */
function crm_api_build(array $in, bool $require_name): array {
    $errors = [];
    $data   = [];

    // Dozwolone pola wejściowe (CrmManager i tak filtruje whitelistą)
    $fields = [
        'type','status','imie_nazwisko','imie','nazwisko','email','telefon',
        'organizacja','stanowisko','nip','krs','regon','forma_prawna','branza',
        'strona_www','osoba_kontaktowa','adres','data_urodzenia','pesel',
        'addr_street','addr_house','addr_flat','addr_postal','addr_city','addr_country',
        'wojewodztwo','powiat','gmina','source',
    ];
    foreach ($fields as $f) {
        if (array_key_exists($f, $in)) {
            $v = $in[$f];
            $data[$f] = is_string($v) ? trim($v) : $v;
        }
    }

    // imie + nazwisko → imie_nazwisko (jeśli nie podano wprost)
    if (empty($data['imie_nazwisko'])) {
        $full = trim(($data['imie'] ?? '') . ' ' . ($data['nazwisko'] ?? ''));
        if ($full !== '') $data['imie_nazwisko'] = $full;
    }

    if ($require_name && empty($data['imie_nazwisko'])) {
        $errors[] = 'Pole imie_nazwisko (lub imie/nazwisko) jest wymagane.';
    }

    if (isset($data['type']) && !in_array($data['type'], ['osoba','organizacja'], true)) {
        $errors[] = "Pole type musi być 'osoba' lub 'organizacja'.";
    }

    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Nieprawidłowy adres e-mail.';
    }

    if (isset($data['status']) && $data['status'] !== ''
        && !array_key_exists($data['status'], crm_statuses())) {
        $errors[] = 'Nieprawidłowy status.';
    }

    // Normalizacja numerów identyfikacyjnych
    foreach (['nip','krs','regon','pesel'] as $idf) {
        if (!empty($data[$idf])) $data[$idf] = preg_replace('/[\s\-]/', '', (string)$data[$idf]);
    }

    return [$data, $errors];
}

// ══════════════════════════════════════════════════════════════════════════════
// SUB-ZASOBY: notatki / tagi  (?id=N&resource=...)
// ══════════════════════════════════════════════════════════════════════════════
if ($resource !== '') {
    if ($id <= 0) api_error('Brak parametru id.', 400);
    if (!db_one("SELECT id FROM crm_contacts WHERE id=? AND crm_active=1", [$id])) {
        api_error('Nie znaleziono kontaktu.', 404);
    }
    global $api_current_key;
    $author = (int)($api_current_key['created_by'] ?? 0) ?: null;

    if ($resource === 'notes') {
        if ($method !== 'POST') api_error('Method Not Allowed', 405);
        $in   = crm_api_input();
        $body = trim((string)($in['note'] ?? $in['body'] ?? ''));
        if ($body === '') api_error('Pole note jest wymagane.', 400);
        $note_id = CrmManager::addNote($id, $body, $author, !empty($in['pinned']));
        api_json(['data' => ['id' => $note_id, 'contact_id' => $id, 'body' => $body]], 201);
    }

    if ($resource === 'tags') {
        if ($method === 'POST') {
            $in  = crm_api_input();
            $tag = trim((string)($in['tag'] ?? ''));
            if ($tag === '') api_error('Pole tag jest wymagane.', 400);
            CrmManager::addTag($id, $tag);
            api_json(['data' => ['contact_id' => $id, 'tags' => crm_api_tags($id)]], 201);
        }
        if ($method === 'DELETE') {
            $tag = trim((string)($_GET['tag'] ?? ''));
            if ($tag === '') api_error('Parametr tag jest wymagany.', 400);
            CrmManager::removeTag($id, $tag);
            api_json(['data' => ['contact_id' => $id, 'tags' => crm_api_tags($id)]]);
        }
        api_error('Method Not Allowed', 405);
    }

    api_error('Nieznany zasób.', 404);
}

// ══════════════════════════════════════════════════════════════════════════════
// KONTAKT POJEDYNCZY  (?id=N)
// ══════════════════════════════════════════════════════════════════════════════
if ($id > 0) {
    if ($method === 'GET') {
        $c = crm_api_get_full($id);
        if (!$c) api_error('Nie znaleziono kontaktu.', 404);
        api_json(['data' => $c]);
    }

    if ($method === 'PATCH' || $method === 'PUT') {
        if (!db_one("SELECT id FROM crm_contacts WHERE id=? AND crm_active=1", [$id])) {
            api_error('Nie znaleziono kontaktu.', 404);
        }
        [$data, $errors] = crm_api_build(crm_api_input(), false);
        if ($errors) api_error(implode(' ', $errors), 422);
        if (!$data) api_error('Brak pól do aktualizacji.', 400);
        CrmManager::updateContact($id, $data);
        api_json(['data' => crm_api_get_full($id)]);
    }

    if ($method === 'DELETE') {
        if (!db_one("SELECT id FROM crm_contacts WHERE id=? AND crm_active=1", [$id])) {
            api_error('Nie znaleziono kontaktu.', 404);
        }
        CrmManager::deleteContact($id);
        api_json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    api_error('Method Not Allowed', 405);
}

// ══════════════════════════════════════════════════════════════════════════════
// KOLEKCJA  (bez id)
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    global $api_current_key;
    [$data, $errors] = crm_api_build(crm_api_input(), true);
    if ($errors) api_error(implode(' ', $errors), 422);
    if (empty($data['type']))   $data['type']   = 'osoba';
    if (empty($data['source'])) $data['source'] = 'api';
    if (empty($data['status'])) $data['status'] = 'prospect';
    $data['created_by'] = (int)($api_current_key['created_by'] ?? 0) ?: null;
    $new_id = CrmManager::createContact($data);
    api_json(['data' => crm_api_get_full($new_id)], 201);
}

if ($method !== 'GET') {
    api_error('Method Not Allowed', 405);
}

// ── GET: lista z filtrami + paginacją ─────────────────────────────────────────
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
$offset   = ($page - 1) * $per_page;

$where  = ['c.crm_active = 1'];
$params = [];

if (!empty($_GET['q'])) {
    $where[] = '(c.imie_nazwisko LIKE ? OR c.email LIKE ? OR c.organizacja LIKE ? OR c.telefon LIKE ?)';
    $q = '%' . $_GET['q'] . '%';
    array_push($params, $q, $q, $q, $q);
}
foreach (['status' => 'c.status = ?', 'type' => 'c.type = ?', 'source' => 'c.source = ?',
          'wojewodztwo' => 'c.wojewodztwo = ?'] as $key => $cond) {
    if (!empty($_GET[$key])) { $where[] = $cond; $params[] = $_GET[$key]; }
}
if (!empty($_GET['branza'])) { $where[] = 'c.branza LIKE ?'; $params[] = '%' . $_GET['branza'] . '%'; }
if (!empty($_GET['tag'])) {
    $where[]  = 'EXISTS (SELECT 1 FROM crm_tags t WHERE t.contact_id=c.id AND t.tag=?)';
    $params[] = mb_strtolower(trim($_GET['tag']));
}
if (!empty($_GET['has_email'])) $where[] = "(c.email IS NOT NULL AND c.email != '')";
if (!empty($_GET['has_phone'])) $where[] = "(c.telefon IS NOT NULL AND c.telefon != '')";
if (!empty($_GET['created_from'])) { $where[] = 'c.created_at >= ?'; $params[] = substr($_GET['created_from'], 0, 10) . ' 00:00:00'; }
if (!empty($_GET['created_to']))   { $where[] = 'c.created_at <= ?'; $params[] = substr($_GET['created_to'], 0, 10) . ' 23:59:59'; }

$sql_where = 'WHERE ' . implode(' AND ', $where);

$total = (int)(db_one("SELECT COUNT(*) AS cnt FROM crm_contacts c $sql_where", $params)['cnt'] ?? 0);

$rows = db_all(
    "SELECT c.*,
            (SELECT GROUP_CONCAT(t.tag, ',') FROM crm_tags t WHERE t.contact_id=c.id) AS tags_csv
       FROM crm_contacts c
       $sql_where
       ORDER BY c.updated_at DESC
       LIMIT ? OFFSET ?",
    array_merge($params, [$per_page, $offset])
);

$data = [];
foreach ($rows as $r) {
    $c = crm_api_contact($r);
    $c['tags'] = $r['tags_csv'] ? explode(',', $r['tags_csv']) : [];
    $data[] = $c;
}

api_json([
    'data' => $data,
    'meta' => [
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'pages'    => max(1, (int)ceil($total / $per_page)),
    ],
]);
