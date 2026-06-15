<?php
/**
 * Person search API — JSON
 *
 * GET ?q=...   min 2 chars; empty/short → 50 most recently updated
 * Returns array of person objects with all fields needed by person_picker.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/persons.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

$cols = "id, imie_nazwisko, pesel, email, telefon, adres, data_urodzenia,
         seria_nr_dowodu, urzad_skarbowy, rachunek_bankowy,
         addr_street, addr_house, addr_flat, addr_postal, addr_city, addr_country";

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    // Browse mode: return 50 most recently updated persons
    $rows = db_all(
        "SELECT {$cols} FROM persons ORDER BY updated_at DESC, imie_nazwisko LIMIT 50"
    );
} else {
    $like = '%' . $q . '%';
    $rows = db_all(
        "SELECT {$cols} FROM persons
         WHERE imie_nazwisko LIKE ? OR pesel LIKE ? OR email LIKE ? OR telefon LIKE ?
         ORDER BY imie_nazwisko
         LIMIT 30",
        [$like, $like, $like, $like]
    );
}

// Mask PESEL: expose only first 6 digits for privacy
foreach ($rows as &$r) {
    if ($r['pesel']) {
        $r['pesel_display'] = substr($r['pesel'], 0, 6) . '…';
    } else {
        $r['pesel_display'] = '';
    }
}
unset($r);

echo json_encode(array_values($rows));
