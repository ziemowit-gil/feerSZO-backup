<?php
/**
 * crm/mobile/api/call.php — rejestrowanie rozmów z mobilnego dialera.
 *
 * POST action=start   {cid, pid?, tel}        → zapisuje aktywność „call" → { id }
 * POST action=outcome {id, outcome, note?}    → dopisuje wynik i notatkę do własnego wpisu
 *
 * Zapis wymaga uprawnienia zapisu w CRM; sam odczyt listy i dzwonienie — nie.
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/mobile.php';

crm_mobile_guard(true);

/** Dozwolone wyniki rozmowy → etykieta zapisywana w crm_activities.outcome. */
const CRM_MOBILE_OUTCOMES = [
    'answered'  => 'Odebrał',
    'no_answer' => 'Nie odebrał',
    'callback'  => 'Do oddzwonienia',
    'wrong'     => 'Błędny numer',
];

function cm_ok(mixed $d = null): never { echo json_encode(['ok' => true, 'data' => $d], JSON_UNESCAPED_UNICODE); exit; }
function cm_err(string $m, int $c = 400): never { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') cm_err('Wymagana metoda POST.', 405);

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) $body = $_POST;

// CSRF — token z sesji, przekazywany przez aplikację w nagłówku lub w ciele.
$token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? ''));
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) cm_err('Błąd CSRF — odśwież aplikację.', 403);
if (function_exists('system_block_writes')) system_block_writes();

if (!can_write('crm') && !is_admin()) cm_err('Brak uprawnień do zapisu w CRM.', 403);

$uid    = (int)(current_user()['id'] ?? 0);
$action = (string)($body['action'] ?? '');
$now    = date('Y-m-d H:i:s');

if ($action === 'start') {
    $cid = (int)($body['cid'] ?? 0);
    $pid = (int)($body['pid'] ?? 0);
    $tel = trim((string)($body['tel'] ?? ''));
    if (!$cid || $tel === '') cm_err('Brak kontaktu lub numeru.');

    $contact = db_one("SELECT id, imie_nazwisko, telefon FROM crm_contacts WHERE id=? AND crm_active=1", [$cid]);
    if (!$contact) cm_err('Nie znaleziono kontaktu.', 404);

    // Numer musi należeć do tego kontaktu (albo do jego osoby kontaktowej) —
    // inaczej dziennik rozmów dałoby się zaśmiecić dowolną treścią.
    $person = null;
    if ($pid) {
        $person = db_one(
            "SELECT id, imie_nazwisko, telefon FROM crm_contact_persons WHERE id=? AND contact_id=?",
            [$pid, $cid]
        );
        if (!$person) cm_err('Nie znaleziono osoby kontaktowej.', 404);
    }
    $allowed = array_column(crm_mobile_phones($person ? $person['telefon'] : $contact['telefon']), 'tel');
    if (!in_array($tel, $allowed, true)) cm_err('Numer nie należy do tego kontaktu.', 422);

    $who = $person ? (string)$person['imie_nazwisko'] : (string)$contact['imie_nazwisko'];

    $id = db_insert('crm_activities', [
        'contact_id'   => $cid,
        'type'         => 'call',
        'title'        => 'Telefon wychodzący — ' . $tel,
        'description'  => $person ? ('Osoba kontaktowa: ' . $who) : null,
        'scheduled_at' => $now,
        'status'       => 'done',
        'assigned_to'  => $uid,
        'created_by'   => $uid,
        'created_at'   => $now,
        'updated_at'   => $now,
        'completed_at' => $now,
    ]);
    cm_ok(['id' => $id]);
}

if ($action === 'outcome') {
    $aid     = (int)($body['id'] ?? 0);
    $outcome = (string)($body['outcome'] ?? '');
    $note    = trim((string)($body['note'] ?? ''));
    if (!isset(CRM_MOBILE_OUTCOMES[$outcome])) cm_err('Nieznany wynik rozmowy.');

    $act = db_one("SELECT id, description, created_by FROM crm_activities WHERE id=? AND type='call'", [$aid]);
    if (!$act) cm_err('Nie znaleziono wpisu rozmowy.', 404);
    if ((int)$act['created_by'] !== $uid && !is_admin()) cm_err('To nie jest Twój wpis.', 403);

    // Notatka dopisywana pod ewentualną informacją o osobie kontaktowej.
    $desc = trim((string)($act['description'] ?? ''));
    if ($note !== '') $desc = $desc === '' ? $note : $desc . "\n" . $note;

    db()->prepare("UPDATE crm_activities SET outcome=?, description=?, updated_at=? WHERE id=?")
        ->execute([CRM_MOBILE_OUTCOMES[$outcome], $desc !== '' ? $desc : null, $now, $aid]);

    cm_ok();
}

cm_err('Nieznana akcja.');
