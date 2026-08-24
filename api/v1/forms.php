<?php
/**
 * REST API — przyjmowanie zgłoszeń z formularzy zewnętrznych (CMS).
 *
 * CMS stoi pod INNYM adresem niż SZO, więc integracja jest serwer-serwer:
 * Laravel woła ten endpoint z tokenem Bearer. Nie ma tu nic dla przeglądarki —
 * żadnego CORS, żadnej sesji, żadnego ciasteczka. Gdyby formularz wołał ten
 * adres z poziomu strony, token siedziałby w kodzie strony, czyli publicznie.
 *
 * Routing:
 *   GET  /api/v1/forms.php               → lista aktywnych formularzy SZO
 *                                          (slug, tytuł, pola, zgody) — po to,
 *                                          żeby w CMS-ie dało się je zmapować
 *   GET  /api/v1/forms.php?slug=X        → jeden formularz ze szczegółami
 *   POST /api/v1/forms.php               → przyjmij zgłoszenie
 *
 * Ciało POST:
 *   {
 *     "form":     "kontakt",              // slug formularza w SZO
 *     "data":     {"imie_nazwisko":"…", "email":"…", "telefon":"…", …},
 *     "custom":   {"12":"wartość"},       // pola niestandardowe po ID definicji
 *     "consents": ["c1","c2"],            // zaznaczone zgody
 *     "meta":     {"ip":"1.2.3.4", "url":"https://feer.org.pl/kontakt", "external_id":"77"},
 *     "intake":   {"inbox":true,"activity":true,"task":false}   // nadpisanie skutków
 *   }
 *
 * Odpowiedź: {ok, contact_id, created, inbox_id, activity_id, task_id, duplicate}
 *
 * Uprawnienia: forms:read (GET), forms:submit (POST). Osobne od crm:write —
 * token dla CMS-a ma móc TYLKO przyjmować zgłoszenia, nie kasować kontaktów.
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/crm.php';
require_once dirname(__DIR__, 2) . '/includes/crm_form_intake.php';

api_auth_migrate();
crm_migrate();
crm_form_intake_schema_heal();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$remote = $_SERVER['REMOTE_ADDR'] ?? '';

// ── Odczyt: katalog formularzy do zmapowania po stronie CMS ────────────────
if ($method === 'GET') {
    api_require('forms:read');

    $slug = trim((string)($_GET['slug'] ?? ''));
    $sql  = "SELECT * FROM crm_web_forms WHERE is_active=1" . ($slug ? " AND slug=?" : '') . " ORDER BY title";
    $rows = db_all($sql, $slug ? [$slug] : []);

    $out = array_map(function (array $f) {
        $fields   = json_decode((string)$f['fields_json'],   true) ?: [];
        $consents = json_decode((string)($f['consents_json'] ?? '[]'), true) ?: [];
        return [
            'slug'        => $f['slug'],
            'title'       => $f['title'],
            'description' => $f['description'],
            // Nazwy pól to KONTRAKT: CMS musi przysłać dokładnie te klucze.
            'fields' => array_map(fn($x) => [
                'name'     => $x['name']  ?? '',
                'label'    => $x['label'] ?? '',
                'type'     => $x['type']  ?? 'text',
                'required' => !empty($x['required']),
                'field_def_id' => isset($x['field_def_id']) ? (int)$x['field_def_id'] : null,
            ], $fields),
            'consents' => array_map(fn($c) => [
                'id'       => (string)($c['id'] ?? ''),
                'text'     => (string)($c['text'] ?? ''),
                'required' => !empty($c['required']),
            ], $consents),
            'intake' => crm_form_intake_config($f),
        ];
    }, $rows);

    if ($slug) {
        if (!$out) api_error('Nie ma aktywnego formularza o tym slugu.', 404);
        api_json(['ok' => true, 'form' => $out[0]]);
    }
    api_json(['ok' => true, 'forms' => $out, 'count' => count($out)]);
}

// ── Zapis: przyjęcie zgłoszenia ────────────────────────────────────────────
if ($method !== 'POST') {
    api_error('Method Not Allowed', 405);
}
api_require('forms:submit');

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if ($raw !== '' && !is_array($body)) api_error('Invalid JSON body', 400);
$body = is_array($body) ? $body : $_POST;

$slug = trim((string)($body['form'] ?? ''));
$data = (array)($body['data'] ?? []);
$meta = (array)($body['meta'] ?? []);

if ($slug === '') api_error('Brak pola „form" (slug formularza w SZO).', 400);
if (!$data)       api_error('Brak pola „data" ze zgłoszeniem.', 400);

$form = db_one("SELECT * FROM crm_web_forms WHERE slug=? AND is_active=1", [$slug]);
if (!$form) {
    crm_form_intake_log([
        'source' => 'cms', 'form_slug' => $slug, 'status' => 'error',
        'error'  => 'Nie ma aktywnego formularza o tym slugu.',
        'payload' => $body, 'remote_ip' => $remote,
    ]);
    api_error('Nie ma aktywnego formularza o slugu „' . $slug . '".', 404);
}

// Idempotencja: CMS może ponowić żądanie po timeoucie, a wtedy nie wolno
// założyć drugiego zgłoszenia. Kluczem jest identyfikator po stronie CMS-a.
$external_id = trim((string)($meta['external_id'] ?? ''));
if ($external_id !== '') {
    $seen = db_one(
        "SELECT contact_id, inbox_id FROM crm_form_intake_log
          WHERE source='cms' AND form_slug=? AND status='ok' AND payload LIKE ?
          ORDER BY id DESC LIMIT 1",
        [$slug, '%"external_id":"' . $external_id . '"%']
    );
    if ($seen) {
        api_json([
            'ok'         => true,
            'duplicate'  => true,
            'contact_id' => (int)$seen['contact_id'],
            'inbox_id'   => (int)$seen['inbox_id'],
            'message'    => 'Zgłoszenie o tym external_id było już przyjęte — nic nie zdublowano.',
        ]);
    }
}

$res = crm_form_intake($form, $data, [
    'custom'   => (array)($body['custom']   ?? []),
    'consents' => (array)($body['consents'] ?? []),
    'intake'   => (array)($body['intake']   ?? []),
    // IP zgłaszającego przekazuje CMS — do rejestru zgód ma trafić adres
    // człowieka, który klikał, a nie serwera, który przekazał żądanie.
    'ip'       => (string)($meta['ip'] ?? '') ?: $remote,
    'source'   => 'cms:' . $slug,
]);

crm_form_intake_log([
    'source'     => 'cms',
    'form_slug'  => $slug,
    'contact_id' => $res['contact_id'] ?? null,
    'inbox_id'   => $res['inbox_id']   ?? null,
    'status'     => !empty($res['ok']) ? 'ok' : 'error',
    'error'      => (string)($res['error'] ?? ''),
    'payload'    => ['data' => $data, 'meta' => $meta],
    'remote_ip'  => $remote,
]);

if (empty($res['ok'])) {
    api_error((string)($res['error'] ?? 'Nie udało się przyjąć zgłoszenia.'), 422);
}

api_json([
    'ok'          => true,
    'duplicate'   => false,
    'contact_id'  => (int)$res['contact_id'],
    'created'     => (bool)$res['created'],
    'inbox_id'    => (int)($res['inbox_id']    ?? 0),
    'activity_id' => (int)($res['activity_id'] ?? 0),
    'task_id'     => (int)($res['task_id']     ?? 0),
    'contact_url' => APP_URL . '/crm/contact/view.php?id=' . (int)$res['contact_id'],
]);
