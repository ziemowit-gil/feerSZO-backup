<?php
/**
 * Handler akcji dla kont M365 bez umowy.
 * POST: action, sa_id (opcjonalne przy create)
 * Tylko dla administratorów.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . APP_URL . '/admin/m365.php?tab=standalone'); exit; }
csrf_check();

$action = $_POST['action'] ?? '';
$sa_id  = intval($_POST['sa_id'] ?? 0);
$back   = APP_URL . '/admin/m365.php?tab=standalone';

// Pobierz wpis jeśli akcja go wymaga
$acc = null;
if ($sa_id && $action !== 'create') {
    $acc = db_one("SELECT * FROM m365_standalone_accounts WHERE id = ?", [$sa_id]);
    if (!$acc) {
        flash_set('danger', 'Nie znaleziono wpisu o ID ' . $sa_id . '.');
        header('Location: ' . $back); exit;
    }
}

try {
    $graph = new M365Graph();

    switch ($action) {

        // ── Utwórz nowe konto ────────────────────────────────────────────────
        case 'create': {
            if (!$graph->is_configured()) {
                throw new RuntimeException('Integracja M365 nie jest skonfigurowana.');
            }

            $imie_nazwisko   = trim($_POST['imie_nazwisko'] ?? '');
            $email           = trim($_POST['email'] ?? '') ?: null;
            $opis            = trim($_POST['opis'] ?? '') ?: null;
            $konto_aktywne   = !empty($_POST['konto_aktywne']);
            $przypisz_lic    = !empty($_POST['przypisz_licencje']);
            $user            = current_user();

            if (!$imie_nazwisko) throw new RuntimeException('Imię i nazwisko jest wymagane.');

            // Jeśli sa_id podano — aktualizujemy istniejący wpis (re-create)
            // Jeśli nie — tworzymy nowy wpis
            if ($sa_id) {
                $acc = db_one("SELECT * FROM m365_standalone_accounts WHERE id=?", [$sa_id]);
                if (!$acc) throw new RuntimeException('Nie znaleziono wpisu.');
                $imie_nazwisko = $imie_nazwisko ?: $acc['imie_nazwisko'];
                $email         = $email ?? ($acc['email'] ?: null);
            }

            $login    = $graph->unique_login($imie_nazwisko);
            $password = M365Graph::generate_password();
            $azure    = $graph->create_user($login, $imie_nazwisko, $password, $konto_aktywne);
            $user_id  = $azure['id'];

            $lic_assigned = 0;
            if ($przypisz_lic) {
                $sku = m365_setting('m365_license_sku_id');
                if ($sku) {
                    $graph->assign_license($user_id, $sku);
                    $lic_assigned = 1;
                }
            }

            $sent = false;
            $sender = m365_setting('m365_sender_user_id');
            if ($sender && $email) {
                try {
                    $graph->send_welcome_email($sender, $email, $imie_nazwisko, $login, $password);
                    $sent = true;
                } catch (\Exception $e) { /* mail nieobowiązkowy */ }
            }

            $now = date('Y-m-d H:i:s');
            $data = [
                'imie_nazwisko'            => $imie_nazwisko,
                'email'                    => $email,
                'opis'                     => $opis,
                'm365_login'               => $login,
                'm365_user_id'             => $user_id,
                'm365_konto_aktywne'       => $konto_aktywne ? 1 : 0,
                'm365_licencja_przypisana' => $lic_assigned,
                'm365_data_utworzenia'     => $now,
                'created_by'               => $user['id'],
                'updated_at'               => $now,
            ];

            if ($sa_id) {
                db_update('m365_standalone_accounts', $data, $sa_id);
            } else {
                $data['created_at'] = $now;
                $sa_id = db_insert('m365_standalone_accounts', $data);
            }

            // Zapisz hasło jednorazowo w sesji
            auth_start();
            $_SESSION['m365sa_new_login'] = $login;
            $_SESSION['m365sa_new_pass']  = $password;
            $_SESSION['m365sa_sent']      = $sent;
            $_SESSION['m365sa_email']     = $email ?? '';

            // Audit log
            log_contract_action('m365_standalone', $sa_id, $user['id'], 'create',
                "Utworzono konto M365: {$login}" . ($sent ? ' (mail wysłany)' : ''));

            flash_set('success',
                'Konto M365 <strong>' . h($login) . '</strong> zostało utworzone'
                . ($konto_aktywne ? ' i jest <strong>aktywne</strong>' : ' (nieaktywne)')
                . ($sent ? ' — mail wysłany na ' . h($email) : '')
                . '.');
            break;
        }

        // ── Włącz konto ──────────────────────────────────────────────────────
        case 'enable': {
            if (!$acc['m365_user_id']) throw new RuntimeException('Brak ID użytkownika Azure AD.');
            $graph->set_enabled($acc['m365_user_id'], true);
            db_update('m365_standalone_accounts', ['m365_konto_aktywne' => 1, 'updated_at' => date('Y-m-d H:i:s')], $sa_id);
            log_contract_action('m365_standalone', $sa_id, current_user()['id'], 'edit', 'Włączono konto: ' . $acc['m365_login']);
            flash_set('success', 'Konto <strong>' . h($acc['m365_login']) . '</strong> zostało włączone.');
            break;
        }

        // ── Wyłącz konto ─────────────────────────────────────────────────────
        case 'disable': {
            if (!$acc['m365_user_id']) throw new RuntimeException('Brak ID użytkownika Azure AD.');
            $graph->set_enabled($acc['m365_user_id'], false);
            db_update('m365_standalone_accounts', ['m365_konto_aktywne' => 0, 'updated_at' => date('Y-m-d H:i:s')], $sa_id);
            log_contract_action('m365_standalone', $sa_id, current_user()['id'], 'edit', 'Wyłączono konto: ' . $acc['m365_login']);
            flash_set('success', 'Konto <strong>' . h($acc['m365_login']) . '</strong> zostało wyłączone.');
            break;
        }

        // ── Wyślij mail z nowym hasłem ────────────────────────────────────────
        case 'send_email': {
            if (!$acc['m365_user_id']) throw new RuntimeException('Brak ID użytkownika Azure AD.');
            if (!$acc['email'])        throw new RuntimeException('Brak adresu e-mail w rekordzie.');
            $sender = m365_setting('m365_sender_user_id');
            if (!$sender) throw new RuntimeException('Nie skonfigurowano nadawcy maili (m365_sender_user_id).');

            $password = M365Graph::generate_password();
            $graph->set_password($acc['m365_user_id'], $password);
            $graph->send_welcome_email($sender, $acc['email'], $acc['imie_nazwisko'], $acc['m365_login'], $password);

            auth_start();
            $_SESSION['m365sa_new_login'] = $acc['m365_login'];
            $_SESSION['m365sa_new_pass']  = $password;
            $_SESSION['m365sa_sent']      = true;
            $_SESSION['m365sa_email']     = $acc['email'];

            log_contract_action('m365_standalone', $sa_id, current_user()['id'], 'edit', 'Wysłano mail z nowym hasłem: ' . $acc['email']);
            flash_set('success', 'Nowe hasło wygenerowane i mail wysłany na <strong>' . h($acc['email']) . '</strong>.');
            break;
        }

        // ── Edytuj dane wpisu ─────────────────────────────────────────────────
        case 'edit': {
            $imie_nazwisko = trim($_POST['imie_nazwisko'] ?? '');
            $email         = trim($_POST['email'] ?? '') ?: null;
            $opis          = trim($_POST['opis'] ?? '') ?: null;
            if (!$imie_nazwisko) throw new RuntimeException('Imię i nazwisko jest wymagane.');
            db_update('m365_standalone_accounts', [
                'imie_nazwisko' => $imie_nazwisko,
                'email'         => $email,
                'opis'          => $opis,
                'updated_at'    => date('Y-m-d H:i:s'),
            ], $sa_id);
            flash_set('success', 'Dane wpisu zostały zaktualizowane.');
            break;
        }

        // ── Powiąż z kontem lokalnym ─────────────────────────────────────────
        case 'link_local_user': {
            $local_uid = intval($_POST['local_user_id'] ?? 0);
            if (!$local_uid) throw new RuntimeException('Wybierz użytkownika do powiązania.');
            if (!$acc['m365_user_id']) throw new RuntimeException('Brak ID użytkownika Azure AD.');

            $local = db_one("SELECT id, name FROM users WHERE id = ?", [$local_uid]);
            if (!$local) throw new RuntimeException('Nie znaleziono wybranego użytkownika.');

            // Usuń kolizję microsoft_id w tabeli users
            db()->prepare("UPDATE users SET microsoft_id = NULL WHERE microsoft_id = ? AND id != ?")
               ->execute([$acc['m365_user_id'], $local_uid]);

            db()->prepare("UPDATE users SET microsoft_id = ? WHERE id = ?")
               ->execute([$acc['m365_user_id'], $local_uid]);

            db_update('m365_standalone_accounts', ['linked_user_id' => $local_uid, 'updated_at' => date('Y-m-d H:i:s')], $sa_id);

            log_contract_action('m365_standalone', $sa_id, current_user()['id'], 'edit',
                "Powiązano z kontem lokalnym: {$local['name']} (#{$local_uid})");
            flash_set('success',
                'Konto lokalne <strong>' . h($local['name']) . '</strong> powiązano z M365 <strong>' . h($acc['m365_login']) . '</strong>.');
            break;
        }

        // ── Odepnij konto M365 (zachowaj wpis) ───────────────────────────────
        case 'unlink': {
            db_update('m365_standalone_accounts', [
                'm365_login'               => null,
                'm365_user_id'             => null,
                'm365_konto_aktywne'       => 0,
                'm365_licencja_przypisana' => 0,
                'linked_user_id'           => null,
                'updated_at'               => date('Y-m-d H:i:s'),
            ], $sa_id);
            log_contract_action('m365_standalone', $sa_id, current_user()['id'], 'edit',
                'Odpięto konto M365: ' . $acc['m365_login']);
            flash_set('warning', 'Konto M365 zostało odpięte od wpisu (konto w Azure AD nie zostało usunięte).');
            break;
        }

        // ── Usuń konto z Azure AD ─────────────────────────────────────────────
        case 'delete_m365': {
            if (!$acc['m365_user_id']) throw new RuntimeException('Brak ID użytkownika Azure AD.');
            $deleted_login = $acc['m365_login'];

            $graph->delete_user($acc['m365_user_id']);

            // Dezaktywuj powiązane konto lokalne
            $local = null;
            if ($acc['linked_user_id']) {
                $local = db_one("SELECT id, name FROM users WHERE id = ?", [$acc['linked_user_id']]);
                if ($local) {
                    db()->prepare("UPDATE users SET is_active=0, microsoft_id=NULL WHERE id=?")->execute([$local['id']]);
                }
            }

            db_update('m365_standalone_accounts', [
                'm365_login'               => null,
                'm365_user_id'             => null,
                'm365_konto_aktywne'       => 0,
                'm365_licencja_przypisana' => 0,
                'linked_user_id'           => null,
                'updated_at'               => date('Y-m-d H:i:s'),
            ], $sa_id);

            log_contract_action('m365_standalone', $sa_id, current_user()['id'], 'edit',
                'Usunięto konto M365 z Azure AD: ' . $deleted_login
                . ($local ? ' | Dezaktywowano konto lokalne: ' . $local['name'] : ''));

            // Powiadom adminów
            $actor = current_user();
            $mail_body = '
<p>Informacja systemowa — <strong>' . h(ORG_NAME) . '</strong></p>
<table style="border-collapse:collapse;font-family:sans-serif;font-size:14px">
  <tr><td style="padding:4px 16px 4px 0;color:#555">Konto M365:</td>
      <td><strong>' . h($deleted_login) . '</strong></td></tr>
  <tr><td style="padding:4px 16px 4px 0;color:#555">Typ:</td><td>Konto bez umowy</td></tr>
  <tr><td style="padding:4px 16px 4px 0;color:#555">Właściciel:</td>
      <td>' . h($acc['imie_nazwisko']) . '</td></tr>
  <tr><td style="padding:4px 16px 4px 0;color:#555">Wykonał:</td>
      <td>' . h($actor['name']) . ' (' . h($actor['email']) . ')</td></tr>
  <tr><td style="padding:4px 16px 4px 0;color:#555">Data:</td>
      <td>' . date('Y-m-d H:i:s') . '</td></tr>
</table>';
            foreach (db_all("SELECT email FROM users WHERE role='admin' AND is_active=1") as $adm) {
                approval_send_email($adm['email'], '[' . ORG_NAME . '] Usunięto konto M365 (bez umowy)', $mail_body);
            }

            flash_set('warning',
                'Konto <strong>' . h($deleted_login) . '</strong> usunięte z Azure AD'
                . ($local ? ' — konto lokalne <strong>' . h($local['name']) . '</strong> dezaktywowane.' : '.'));
            break;
        }

        // ── Usuń wpis z rejestru (nie usuwa konta Azure AD) ───────────────────
        case 'delete_row': {
            db()->prepare("DELETE FROM m365_standalone_accounts WHERE id = ?")->execute([$sa_id]);
            log_contract_action('m365_standalone', $sa_id, current_user()['id'], 'delete',
                'Usunięto wpis z rejestru: ' . $acc['imie_nazwisko']
                . ($acc['m365_login'] ? ' (M365: ' . $acc['m365_login'] . ')' : ''));
            flash_set('warning',
                'Wpis dla <strong>' . h($acc['imie_nazwisko']) . '</strong> usunięty z rejestru.'
                . ($acc['m365_login'] ? ' Konto <strong>' . h($acc['m365_login']) . '</strong> w Azure AD <strong>NIE zostało usunięte</strong>.' : ''));
            break;
        }

        default:
            flash_set('warning', 'Nieznana akcja: ' . h($action));
    }

} catch (\Exception $e) {
    flash_set('danger', 'Błąd: ' . $e->getMessage());
}

header('Location: ' . $back);
exit;
