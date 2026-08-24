<?php
/**
 * crm/api/contacts_search.php — Szybkie wyszukiwanie kontaktów CRM.
 * GET ?q=... — zwraca JSON [{id, name, email, telefon, organizacja}]
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) { http_response_code(401); echo json_encode([]); exit; }
if (!can_write('crm') && !is_admin()) { http_response_code(403); echo json_encode([]); exit; }

$q      = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$limit  = min(30, max(1, (int)($_GET['limit'] ?? 20)));
$exclude = array_filter(array_map('intval', explode(',', $_GET['exclude'] ?? '')));

// Tryb „wszyscy o danym statusie" — do masowej wysyłki; wtedy fraza nie jest wymagana
if ($status !== '') {
    $lim  = min(1000, max(1, (int)($_GET['limit'] ?? 500)));
    $rows = db_all(
        "SELECT id, imie_nazwisko, email, telefon, organizacja, type
           FROM crm_contacts
          WHERE crm_active=1 AND status=?
       ORDER BY imie_nazwisko
          LIMIT ?", [$status, $lim]
    );
    echo json_encode(array_map(static function ($r) {
        $to = crm_contact_recipient((int)$r['id'], null, $r);
        return [
            'id'          => (int)$r['id'],
            'name'        => $r['imie_nazwisko'],
            'email'       => $r['email'] ?: null,
            'telefon'     => $r['telefon'] ?: null,
            'organizacja' => $r['organizacja'] ?: null,
            'type'        => $r['type'],
            'to_email'    => $to['email']   ?: null,
            'to_telefon'  => $to['telefon'] ?: null,
        ];
    }, $rows), JSON_UNESCAPED_UNICODE);
    exit;
}

if (strlen($q) < 1) { echo json_encode([]); exit; }

$like   = '%' . $q . '%';
$params = [$like, $like, $like, $like];

$excl_sql = '';
if ($exclude) {
    $placeholders = implode(',', array_fill(0, count($exclude), '?'));
    $excl_sql = " AND id NOT IN ({$placeholders})";
    $params = array_merge($params, $exclude);
}

$params[] = $limit;

$rows = db_all(
    "SELECT id, imie_nazwisko, email, telefon, organizacja, type
     FROM crm_contacts
     WHERE crm_active=1
       AND (imie_nazwisko LIKE ? OR email LIKE ? OR organizacja LIKE ? OR telefon LIKE ?)
       {$excl_sql}
     ORDER BY imie_nazwisko
     LIMIT ?",
    $params
);

// Poza własnymi danymi kontaktu zwracamy też adres RZECZYWISTEGO adresata
// (osoba oznaczona jako domyślny adresat → osoba główna → podmiot). Firma bez
// adresu ogólnego, ale z e-mailem osoby kontaktowej, jest osiągalna — pola
// `email`/`telefon` zostają nietknięte dla pozostałych konsumentów tego API.
echo json_encode(array_map(function ($r) {
    $to = crm_contact_recipient((int)$r['id'], null, $r);
    return [
        'id'          => (int)$r['id'],
        'name'        => $r['imie_nazwisko'],
        'email'       => $r['email'] ?: null,
        'telefon'     => $r['telefon'] ?: null,
        'organizacja' => $r['organizacja'] ?: null,
        'type'        => $r['type'],
        'to_email'    => $to['email']   ?: null,
        'to_telefon'  => $to['telefon'] ?: null,
        'to_name'     => $to['person_id'] ? $to['name'] : null,
    ];
}, $rows), JSON_UNESCAPED_UNICODE);
