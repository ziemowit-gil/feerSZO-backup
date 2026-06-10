<?php
/**
 * it/action.php — endpoint dla wszystkich akcji IT
 * Obsługuje: M365 (create/enable/disable/delete/link), hasła, canva, inne serwisy
 * POST: action, account_id [, ...parametry]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/includes/it_helpers.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin', 'editor');

$is_json = (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json')
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || in_array($_POST['action'] ?? '', ['reveal_password']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($is_json) { header('Content-Type: application/json'); echo json_encode(['error'=>'Method not allowed']); exit; }
    header('Location: ' . APP_URL . '/it/index.php'); exit;
}
csrf_check();

$action     = $_POST['action']    ?? '';
$account_id = intval($_POST['account_id'] ?? 0);
$back       = $_POST['back']      ?? APP_URL . '/it/accounts.php';

// Wczytaj konto IT jeśli podano account_id
$account = null;
if ($account_id) {
    $account = db_one(
        "SELECT a.*, s.slug AS service_slug, s.name AS service_name, s.id AS sid
         FROM it_accounts a JOIN it_services s ON s.id=a.service_id
         WHERE a.id=?",
        [$account_id]
    );
    if (!$account) {
        flash_set('danger', 'Nie znaleziono konta IT.');
        header('Location: ' . APP_URL . '/it/accounts.php'); exit;
    }
    $back = APP_URL . '/it/accounts.php?id=' . $account_id;
}

// ── Pomocnicze: wczytaj dane umowy powiązanej z kontem ──────────────────────
function load_contract_row(?string $type, ?int $id): ?array {
    if (!$type || !$id) return null;
    $table = match ($type) {
        'wolontariat' => 'umowy_wolontariat',
        'zlecenie'    => 'umowy_zlecenie',
        'dzielo'      => 'umowy_dzielo',
        'praca'       => 'umowy_praca',
        default       => null,
    };
    return $table ? db_one("SELECT * FROM {$table} WHERE id=?", [$id]) : null;
}

try {

    // ══════════════════════════════════════════════════════════════════════
    // HASŁA
    // ══════════════════════════════════════════════════════════════════════

    if ($action === 'reveal_password') {
        if (!is_admin()) { echo json_encode(['error'=>'Brak uprawnień.']); exit; }
        $pid = intval($_POST['password_id'] ?? 0);
        $pw  = db_one("SELECT password_enc, is_superseded FROM it_service_passwords WHERE id=?", [$pid]);
        if (!$pw || !$pw['password_enc']) { echo json_encode(['error'=>'Brak danych hasła.']); exit; }

        // Zaloguj ujawnienie
        log_contract_action(
            $account['contract_type'] ?? 'it', (int)($account['contract_id'] ?? 0),
            current_user()['id'], 'it_password_reveal',
            'Ujawniono hasło (password_id=' . $pid . ') przez: ' . (current_user()['name'] ?? '?')
        );

        $plain = it_decrypt_password($pw['password_enc']);
        header('Content-Type: application/json');
        echo json_encode($plain !== null ? ['password' => $plain] : ['error' => 'Błąd deszyfrowania.']);
        exit;
    }

    if ($action === 'issue_password' || $action === 'issue_password_manual') {
        $plain = trim($_POST['password_plain'] ?? '');
        if (!$plain) $plain = M365Graph::generate_password();

        $service_id_manual = intval($_POST['service_id'] ?? 0);
        $login_manual      = trim($_POST['login'] ?? '');
        $send_email        = trim($_POST['send_to_email'] ?? '');
        $notes             = trim($_POST['notes'] ?? '') ?: null;

        $sid    = $account ? (int)$account['sid'] : $service_id_manual;
        $login  = $account ? ($account['login'] ?? '') : $login_manual;
        $ctype  = $account ? $account['contract_type'] : null;
        $cid    = $account ? (int)$account['contract_id'] : null;
        $pid    = $account ? ($account['person_id'] ? (int)$account['person_id'] : null) : null;

        if (!$sid) throw new \RuntimeException('Brak serwisu — wybierz serwis.');

        // Wyślij email jeśli podano
        $sent = false;
        if ($send_email && $login) {
            $svc = db_one("SELECT name FROM it_services WHERE id=?", [$sid]);
            $sender = m365_setting('m365_sender_user_id');
            if ($sender) {
                $graph = new M365Graph();
                $display = $account['display_name'] ?? $login;
                $graph->send_welcome_email($sender, $send_email, $display, $login, $plain);
                $sent = true;
            }
        }

        $pw_id = it_log_password([
            'account_id'    => $account ? $account_id : null,
            'service_id'    => $sid,
            'contract_type' => $ctype,
            'contract_id'   => $cid,
            'person_id'     => $pid,
            'login'         => $login ?: $login_manual,
            'plain'         => $plain,
            'sent_to_email' => $sent ? $send_email : null,
            'notes'         => $notes,
            'issued_by'     => current_user()['id'],
        ]);

        // Jednorazowe wyświetlenie w sesji
        auth_start();
        $_SESSION['it_issued_pass'] = [
            'login' => $login ?: $login_manual,
            'pass'  => $plain,
            'sent'  => $sent,
        ];

        flash_set('success', 'Hasło wydane' . ($sent ? ' i wysłane na ' . h($send_email) : '') . '.');
        header('Location: ' . $back); exit;
    }

    // ══════════════════════════════════════════════════════════════════════
    // M365 — tworzenie konta przez moduł IT
    // ══════════════════════════════════════════════════════════════════════

    if ($action === 'create_m365') {
        $ctype = $_POST['contract_type'] ?? '';
        $cid   = intval($_POST['contract_id'] ?? 0);
        if (!$ctype || !$cid) throw new \RuntimeException('Brak danych umowy.');

        $crow = load_contract_row($ctype, $cid);
        if (!$crow) throw new \RuntimeException('Nie znaleziono umowy.');

        $graph = new M365Graph();
        if (!$graph->is_configured()) throw new \RuntimeException('Integracja M365 nie jest skonfigurowana.');

        $person_name  = $crow['imie_nazwisko'] ?? '';
        $person_email = $crow['email'] ?? null;

        $login    = $graph->unique_login($person_name);
        $password = M365Graph::generate_password();
        $enabled  = m365_should_be_active($crow);

        $user     = $graph->create_user($login, $person_name, $password, $enabled);
        $user_id  = $user['id'];

        $lic_assigned = 0;
        $sku = m365_setting('m365_license_sku_id');
        if ($sku) { $graph->assign_license($user_id, $sku); $lic_assigned = 1; }

        $sent   = false;
        $sender = m365_setting('m365_sender_user_id');
        if ($sender && $person_email) {
            $graph->send_welcome_email($sender, $person_email, $person_name, $login, $password);
            $sent = true;
        }

        // Zapisz do umowy (backward compat)
        $table = match ($ctype) { 'wolontariat'=>'umowy_wolontariat','zlecenie'=>'umowy_zlecenie','dzielo'=>'umowy_dzielo',default=>throw new \RuntimeException("Nieobsługiwany typ: {$ctype}") };
        db_update($table, [
            'm365_konto'               => 1,
            'm365_login'               => $login,
            'm365_user_id'             => $user_id,
            'm365_konto_aktywne'       => $enabled ? 1 : 0,
            'm365_data_utworzenia'     => date('Y-m-d H:i:s'),
            'm365_licencja_przypisana' => $lic_assigned,
        ], $cid);

        // Zapisz do it_accounts
        $acc_id = it_sync_account($ctype, $cid, 'm365', [
            'login'            => $login,
            'external_id'      => $user_id,
            'display_name'     => $person_name,
            'is_active'        => $enabled ? 1 : 0,
            'license_assigned' => $lic_assigned,
        ]);

        // Zapisz hasło
        it_log_password([
            'account_id'    => $acc_id,
            'service_id'    => (int)(db_one("SELECT id FROM it_services WHERE slug='m365'")['id'] ?? 1),
            'contract_type' => $ctype,
            'contract_id'   => $cid,
            'login'         => $login,
            'plain'         => $password,
            'sent_to_email' => $sent ? $person_email : null,
            'issued_by'     => current_user()['id'],
        ]);

        // Auto-powiąż / utwórz konto lokalne
        $link_result = m365_auto_link_or_create_local($person_email ?? '', $person_name, $user_id);
        if ($link_result['msg']) {
            log_contract_action($ctype, $cid, current_user()['id'], 'note', $link_result['msg']);
        }

        auth_start();
        $_SESSION['m365_new_pass']  = $password;
        $_SESSION['m365_new_login'] = $login;
        $_SESSION['m365_sent']      = $sent;
        $_SESSION['it_issued_pass'] = ['login'=>$login,'pass'=>$password,'sent'=>$sent];

        $link_suffix = match($link_result['action']) {
            'linked'  => ' · konto lokalne powiązane',
            'created' => ' · konto lokalne utworzone',
            default   => '',
        };
        log_contract_action($ctype, $cid, current_user()['id'], 'edit', "Utworzono konto M365: {$login}{$link_suffix}" . ($sent?' (mail wysłany)':''));
        flash_set('success', "Konto M365 utworzone: {$login}{$link_suffix}" . ($sent ? ' (mail wysłany)' : ' (brak e-mail)'));
        header('Location: ' . APP_URL . "/contracts/{$ctype}/view.php?id={$cid}#tab-m365"); exit;
    }

    // ══════════════════════════════════════════════════════════════════════
    // M365 — akcje na istniejącym koncie przez it_accounts
    // ══════════════════════════════════════════════════════════════════════

    if (!$account) throw new \RuntimeException('Wymagane account_id dla tej akcji.');
    $ctype = $account['contract_type'];
    $cid   = (int)($account['contract_id'] ?? 0);
    $crow  = load_contract_row($ctype, $cid);
    $table = $ctype ? match ($ctype) { 'wolontariat'=>'umowy_wolontariat','zlecenie'=>'umowy_zlecenie','dzielo'=>'umowy_dzielo','praca'=>'umowy_praca',default=>null } : null;

    if ($action === 'enable' || $action === 'disable') {
        if ($account['service_slug'] === 'm365') {
            if (!$account['external_id']) throw new \RuntimeException('Brak Azure AD ID.');
            $graph = new M365Graph();
            if (!$graph->is_configured()) throw new \RuntimeException('M365 nie jest skonfigurowane.');
            $graph->set_enabled($account['external_id'], $action === 'enable');
        }
        $active = $action === 'enable' ? 1 : 0;
        db_update('it_accounts', ['is_active' => $active, 'updated_at' => date('Y-m-d H:i:s')], $account_id);
        if ($table && $cid) db_update($table, ['m365_konto_aktywne' => $active], $cid);

        // Auto-powiąż / utwórz konto lokalne przy włączaniu konta M365
        if ($action === 'enable' && $account['service_slug'] === 'm365' && $account['external_id']) {
            $_ae = $crow['email'] ?? ($account['login'] ?? '');
            $_an = $crow['imie_nazwisko'] ?? ($account['display_name'] ?? '');
            $link_result = m365_auto_link_or_create_local($_ae, $_an, $account['external_id']);
            if ($link_result['msg'] && $ctype && $cid) {
                log_contract_action($ctype, $cid, current_user()['id'], 'note', $link_result['msg']);
            }
        }

        if ($ctype && $cid) log_contract_action($ctype, $cid, current_user()['id'], 'edit',
            ($action==='enable' ? 'Włączono' : 'Wyłączono') . ' konto ' . $account['service_name']);
        flash_set('success', 'Konto ' . ($active ? 'włączone' : 'wyłączone') . '.');

    } elseif ($action === 'activate' || $action === 'deactivate') {
        $active = $action === 'activate' ? 1 : 0;
        db_update('it_accounts', [
            'is_active'         => $active,
            'deactivated_at'    => $active ? null : date('Y-m-d H:i:s'),
            'deactivated_by'    => $active ? null : current_user()['id'],
            'updated_at'        => date('Y-m-d H:i:s'),
        ], $account_id);
        flash_set('success', 'Konto ' . ($active ? 'aktywowane' : 'dezaktywowane') . '.');

    } elseif ($action === 'reset_password') {
        if ($account['service_slug'] !== 'm365') throw new \RuntimeException('Reset hasła dostępny tylko dla M365.');
        if (!$account['external_id']) throw new \RuntimeException('Brak Azure AD ID.');
        if (!$crow || empty($crow['email'])) throw new \RuntimeException('Brak adresu e-mail w umowie.');

        $graph = new M365Graph();
        if (!$graph->is_configured()) throw new \RuntimeException('M365 nie jest skonfigurowane.');

        $password = M365Graph::generate_password();
        $graph->set_password($account['external_id'], $password);

        $sender = m365_setting('m365_sender_user_id');
        $sent   = false;
        if ($sender) {
            $graph->send_welcome_email($sender, $crow['email'], $account['display_name'] ?? $account['login'], $account['login'], $password);
            $sent = true;
        }

        it_log_password([
            'account_id'    => $account_id,
            'service_id'    => (int)$account['sid'],
            'contract_type' => $ctype,
            'contract_id'   => $cid,
            'login'         => $account['login'],
            'plain'         => $password,
            'sent_to_email' => $sent ? $crow['email'] : null,
            'notes'         => 'Reset hasła',
            'issued_by'     => current_user()['id'],
        ]);

        auth_start();
        $_SESSION['m365_new_pass']  = $password;
        $_SESSION['m365_new_login'] = $account['login'];
        $_SESSION['m365_sent']      = $sent;
        $_SESSION['it_issued_pass'] = ['login'=>$account['login'],'pass'=>$password,'sent'=>$sent];

        if ($ctype && $cid) log_contract_action($ctype, $cid, current_user()['id'], 'edit',
            'Reset hasła M365: ' . $account['login'] . ($sent ? ' (mail wysłany)' : ''));
        flash_set('success', 'Hasło zresetowane' . ($sent ? ' i wysłane na ' . h($crow['email']) : '') . '.');

    } elseif ($action === 'delete_m365') {
        if (!is_admin()) throw new \RuntimeException('Tylko administrator może trwale usuwać konta M365.');
        if ($account['service_slug'] !== 'm365') throw new \RuntimeException('Akcja tylko dla kont M365.');
        if (!$account['external_id']) throw new \RuntimeException('Brak Azure AD ID.');

        $graph = new M365Graph();
        if (!$graph->is_configured()) throw new \RuntimeException('M365 nie jest skonfigurowane.');
        $graph->delete_user($account['external_id']);

        // Dezaktywuj konto lokalne
        $local = db_one("SELECT id, name FROM users WHERE microsoft_id=? LIMIT 1", [$account['external_id']]);
        if ($local) db()->prepare("UPDATE users SET is_active=0 WHERE id=?")->execute([$local['id']]);

        // Wyczyść umowę
        if ($table && $cid) db_update($table, ['m365_konto'=>0,'m365_login'=>null,'m365_user_id'=>null,'m365_konto_aktywne'=>0,'m365_licencja_przypisana'=>0], $cid);

        // Oznacz konto IT jako nieaktywne
        db_update('it_accounts', ['is_active'=>0,'deactivated_at'=>date('Y-m-d H:i:s'),'deactivated_by'=>current_user()['id']], $account_id);

        if ($ctype && $cid) {
            log_contract_action($ctype, $cid, current_user()['id'], 'edit',
                'Usunięto konto M365: ' . $account['login'] . ($local ? ' | Dezaktywowano konto lokalne: ' . $local['name'] : ''));
        }

        // Powiadom adminów
        $admins = db_all("SELECT email, name FROM users WHERE role='admin' AND is_active=1");
        $actor  = current_user();
        foreach ($admins as $admin) {
            approval_send_email($admin['email'], '[' . ORG_NAME . '] Usunięto konto Microsoft 365',
                '<p>Usunięto konto M365 <strong>' . htmlspecialchars($account['login']) . '</strong>'
                . ' przez ' . htmlspecialchars($actor['name']) . ' (' . date('Y-m-d H:i:s') . ')</p>'
                . ($local ? '<p>Konto lokalne <strong>' . htmlspecialchars($local['name']) . '</strong> dezaktywowane.</p>' : '')
            );
        }
        flash_set('warning', 'Konto M365 <strong>' . h($account['login']) . '</strong> zostało usunięte z Azure AD.');
        header('Location: ' . APP_URL . '/it/accounts.php'); exit;

    } elseif ($action === 'link_local_user') {
        if ($account['service_slug'] !== 'm365') throw new \RuntimeException('Powiązanie lokalne dostępne tylko dla M365.');
        $local_uid = intval($_POST['local_user_id'] ?? 0);
        if (!$local_uid) throw new \RuntimeException('Wybierz użytkownika do powiązania.');
        if (!$account['external_id']) throw new \RuntimeException('Brak Azure AD ID w koncie.');
        $local = db_one("SELECT id, name FROM users WHERE id=?", [$local_uid]);
        if (!$local) throw new \RuntimeException('Nie znaleziono użytkownika.');
        db()->prepare("UPDATE users SET microsoft_id=NULL WHERE microsoft_id=? AND id!=?")->execute([$account['external_id'], $local_uid]);
        db()->prepare("UPDATE users SET microsoft_id=? WHERE id=?")->execute([$account['external_id'], $local_uid]);
        flash_set('success', 'Powiązano konto lokalne <strong>' . h($local['name']) . '</strong> z M365.');

    } else {
        throw new \RuntimeException("Nieznana akcja: {$action}");
    }

} catch (\Exception $e) {
    flash_set('danger', 'Błąd IT: ' . $e->getMessage());
}

header('Location: ' . $back); exit;
