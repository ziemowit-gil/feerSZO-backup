<?php
/**
 * Endpoint M365: tworzy/włącza/wyłącza konto, wysyła mail powitalny.
 * POST: type, id, action (create|enable|disable|send_email|toggle)
 * Przekierowuje do contracts/{type}/view.php?id={id}
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';

require_role('admin', 'editor');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . APP_URL); exit; }
csrf_check();

$type   = preg_replace('/[^a-z]/', '', $_POST['type'] ?? '');
$id     = intval($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';
$back   = APP_URL . "/contracts/{$type}/view.php?id={$id}";

$allowed_types = ['zlecenie', 'dzielo', 'wolontariat'];
if (!in_array($type, $allowed_types, true) || !$id) {
    flash_set('danger', 'Nieprawidłowy typ umowy lub ID.'); header('Location: ' . $back); exit;
}

$table = table_for_type($type);
$row = db_one("SELECT * FROM {$table} WHERE id = ?", [$id]);
if (!$row) { flash_set('danger', 'Nie znaleziono umowy.'); header('Location: ' . $back); exit; }

// Pole z adresem email zleceniobiorcy (zależy od typu)
$person_email = $row['email'] ?? null; // wolontariat ma email; zlecenie/dzielo — brak pola email
$person_name  = $row['imie_nazwisko'] ?? '';

try {
    $graph = new M365Graph();
    if (!$graph->is_configured()) throw new RuntimeException('Integracja M365 nie jest skonfigurowana. Przejdź do Admin → Ustawienia M365.');

    if ($action === 'create' || ($action === 'toggle' && !$row['m365_konto'])) {
        // Generuj login i hasło
        $login    = $graph->unique_login($person_name);
        $password = M365Graph::generate_password();
        $enabled  = m365_should_be_active($row);

        // Utwórz użytkownika w Azure AD
        $user = $graph->create_user($login, $person_name, $password, $enabled);
        $user_id = $user['id'];

        // Przypisz licencję
        $sku = m365_setting('m365_license_sku_id');
        if ($sku) {
            $graph->assign_license($user_id, $sku);
            $lic_assigned = 1;
        } else {
            $lic_assigned = 0;
        }

        // Wyślij mail (jeśli mamy adres e-mail osoby)
        $sent = false;
        $sender = m365_setting('m365_sender_user_id');
        if ($sender && $person_email) {
            $graph->send_welcome_email($sender, $person_email, $person_name, $login, $password);
            $sent = true;
        }

        // Zapisz do bazy
        db_update($table, [
            'm365_konto'              => 1,
            'm365_login'              => $login,
            'm365_user_id'            => $user_id,
            'm365_konto_aktywne'      => $enabled ? 1 : 0,
            'm365_data_utworzenia'    => date('Y-m-d H:i:s'),
            'm365_licencja_przypisana'=> $lic_assigned,
        ], $id);

        // Zapisz hasło jednorazowo w sesji (do wyświetlenia)
        auth_start();
        $_SESSION['m365_new_pass']  = $password;
        $_SESSION['m365_new_login'] = $login;
        $_SESSION['m365_sent']      = $sent;

        flash_set('success', "Konto M365 utworzone: {$login}" . ($sent ? ' (mail wysłany)' : ' (brak e-mail — mail nie wysłany)'));

    } elseif ($action === 'enable' || ($action === 'toggle_active' && !$row['m365_konto_aktywne'])) {
        if (!$row['m365_user_id']) throw new RuntimeException('Brak ID użytkownika Azure AD.');
        $graph->set_enabled($row['m365_user_id'], true);
        db_update($table, ['m365_konto_aktywne' => 1], $id);
        flash_set('success', 'Konto M365 włączone.');

    } elseif ($action === 'disable' || ($action === 'toggle_active' && $row['m365_konto_aktywne'])) {
        if (!$row['m365_user_id']) throw new RuntimeException('Brak ID użytkownika Azure AD.');
        $graph->set_enabled($row['m365_user_id'], false);
        db_update($table, ['m365_konto_aktywne' => 0], $id);
        flash_set('success', 'Konto M365 wyłączone.');

    } elseif ($action === 'send_email') {
        if (!$row['m365_user_id']) throw new RuntimeException('Brak ID użytkownika Azure AD.');
        $sender = m365_setting('m365_sender_user_id');
        if (!$sender) throw new RuntimeException('Nie skonfigurowano nadawcy maili (m365_sender_user_id).');
        if (!$person_email) throw new RuntimeException('Ta umowa nie zawiera adresu e-mail odbiorcy.');
        $password = M365Graph::generate_password();
        // Reset hasła przez set_enabled (reużywa PATCH) — użyj osobnej instancji
        $graph->set_password($row['m365_user_id'], $password);
        $graph->send_welcome_email($sender, $person_email, $person_name, $row['m365_login'], $password);
        auth_start();
        $_SESSION['m365_new_pass']  = $password;
        $_SESSION['m365_new_login'] = $row['m365_login'];
        $_SESSION['m365_sent']      = true;
        flash_set('success', 'Nowe hasło wygenerowane i mail wysłany.');

    } elseif ($action === 'toggle' && $row['m365_konto']) {
        // Przełącznik "konto utworzono" — wpisz ręcznie istniejący login
        $manual_login = trim($_POST['m365_login_manual'] ?? '');
        $manual_uid   = trim($_POST['m365_user_id_manual'] ?? '');
        db_update($table, [
            'm365_konto'           => 1,
            'm365_login'           => $manual_login ?: $row['m365_login'],
            'm365_user_id'         => $manual_uid   ?: $row['m365_user_id'],
            'm365_data_utworzenia' => $row['m365_data_utworzenia'] ?: date('Y-m-d H:i:s'),
        ], $id);
        flash_set('success', 'Dane konta M365 zaktualizowane.');
    } elseif ($action === 'unlink') {
        db_update($table, ['m365_konto' => 0, 'm365_login' => null, 'm365_user_id' => null, 'm365_konto_aktywne' => 0], $id);
        flash_set('warning', 'Powiązanie z kontem M365 usunięte (konto w Azure AD NIE zostało usunięte).');

    } elseif ($action === 'delete_m365') {
        if (!is_admin()) throw new RuntimeException('Tylko administrator może trwale usuwać konta M365.');
        if (!$row['m365_user_id']) throw new RuntimeException('Brak ID użytkownika Azure AD.');

        $deleted_login = $row['m365_login'];

        // Usuń konto z Azure AD
        $graph->delete_user($row['m365_user_id']);

        // Dezaktywuj powiązane konto lokalne
        $local = db_one("SELECT id, name FROM users WHERE microsoft_id = ? LIMIT 1", [$row['m365_user_id']]);
        if ($local) {
            db()->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$local['id']]);
        }

        // Wyczyść pola M365 na umowie
        db_update($table, [
            'm365_konto'               => 0,
            'm365_login'               => null,
            'm365_user_id'             => null,
            'm365_konto_aktywne'       => 0,
            'm365_licencja_przypisana' => 0,
        ], $id);

        // Zaloguj operację
        require_once dirname(__DIR__) . '/includes/approval.php';
        log_contract_action($type, $id, current_user()['id'], 'edit',
            'Usunięto konto M365: ' . $deleted_login
            . ($local ? ' | Dezaktywowano konto lokalne: ' . $local['name'] : ''));

        $flash_msg = 'Konto M365 <strong>' . htmlspecialchars($deleted_login) . '</strong>'
            . ' zostało usunięte z Azure AD'
            . ($local ? ' — powiązane konto lokalne <strong>'
                . htmlspecialchars($local['name']) . '</strong> dezaktywowane.' : '.');
        flash_set('warning', $flash_msg);

        // Powiadom wszystkich adminów e-mailem
        require_once dirname(__DIR__) . '/includes/approval.php';
        $admins = db_all("SELECT email, name FROM users WHERE role = 'admin' AND is_active = 1");
        $actor  = current_user();
        $mail_subject = '[' . ORG_NAME . '] Usunięto konto Microsoft 365';
        $mail_body = '
<p>Informacja systemowa — <strong>' . htmlspecialchars(ORG_NAME) . '</strong></p>
<table style="border-collapse:collapse;font-family:sans-serif;font-size:14px">
  <tr><td style="padding:4px 16px 4px 0;color:#555">Konto M365:</td>
      <td><strong>' . htmlspecialchars($deleted_login) . '</strong></td></tr>
  <tr><td style="padding:4px 16px 4px 0;color:#555">Typ umowy:</td>
      <td>' . htmlspecialchars(CONTRACT_TYPES[$type] ?? $type) . ' #' . $id . '</td></tr>
  <tr><td style="padding:4px 16px 4px 0;color:#555">Wykonał:</td>
      <td>' . htmlspecialchars($actor['name']) . ' (' . htmlspecialchars($actor['email']) . ')</td></tr>
  <tr><td style="padding:4px 16px 4px 0;color:#555">Data:</td>
      <td>' . date('Y-m-d H:i:s') . '</td></tr>
  ' . ($local ? '<tr><td style="padding:4px 16px 4px 0;color:#555">Konto lokalne:</td>
      <td>Dezaktywowano: <strong>' . htmlspecialchars($local['name']) . '</strong></td></tr>' : '') . '
</table>
<p style="color:#888;font-size:12px;margin-top:16px">Wiadomość automatyczna — Rejestr Umów ' . htmlspecialchars(ORG_NAME) . '</p>';
        foreach ($admins as $admin) {
            approval_send_email($admin['email'], $mail_subject, $mail_body);
        }

    } elseif ($action === 'link_local_user') {
        $local_uid = intval($_POST['local_user_id'] ?? 0);
        if (!$local_uid) throw new RuntimeException('Wybierz użytkownika do powiązania.');
        if (!$row['m365_user_id']) throw new RuntimeException('Brak ID użytkownika Azure AD w umowie.');

        $local = db_one("SELECT id, name FROM users WHERE id = ?", [$local_uid]);
        if (!$local) throw new RuntimeException('Nie znaleziono wybranego użytkownika.');

        // Jeśli inne konto miało już ten microsoft_id — wyczyść kolizję
        db()->prepare("UPDATE users SET microsoft_id = NULL WHERE microsoft_id = ? AND id != ?")
            ->execute([$row['m365_user_id'], $local_uid]);

        db()->prepare("UPDATE users SET microsoft_id = ? WHERE id = ?")
            ->execute([$row['m365_user_id'], $local_uid]);

        flash_set('success',
            'Konto lokalne <strong>' . htmlspecialchars($local['name']) . '</strong>'
            . ' powiązano z kontem M365 <strong>' . htmlspecialchars($row['m365_login']) . '</strong>.'
            . ' Użytkownik może teraz logować się przez Microsoft 365.');

    } else {
        flash_set('warning', 'Nieznana akcja.');
    }
} catch (\Exception $e) {
    flash_set('danger', 'Błąd M365: ' . $e->getMessage());
}

header('Location: ' . $back);
exit;
