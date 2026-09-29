<?php
/**
 * REST API — darowizny przyjęte przez zewnętrzną stronę (CMS feer-web).
 *
 * Strona przyjmuje płatność w Przelewy24 u siebie i woła ten endpoint
 * serwer-serwer DOPIERO po potwierdzeniu transakcji (transaction/verify).
 * Rejestr `donations` nie ma statusu — każdy wiersz to wpłata, która wpłynęła —
 * więc nie wolno tu wysyłać wpłat „w toku".
 *
 * Routing:
 *   POST /api/v1/donations.php  → zapisz darowiznę (+ kontakt i zgody)
 *
 * Ciało POST:
 *   {
 *     "external_id":   "p24:7f3c…",          // WYMAGANE — klucz idempotencji
 *     "amount":        50.00,                 // PLN, > 0
 *     "currency":      "PLN",
 *     "donation_date": "2026-09-29",          // domyślnie dziś; nie z przyszłości
 *     "channel":       "p24",                 // klucz DONATION_CHANNELS
 *     "bank_ref":      "312345678",           // np. orderId z Przelewy24
 *     "purpose":       "Darowizna na cele statutowe",
 *     "is_anonymous":  false,
 *     "note":          "…",
 *     "donor":    {"imie_nazwisko":"…", "email":"…", "telefon":"…", "adres":"…"},
 *     "form":     "darowizna",               // opcjonalnie: formularz SZO, przez który
 *                                            // idzie kontakt i zgody (rejestr zgód)
 *     "consents": ["rodo","newsletter"],     // zgody wg definicji tego formularza
 *     "meta":     {"ip":"1.2.3.4", "url":"https://…/wsparcie/darowizna"}
 *   }
 *
 * Odpowiedź 201: {ok, duplicate:false, donation_id, contact_id, contact_created}
 * Odpowiedź 200: {ok, duplicate:true,  donation_id, contact_id} — ta sama wpłata
 *                przyszła drugi raz (ponowienie po timeoucie, drugi webhook).
 *
 * Uprawnienie: donations:submit. Osobne od crm:write i forms:submit — klucz
 * strony ma móc dopisywać wpłaty, a nie edytować rejestr czy kartotekę.
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/crm.php';
require_once dirname(__DIR__, 2) . '/includes/crm_form_intake.php';
require_once dirname(__DIR__, 2) . '/includes/donations.php';

api_auth_migrate();
crm_migrate();
crm_form_intake_schema_heal();
donations_migrate();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$remote = $_SERVER['REMOTE_ADDR'] ?? '';

if ($method !== 'POST') {
    api_error('Method Not Allowed', 405);
}
api_require('donations:submit');

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) api_error('Invalid JSON body', 400);

// ── Walidacja ──────────────────────────────────────────────────────────────
$external_id = trim((string)($body['external_id'] ?? ''));
if ($external_id === '' || mb_strlen($external_id) > 190) {
    api_error('Brak pola „external_id" (identyfikator wpłaty po stronie CMS-a).', 422);
}
// Prefiks odróżnia wpłaty ze strony od innych importów z bramek w tym samym rejestrze.
$ext_source = 'cms:' . $external_id;

$amount = round((float)($body['amount'] ?? 0), 2);
if ($amount <= 0) api_error('Kwota musi być większa od zera.', 422);

$currency = strtoupper(trim((string)($body['currency'] ?? 'PLN'))) ?: 'PLN';
if (!preg_match('/^[A-Z]{3}$/', $currency)) api_error('Nieprawidłowy kod waluty.', 422);

$date = trim((string)($body['donation_date'] ?? '')) ?: date('Y-m-d');
$dt   = DateTime::createFromFormat('!Y-m-d', $date);
if (!$dt || $dt->format('Y-m-d') !== $date) api_error('Nieprawidłowa data (oczekiwany format RRRR-MM-DD).', 422);
if ($date > date('Y-m-d')) api_error('Data darowizny nie może być z przyszłości.', 422);

$channel = (string)($body['channel'] ?? 'p24');
if (!array_key_exists($channel, DONATION_CHANNELS)) api_error('Nieznany kanał wpłaty.', 422);

$donor = (array)($body['donor'] ?? []);
$email = trim((string)($donor['email'] ?? ''));
$name  = trim((string)($donor['imie_nazwisko'] ?? ''));
if ($name === '') {
    $name = trim(trim((string)($donor['imie'] ?? '')) . ' ' . trim((string)($donor['nazwisko'] ?? '')));
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('Nieprawidłowy adres e-mail.', 422);
if ($name === '' && $email === '') api_error('Brak danych darczyńcy (imię i nazwisko lub e-mail).', 422);

$meta = (array)($body['meta'] ?? []);
$ip   = (string)($meta['ip'] ?? '') ?: $remote;

// ── Idempotencja ───────────────────────────────────────────────────────────
$seen = crm_one("SELECT id, contact_id FROM donations WHERE ext_source = ? AND deleted_at IS NULL", [$ext_source]);
if ($seen) {
    api_json([
        'ok'          => true,
        'duplicate'   => true,
        'donation_id' => (int)$seen['id'],
        'contact_id'  => (int)$seen['contact_id'],
        'message'     => 'Darowizna o tym external_id jest już w rejestrze — nic nie zdublowano.',
    ]);
}

// ── Kontakt (+ zgody) ──────────────────────────────────────────────────────
$contact_data = array_filter([
    'imie_nazwisko' => $name,
    'email'         => $email,
    'telefon'       => trim((string)($donor['telefon'] ?? '')),
    'adres'         => trim((string)($donor['adres'] ?? '')),
], fn($v) => $v !== '');

$contact_id      = 0;
$contact_created = false;
$form_slug = trim((string)($body['form'] ?? ''));
$form      = $form_slug !== '' ? db_one("SELECT * FROM crm_web_forms WHERE slug=? AND is_active=1", [$form_slug]) : null;

if ($form) {
    // Ta sama ścieżka co zgłoszenia z formularzy: dedup po e-mailu, uzupełnianie
    // wyłącznie pustych pól i zgody do rejestru (cel, moment, IP, klauzula).
    $res = crm_form_intake($form, $contact_data, [
        'consents' => (array)($body['consents'] ?? []),
        'ip'       => $ip,
        'source'   => 'cms:darowizna',
    ]);
    crm_form_intake_log([
        'source'     => 'cms',
        'form_slug'  => $form_slug,
        'contact_id' => $res['contact_id'] ?? null,
        'inbox_id'   => $res['inbox_id']   ?? null,
        'status'     => !empty($res['ok']) ? 'ok' : 'error',
        'error'      => (string)($res['error'] ?? ''),
        'payload'    => ['data' => $contact_data, 'meta' => $meta + ['external_id' => $external_id]],
        'remote_ip'  => $remote,
    ]);
    if (!empty($res['ok'])) {
        $contact_id      = (int)$res['contact_id'];
        $contact_created = (bool)$res['created'];
    }
} elseif ($email !== '') {
    // Bez formularza: tylko dopasowanie po e-mailu albo nowy kontakt.
    $existing = crm_one(
        "SELECT id FROM crm_contacts WHERE crm_active=1 AND lower(email)=lower(?) ORDER BY id LIMIT 1",
        [$email]
    );
    if ($existing) {
        $contact_id = (int)$existing['id'];
    } else {
        $contact_id = (int)crm_insert('crm_contacts', $contact_data + [
            'imie_nazwisko' => $name ?: $email,
            'type'          => 'osoba',
            'status'        => 'prospect',
            'source'        => 'cms:darowizna',
            'crm_active'    => 1,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $contact_created = $contact_id > 0;
    }
}
// Brak kontaktu nie blokuje zapisu wpłaty — pieniądze wpłynęły i muszą być
// w rejestrze; dane darczyńcy i tak zostają na samej darowiźnie.

// ── Darowizna ──────────────────────────────────────────────────────────────
$key = $GLOBALS['api_current_key'] ?? [];
try {
    $donation_id = donation_add([
        'contact_id'    => $contact_id,
        'donor_name'    => $name ?: $email,
        'donor_address' => (string)($donor['adres'] ?? ''),
        'kind'          => 'pieniezna',
        'amount'        => $amount,
        'currency'      => $currency,
        'donation_date' => $date,
        'channel'       => $channel,
        'purpose'       => (string)($body['purpose'] ?? ''),
        'bank_ref'      => (string)($body['bank_ref'] ?? ''),
        'is_anonymous'  => !empty($body['is_anonymous']),
        'note'          => (string)($body['note'] ?? ''),
        'ext_source'    => $ext_source,
    ], (int)($key['created_by'] ?? 0));
} catch (\Throwable $e) {
    // Wyścig dwóch równoczesnych powiadomień — drugie trafia w unikalny indeks.
    $seen = crm_one("SELECT id, contact_id FROM donations WHERE ext_source = ? AND deleted_at IS NULL", [$ext_source]);
    if ($seen) {
        api_json(['ok' => true, 'duplicate' => true, 'donation_id' => (int)$seen['id'], 'contact_id' => (int)$seen['contact_id']]);
    }
    api_error('Nie udało się zapisać darowizny.', 500);
}

if (!$donation_id) api_error('Nie udało się zapisać darowizny (niekompletne dane).', 422);

if ($contact_id) {
    try {
        CrmManager::addNote($contact_id, sprintf(
            'Darowizna online %s %s (%s, %s).',
            number_format($amount, 2, ',', ' '), $currency, DONATION_CHANNELS[$channel], $date
        ), null);
    } catch (\Throwable $e) {}
}

api_audit('create', 'donations', $donation_id, array_keys($body), 201);

api_json([
    'ok'              => true,
    'duplicate'       => false,
    'donation_id'     => $donation_id,
    'contact_id'      => $contact_id,
    'contact_created' => $contact_created,
], 201);
