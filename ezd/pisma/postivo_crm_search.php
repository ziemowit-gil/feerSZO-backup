<?php
/**
 * Autocomplete kontaktów CRM dla formularza wysyłki Postivo.
 * GET ?q=fraza  → JSON [{id, label, name, street, house, flat, postal, city}]
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';

require_login();
ezd_require_access();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo '[]'; exit; }

$like = '%' . $q . '%';
$rows = db_all(
    "SELECT id, imie_nazwisko, organizacja, addr_street, addr_house, addr_flat, addr_postal, addr_city
       FROM crm_contacts
      WHERE (imie_nazwisko LIKE ? OR organizacja LIKE ? OR email LIKE ?)
        AND status != 'archived'
      ORDER BY imie_nazwisko LIMIT 12",
    [$like, $like, $like]
);

$out = [];
foreach ($rows as $r) {
    $label = $r['imie_nazwisko'] ?: $r['organizacja'];
    if ($r['organizacja'] && $r['imie_nazwisko']) {
        $label = $r['imie_nazwisko'] . ' (' . $r['organizacja'] . ')';
    }
    $out[] = [
        'id'     => (int)$r['id'],
        'label'  => $label,
        'name'   => $r['imie_nazwisko'] ?: $r['organizacja'],
        'street' => $r['addr_street']  ?? '',
        'house'  => $r['addr_house']   ?? '',
        'flat'   => $r['addr_flat']    ?? '',
        'postal' => $r['addr_postal']  ?? '',
        'city'   => $r['addr_city']    ?? '',
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
