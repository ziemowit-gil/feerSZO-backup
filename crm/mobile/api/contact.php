<?php
/**
 * crm/mobile/api/contact.php — nowy kontakt z mobilnego dialera.
 *
 * POST {_csrf, kind: osoba|organizacja, name, tel, email?, company?, force?}
 *   → { ok, data: item }            item w formacie listy (api/search.php), od razu do zadzwonienia
 *   → { ok:false, dup:{cid,name} }  numer jest już w CRM — aplikacja pyta, czy zapisać mimo to
 *
 * Szybkie dodawanie z paska CRM (crm/api/quick_create.php) nie przyjmuje
 * telefonu, a w dialerze bez numeru kontakt nie ma sensu — stąd osobny punkt.
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/mobile.php';

crm_mobile_guard(true);

function cc_ok(mixed $d = null): never { echo json_encode(['ok' => true, 'data' => $d], JSON_UNESCAPED_UNICODE); exit; }
function cc_err(string $m, int $c = 400, array $extra = []): never
{
    http_response_code($c);
    echo json_encode(['ok' => false, 'error' => $m] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') cc_err('Wymagana metoda POST.', 405);

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) $body = $_POST;

$token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? ''));
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) cc_err('Błąd CSRF — odśwież aplikację.', 403);
if (function_exists('system_block_writes')) system_block_writes();
if (!can_write('crm') && !is_admin()) cc_err('Brak uprawnień do zapisu w CRM.', 403);

$kind    = ($body['kind'] ?? '') === 'organizacja' ? 'organizacja' : 'osoba';
$name    = trim((string)($body['name'] ?? ''));
$tel     = trim((string)($body['tel'] ?? ''));
$email   = trim((string)($body['email'] ?? ''));
$company = trim((string)($body['company'] ?? ''));

if ($name === '') cc_err($kind === 'osoba' ? 'Wpisz imię i nazwisko.' : 'Wpisz nazwę firmy lub organizacji.');
if (!crm_mobile_phones($tel)) cc_err('Wpisz numer telefonu (co najmniej 6 cyfr).');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) cc_err('Adres e-mail jest niepoprawny.');

// Ten sam numer już w kartotece? Porównujemy 9 ostatnich cyfr — „+48 600…”,
// „600-…” i „0048 600…” to ten sam telefon.
if (empty($body['force'])) {
    $tail = substr(preg_replace('/\D+/', '', $tel) ?? '', -9);
    if (strlen($tail) >= 6) {
        $cands = db_all(
            "SELECT id, imie_nazwisko, telefon FROM crm_contacts
              WHERE crm_active=1 AND telefon IS NOT NULL AND telefon LIKE ?
              LIMIT 20",
            ['%' . substr($tail, -3) . '%']
        );
        foreach ($cands as $c) {
            foreach (crm_mobile_phones((string)$c['telefon']) as $p) {
                if (str_ends_with(preg_replace('/\D+/', '', $p['tel']) ?? '', $tail)) {
                    cc_err('Ten numer ma już kontakt „' . $c['imie_nazwisko'] . '”.', 409,
                           ['dup' => ['cid' => (int)$c['id'], 'name' => (string)$c['imie_nazwisko']]]);
                }
            }
        }
    }
}

$uid = (int)(current_user()['id'] ?? 0);
$now = date('Y-m-d H:i:s');
$id  = db_insert('crm_contacts', [
    'type'            => $kind,
    'status'          => crm_status_default(),
    'imie_nazwisko'   => mb_substr($name, 0, 200),
    'telefon'         => mb_substr($tel, 0, 100),
    'email'           => $email !== '' ? $email : null,
    'organizacja'     => ($kind === 'osoba' && $company !== '') ? mb_substr($company, 0, 200) : null,
    'avatar_initials' => crm_mobile_initials($name),
    'source'          => 'dialer',
    'created_by'      => $uid ?: null,
    'created_at'      => $now,
    'updated_at'      => $now,
]);

$row = db_one("SELECT id, imie_nazwisko, telefon, organizacja, stanowisko, type FROM crm_contacts WHERE id=?", [(int)$id]);
cc_ok(crm_mobile_item_contact($row ?: []));
