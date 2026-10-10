<?php
/**
 * tozsamosc/kursant_klucz_login.php — logowanie kursanta kluczem sprzętowym (WebAuthn).
 * Tylko konta po pierwszym logowaniu (mają już hasło/SMS za sobą) i z co najmniej jednym kluczem.
 * ?api=begin&login=… zwraca opcje; ?api=complete (POST data=JSON) loguje i zwraca adres przekierowania.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/includes/ti_messages.php';
require_once dirname(__DIR__) . '/includes/ti_sec_image.php';
require_once dirname(__DIR__) . '/includes/ti_student_webauthn.php';
require_once dirname(__DIR__) . '/karty30/ti/kursant/auth.php';

karty30_migrate();
ti_sw_migrate();
ti_sec_image_migrate();
header('Content-Type: application/json; charset=utf-8');

try {
    $api = (string)($_GET['api'] ?? '');
    if ($api === 'begin') {
        $login = trim((string)($_GET['login'] ?? ''));
        $acc = $login !== '' ? db_one(
            "SELECT id FROM k30_ti_student_accounts
              WHERE (login = ? OR (login_alias != '' AND login_alias = ?)) AND is_active = 1
                AND child_access_blocked = 0 AND last_login IS NOT NULL",
            [$login, $login]) : null;
        if (!$acc || ti_sw_count((int)$acc['id']) === 0) {
            throw new \RuntimeException('Dla tego loginu nie ma zarejestrowanego klucza.');
        }
        echo json_encode(ti_sw_begin_auth((int)$acc['id']));
    } elseif ($api === 'complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode((string)($_POST['data'] ?? ''), true);
        if (!is_array($payload)) throw new \RuntimeException('Brak danych klucza.');
        $account_id = ti_sw_complete_auth($payload);
        $acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id = ? AND is_active = 1", [$account_id]);
        if (!$acc) throw new \RuntimeException('Konto jest nieaktywne.');
        student_login_user($acc, 'webauthn', 'klucz sprzętowy (Tożsamość)');
        $wybrany = db_one("SELECT sec_chosen FROM k30_ti_student_accounts WHERE id = ?", [$account_id]);
        echo json_encode(['ok' => true, 'redirect' => APP_URL . (empty($wybrany['sec_chosen'])
            ? '/karty30/ti/kursant/sec_choose.php'
            : '/karty30/ti/kursant/index.php')]);
    } else {
        throw new \RuntimeException('Nieznana operacja.');
    }
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
