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
 *     "fields":   [{"name":"nip","label":"NIP","type":"text"}],  // opis pól z CMS-a — brakujące
 *                                          // pola SZO dopisuje do formularza (wbudowane albo pola dodatkowe CRM)
 *     "meta":     {"ip":"1.2.3.4", "url":"https://feer.org.pl/kontakt", "external_id":"77"},
 *     "intake":   {"inbox":true,"activity":true,"task":false}   // nadpisanie skutków
 *   }
 *
 * Odpowiedź: {ok, contact_id, created, inbox_id, activity_id, task_id, duplicate, created_fields}
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

    // Pola wbudowane kartoteki — w fields_json jest tylko klucz, etykieta i typ są tutaj.
    $builtin = [
        'imie_nazwisko' => ['Imię i nazwisko', 'text'],  'email'       => ['Adres e-mail', 'email'],
        'telefon'       => ['Telefon', 'tel'],           'organizacja' => ['Organizacja', 'text'],
        'stanowisko'    => ['Stanowisko', 'text'],       'adres'       => ['Adres', 'text'],
        'notatka'       => ['Wiadomość / Notatka', 'textarea'], 'wojewodztwo' => ['Województwo', 'text'],
        'powiat'        => ['Powiat', 'text'],           'gmina'       => ['Gmina', 'text'],
    ];
    $defs = [];
    foreach (db_all("SELECT id, label, field_type, options FROM crm_contact_field_defs") as $d) $defs[(int)$d['id']] = $d;

    $out = array_map(function (array $f) use ($builtin, $defs) {
        $fields   = json_decode((string)$f['fields_json'],   true) ?: [];
        $consents = json_decode((string)($f['consents_json'] ?? '[]'), true) ?: [];
        return [
            'slug'        => $f['slug'],
            'title'       => $f['title'],
            'description' => $f['description'],
            // Nazwy pól to KONTRAKT: CMS musi przysłać dokładnie te klucze.
            // Pole dodatkowe CRM (type=custom) bez klucza CMS wysyła w "custom" po field_def_id;
            // etykieta, typ i opcje pochodzą z jego definicji w kartotece.
            'fields' => array_map(function ($x) use ($builtin, $defs) {
                $defId = isset($x['field_def_id']) ? (int)$x['field_def_id'] : null;
                $def   = $defId ? ($defs[$defId] ?? null) : null;
                $name  = (string)($x['name'] ?? '');
                $type  = $def ? (string)$def['field_type'] : ($builtin[$name][1] ?? (string)($x['type'] ?? 'text'));
                return [
                    'name'         => $name,
                    'label'        => (string)($x['label'] ?? '') ?: ($def['label'] ?? ($builtin[$name][0] ?? '')),
                    'type'         => $type,
                    'required'     => !empty($x['required']),
                    'field_def_id' => $defId,
                    'options'      => $def && $type === 'select'
                                        ? array_values(array_filter(array_map('trim', explode(',', (string)$def['options'])), 'strlen'))
                                        : [],
                    'placeholder'  => (string)($x['placeholder'] ?? ''),
                ];
            }, $fields),
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

// Pola, których formularz SZO jeszcze nie ma, są dopisywane (pole wbudowane albo pole
// dodatkowe CRM) — wartości trafiają do kartoteki zamiast przepadać.
$ensured = crm_form_ensure_fields($form, $data, (array)($body['fields'] ?? []));
$form    = $ensured['form'];
// Listy (np. wybór wielokrotny) dalej jako tekst — kartoteka, skrzynka i powiadomienie oczekują napisów.
$data = array_map(fn($v) => is_array($v) ? implode(', ', array_map('strval', $v)) : $v, $data);

$res = crm_form_intake($form, $data, [
    'custom'   => (array)($body['custom'] ?? []) + $ensured['custom'],
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
    'created_fields' => $ensured['created'],
]);
