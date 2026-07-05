<?php
/**
 * AJAX: popup zgody na weryfikację RPTS w panelu wolontariusza.
 * POST action=submit  {pesel, data_urodzenia, miejsce_urodzenia,
 *                       nazwisko_rodowe, imie_ojca, imie_matki, consent, _csrf}
 * POST action=snooze  {_csrf} — odłóż popup do następnego logowania (sesja)
 * Response: {ok: true} | {ok: false, error: string}
 */
define('SKIP_CONSENT_CHECK', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/rpts.php';

header('Content-Type: application/json; charset=utf-8');

$_user = current_user();
if (!$_user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

csrf_check();

$action = $_POST['action'] ?? '';

if ($action === 'snooze') {
    $_SESSION['rpts_consent_snoozed'] = true;
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'submit') {
    $required = ['pesel', 'data_urodzenia', 'miejsce_urodzenia', 'nazwisko_rodowe', 'imie_ojca', 'imie_matki'];
    foreach ($required as $f) {
        if (trim($_POST[$f] ?? '') === '') {
            echo json_encode(['ok' => false, 'error' => 'Uzupełnij wszystkie pola formularza.']);
            exit;
        }
    }
    $pesel = preg_replace('/\D/', '', $_POST['pesel']);
    if (strlen($pesel) !== 11) {
        echo json_encode(['ok' => false, 'error' => 'Numer PESEL musi mieć 11 cyfr.']);
        exit;
    }
    if (empty($_POST['consent'])) {
        echo json_encode(['ok' => false, 'error' => 'Zaznacz zgodę na weryfikację w RPTS.']);
        exit;
    }

    rpts_consent_save((int)$_user['id'], [
        'pesel'             => $pesel,
        'data_urodzenia'    => $_POST['data_urodzenia'],
        'miejsce_urodzenia' => $_POST['miejsce_urodzenia'],
        'nazwisko_rodowe'   => $_POST['nazwisko_rodowe'],
        'imie_ojca'         => $_POST['imie_ojca'],
        'imie_matki'        => $_POST['imie_matki'],
    ]);
    unset($_SESSION['rpts_consent_snoozed']);

    // Otwórz sprawę w EZD dokumentującą złożenie zgody (nie blokuje odpowiedzi w razie błędu).
    try {
        require_once dirname(__DIR__) . '/includes/ezd.php';
        $_fresh = db_one("SELECT * FROM users WHERE id=?", [(int)$_user['id']]);
        if ($_fresh) ezd_register_rpts_consent($_fresh, (int)$_user['id']);
    } catch (\Throwable $e) {
        error_log('[rpts_consent] EZD: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Nieznana akcja.']);
