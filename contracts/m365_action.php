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

        // Sprawdź dostępność licencji przed tworzeniem konta w Azure AD
        $sku = m365_setting('m365_license_sku_id');
        if ($sku) {
            foreach ($graph->get_subscribed_skus() as $s) {
                if ($s['skuId'] === $sku) {
                    $free = ($s['prepaidUnits']['enabled'] ?? 0) - ($s['consumedUnits'] ?? 0);
                    if ($free <= 0) throw new RuntimeException(
                        "Brak wolnych licencji M365 ({$s['skuPartNumber']}) — konto nie zostanie utworzone. Zakup dodatkowe licencje."
                    );
                    break;
                }
            }
        }

        // Utwórz użytkownika w Azure AD
        $user = $graph->create_user($login, $person_name, $password, $enabled);
        $user_id = $user['id'];

        // Przypisz licencję
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

        // Zapisz hasło do historii IT (zaszyfrowane) — chroni przed utratą przy odświeżeniu strony
        try {
            require_once dirname(__DIR__) . '/includes/it_helpers.php';
            it_migrate();
            $svc = db_one("SELECT id FROM it_services WHERE slug='m365'");
            if ($svc) {
                it_log_password([
                    'service_id'    => (int)$svc['id'],
                    'contract_type' => $type,
                    'contract_id'   => $id,
                    'login'         => $login,
                    'plain'         => $password,
                    'sent_to_email' => $sent ? ($person_email ?? null) : null,
                    'notes'         => 'Wygenerowano przy tworzeniu konta M365',
                    'issued_by'     => current_user()['id'],
                ]);
            }
        } catch (\Throwable $e) {}

        // Zapisz do bazy
        db_update($table, [
            'm365_konto'              => 1,
            'm365_login'              => $login,
            'm365_user_id'            => $user_id,
            'm365_konto_aktywne'      => $enabled ? 1 : 0,
            'm365_data_utworzenia'    => date('Y-m-d H:i:s'),
            'm365_licencja_przypisana'=> $lic_assigned,
        ], $id);

        // Auto-powiąż / utwórz konto lokalne
        $link_result = m365_auto_link_or_create_local($person_email ?? '', $person_name, $user_id);
        if ($link_result['msg']) {
            log_contract_action($type, $id, (int)current_user()['id'], 'note', $link_result['msg']);
        }

        // Zapisz hasło jednorazowo w sesji (do wyświetlenia)
        auth_start();
        $_SESSION['m365_new_pass']  = $password;
        $_SESSION['m365_new_login'] = $login;
        $_SESSION['m365_sent']      = $sent;

        if ($link_result['action'] === 'created') {
            flash_set('warning',
                'Automatycznie utworzono konto lokalne dla <strong>' . htmlspecialchars($person_name) . '</strong> (rola: <strong>viewer</strong>). '
                . 'Zmień rolę w <a href="' . APP_URL . '/admin/users.php">Zarządzaniu użytkownikami</a>, jeśli potrzeba.'
            );
        }
        $link_suffix = match($link_result['action']) {
            'linked'  => ' · konto lokalne powiązane',
            'created' => ' · konto lokalne auto-utworzone (viewer)',
            default   => '',
        };
        flash_set('success', "Konto M365 utworzone: {$login}{$link_suffix}" . ($sent ? ' (mail wysłany)' : ' (brak e-mail — mail nie wysłany)'));

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

    } elseif ($action === 'send_m365_setup_link') {
        // Wysyła LINK (token) zamiast hasła bezpośrednio — wolontariusz klika i widzi dane logowania.
        // Bezpieczniejsze: hasło nie pojawia się w treści e-maila.
        if (!$row['m365_user_id']) throw new RuntimeException('Brak ID użytkownika Azure AD.');
        if (!$person_email)        throw new RuntimeException('Ta umowa nie zawiera adresu e-mail odbiorcy.');
        if (!$row['m365_login'])   throw new RuntimeException('Brak loginu M365 w umowie.');

        // Generuj nowe hasło tymczasowe w M365
        $password = M365Graph::generate_password();
        $graph->set_password($row['m365_user_id'], $password);

        // Generuj jednorazowy token — migracja kolumny w razie potrzeby
        try { db()->exec("ALTER TABLE umowy_wolontariat ADD COLUMN m365_setup_token TEXT"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE umowy_wolontariat ADD COLUMN m365_setup_token_used_at DATETIME"); } catch (\Throwable $e) {}

        $token = bin2hex(random_bytes(24));
        db()->prepare(
            "UPDATE umowy_wolontariat
             SET m365_setup_token=?, m365_setup_token_used_at=NULL
             WHERE id=?"
        )->execute([$token, $id]);

        // Buduj link i treść maila
        $org      = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
        $name_h   = htmlspecialchars($person_name ?: $person_email, ENT_QUOTES);
        $link_url = APP_URL . '/wolontariat/m365_setup.php?token=' . urlencode($token);
        $link_h   = htmlspecialchars($link_url, ENT_QUOTES);
        $mail_html = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#0078d4,#106ebe);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">&#128273; Aktywacja konta Microsoft 365 — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$name_h}</strong>!</p>
  <p>Twoje konto <strong>Microsoft 365</strong> w organizacji <strong>{$org}</strong> jest gotowe.</p>
  <p>Kliknij poniższy przycisk, aby zobaczyć swoje dane logowania i aktywować konto:</p>
  <div style="margin:24px 0;text-align:center">
    <a href="{$link_h}"
       style="background:#0078d4;color:#fff;padding:14px 30px;border-radius:8px;
              text-decoration:none;display:inline-block;font-weight:700;font-size:1rem">
      &#128279; Pokaż dane logowania M365
    </a>
  </div>
  <div style="background:#fff8e1;border-left:3px solid #f59e0b;border-radius:4px;padding:10px 14px;margin:14px 0;font-size:.88em">
    Link jest <strong>jednorazowy</strong> — po kliknięciu wygasa. Zachowaj dane logowania w bezpiecznym miejscu.
  </div>
  <p style="font-size:.88em;color:#555">
    Jeśli nie zamawiałeś/aś aktywacji konta — zignoruj tę wiadomość i skontaktuj się z {$org}.
  </p>
  <p style="color:#6c757d;font-size:.82em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    {$org} · wiadomość automatyczna
  </p>
</div></body></html>
HTML;
        // Wyślij przez M365 Graph lub kolejkę mailową
        $sender = m365_setting('m365_sender_user_id');
        if ($sender) {
            $graph->send_raw_email($sender, $person_email, "Aktywacja konta Microsoft 365 — {$org}", $mail_html);
        } else {
            require_once dirname(__DIR__) . '/includes/mail_queue.php';
            mail_queue_add($person_email, $person_name, "Aktywacja konta Microsoft 365 — {$org}", $mail_html, '', $type, $id, '', true);
        }

        // Zapisz hasło w it_service_passwords (zaszyfrowane)
        require_once dirname(__DIR__) . '/includes/it_helpers.php';
        it_migrate();
        $svc = db_one("SELECT id FROM it_services WHERE slug='m365'");
        if ($svc) {
            it_log_password([
                'service_id'    => (int)$svc['id'],
                'contract_type' => $type,
                'contract_id'   => $id,
                'login'         => $row['m365_login'],
                'plain'         => $password,
                'sent_to_email' => null, // nie wysyłamy hasła mailem — tylko link
                'notes'         => 'Wygenerowano przy link-aktywacji M365',
                'issued_by'     => current_user()['id'],
            ]);
        }

        // Zapisz jednorazowe hasło w sesji (do pokazania adminowi)
        auth_start();
        $_SESSION['m365_new_pass']  = $password;
        $_SESSION['m365_new_login'] = $row['m365_login'];
        $_SESSION['m365_sent']      = true;

        require_once dirname(__DIR__) . '/includes/approval.php';
        log_contract_action($type, $id, (int)current_user()['id'], 'note',
            'Wysłano link aktywacyjny M365 na: ' . $person_email);
        flash_set('success', 'Link aktywacyjny M365 wysłany na ' . $person_email . '.');

    } elseif ($action === 'send_setup_email') {
        // Wysyła e-mail z loginiem M365 i tymczasowym hasłem — forceChangePasswordNextSignIn=true
        // Wolontariusz sam ustawia hasło przy pierwszym logowaniu.
        if (!$row['m365_user_id']) throw new RuntimeException('Brak ID użytkownika Azure AD.');
        if (!$person_email) throw new RuntimeException('Ta umowa nie zawiera adresu e-mail odbiorcy.');
        $password = M365Graph::generate_password();
        $graph->set_password($row['m365_user_id'], $password);
        $org   = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
        $name  = htmlspecialchars($person_name ?: $person_email, ENT_QUOTES);
        $login = htmlspecialchars($row['m365_login'], ENT_QUOTES);
        $pass  = htmlspecialchars($password, ENT_QUOTES);
        $mail_html = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#0078d4,#106ebe);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">&#128273; Ustaw hasło Microsoft 365 — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$name}</strong>!</p>
  <p>Twoje konto Microsoft 365 jest gotowe. Zaloguj się poniższymi danymi — przy pierwszym logowaniu zostaniesz poproszony/a o <strong>ustawienie własnego hasła</strong>.</p>
  <table style="background:#f8f9fa;border-radius:8px;padding:16px;width:100%;margin:16px 0;border-collapse:collapse">
    <tr>
      <td style="padding:5px 14px;color:#6c757d;width:140px;font-size:.9em">Login (e-mail)</td>
      <td style="padding:5px 14px"><strong style="font-family:monospace">{$login}</strong></td>
    </tr>
    <tr>
      <td style="padding:5px 14px;color:#6c757d;font-size:.9em">Hasło tymczasowe</td>
      <td style="padding:5px 14px"><strong style="font-family:monospace;font-size:1.1em;letter-spacing:.05em">{$pass}</strong></td>
    </tr>
  </table>
  <div style="background:#fff8e1;border-left:3px solid #f59e0b;border-radius:4px;padding:10px 14px;margin:14px 0;font-size:.88em">
    To hasło jest jednorazowe. Po zalogowaniu system poprosi o zmianę na własne.
  </div>
  <div style="margin:20px 0;text-align:center">
    <a href="https://portal.office.com" style="background:#0078d4;color:#fff;padding:13px 28px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:700">
      Zaloguj się do Microsoft 365 &#8594;
    </a>
  </div>
  <p style="color:#6c757d;font-size:.82em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    Jeśli masz problem z logowaniem, skontaktuj się z {$org}.
  </p>
</div></body></html>
HTML;
        $sender = m365_setting('m365_sender_user_id');
        if ($sender) {
            $graph->send_raw_email($sender, $person_email, "Ustaw hasło Microsoft 365 — {$org}", $mail_html);
        } else {
            require_once dirname(__DIR__) . '/includes/mail_queue.php';
            mail_queue_add($person_email, $person_name, "Ustaw hasło Microsoft 365 — {$org}", $mail_html, '', $type, $id, '', true);
        }
        auth_start();
        $_SESSION['m365_new_pass']  = $password;
        $_SESSION['m365_new_login'] = $row['m365_login'];
        $_SESSION['m365_sent']      = true;
        require_once dirname(__DIR__) . '/includes/approval.php';
        log_contract_action($type, $id, (int)current_user()['id'], 'note',
            'Wysłano link do ustawienia hasła M365 na: ' . $person_email);
        flash_set('success', 'E-mail z danymi do ustawienia hasła Microsoft 365 wysłany na ' . $person_email . '.');

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
