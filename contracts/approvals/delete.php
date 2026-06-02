<?php
/**
 * Usuwa umowę (admin only). Wymaga podania powodu.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';

require_role('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . APP_URL); exit; }
csrf_check();

$type   = preg_replace('/[^a-z]/', '', $_POST['type'] ?? '');
$id     = intval($_POST['id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$back   = APP_URL . "/contracts/{$type}/list.php";

if (!$type || !$id) { flash_set('danger', 'Błędne parametry.'); header('Location: ' . APP_URL); exit; }
if (!$reason)       { flash_set('danger', 'Podaj powód usunięcia.'); header('Location: ' . APP_URL . "/contracts/{$type}/view.php?id={$id}"); exit; }

$table = table_for_type($type);
$row   = db_one("SELECT * FROM {$table} WHERE id=?", [$id]);
if (!$row) { flash_set('danger', 'Nie znaleziono umowy.'); header('Location: ' . $back); exit; }

$user = current_user();
log_contract_action($type, $id, $user['id'], 'delete', 'Powód: ' . $reason . ' | Usunięta umowa: ' . $row['numer_umowy']);

// Wyciągnij email strony umowy przed usunięciem
$contract_email = match($type) {
    'zlecenie', 'dzielo'  => $row['m365_login']  ?? '',
    'wolontariat'         => $row['email']        ?? $row['m365_login'] ?? '',
    'praca'               => $row['email_login']  ?? '',
    default               => '',
};

// Usuń powiązane wpisy akceptacji i aneksów
db()->prepare("DELETE FROM contract_approvals    WHERE contract_type=? AND contract_id=?")->execute([$type, $id]);
db()->prepare("DELETE FROM contract_amendments   WHERE contract_type=? AND contract_id=?")->execute([$type, $id]);
db()->prepare("DELETE FROM contract_edit_requests WHERE contract_type=? AND contract_id=?")->execute([$type, $id]);

// Usuń umowę
db()->prepare("DELETE FROM {$table} WHERE id=?")->execute([$id]);

flash_set('success', 'Umowa ' . $row['numer_umowy'] . ' została usunięta.');

// ── Sprawdź czy powiązany użytkownik nie jest teraz bez umów ─────────────────
if ($contract_email) {
    $linked_user = db_one(
        "SELECT id, name FROM users WHERE email = ? AND is_active = 1 LIMIT 1",
        [$contract_email]
    );
    if ($linked_user) {
        // Prosta weryfikacja: czy ma jeszcze cokolwiek w jakiejkolwiek tabeli
        $still_has = false;
        foreach (['zlecenie' => 'm365_login', 'dzielo' => 'm365_login',
                  'wolontariat' => 'email', 'praca' => 'email_login'] as $t => $col) {
            if ($still_has) break;
            try {
                $r = db_one("SELECT id FROM umowy_{$t} WHERE {$col} = ? LIMIT 1",
                            [$contract_email]);
                if ($r) $still_has = true;
            } catch (\Exception $e) {}
        }
        if (!$still_has) {
            flash_set('warning',
                'Użytkownik <strong>' . htmlspecialchars($linked_user['name']) . '</strong>'
                . ' nie ma już żadnych umów w systemie. '
                . '<a href="' . APP_URL . '/admin/users.php" class="alert-link">Zarządzaj kontami →</a>'
            );
        }
    }
}

header('Location: ' . $back);
exit;
