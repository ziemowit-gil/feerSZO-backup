<?php
/**
 * crm/api/contact_persons.php — osoby kontaktowe wskazanych podmiotów.
 *
 * Używane przy wysyłce: gdy organizacja albo partner ma kilka osób kontaktowych,
 * nadawca musi wybrać, do której ma pójść wiadomość — inaczej decyduje resolver
 * (domyślny adresat → osoba główna → adres podmiotu) i nikt tego nie widzi.
 *
 * GET ?ids=1,2,3 → {ok, contacts:{ "1": {name, persons:[{id,name,role,address,default}]}, … }}
 * Zwracamy tylko podmioty mające WIĘCEJ NIŻ JEDNĄ osobę z adresem — przy jednej
 * nie ma czego wybierać.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Wymagane logowanie.']);
    exit;
}

$channel = ($_GET['channel'] ?? 'email') === 'sms' ? 'sms' : 'email';
$ids = array_values(array_unique(array_filter(array_map('intval',
    explode(',', (string)($_GET['ids'] ?? ''))))));
$ids = array_slice($ids, 0, 200);

$out = [];
foreach ($ids as $cid) {
    if (!crm_can_access_contact($cid)) continue;

    $c = db_one("SELECT id, imie_nazwisko, type, email, telefon FROM crm_contacts WHERE id=? AND crm_active=1", [$cid]);
    if (!$c) continue;

    try {
        $persons = crm_all(
            "SELECT id, imie_nazwisko, stanowisko, email, telefon, is_primary, is_default_recipient
               FROM crm_contact_persons WHERE contact_id=? ORDER BY is_primary DESC, sort_order, id",
            [$cid]
        );
    } catch (\Throwable $e) { $persons = []; }

    // Osoba bez adresu w wybranym kanale i tak nic nie dostanie — nie pokazujemy jej
    $usable = array_values(array_filter($persons, static fn($p) =>
        trim((string)($channel === 'sms' ? $p['telefon'] : $p['email'])) !== ''));
    if (count($usable) < 2) continue;

    $current = crm_contact_recipient($cid, null, $c);

    $out[(string)$cid] = [
        'name'    => (string)$c['imie_nazwisko'],
        'fallback'=> trim((string)($channel === 'sms' ? $c['telefon'] : $c['email'])),
        'current' => (int)($current['person_id'] ?? 0),
        'persons' => array_map(static fn($p) => [
            'id'      => (int)$p['id'],
            'name'    => (string)$p['imie_nazwisko'],
            'role'    => (string)($p['stanowisko'] ?? ''),
            'address' => trim((string)($channel === 'sms' ? $p['telefon'] : $p['email'])),
            'default' => !empty($p['is_default_recipient']),
        ], $usable),
    ];
}

echo json_encode(['ok' => true, 'channel' => $channel, 'contacts' => $out], JSON_UNESCAPED_UNICODE);
