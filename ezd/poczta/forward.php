<?php
/**
 * ezd/poczta/forward.php — przekazanie korespondencji innemu użytkownikowi.
 *
 * Zwykły POST z przekierowaniem, nie JSON: formularz żyje w panelu pisma
 * wstrzykiwanym przez innerHTML, gdzie skrypty się nie wykonują. Dzięki temu
 * przekazywanie działa też bez JavaScriptu.
 *
 * Sam zapis i wpis do dziennika (poczta_assign_log) robi
 * EzdMailService::assignToUser() — tu tylko walidacja i powrót.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_mail.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . APP_URL . '/ezd/poczta/index.php');
    exit;
}
csrf_check();

$comm_id = (int)($_POST['comm_id'] ?? 0);
$to      = (int)($_POST['user_id'] ?? 0);
$note    = trim((string)($_POST['note'] ?? ''));
$back    = trim((string)($_POST['back'] ?? ''));

// Powrót tylko w obrębie aplikacji — parametr przychodzi z formularza.
$dest = APP_URL . '/ezd/poczta/index.php';
if ($back !== '' && str_starts_with($back, APP_URL . '/')) $dest = $back;

$comm = $comm_id ? db_one("SELECT id, assigned_to, subject FROM crm_communications WHERE id=?", [$comm_id]) : null;
if (!$comm) {
    flash_set('error', 'Nie znaleziono wiadomości.');
    header('Location: ' . $dest); exit;
}
if (!can_write('ezd') && !is_admin()) {
    flash_set('error', 'Brak uprawnień do przekazywania korespondencji.');
    header('Location: ' . $dest); exit;
}
if (!$to) {
    flash_set('error', 'Wskaż osobę, której przekazujesz wiadomość.');
    header('Location: ' . $dest); exit;
}

$target = db_one("SELECT id, name FROM users WHERE id=? AND is_active=1", [$to]);
if (!$target) {
    flash_set('error', 'Wybrana osoba nie istnieje lub konto jest nieaktywne.');
    header('Location: ' . $dest); exit;
}
if ((int)($comm['assigned_to'] ?? 0) === $to && $note === '') {
    flash_set('error', 'Wiadomość jest już przypisana do tej osoby — dodaj dyspozycję, jeśli chcesz zapisać wpis.');
    header('Location: ' . $dest); exit;
}

$svc = new EzdMailService();
$svc->assignToUser($comm_id, $to, $note);

flash_set('success', 'Korespondencja przekazana do: ' . $target['name'] . '.');
header('Location: ' . $dest);
exit;
