<?php
/**
 * crm/api/postal_lookup.php — adresaci listowni z CRM dla korespondencji seryjnej.
 *
 * GET ?q=… → [{ id, name, line, persons:[{id, name, role, line, default}] }]
 *
 * `line` to gotowa linia adresowa domyślnego adresata; `persons` pozwala wybrać
 * inną osobę kontaktową tego podmiotu. Używa tego samego resolvera co wysyłka
 * e-mail (crm_contact_recipient), więc „domyślny adresat" znaczy wszędzie to samo.
 */

declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) { http_response_code(401); echo '[]'; exit; }
if (!can_read('crm') && !is_admin()) { http_response_code(403); echo '[]'; exit; }

$q     = trim((string)($_GET['q'] ?? ''));
$limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));
if ($q === '') { echo '[]'; exit; }

$like = '%' . $q . '%';
$rows = db_all(
    "SELECT * FROM crm_contacts
      WHERE crm_active = 1
        AND (imie_nazwisko LIKE ? OR organizacja LIKE ? OR nip LIKE ?)
   ORDER BY imie_nazwisko
      LIMIT ?",
    [$like, $like, $like, $limit]
);

$out = [];
foreach ($rows as $r) {
    $cid     = (int)$r['id'];
    $persons = [];
    foreach (CrmManager::getContactPersons($cid) as $p) {
        $persons[] = [
            'id'      => (int)$p['id'],
            'name'    => $p['imie_nazwisko'],
            'role'    => $p['stanowisko'] ?: '',
            'line'    => crm_recipient_postal_line($cid, (int)$p['id']),
            'default' => !empty($p['is_default_recipient']),
        ];
    }
    $out[] = [
        'id'      => $cid,
        'name'    => $r['imie_nazwisko'],
        'org'     => $r['organizacja'] ?: null,
        'line'    => crm_recipient_postal_line($cid),
        'persons' => $persons,
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
