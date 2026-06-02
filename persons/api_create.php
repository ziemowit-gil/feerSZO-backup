<?php
/**
 * Person quick-create API (used by person_picker "Nowa osoba" modal).
 *
 * POST JSON {_csrf, imie_nazwisko, pesel, email, telefon}
 * Response: application/json
 *   {ok:true,  person:{id, imie_nazwisko, pesel, email, telefon, ...}}
 *   {ok:false, errors:["Imię i nazwisko jest wymagane."]}
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/persons.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

// Accept JSON or form POST
$json = json_decode(file_get_contents('php://input'), true) ?? [];
$data = array_merge($json, $_POST);

// CSRF
$csrf_given   = $data['_csrf'] ?? '';
$csrf_session = $_SESSION['csrf'] ?? '';
if (!hash_equals((string)$csrf_session, (string)$csrf_given)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'errors' => ['Nieprawidłowy token CSRF.']]);
    exit;
}

$errors = [];

$imie_nazwisko = trim($data['imie_nazwisko'] ?? '');
$pesel         = trim($data['pesel']         ?? '');
$email         = trim($data['email']         ?? '');
$telefon       = trim($data['telefon']       ?? '');

if ($imie_nazwisko === '') {
    $errors[] = 'Imię i nazwisko jest wymagane.';
}
if ($pesel !== '' && !preg_match('/^\d{11}$/', $pesel)) {
    $errors[] = 'PESEL musi składać się z 11 cyfr.';
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Nieprawidłowy adres e-mail.';
}

if ($errors) {
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

try {
    $insert = [
        'imie_nazwisko' => $imie_nazwisko,
        'created_by'    => current_user()['id'] ?? null,
        'created_at'    => date('Y-m-d H:i:s'),
        'updated_at'    => date('Y-m-d H:i:s'),
    ];
    if ($pesel   !== '') $insert['pesel']   = $pesel;
    if ($email   !== '') $insert['email']   = $email;
    if ($telefon !== '') $insert['telefon'] = $telefon;

    $new_id = db_insert('persons', $insert);

    $person = db_one(
        "SELECT id, imie_nazwisko, pesel, email, telefon, adres, data_urodzenia,
                addr_street, addr_house, addr_flat, addr_postal, addr_city, addr_country
         FROM persons WHERE id = ?",
        [$new_id]
    );
    $person['pesel_display'] = $person['pesel'] ? substr($person['pesel'], 0, 6) . '…' : '';

    echo json_encode(['ok' => true, 'person' => $person]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'errors' => ['Błąd serwera: ' . $e->getMessage()]]);
}
