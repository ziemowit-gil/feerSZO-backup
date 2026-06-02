<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/termination.php';
require_once dirname(dirname(__DIR__)) . '/includes/amendments.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once dirname(dirname(__DIR__)) . '/includes/letters.php';
require_once dirname(dirname(__DIR__)) . '/includes/certificates.php';
require_once dirname(dirname(__DIR__)) . '/includes/m365.php';
require_once dirname(dirname(__DIR__)) . '/includes/messages.php';
require_once dirname(dirname(__DIR__)) . '/includes/supervisors.php';
require_once dirname(dirname(__DIR__)) . '/includes/tasks.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';
require_once dirname(dirname(__DIR__)) . '/includes/whatsapp.php';
require_once dirname(dirname(__DIR__)) . '/includes/persons.php';
require_once dirname(dirname(__DIR__)) . '/includes/grants.php';
require_once dirname(dirname(__DIR__)) . '/includes/timesheets.php';
require_once dirname(dirname(__DIR__)) . '/includes/apaczka.php';

require_once dirname(dirname(__DIR__)) . '/includes/sms.php';
require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
require_login();
$TYPE  = 'wolontariat';
$TABLE = 'umowy_wolontariat';
$id    = intval($_GET['id'] ?? 0);
$row   = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);

// ── Kod odzyskiwania — odczyt jednorazowy z sesji ─────────────────────────────
auth_start();
$_show_recovery = !empty($_GET['show_recovery']) && isset($_SESSION['recovery_code_plain_' . $id]);
$_recovery_code = '';
if ($_show_recovery) {
    $_recovery_code = $_SESSION['recovery_code_plain_' . $id];
    unset($_SESSION['recovery_code_plain_' . $id]); // jednorazowe — usuń po odczycie
}
if (!$row) { http_response_code(404); die('Nie znaleziono porozumienia.'); }
if (!viewer_owns_contract($TYPE, $row)) {
    flash_set('error', 'Nie masz dostępu do tej umowy.');
    header('Location: ' . APP_URL . '/panel/index.php'); exit;
}

// ── Szybka zmiana statusu ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_set_status'])) {
    csrf_check();
    if (can_edit()) {
        $new_status = $_POST['status'] ?? '';
        if (isset(STATUS_LABELS[$new_status])) {
            $old_status = $row['status'];
            db_update($TABLE, ['status' => $new_status], $id);
            log_contract_action($TYPE, $id, (int)current_user()['id'], 'status_change',
                'Zmiana statusu: ' . $old_status . ' → ' . $new_status);
            $row['status'] = $new_status;
        }
    }
    header('Location: view.php?id=' . $id); exit;
}

// ── Obsługa opiekuna umowy ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_set_supervisor'])) {
    csrf_check();
    supervisor_set($TYPE, $id, (int)($_POST['sup_user_id'] ?? 0));
    header('Location: view.php?id=' . $id); exit;
}

// ── Obsługa wiadomości ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_msg_send'])) {
    csrf_check();
    $body = trim($_POST['msg_body'] ?? '');
    if ($body !== '') {
        $u = current_user();
        $sender_type = can_edit() ? 'admin' : 'user';
        pmsg_send('contract', $id, $TYPE, $sender_type, (int)$u['id'], $u['name'], $body);
    }
    header('Location: ' . APP_URL . "/contracts/{$TYPE}/view.php?id={$id}#tab-messages-anchor");
    exit;
}

// ── Nadanie hasła do konta portalu ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_set_portal_pass']) && can_edit()) {
    csrf_check();
    $email = trim($row['email'] ?? '');
    $new_pass = trim($_POST['new_pass'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('danger', 'Brak adresu e-mail — nie można znaleźć konta.');
    } elseif (strlen($new_pass) < 6) {
        flash_set('danger', 'Hasło musi mieć co najmniej 6 znaków.');
    } else {
        $portal_user = db_one("SELECT id FROM users WHERE LOWER(email)=LOWER(?)", [$email]);
        if (!$portal_user) {
            flash_set('warning', 'Wolontariusz nie ma konta w portalu.');
        } else {
            $hash = password_hash($new_pass, PASSWORD_BCRYPT);
            db()->prepare("UPDATE users SET password=?, login_code=NULL WHERE id=?")->execute([$hash, (int)$portal_user['id']]);
            log_contract_action($TYPE, $id, (int)current_user()['id'], 'note', 'Nadano nowe hasło do konta portalu dla: ' . $email);
            // Wyślij email z nowym hasłem
            $org  = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
            $name = h($row['imie_nazwisko'] ?? $email);
            $numer = h($row['numer_umowy']);
            $login_url = APP_URL . '/auth/login.php';
            $body = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#0d6efd;padding:20px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.2rem">🔑 Nowe hasło do portalu — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>Witaj, <strong>{$name}</strong>!</p>
  <p>Administrator nadał Ci nowe hasło do portalu wolontariusza (umowa <strong>{$numer}</strong>).</p>
  <table style="background:#f8f9fa;border-radius:6px;padding:16px;width:100%;margin:12px 0;border-collapse:collapse">
    <tr><td style="padding:4px 12px;color:#6c757d;width:120px">Login</td>
        <td style="padding:4px 12px"><strong>{$email}</strong></td></tr>
    <tr><td style="padding:4px 12px;color:#6c757d">Hasło</td>
        <td style="padding:4px 12px"><strong style="font-family:monospace;font-size:1.15em">{$new_pass}</strong></td></tr>
  </table>
  <div style="margin:20px 0;text-align:center">
    <a href="{$login_url}" style="background:#0d6efd;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;display:inline-block">
      Zaloguj się do portalu →
    </a>
  </div>
  <p style="color:#6c757d;font-size:.85em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    Jeśli nie spodziewałeś/aś się tej wiadomości, skontaktuj się z organizacją.
  </p>
</div></body></html>
HTML;
            require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
            mail_queue_add($email, $row['imie_nazwisko'] ?? $email, "Nowe hasło do portalu — {$org}", $body);
            mail_queue_process();
            flash_set('success', 'Hasło zostało zmienione i wysłane na adres ' . $email . '.');
        }
    }
    header('Location: view.php?id=' . $id); exit;
}

// ── Ponowne wysłanie danych do panelu wolontariusza ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_resend_portal']) && can_edit()) {
    csrf_check();
    $email = trim($row['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('danger', 'Brak adresu e-mail wolontariusza — nie można wysłać danych.');
    } else {
        $portal_user = db_one("SELECT id, name FROM users WHERE LOWER(email)=LOWER(?)", [$email]);
        if (!$portal_user) {
            flash_set('warning', 'Wolontariusz nie ma konta w portalu. Edytuj i zapisz umowę ponownie, aby konto zostało utworzone automatycznie.');
        } else {
            // Generuj jednorazowy kod dostępu i wyślij link
            $code  = strtoupper(bin2hex(random_bytes(5)));
            db()->prepare("UPDATE users SET login_code=? WHERE id=?")->execute([$code, (int)$portal_user['id']]);
            $login_url = APP_URL . '/auth/login.php?tab=code';
            $org       = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
            $name      = h($row['imie_nazwisko'] ?? $portal_user['name']);
            $numer     = h($row['numer_umowy']);
            $body = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#0d6efd;padding:20px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.2rem">📋 Portal Wolontariusza — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>Witaj, <strong>{$name}</strong>!</p>
  <p>Poniżej znajdziesz dane do logowania do portalu wolontariusza w związku z umową <strong>{$numer}</strong>.</p>
  <p>Twój adres e-mail: <strong>{$email}</strong></p>
  <div style="background:#f8f9fa;border-radius:6px;padding:16px;margin:16px 0;text-align:center">
    <div style="color:#6c757d;font-size:.85rem;margin-bottom:6px">Jednorazowy kod dostępu (ważny 7 dni)</div>
    <div style="font-family:monospace;font-size:2rem;font-weight:700;letter-spacing:.15em;color:#0d6efd">{$code}</div>
  </div>
  <div style="margin:20px 0;text-align:center">
    <a href="{$login_url}" style="background:#0d6efd;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;display:inline-block">
      Zaloguj się do portalu →
    </a>
  </div>
  <p style="color:#6c757d;font-size:.85em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    Jeśli nie spodziewałeś/aś się tej wiadomości, zignoruj ją.
  </p>
</div></body></html>
HTML;
            require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
            mail_queue_add($email, $row['imie_nazwisko'] ?? $email, "Dane logowania do portalu — {$org}", $body);
            mail_queue_process();
            log_contract_action($TYPE, $id, (int)current_user()['id'], 'note', 'Ponownie wysłano dane logowania do panelu na: ' . $email);
            flash_set('success', 'Dane logowania zostały wysłane na adres ' . $email . '.');
        }
    }
    header('Location: view.php?id=' . $id); exit;
}

$PAGE_TITLE = 'Porozumienie ' . $row['numer_umowy'];

// ── Dane do zakładek ──────────────────────────────────────────────────────────
$_pending_term    = get_pending_termination_for_contract($TYPE, $id);
$amendments       = get_amendments($TYPE, $id);
$edit_requests    = get_edit_requests($TYPE, $id);
$approval         = get_current_approval($TYPE, $id);
$audit_log        = get_audit_log($TYPE, $id);
$_letters         = get_contract_letters($TYPE, $id);
$cert_requests    = get_certificate_requests($TYPE, $id);
$cert_has_pending = !empty(array_filter($cert_requests, fn($r) => $r['status'] === 'oczekuje'));
$has_pending_edit = !empty(array_filter($edit_requests, fn($r) => $r['status'] === 'oczekuje'));

// Zwroty kosztów
require_once dirname(dirname(__DIR__)) . '/includes/zwroty_kosztow.php';
$_fm = new FinanceManager();
$_zwroty = $_fm->listForContract($id, $TYPE);
$_zwroty_cnt_pending = count(array_filter($_zwroty, fn($z) => in_array($z['status'],['oczekuje','weryfikacja'])));
$_zwroty_el = $_fm->validateEligibility($id, $TYPE);
$m365_enabled     = m365_setting('m365_enabled') === '1';

$person = !empty($row['person_id']) ? person_by_id((int)$row['person_id']) : null;
$linked_action = !empty($row['action_id']) ? action_by_id((int)$row['action_id']) : null;
$linked_grant  = !empty($row['grant_id'])  ? grant_by_id((int)$row['grant_id'])   : null;
$unit_name = '';
if (!empty($row['org_unit_id'])) {
    try {
        $pos = db_one("SELECT name FROM org_units WHERE id=?", [(int)$row['org_unit_id']]);
        $unit_name = $pos['name'] ?? '';
    } catch(\Throwable $e) {}
}

// Odznaki na zakładkach
$_badge_obieg = 0;
if ($approval && $approval['status'] === 'oczekuje') $_badge_obieg++;
$_badge_obieg += count(array_filter($amendments, fn($a) => $a['status'] === 'oczekuje'));
$_badge_obieg += count(array_filter($edit_requests, fn($r) => $r['status'] === 'oczekuje'));

$_badge_docs = count(array_filter($cert_requests, fn($r) => $r['status'] === 'oczekuje'));

// ── Zadania powiązane z umową ─────────────────────────────────────────────────
$_tasks_enabled = true;
try {
    $_tm = db_one("SELECT value FROM settings WHERE key_='tasks_enabled'");
    $_tasks_enabled = ($_tm['value'] ?? '1') !== '0';
} catch (\Throwable $e) {}

$_contract_tasks = [];
$_contract_workspaces = [];
if ($_tasks_enabled && can_edit()) {
    try {
        $_contract_tasks = db_all(
            "SELECT t.*, tl.name AS list_name, tw.name AS workspace_name
             FROM tasks t
             JOIN task_lists tl ON tl.id = t.list_id
             JOIN task_workspaces tw ON tw.id = t.workspace_id
             WHERE t.contract_type = ? AND t.contract_id = ? AND t.deleted_at IS NULL
             ORDER BY t.created_at DESC",
            [$TYPE, $id]
        );
    } catch (\Throwable $e) {}
    try {
        $_contract_workspaces = db_all(
            "SELECT tw.*, (SELECT COUNT(*) FROM task_lists tl2 WHERE tl2.workspace_id=tw.id) AS list_count
             FROM task_workspaces tw WHERE tw.is_active=1 ORDER BY tw.name"
        );
    } catch (\Throwable $e) {}
}

// ── Obsługa WhatsApp ──────────────────────────────────────────────────────────
$_wa_send_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_wa_send']) && can_edit()) {
    csrf_check();
    $wa_phone = trim($_POST['wa_phone'] ?? $row['telefon'] ?? '');
    $wa_msg   = trim($_POST['wa_message'] ?? '');
    if (!$wa_phone || !$wa_msg) {
        $_wa_send_result = ['ok' => false, 'msg' => 'Podaj numer telefonu i treść wiadomości.'];
    } else {
        try {
            wa_send_message($wa_phone, $wa_msg);
            log_system_action((int)current_user()['id'], 'wa_send', 'WhatsApp → ' . $wa_phone . ' [' . $row['numer_umowy'] . ']');
            $_wa_send_result = ['ok' => true, 'msg' => 'Wiadomość WhatsApp wysłana na +' . preg_replace('/\D/', '', $wa_phone)];
        } catch (\Exception $e) {
            $_wa_send_result = ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
}

// ── Obsługa tworzenia zadania z umowy ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_add_task']) && can_edit()) {
    csrf_check();
    $t_title    = trim($_POST['task_title']    ?? '');
    $t_list_id  = (int)($_POST['task_list_id'] ?? 0);
    $t_ws_id    = (int)($_POST['task_ws_id']   ?? 0);
    $t_priority = (int)($_POST['task_priority'] ?? 2);
    $t_due      = trim($_POST['task_due']       ?? '');
    $t_desc     = trim($_POST['task_desc']      ?? '');

    if ($t_title && $t_list_id && $t_ws_id) {
        task_require_workspace_access($t_ws_id, ['admin', 'editor']);
        $list_check = db_one("SELECT id FROM task_lists WHERE id=? AND workspace_id=?", [$t_list_id, $t_ws_id]);
        if ($list_check) {
            $max_pos = db_one("SELECT MAX(position) AS mp FROM tasks WHERE list_id=? AND deleted_at IS NULL", [$t_list_id]);
            $pos = ((float)($max_pos['mp'] ?? 0)) + 1000;
            $now = date('Y-m-d H:i:s');
            $uid = (int)current_user()['id'];
            $task_desc = $t_desc;
            if (!$task_desc) $task_desc = 'Wolontariusz: ' . $row['imie_nazwisko'] . ' · Umowa: ' . $row['numer_umowy'];
            db()->prepare(
                "INSERT INTO tasks (workspace_id, list_id, title, description, position, priority, due_date,
                                    created_by, contract_type, contract_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $t_ws_id, $t_list_id, $t_title, $task_desc, $pos,
                $t_priority, $t_due ?: null,
                $uid, $TYPE, $id, $now, $now
            ]);
            $new_task_id = (int)db()->lastInsertId();
            task_log($new_task_id, $uid, 'created', null, null, ['source' => 'contract', 'contract' => $row['numer_umowy']]);
            flash_set('success', 'Zadanie „' . $t_title . '" dodane do tablicy.');
        }
    }
    header('Location: view.php?id=' . $id . '#tab-tasks-anchor'); exit;
}

// ── Canva — oznacz jako zaproszony / włącz dostęp ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_canva_invited']) && can_edit()) {
    csrf_check();
    $now = date('Y-m-d H:i:s');
    db_update($TABLE, ['canva_invited_at' => $now, 'canva_access' => 1], $id);
    log_contract_action($TYPE, $id, (int)current_user()['id'], 'note',
        'Oznaczono jako zaproszony do Canva');
    // Wyślij wolontariuszowi mail potwierdzający aktywację
    $email_vol = trim($row['email'] ?? '');
    if ($email_vol && filter_var($email_vol, FILTER_VALIDATE_EMAIL)) {
        $org  = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
        $name = h($row['imie_nazwisko'] ?? $email_vol);
        $m365 = trim($row['m365_login'] ?? '');
        $login_method = $m365
            ? "kontem Microsoft 365 (<strong>{$m365}</strong>)"
            : "adresem e-mail (<strong>{$email_vol}</strong>)";
        $body = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#7c3aed,#a855f7);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">🎨 Twój dostęp do Canva jest aktywny — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$name}</strong>!</p>
  <p>
    Twoje zaproszenie do przestrzeni <strong>Canva Pro</strong> organizacji <strong>{$org}</strong>
    zostało wysłane. Sprawdź skrzynkę e-mail pod adresem <strong>{$email_vol}</strong>
    i kliknij przycisk <strong>„Dołącz do zespołu"</strong> w wiadomości od Canva.
  </p>
  <div style="background:#fdf4ff;border-left:4px solid #a855f7;border-radius:4px;padding:14px 18px;margin:18px 0;font-size:.92em">
    Loguj się do Canva przez {$login_method}.<br>
    Jeśli używasz SSO (Microsoft 365), wybierz opcję <em>„Continue with Microsoft"</em> na stronie logowania Canva.
  </div>
  <div style="margin:22px 0;text-align:center">
    <a href="https://www.canva.com" style="background:#7c3aed;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
      Otwórz Canva →
    </a>
  </div>
  <p style="font-size:.82em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
    W razie pytań skontaktuj się ze swoim opiekunem w {$org}.
  </p>
</div>
</body></html>
HTML;
        try {
            approval_send_email($email_vol, "Twój dostęp do Canva jest gotowy — {$org}", $body);
        } catch (\Throwable $e) {}
    }
    flash_set('success', 'Zaproszenie Canva oznaczone. E-mail wysłany do wolontariusza.');
    header('Location: view.php?id=' . $id . '#tab-m365-anchor'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_canva_toggle']) && can_edit()) {
    csrf_check();
    $new_val = (int)($_POST['canva_access_val'] ?? 0);
    db_update($TABLE, ['canva_access' => $new_val], $id);
    if (!$new_val) db_update($TABLE, ['canva_invited_at' => null], $id);
    log_contract_action($TYPE, $id, (int)current_user()['id'], 'note',
        'Canva access: ' . ($new_val ? 'włączono' : 'wyłączono'));
    header('Location: view.php?id=' . $id . '#tab-m365-anchor'); exit;
}

// ── Utwórz / podepnij konto lokalne na podstawie M365 (dla umów przed 01.06) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_create_local_from_m365']) && can_edit()) {
    csrf_check();
    $m365_login = trim($row['m365_login'] ?? '');
    $m365_uid   = trim($row['m365_user_id'] ?? '');
    $name       = trim($row['imie_nazwisko'] ?? '');
    $email      = trim($row['email'] ?? $m365_login);

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('danger', 'Brak prawidłowego adresu e-mail — nie można utworzyć konta lokalnego.');
        header('Location: view.php?id=' . $id . '#tab-m365-anchor'); exit;
    }

    // 1. Szukaj istniejącego konta po microsoft_id
    $existing = $m365_uid
        ? db_one("SELECT id FROM users WHERE microsoft_id=?", [$m365_uid])
        : null;

    // 2. Jeśli nie znaleziono po microsoft_id — szukaj po e-mail
    if (!$existing) {
        $existing = db_one("SELECT id FROM users WHERE LOWER(email)=LOWER(?)", [$email]);
    }

    if ($existing) {
        // Konto istnieje — tylko podepnij microsoft_id
        db()->prepare("UPDATE users SET microsoft_id=?, is_active=1 WHERE id=?")
            ->execute([$m365_uid ?: null, (int)$existing['id']]);
        log_contract_action($TYPE, $id, (int)current_user()['id'], 'note',
            'Powiązano istniejące konto lokalne (id=' . $existing['id'] . ') z kontem M365: ' . $m365_login);
        flash_set('success', 'Istniejące konto lokalne zostało powiązane z kontem Microsoft 365.');
    } else {
        // Konto nie istnieje — utwórz nowe (bez hasła, logowanie tylko przez M365)
        $uid = db_insert('users', [
            'name'         => $name ?: $email,
            'email'        => $email,
            'password'     => null,          // brak hasła — logowanie przez M365
            'microsoft_id' => $m365_uid ?: null,
            'role'         => 'viewer',
            'is_active'    => 1,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        log_contract_action($TYPE, $id, (int)current_user()['id'], 'note',
            'Utworzono konto lokalne (id=' . $uid . ') na podstawie M365: ' . $m365_login);
        flash_set('success', 'Konto lokalne zostało utworzone i powiązane z kontem Microsoft 365. Logowanie tylko przez M365.');
    }
    header('Location: view.php?id=' . $id . '#tab-m365-anchor'); exit;
}

include dirname(dirname(__DIR__)) . '/includes/header.php';

// Hasło M365 z sesji (wyświetlamy przed zakładkami)
auth_start();
$_m365_creds = null;
if (!empty($_SESSION['m365_new_login'])) {
    $_m365_creds = [
        'login' => $_SESSION['m365_new_login'],
        'pass'  => $_SESSION['m365_new_pass'],
        'sent'  => $_SESSION['m365_sent'] ?? false,
        'email' => $row['email'] ?? '',
    ];
    unset($_SESSION['m365_new_login'], $_SESSION['m365_new_pass'], $_SESSION['m365_sent']);
}
?>

<?php
$_cvh_type       = $TYPE;
$_cvh_id         = $id;
$_cvh_row        = $row;
$_cvh_icon       = 'bi-heart';
$_cvh_label      = 'Porozumienie wolontariackie';
$_cvh_person     = $row['imie_nazwisko'] ?? '';
$_cvh_person_sub = $row['email'] ?? ($row['m365_login'] ?? '');
$_cvh_amount     = null;
$_cvh_amount_lbl = '';
$_cvh_end_date   = $row['data_zakonczenia'] ?? null;
$_cvh_subject    = $row['zakres_czynnosci'] ?? ($row['opis_zadania'] ?? null);
$_cvh_list_url   = APP_URL . '/contracts/wolontariat/list.php';
$_cvh_edit_url   = 'edit.php?id=' . $id;
include dirname(dirname(__DIR__)) . '/includes/contract_view_header.php';
?>

<!-- ── Styles widoku umowy ──────────────────────────────────────────────────── -->
<style>
/* Zakładki — nowoczesny styl */
#wolontariatTabs {
  border-bottom: 2px solid #E2E8F0;
  flex-wrap: nowrap; overflow-x: auto; overflow-y: hidden;
  scrollbar-width: none; -ms-overflow-style: none;
  gap: .15rem; padding-bottom: 0;
}
#wolontariatTabs::-webkit-scrollbar { display: none; }
#wolontariatTabs .nav-link {
  border: none; border-bottom: 2px solid transparent; border-radius: 0;
  padding: .65rem 1rem; font-size: .83rem; font-weight: 500;
  color: #64748B; white-space: nowrap;
  margin-bottom: -2px; transition: color .12s, border-color .12s;
  display: flex; align-items: center; gap: .35rem;
}
#wolontariatTabs .nav-link:hover { color: #1E3A5F; }
#wolontariatTabs .nav-link.active {
  color: #1E6DFF; border-bottom-color: #1E6DFF;
  font-weight: 700; background: none;
}
#wolontariatTabs .nav-link .bi { font-size: .9rem; }

/* Zawartość zakładek */
#wolontariatTabsContent {
  border: 1px solid #E2E8F0 !important;
  border-top: none !important;
  border-radius: 0 0 12px 12px !important;
}

/* Karty sekcji wewnątrz zakładek (inne zakładki) */
.tab-pane .card {
  border: 1px solid #E2E8F0 !important;
  border-radius: 12px !important;
  box-shadow: 0 1px 4px rgba(0,0,0,.04) !important;
  overflow: hidden;
}
.tab-pane .card-header {
  background: #F8FAFC !important;
  border-bottom: 1px solid #E2E8F0 !important;
  padding: .75rem 1.1rem !important;
  font-size: .88rem !important;
  font-weight: 700 !important;
  color: #1E293B !important;
  display: flex; align-items: center; gap: .4rem;
}
.tab-pane .card-body { padding: 1rem 1.1rem !important; }

/* Detail label/value */
.detail-label {
  font-size: .7rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: #94A3B8; margin-bottom: .2rem;
}
.detail-value { font-size: .9rem; color: #1E293B; line-height: 1.4; }

/* ── Poziome karty sekcji (tab Umowa) ──────────────────────────── */
.irow {
  display: flex; align-items: stretch;
  background: #fff; border: 1px solid #E2E8F0;
  border-radius: 12px; margin-bottom: .65rem;
  box-shadow: 0 1px 4px rgba(0,0,0,.04); overflow: hidden;
}
.irow-head {
  display: flex; flex-direction: column;
  align-items: center; justify-content: flex-start;
  gap: .45rem; padding: 1rem .75rem .85rem;
  background: #F8FAFC; border-right: 1px solid #E2E8F0;
  min-width: 80px; text-align: center; flex-shrink: 0;
}
.irow-head-icon {
  width: 36px; height: 36px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.05rem; flex-shrink: 0;
}
.irow-head-label {
  font-size: .62rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: #94A3B8; line-height: 1.3;
}
.irow-body {
  flex: 1; padding: .85rem 1.2rem;
  display: flex; flex-wrap: wrap; gap: .55rem 2.2rem; align-items: flex-start;
}
.ifield { min-width: 110px; flex: 0 1 auto; }
.ifield-wide { flex: 1 1 260px; }
.ifield-full { flex: 1 1 100%; }
@media (max-width: 576px) {
  .irow { flex-direction: column; }
  .irow-head { flex-direction: row; min-width: unset; border-right: none; border-bottom: 1px solid #E2E8F0; padding: .6rem 1rem; }
  .irow-head-label { font-size: .72rem; }
}
</style>

<!-- ── ZAKŁADKI ────────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-0 no-print" id="wolontariatTabs" role="tablist">

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-umowa-btn" data-bs-toggle="tab"
            data-bs-target="#tab-umowa" type="button" role="tab">
      <i class="bi bi-file-text"></i> Umowa
    </button>
  </li>

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-wolontariusz-btn" data-bs-toggle="tab"
            data-bs-target="#tab-wolontariusz" type="button" role="tab">
      <i class="bi bi-person"></i> Wolontariusz
    </button>
  </li>

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-docs-btn" data-bs-toggle="tab"
            data-bs-target="#tab-docs" type="button" role="tab">
      <i class="bi bi-folder2-open"></i> Dokumenty
      <?php if ($_badge_docs): ?>
      <span class="badge bg-warning text-dark ms-1"><?= $_badge_docs ?></span>
      <?php endif; ?>
    </button>
  </li>

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-obieg-btn" data-bs-toggle="tab"
            data-bs-target="#tab-obieg" type="button" role="tab">
      <i class="bi bi-arrow-repeat"></i> Obieg
      <?php if ($_badge_obieg): ?>
      <span class="badge bg-danger ms-1"><?= $_badge_obieg ?></span>
      <?php endif; ?>
    </button>
  </li>

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-m365-btn" data-bs-toggle="tab"
            data-bs-target="#tab-m365" type="button" role="tab">
      <i class="bi bi-microsoft"></i> M365
      <?php if ($row['m365_konto']): ?>
      <span class="badge <?= $row['m365_konto_aktywne'] ? 'bg-success' : 'bg-secondary' ?> ms-1">
        <?= $row['m365_konto_aktywne'] ? '●' : '○' ?>
      </span>
      <?php endif; ?>
    </button>
  </li>

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-historia-btn" data-bs-toggle="tab"
            data-bs-target="#tab-historia" type="button" role="tab">
      <i class="bi bi-journal-text"></i> Historia
      <?php if ($audit_log): ?>
      <span class="badge bg-secondary ms-1"><?= count($audit_log) ?></span>
      <?php endif; ?>
    </button>
  </li>

  <?php if ($_tasks_enabled && can_edit()): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-tasks-btn" data-bs-toggle="tab"
            data-bs-target="#tab-tasks" type="button" role="tab">
      <i class="bi bi-kanban"></i> Zadania
      <?php if ($_contract_tasks): ?>
      <span class="badge bg-primary ms-1"><?= count($_contract_tasks) ?></span>
      <?php endif; ?>
    </button>
  </li>
  <?php endif; ?>

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-zwroty-btn" data-bs-toggle="tab"
            data-bs-target="#tab-zwroty" type="button" role="tab">
      <i class="bi bi-receipt-cutoff"></i> Zwroty kosztów
      <?php if ($_zwroty_cnt_pending): ?>
      <span class="badge bg-warning text-dark ms-1"><?= $_zwroty_cnt_pending ?></span>
      <?php elseif (count($_zwroty)): ?>
      <span class="badge bg-secondary ms-1"><?= count($_zwroty) ?></span>
      <?php endif; ?>
    </button>
  </li>

  <?php if (apaczka_setting('apaczka_enabled') !== '0' && can_edit()): ?>
  <?php $_ship_rows = shipments_for_contract($id, 'wolontariat'); $_ship_pending_tab = count(array_filter($_ship_rows, fn($r) => $r['status'] === 'requested')); ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-shipments-btn" data-bs-toggle="tab"
            data-bs-target="#tab-shipments" type="button" role="tab">
      <i class="bi bi-box-seam"></i> Przesyłki
      <?php if ($_ship_pending_tab): ?>
      <span class="badge bg-warning text-dark ms-1"><?= $_ship_pending_tab ?></span>
      <?php elseif (count($_ship_rows)): ?>
      <span class="badge bg-secondary ms-1"><?= count($_ship_rows) ?></span>
      <?php endif; ?>
    </button>
  </li>
  <?php endif; ?>

  <?php if (module_enabled('timesheets_enabled')): ?>
  <?php $_ts_rows = ts_contract_summary($id); $_ts_pending_tab = count(array_filter($_ts_rows, fn($r) => $r['status'] === 'złożone')); ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-godziny-btn" data-bs-toggle="tab"
            data-bs-target="#tab-godziny" type="button" role="tab">
      <i class="bi bi-clock-history"></i> Godziny
      <?php if ($_ts_pending_tab): ?>
      <span class="badge bg-warning text-dark ms-1"><?= $_ts_pending_tab ?></span>
      <?php elseif (count($_ts_rows)): ?>
      <span class="badge bg-secondary ms-1"><?= count($_ts_rows) ?></span>
      <?php endif; ?>
    </button>
  </li>
  <?php endif; ?>

  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-messages-btn" data-bs-toggle="tab"
            data-bs-target="#tab-messages" type="button" role="tab">
      <i class="bi bi-chat-dots"></i> Wiadomości
      <?php
        $_msg_unread = msg_unread_thread('contract', $id, can_edit() ? 'admin' : 'user');
        if ($_msg_unread): ?>
      <span class="badge bg-danger ms-1"><?= $_msg_unread ?></span>
      <?php endif; ?>
    </button>
  </li>

</ul>

<div class="tab-content border border-top-0 rounded-bottom bg-white shadow-sm mb-3"
     id="wolontariatTabsContent" style="padding:1.25rem">

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 1 — UMOWA
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-umowa" role="tabpanel">

  <!-- Dane podstawowe -->
  <div class="irow">
    <div class="irow-head">
      <div class="irow-head-icon" style="background:#EEF4FF;color:#2563EB"><i class="bi bi-file-text-fill"></i></div>
      <div class="irow-head-label">Dane<br>umowy</div>
    </div>
    <div class="irow-body">
      <div class="ifield">
        <div class="detail-label">Opiekun wolontariusza</div>
        <div class="detail-value"><?= h($row['opiekun']) ?: '—' ?></div>
      </div>
      <div class="ifield">
        <div class="detail-label">Data zawarcia</div>
        <div class="detail-value"><?= date_pl($row['data_zawarcia']) ?></div>
      </div>
      <div class="ifield">
        <div class="detail-label">Data rozpoczęcia</div>
        <div class="detail-value"><?= date_pl($row['data_rozpoczecia']) ?></div>
      </div>
      <div class="ifield">
        <div class="detail-label">Data zakończenia</div>
        <div class="detail-value">
          <?php if ($row['bezterminowa']): ?>
            <span class="badge bg-info text-dark">Bezterminowe</span>
          <?php else: ?>
            <?= date_pl($row['data_zakonczenia']) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="ifield">
        <div class="detail-label">Projekt / program</div>
        <div class="detail-value"><?= h($row['projekt_program']) ?: '—' ?></div>
      </div>
      <div class="ifield">
        <div class="detail-label">Miejsce wolontariatu</div>
        <div class="detail-value"><?= h($row['miejsce_wolontariatu']) ?: '—' ?></div>
      </div>
    </div>
  </div>

  <!-- Szczegóły wolontariatu -->
  <div class="irow">
    <div class="irow-head">
      <div class="irow-head-icon" style="background:#F0FDF4;color:#16A34A"><i class="bi bi-heart-fill"></i></div>
      <div class="irow-head-label">Szczegóły</div>
    </div>
    <div class="irow-body">
      <div class="ifield-full">
        <div class="detail-label">Przedmiot porozumienia</div>
        <div class="detail-value"><?= nl2br(h($row['przedmiot_porozumienia'])) ?: '—' ?></div>
      </div>
      <div class="ifield">
        <div class="detail-label">Godzin / tydzień</div>
        <div class="detail-value"><?= ($row['godzin_tygodniowo'] !== null && $row['godzin_tygodniowo'] !== '') ? h($row['godzin_tygodniowo']) . ' h' : '—' ?></div>
      </div>
      <div class="ifield">
        <div class="detail-label">Godzin przepracowanych</div>
        <div class="detail-value"><?= ($row['godzin_przepracowanych'] !== null && $row['godzin_przepracowanych'] !== '') ? h($row['godzin_przepracowanych']) . ' h' : '—' ?></div>
      </div>
      <div class="ifield">
        <div class="detail-label">Zwrot kosztów</div>
        <div class="detail-value"><?= yn($row['zwrot_kosztow']) ?></div>
      </div>
      <?php if ($row['zwrot_kosztow'] && $row['zwrot_kosztow_opis']): ?>
      <div class="ifield-wide">
        <div class="detail-label">Opis zwrotu kosztów</div>
        <div class="detail-value"><?= h($row['zwrot_kosztow_opis']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- BHP i ubezpieczenia -->
  <div class="irow">
    <div class="irow-head">
      <div class="irow-head-icon" style="background:#FFF7ED;color:#EA580C"><i class="bi bi-shield-check"></i></div>
      <div class="irow-head-label">BHP<br>i ubezp.</div>
    </div>
    <div class="irow-body">
      <div class="ifield">
        <div class="detail-label">Szkolenie BHP</div>
        <div class="detail-value"><?= yn($row['szkolenie_bhp']) ?></div>
      </div>
      <?php if ($row['szkolenie_bhp']): ?>
      <div class="ifield">
        <div class="detail-label">Data szkolenia BHP</div>
        <div class="detail-value"><?= date_pl($row['data_szkolenia_bhp']) ?></div>
      </div>
      <?php endif; ?>
      <div class="ifield">
        <div class="detail-label">Ubezpieczenie NNW</div>
        <div class="detail-value"><?= yn($row['ubezpieczenie_nnw']) ?></div>
      </div>
      <?php if ($row['ubezpieczenie_nnw'] && $row['numer_polisy_nnw']): ?>
      <div class="ifield">
        <div class="detail-label">Nr polisy NNW</div>
        <div class="detail-value"><?= h($row['numer_polisy_nnw']) ?></div>
      </div>
      <?php endif; ?>
      <div class="ifield">
        <div class="detail-label">Ubezpieczenie OC</div>
        <div class="detail-value"><?= yn($row['ubezpieczenie_oc']) ?></div>
      </div>
    </div>
  </div>

  <!-- Podpisanie -->
  <div class="irow">
    <div class="irow-head">
      <div class="irow-head-icon" style="background:#F5F3FF;color:#7C3AED"><i class="bi bi-pen-fill"></i></div>
      <div class="irow-head-label">Podpi-<br>sanie</div>
    </div>
    <div class="irow-body">
      <div class="ifield">
        <div class="detail-label">Forma podpisania</div>
        <div class="detail-value">
          <?php
          $forma_labels = [
            'papierowa'             => '<i class="bi bi-pen text-secondary"></i> Papierowa',
            'elektroniczna'         => '<i class="bi bi-laptop text-primary"></i> Elektroniczna',
            'epodpis_kwalifikowany' => '<i class="bi bi-shield-lock text-success"></i> ePodpis kwalifikowany',
          ];
          echo $forma_labels[$row['forma_podpisania'] ?? ''] ?? h(ucfirst($row['forma_podpisania'] ?? '')) ?: '—';
          ?>
        </div>
      </div>
      <?php if ($row['forma_podpisania'] === 'elektroniczna'): ?>
      <div class="ifield">
        <div class="detail-label">Platforma</div>
        <div class="detail-value"><?= h($row['platforma_el']) ?: '—' ?></div>
      </div>
      <div class="ifield">
        <div class="detail-label">ID dokumentu</div>
        <div class="detail-value"><?= h($row['id_dokumentu_el']) ?: '—' ?></div>
      </div>
      <div class="ifield">
        <div class="detail-label">Plik potwierdzenia</div>
        <div class="detail-value"><?= upload_link($row['plik_potwierdzenia']) ?></div>
      </div>
      <?php elseif ($row['forma_podpisania'] === 'epodpis_kwalifikowany'): ?>
      <div class="ifield">
        <div class="detail-label">Dostawca (TSP)</div>
        <div class="detail-value"><?= h($row['epodpis_dostawca'] ?? '') ?: '—' ?></div>
      </div>
      <div class="ifield">
        <div class="detail-label">Nr seryjny certyfikatu</div>
        <div class="detail-value font-monospace small"><?= h($row['epodpis_nr_certyfikatu'] ?? '') ?: '—' ?></div>
      </div>
      <div class="ifield">
        <div class="detail-label">Ważność certyfikatu</div>
        <div class="detail-value">
          <?php
          $waz = $row['epodpis_data_waznosci'] ?? '';
          if ($waz) {
              $past = strtotime($waz) < time();
              echo '<span class="' . ($past ? 'text-danger' : 'text-success') . '">';
              echo date_pl($waz);
              echo $past ? ' <i class="bi bi-exclamation-circle"></i>' : ' <i class="bi bi-check-circle"></i>';
              echo '</span>';
          } else { echo '—'; }
          ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($row['uwagi']): ?>
  <div class="irow">
    <div class="irow-head">
      <div class="irow-head-icon" style="background:#F8FAFC;color:#64748B"><i class="bi bi-chat-left-text"></i></div>
      <div class="irow-head-label">Uwagi</div>
    </div>
    <div class="irow-body">
      <div class="ifield-full">
        <div class="detail-value" style="font-size:.88rem;color:#374151"><?= nl2br(h($row['uwagi'])) ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($row['nr_roboczy'] || $row['nr_system'] || $row['nr_rejestru']): ?>
  <div class="irow">
    <div class="irow-head">
      <div class="irow-head-icon" style="background:#F8FAFC;color:#94A3B8"><i class="bi bi-hash"></i></div>
      <div class="irow-head-label">Numery<br>ref.</div>
    </div>
    <div class="irow-body">
      <?php if ($row['nr_roboczy']): ?>
      <div class="ifield">
        <div class="detail-label">Nr roboczy</div>
        <div class="detail-value"><?= h($row['nr_roboczy']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($row['nr_system']): ?>
      <div class="ifield">
        <div class="detail-label">Nr ogólny (webNGO)</div>
        <div class="detail-value"><?= h($row['nr_system']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($row['nr_rejestru']): ?>
      <div class="ifield">
        <div class="detail-label">Nr rejestru</div>
        <div class="detail-value fw-bold font-monospace"><?= h($row['nr_rejestru']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /tab-umowa -->

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 2 — WOLONTARIUSZ
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-wolontariusz" role="tabpanel">

  <div class="row g-3">
  <div class="col-lg-8">

    <!-- Dane osobowe -->
    <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-person-vcard"></i> Dane osobowe</div>
    <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6"><div class="detail-label">Imię i nazwisko</div><div class="detail-value fw-semibold"><?= h($row['imie_nazwisko']) ?: '—' ?></div></div>
      <div class="col-md-3"><div class="detail-label">PESEL</div><div class="detail-value font-monospace"><?= h($row['pesel']) ?: '—' ?></div></div>
      <div class="col-md-3"><div class="detail-label">Data urodzenia</div><div class="detail-value"><?= date_pl($row['data_urodzenia']) ?></div></div>
      <div class="col-md-8"><div class="detail-label">Adres zamieszkania</div><div class="detail-value"><?= address_format($row, true) ?: '—' ?></div></div>
      <div class="col-md-4"><div class="detail-label">Telefon</div>
        <div class="detail-value">
          <?= $row['telefon'] ? '<a href="tel:' . h($row['telefon']) . '">' . h($row['telefon']) . '</a>' : '—' ?>
        </div>
      </div>
      <div class="col-md-6"><div class="detail-label">Adres e-mail</div>
        <div class="detail-value d-flex align-items-center gap-2 flex-wrap">
          <?= $row['email'] ? '<a href="mailto:' . h($row['email']) . '">' . h($row['email']) . '</a>' : '—' ?>
          <?php if ($row['email'] && can_edit()): ?>
          <div class="dropdown d-inline-block">
            <button class="btn btn-outline-secondary btn-sm py-0 px-2 dropdown-toggle" type="button"
                    data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-person-lock me-1"></i>Konto portalu
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:260px">
              <li><h6 class="dropdown-header small">
                <i class="bi bi-envelope me-1"></i><?= h($row['email']) ?>
              </h6></li>
              <li><hr class="dropdown-divider my-1"></li>

              <!-- Wyślij kod jednorazowy -->
              <li>
                <form method="post" class="px-3 py-1">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <button type="submit" name="_resend_portal" value="1" class="btn btn-sm btn-outline-primary w-100 text-start">
                    <i class="bi bi-send me-2"></i>Wyślij kod jednorazowy
                  </button>
                  <div class="text-muted" style="font-size:.75rem;margin-top:3px;padding-left:2px">Link do logowania e-mailem (7 dni)</div>
                </form>
              </li>

              <li><hr class="dropdown-divider my-1"></li>

              <!-- Nadaj hasło -->
              <li>
                <div class="px-3 py-1">
                  <div class="small fw-semibold mb-1"><i class="bi bi-key me-1"></i>Nadaj nowe hasło</div>
                  <form method="post" class="d-flex gap-1" id="form_set_pass_<?= $id ?>">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="password" name="new_pass" class="form-control form-control-sm"
                           placeholder="Min. 6 znaków" minlength="6" required
                           style="font-family:monospace">
                    <button type="submit" name="_set_portal_pass" value="1"
                            class="btn btn-sm btn-warning flex-shrink-0" title="Zapisz i wyślij e-mailem">
                      <i class="bi bi-check-lg"></i>
                    </button>
                  </form>
                  <div class="text-muted" style="font-size:.75rem;margin-top:3px">Hasło zostanie wysłane e-mailem</div>
                </div>
              </li>
            </ul>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-md-3"><div class="detail-label">Niepełnoletni</div><div class="detail-value"><?= yn($row['niepelnoletni']) ?></div></div>
      <?php if ($row['niepelnoletni'] && $row['zgoda_opiekuna']): ?>
      <div class="col-md-3"><div class="detail-label">Zgoda opiekuna</div><div class="detail-value"><?= upload_link($row['zgoda_opiekuna']) ?></div></div>
      <?php endif; ?>

      <?php if ($row['niepelnoletni'] && ($row['rodzic_imie_nazwisko'] || $row['rodzic_email'])): ?>
      <div class="col-12 mt-1">
        <div class="border rounded p-2 bg-warning-subtle small">
          <div class="fw-semibold mb-1 text-warning-emphasis">
            <i class="bi bi-person-hearts"></i> Rodzic / opiekun prawny
          </div>
          <div class="row g-2">
            <?php if ($row['rodzic_imie_nazwisko']): ?>
            <div class="col-md-4">
              <span class="text-muted">Imię i nazwisko</span><br>
              <strong><?= h($row['rodzic_imie_nazwisko']) ?></strong>
            </div>
            <?php endif; ?>
            <?php if ($row['rodzic_email']): ?>
            <div class="col-md-4">
              <span class="text-muted">E-mail</span><br>
              <a href="mailto:<?= h($row['rodzic_email']) ?>"><?= h($row['rodzic_email']) ?></a>
            </div>
            <?php endif; ?>
            <?php if ($row['rodzic_telefon']): ?>
            <div class="col-md-4">
              <span class="text-muted">Telefon</span><br>
              <a href="tel:<?= h($row['rodzic_telefon']) ?>"><?= h($row['rodzic_telefon']) ?></a>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($row['adres_linia1'] || $row['adres_odbiorca']): ?>
      <div class="col-12"><hr class="my-1"></div>
      <div class="col-12"><div class="detail-label"><i class="bi bi-mailbox"></i> Adres do korespondencji</div></div>
      <div class="col-md-6"><div class="detail-label">Odbiorca</div><div class="detail-value"><?= h($row['adres_odbiorca']) ?: h($row['imie_nazwisko']) ?: '—' ?></div></div>
      <div class="col-md-6"><div class="detail-label">Kraj</div><div class="detail-value"><?= h($row['adres_kraj']) ?: 'PL' ?></div></div>
      <div class="col-md-8"><div class="detail-label">Adres linia 1</div><div class="detail-value"><?= h($row['adres_linia1']) ?: '—' ?></div></div>
      <?php if ($row['adres_linia2']): ?>
      <div class="col-md-4"><div class="detail-label">Adres linia 2</div><div class="detail-value"><?= h($row['adres_linia2']) ?></div></div>
      <?php endif; ?>
      <div class="col-md-4"><div class="detail-label">Kod pocztowy</div><div class="detail-value font-monospace"><?= h($row['adres_kod_pocztowy']) ?: '—' ?></div></div>
      <div class="col-md-8"><div class="detail-label">Miasto</div><div class="detail-value"><?= h($row['adres_miasto']) ?: '—' ?></div></div>
      <?php endif; ?>
    </div>
    </div>
    </div>

    <!-- Godziny pracy -->
    <div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-clock-history"></i> Godziny pracy</div>
    <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <div class="text-center p-3 border rounded">
          <div class="fs-2 fw-bold text-primary">
            <?= ($row['godzin_tygodniowo'] !== null && $row['godzin_tygodniowo'] !== '') ? h($row['godzin_tygodniowo']) : '—' ?>
          </div>
          <div class="text-muted small">godz. / tydzień</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="text-center p-3 border rounded">
          <div class="fs-2 fw-bold text-success">
            <?= ($row['godzin_przepracowanych'] !== null && $row['godzin_przepracowanych'] !== '') ? h($row['godzin_przepracowanych']) : '—' ?>
          </div>
          <div class="text-muted small">godz. przepracowanych</div>
        </div>
      </div>
      <?php if ($row['godzin_tygodniowo'] && $row['data_rozpoczecia'] && !$row['bezterminowa'] && $row['data_zakonczenia']): ?>
      <?php
        $tygodni = round((strtotime($row['data_zakonczenia']) - strtotime($row['data_rozpoczecia'])) / (7 * 86400));
        $planowane = $tygodni * floatval($row['godzin_tygodniowo']);
      ?>
      <div class="col-md-4">
        <div class="text-center p-3 border rounded border-secondary">
          <div class="fs-2 fw-bold text-secondary"><?= number_format($planowane, 0) ?></div>
          <div class="text-muted small">godz. planowanych (<?= $tygodni ?> tyg.)</div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    </div>
    </div>

  </div>
  <div class="col-lg-4">

    <?php if ($person): ?>
    <div class="card shadow-sm mb-3 border-primary border-opacity-25">
    <div class="card-header fw-semibold text-primary"><i class="bi bi-person-vcard"></i> Karta osoby</div>
    <div class="card-body small">
      <div class="fw-semibold mb-1">
        <a href="<?= APP_URL ?>/persons/view.php?id=<?= $person['id'] ?>" class="text-decoration-none">
          <?= h($person['imie_nazwisko']) ?>
        </a>
      </div>
      <?php if ($person['pesel']): ?><div class="text-muted">PESEL: <span class="font-monospace"><?= h($person['pesel']) ?></span></div><?php endif; ?>
      <?php if ($person['email']): ?><div><a href="mailto:<?= h($person['email']) ?>"><?= h($person['email']) ?></a></div><?php endif; ?>
      <?php if ($person['telefon']): ?><div class="text-muted"><?= h($person['telefon']) ?></div><?php endif; ?>
      <?php if ($unit_name): ?><div class="mt-1"><span class="badge bg-secondary"><?= h($unit_name) ?></span></div><?php endif; ?>
      <div class="mt-2">
        <a href="<?= APP_URL ?>/persons/view.php?id=<?= $person['id'] ?>" class="btn btn-sm btn-outline-primary py-0">
          <i class="bi bi-arrow-right"></i> Profil osoby
        </a>
      </div>
    </div>
    </div>
    <?php elseif ($unit_name): ?>
    <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-diagram-3"></i> Komórka organizacyjna</div>
    <div class="card-body small"><span class="badge bg-secondary"><?= h($unit_name) ?></span></div>
    </div>
    <?php endif; ?>

    <?php if ($linked_action || $linked_grant): ?>
    <div class="card shadow-sm mb-3 border-success border-opacity-25">
    <div class="card-header fw-semibold text-success"><i class="bi bi-link-45deg"></i> Powiązania</div>
    <div class="card-body small">
      <?php if ($linked_action): ?>
      <div class="mb-2">
        <div class="text-muted mb-1">Działanie</div>
        <a href="<?= APP_URL ?>/actions/view.php?id=<?= $linked_action['id'] ?>" class="text-decoration-none fw-semibold">
          <i class="bi bi-calendar-event me-1 text-success"></i><?= h($linked_action['nazwa']) ?>
        </a>
        <div><span class="badge bg-secondary mt-1"><?= h($linked_action['status']) ?></span></div>
      </div>
      <?php endif; ?>
      <?php if ($linked_grant): ?>
      <div class="<?= $linked_action ? 'border-top pt-2' : '' ?>">
        <div class="text-muted mb-1">Grant</div>
        <a href="<?= APP_URL ?>/grants/view.php?id=<?= $linked_grant['id'] ?>" class="text-decoration-none fw-semibold">
          <i class="bi bi-cash-coin me-1 text-success"></i><?= h($linked_grant['nazwa']) ?>
        </a>
        <div class="text-muted"><?= h($linked_grant['donator']) ?></div>
      </div>
      <?php endif; ?>
    </div>
    </div>
    <?php endif; ?>

    <!-- Pliki -->
    <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-paperclip"></i> Pliki</div>
    <div class="card-body">
      <div class="mb-2"><div class="detail-label">Plik porozumienia</div><?= upload_link($row['plik_umowy']) ?></div>
      <?php if ($row['plik_potwierdzenia']): ?>
      <div class="mb-2"><div class="detail-label">Potwierdzenie podpisania</div><?= upload_link($row['plik_potwierdzenia']) ?></div>
      <?php endif; ?>
      <?php if ($row['zgoda_opiekuna']): ?>
      <div class="mb-2"><div class="detail-label">Zgoda opiekuna</div><?= upload_link($row['zgoda_opiekuna']) ?></div>
      <?php endif; ?>
    </div>
    </div>

    <!-- Opiekun umowy -->
    <?php $sup = supervisor_get($TYPE, $id); ?>
    <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-person-check"></i> Opiekun umowy</div>
    <div class="card-body">
      <?php if ($sup): ?>
        <div class="fw-semibold small"><?= h($sup['user_name']) ?></div>
        <div class="text-muted" style="font-size:.8rem"><?= h($sup['user_email']) ?></div>
      <?php else: ?>
        <div class="text-muted small">Nieprzypisany</div>
      <?php endif; ?>
      <?php if (can_edit()): ?>
      <form method="post" class="mt-2">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_set_supervisor" value="1">
        <select name="sup_user_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— brak —</option>
          <?php foreach (supervisors_all_editors() as $_se): ?>
          <option value="<?= (int)$_se['id'] ?>" <?= ($sup && (int)$sup['user_id']===(int)$_se['id']) ? 'selected' : '' ?>>
            <?= h($_se['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
    </div>
    </div>

    <!-- Historia zmian -->
    <div class="card shadow-sm">
    <div class="card-header fw-semibold">Metadata</div>
    <div class="card-body small text-muted">
      <div class="mb-1"><i class="bi bi-calendar-plus"></i> Dodano: <?= date_pl($row['created_at']) ?></div>
      <div><i class="bi bi-calendar-check"></i> Zmodyfikowano: <?= date_pl($row['updated_at']) ?></div>
    </div>
    </div>

  </div>
  </div>

</div><!-- /tab-wolontariusz -->

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 3 — DOKUMENTY (Pisma + Zaświadczenia)
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-docs" role="tabpanel">

  <!-- Pisma -->
  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-envelope-paper"></i> Pisma</span>
    <?php if (can_edit()): ?>
    <a href="<?= APP_URL ?>/contracts/letters/add.php?type=<?= $TYPE ?>&id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-plus-lg"></i> Dodaj pismo
    </a>
    <?php endif; ?>
  </div>
  <?php if ($_letters): ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0 align-middle">
    <thead class="table-light">
      <tr><th>Kierunek</th><th>Typ</th><th>Tytuł</th><th>Data</th><th>Strona</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($_letters as $_l): ?>
    <tr>
      <td><?= letter_direction_badge($_l['kierunek']) ?></td>
      <td><?= letter_type_badge($_l['typ_pisma']) ?></td>
      <td>
        <a href="<?= APP_URL ?>/contracts/letters/view.php?id=<?= $_l['id'] ?>" class="text-decoration-none">
          <?= h($_l['tytul']) ?>
        </a>
        <?php if ($_l['email_sent']): ?><i class="bi bi-envelope-check text-success ms-1" title="E-mail wysłany"></i><?php endif; ?>
        <?php if ($_l['plik']): ?><i class="bi bi-paperclip text-muted ms-1" title="Z plikiem"></i><?php endif; ?>
      </td>
      <td class="small text-nowrap"><?= date_pl($_l['data_pisma']) ?></td>
      <td class="small"><?= h($_l['kierunek'] === 'wychodzące' ? ($_l['odbiorca'] ?: '—') : ($_l['nadawca'] ?: '—')) ?></td>
      <td class="text-end text-nowrap">
        <a href="<?= APP_URL ?>/contracts/letters/view.php?id=<?= $_l['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
        <?php if ($_l['plik']): ?>
        <a href="<?= h(letter_file_url($_l['plik'])) ?>" download class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i></a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="card-body text-muted small">Brak pism dla tej umowy.</div>
  <?php endif; ?>
  </div>

  <!-- Zaświadczenia -->
  <div class="card shadow-sm">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-award"></i> Zaświadczenia</span>
    <?php if (!$cert_has_pending): ?>
    <a href="<?= APP_URL ?>/certificates/request.php?type=<?= $TYPE ?>&id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-plus-lg"></i> Złóż wniosek
    </a>
    <?php else: ?>
    <span class="badge bg-warning text-dark"><i class="bi bi-clock"></i> Wniosek w toku</span>
    <?php endif; ?>
  </div>
  <?php if ($cert_requests): ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0">
    <thead class="table-light"><tr><th>Wnioskodawca</th><th>Cel</th><th>Data</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cert_requests as $cr): ?>
    <tr>
      <td><?= h($cr['requester_name']) ?></td>
      <td class="small text-truncate" style="max-width:200px"><?= h($cr['cel']) ?></td>
      <td class="small text-nowrap"><?= date_pl($cr['created_at']) ?></td>
      <td><?= certificate_status_badge($cr['status']) ?></td>
      <td class="text-end text-nowrap">
        <?php if ($cr['status'] === 'oczekuje' && is_admin()): ?>
        <a href="<?= APP_URL ?>/certificates/issue.php?id=<?= $cr['id'] ?>" class="btn btn-sm btn-success">
          <i class="bi bi-award"></i> Wydaj
        </a>
        <?php elseif ($cr['status'] === 'wydane'): ?>
        <a href="<?= APP_URL ?>/certificates/print.php?id=<?= $cr['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success">
          <i class="bi bi-printer"></i> Drukuj
        </a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="card-body text-muted small">Brak wniosków o zaświadczenia.</div>
  <?php endif; ?>
  </div>

</div><!-- /tab-docs -->

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 4 — OBIEG (Akceptacja + Aneksy + Wnioski o edycję)
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-obieg" role="tabpanel">

  <!-- Akceptacja -->
  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-check2-circle"></i> Akceptacja</span>
    <?php if ($approval): echo approval_badge($approval['status']); else: ?>
    <span class="badge bg-secondary">Nie złożono</span>
    <?php endif; ?>
  </div>
  <div class="card-body">

  <?php if ($approval): ?>
  <div class="row g-2 mb-3">
    <div class="col-md-4"><div class="detail-label">Wnioskujący</div><div class="detail-value"><?= h($approval['requested_by_name'] ?? '—') ?></div></div>
    <div class="col-md-4"><div class="detail-label">Data wniosku</div><div class="detail-value"><?= date_pl($approval['requested_at']) ?></div></div>
    <?php if ($approval['decided_at']): ?>
    <div class="col-md-4"><div class="detail-label">Data decyzji</div><div class="detail-value"><?= date_pl($approval['decided_at']) ?></div></div>
    <?php if ($approval['decision_note']): ?>
    <div class="col-12"><div class="detail-label">Uwaga</div><div class="detail-value"><?= h($approval['decision_note']) ?></div></div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($approval && $approval['status'] === 'oczekuje' && is_admin()): ?>
  <form method="post" action="<?= APP_URL ?>/contracts/approvals/approve.php" class="mb-3">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="approval_id" value="<?= $approval['id'] ?>">
    <div class="row g-2 align-items-end">
      <div class="col-md-8">
        <label class="form-label small">Uwaga (opcjonalne)</label>
        <input type="text" name="decision_note" class="form-control form-control-sm">
      </div>
      <div class="col-auto">
        <button name="decision" value="zaakceptowana" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Zaakceptuj</button>
        <button name="decision" value="odrzucona" class="btn btn-sm btn-danger"><i class="bi bi-x-lg"></i> Odrzuć</button>
      </div>
    </div>
  </form>
  <?php endif; ?>

  <div class="d-flex gap-2 flex-wrap">
    <?php if (can_edit() && (!$approval || $approval['status'] !== 'oczekuje')): ?>
    <form method="post" action="<?= APP_URL ?>/contracts/approvals/submit.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="<?= $TYPE ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-sm btn-outline-warning"><i class="bi bi-send"></i> Złóż do akceptacji</button>
    </form>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
      <i class="bi bi-trash3"></i> Usuń umowę
    </button>
    <?php endif; ?>
  </div>

  </div>
  </div>

  <!-- Aneksy -->
  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-file-earmark-diff"></i> Aneksy</span>
    <?php if (can_edit()): ?>
    <a href="<?= APP_URL ?>/contracts/approvals/amendments_submit.php?type=<?= $TYPE ?>&id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-plus-lg"></i> Nowy aneks
    </a>
    <?php endif; ?>
  </div>
  <?php if ($amendments): ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0">
    <thead class="table-light"><tr><th>Nr</th><th>Opis zmian</th><th>Złożono</th><th>Status</th><th>Plik</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($amendments as $am): ?>
    <tr>
      <td><span class="badge bg-secondary">#<?= $am['numer_aneksu'] ?></span></td>
      <td style="max-width:250px">
        <?= h($am['opis_zmian']) ?>
        <?php if (!empty($am['proposed_changes'])): ?>
        <br><button class="btn btn-link btn-sm p-0 mt-1" type="button"
          data-bs-toggle="collapse" data-bs-target="#am-changes-<?= $am['id'] ?>">
          <i class="bi bi-table"></i> Pokaż zmiany pól
        </button>
        <div class="collapse mt-1" id="am-changes-<?= $am['id'] ?>">
          <?= render_amendment_changes($am['proposed_changes']) ?>
        </div>
        <?php endif; ?>
      </td>
      <td><?= date_pl($am['requested_at']) ?></td>
      <td><?= amendment_badge($am['status']) ?></td>
      <td><?= upload_link($am['plik_aneksu'] ?? '') ?></td>
      <td class="text-end">
        <?php if ($am['status'] === 'oczekuje' && is_admin()): ?>
        <form method="post" action="<?= APP_URL ?>/contracts/approvals/amendments_approve.php" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="amendment_id" value="<?= $am['id'] ?>">
          <input type="hidden" name="decision" value="zaakceptowany">
          <button class="btn btn-sm btn-success" title="Zatwierdź"><i class="bi bi-check-lg"></i></button>
        </form>
        <form method="post" action="<?= APP_URL ?>/contracts/approvals/amendments_approve.php" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="amendment_id" value="<?= $am['id'] ?>">
          <input type="hidden" name="decision" value="odrzucony">
          <button class="btn btn-sm btn-danger" title="Odrzuć"><i class="bi bi-x-lg"></i></button>
        </form>
        <?php endif; ?>
        <?php if ($am['decision_note']): ?>
        <span class="text-muted small ms-1" title="<?= h($am['decision_note']) ?>"><i class="bi bi-chat-text"></i></span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="card-body text-muted small">Brak aneksów.</div>
  <?php endif; ?>
  </div>

  <!-- Wnioski o edycję -->
  <div class="card shadow-sm">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-pencil-square"></i> Wnioski o edycję</span>
    <?php if (can_edit() && !$has_pending_edit): ?>
    <a href="<?= APP_URL ?>/contracts/approvals/changes_request.php?type=<?= $TYPE ?>&id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-pencil"></i> Złóż wniosek
    </a>
    <?php endif; ?>
  </div>
  <?php if ($edit_requests): ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0">
    <thead class="table-light"><tr><th>Opis żądanej zmiany</th><th>Złożono przez</th><th>Data</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($edit_requests as $er): ?>
    <tr>
      <td class="text-truncate" style="max-width:280px"><?= h($er['opis_zmian']) ?></td>
      <td><?= h($er['requested_by_name'] ?? '—') ?></td>
      <td><?= date_pl($er['requested_at']) ?></td>
      <td><?= edit_request_badge($er['status']) ?></td>
      <td class="text-end">
        <?php if ($er['status'] === 'oczekuje' && is_admin()): ?>
        <form method="post" action="<?= APP_URL ?>/contracts/approvals/changes_approve.php" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="request_id" value="<?= $er['id'] ?>">
          <input type="hidden" name="decision" value="zaakceptowany">
          <button class="btn btn-sm btn-success" title="Zatwierdź"><i class="bi bi-check-lg"></i></button>
        </form>
        <form method="post" action="<?= APP_URL ?>/contracts/approvals/changes_approve.php" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="request_id" value="<?= $er['id'] ?>">
          <input type="hidden" name="decision" value="odrzucony">
          <button class="btn btn-sm btn-danger" title="Odrzuć"><i class="bi bi-x-lg"></i></button>
        </form>
        <?php endif; ?>
        <?php if ($er['decision_note']): ?>
        <span class="text-muted small" title="<?= h($er['decision_note']) ?>"><i class="bi bi-chat-text"></i></span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="card-body text-muted small">Brak wniosków o edycję.</div>
  <?php endif; ?>
  </div>

</div><!-- /tab-obieg -->

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 5 — M365
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-m365" role="tabpanel">

  <div class="card shadow-sm">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-microsoft"></i> Microsoft 365</span>
    <?php if ($row['m365_konto']): ?>
    <span class="badge bg-<?= $row['m365_konto_aktywne'] ? 'success' : 'secondary' ?>">
      <?= $row['m365_konto_aktywne'] ? 'Konto aktywne' : 'Konto nieaktywne' ?>
    </span>
    <?php else: ?>
    <span class="badge bg-light text-dark border">Brak konta</span>
    <?php endif; ?>
  </div>
  <div class="card-body">

  <?php if ($row['m365_konto']): ?>
  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="detail-label">Login M365</div>
      <div class="detail-value font-monospace"><?= h($row['m365_login']) ?></div></div>
    <div class="col-md-4"><div class="detail-label">Utworzono</div>
      <div class="detail-value"><?= date_pl($row['m365_data_utworzenia']) ?></div></div>
    <div class="col-md-4"><div class="detail-label">Licencja przypisana</div>
      <div class="detail-value"><?= yn($row['m365_licencja_przypisana']) ?></div></div>
  </div>

  <?php if (can_edit()): ?>
  <div class="d-flex gap-2 flex-wrap mb-3">
    <?php if ($row['m365_konto_aktywne']): ?>
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="wolontariat">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="disable">
      <button class="btn btn-sm btn-warning" data-confirm="Wyłączyć konto M365?">
        <i class="bi bi-pause-circle"></i> Wyłącz konto
      </button>
    </form>
    <?php else: ?>
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="wolontariat">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="enable">
      <button class="btn btn-sm btn-success"><i class="bi bi-play-circle"></i> Włącz konto</button>
    </form>
    <?php endif; ?>
    <?php if ($row['email']): ?>
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="wolontariat">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="send_email">
      <button class="btn btn-sm btn-outline-primary"><i class="bi bi-envelope"></i> Wyślij mail z hasłem</button>
    </form>
    <?php endif; ?>
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="wolontariat">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="unlink">
      <button class="btn btn-sm btn-outline-danger" data-confirm="Odpiąć konto? Konto w Azure AD NIE zostanie usunięte.">
        <i class="bi bi-unlink"></i> Odepnij
      </button>
    </form>
    <?php if (is_admin()):
      $_del_login = addslashes($row['m365_login'] ?? ''); ?>
    <button type="button" class="btn btn-sm btn-danger"
      onclick="if(confirm('Trwale usunąć konto M365 <?= h($_del_login) ?> z Azure AD?\n\nTej operacji nie można cofnąć.\nPowiązane konto lokalne zostanie dezaktywowane.')) { document.getElementById('form_delete_m365_<?= $id ?>').submit(); }">
      <i class="bi bi-trash3"></i> Usuń konto M365
    </button>
    <form id="form_delete_m365_<?= $id ?>" method="post" action="<?= APP_URL ?>/contracts/m365_action.php" class="d-none">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="type"    value="wolontariat">
      <input type="hidden" name="id"      value="<?= $id ?>">
      <input type="hidden" name="action"  value="delete_m365">
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php
  $_local_users = db_all("SELECT id, name, email, microsoft_id FROM users WHERE is_active = 1 ORDER BY name");
  ?>
  <?php if (can_edit() && $row['m365_user_id']): ?>
  <div class="border-top pt-3">
    <div class="d-flex align-items-center gap-2 mb-2">
      <span class="small fw-semibold text-muted"><i class="bi bi-link-45deg"></i> Powiązanie z kontem lokalnym</span>
      <?php
      $linked_local = null;
      foreach ($_local_users as $_lu) {
          if ($_lu['microsoft_id'] === $row['m365_user_id']) { $linked_local = $_lu; break; }
      }
      ?>
      <?php if ($linked_local): ?>
      <span class="badge bg-success"><i class="bi bi-check"></i> <?= h($linked_local['name']) ?></span>
      <?php else: ?>
      <span class="badge bg-secondary">Brak powiązania</span>
      <?php endif; ?>
    </div>
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php" class="d-flex gap-2 align-items-center flex-wrap">
      <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
      <input type="hidden" name="type"   value="wolontariat">
      <input type="hidden" name="id"     value="<?= $id ?>">
      <input type="hidden" name="action" value="link_local_user">
      <select name="local_user_id" class="form-select form-select-sm" style="width:auto;max-width:260px" required>
        <option value="">— wybierz konto lokalne —</option>
        <?php foreach ($_local_users as $_lu): ?>
        <option value="<?= intval($_lu['id']) ?>" <?= ($linked_local && $linked_local['id'] === $_lu['id']) ? 'selected' : '' ?>>
          <?= h($_lu['name']) ?> &lt;<?= h($_lu['email']) ?>&gt;
          <?= $_lu['microsoft_id'] ? '✓' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-link-45deg"></i> Powiąż
      </button>
    </form>
  </div>
  <?php endif; ?>

  <?php
  // Sekcja: konto lokalne na podstawie M365 (umowy przed 01.06.2026 z kontem M365)
  if ($row['m365_konto'] && can_edit() && !empty($row['is_technical'])):
      $m365_email    = trim($row['email'] ?? $row['m365_login'] ?? '');
      $m365_uid_val  = trim($row['m365_user_id'] ?? '');
      // Sprawdź czy konto lokalne już istnieje i jest powiązane
      $_local_linked = null;
      if ($m365_uid_val) {
          $_local_linked = db_one("SELECT id, name, email FROM users WHERE microsoft_id=?", [$m365_uid_val]);
      }
      if (!$_local_linked && $m365_email) {
          $_local_linked = db_one("SELECT id, name, email, microsoft_id FROM users WHERE LOWER(email)=LOWER(?)", [$m365_email]);
      }
  ?>
  <div class="border-top pt-3 mt-3">
    <div class="d-flex align-items-center gap-2 mb-2">
      <i class="bi bi-person-badge text-primary"></i>
      <span class="fw-semibold small">Konto lokalne portalu (współpraca przed 01.06.2026)</span>
      <?php if ($_local_linked): ?>
        <?php if ($_local_linked['microsoft_id']): ?>
        <span class="badge bg-success"><i class="bi bi-link-45deg"></i> Powiązane — <?= h($_local_linked['name'] ?: $_local_linked['email']) ?></span>
        <?php else: ?>
        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> Konto istnieje, brak powiązania M365</span>
        <?php endif; ?>
      <?php else: ?>
      <span class="badge bg-secondary">Brak konta lokalnego</span>
      <?php endif; ?>
    </div>

    <?php if (!$_local_linked || !$_local_linked['microsoft_id']): ?>
    <div class="alert alert-info py-2 px-3 mb-2 small">
      <?php if ($_local_linked): ?>
      <i class="bi bi-info-circle me-1"></i>
      Znaleziono konto lokalne <strong><?= h($_local_linked['email']) ?></strong> bez powiązania z M365.
      Kliknij poniżej, aby powiązać — użytkownik będzie logować się przez Microsoft 365.
      <?php else: ?>
      <i class="bi bi-info-circle me-1"></i>
      Ta umowa dotyczy współpracy przed 01.06.2026. Utwórz konto lokalne na podstawie konta M365
      <strong><?= h($row['m365_login']) ?></strong> — użytkownik będzie logować się przez Microsoft 365.
      <?php endif; ?>
    </div>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_create_local_from_m365" value="1">
      <button type="submit" class="btn btn-sm btn-primary">
        <i class="bi bi-person-plus-fill me-1"></i>
        <?= $_local_linked ? 'Powiąż konto z M365' : 'Utwórz konto lokalne z M365' ?>
      </button>
    </form>
    <?php else: ?>
    <div class="text-muted small">
      <i class="bi bi-check-circle-fill text-success me-1"></i>
      Użytkownik loguje się przez Microsoft 365.
      Konto lokalne: <strong><?= h($_local_linked['email']) ?></strong>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php elseif ($m365_enabled && can_edit()): ?>
  <p class="text-muted mb-2">Brak powiązanego konta Microsoft 365.</p>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <form method="post" action="<?= APP_URL ?>/contracts/m365_action.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="type" value="wolontariat">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="create">
      <button class="btn btn-primary"><i class="bi bi-microsoft"></i> Utwórz konto M365</button>
    </form>
    <?php if ($row['email']): ?>
    <small class="text-success"><i class="bi bi-check-circle"></i> Mail zostanie wysłany na: <?= h($row['email']) ?></small>
    <?php else: ?>
    <small class="text-warning"><i class="bi bi-exclamation-triangle"></i> Brak adresu e-mail — mail powitalny nie zostanie wysłany</small>
    <?php endif; ?>
  </div>

  <?php elseif (!$m365_enabled): ?>
  <p class="text-muted small">
    Integracja M365 wyłączona.
    <a href="<?= APP_URL ?>/admin/m365_settings.php">Włącz w ustawieniach →</a>
  </p>
  <?php endif; ?>

  </div>
  </div>

  <!-- ── Sekcja Canva ──────────────────────────────────────────────────────── -->
  <?php if (can_edit()): ?>
  <?php
  $canva_access     = !empty($row['canva_access']);
  $canva_invited_at = $row['canva_invited_at'] ?? null;
  $has_m365_for_canva = !empty($row['m365_login']) || !empty($row['m365_user_id']);
  ?>
  <div class="card shadow-sm mt-3">
  <div class="card-header fw-semibold d-flex align-items-center justify-content-between"
       style="background:#fdf4ff;border-bottom:1px solid #e9d5ff">
    <span style="color:#7c3aed">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="me-1"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2z"/></svg>
      Canva Pro
    </span>
    <?php if ($canva_access && $canva_invited_at): ?>
    <span class="badge bg-success" style="font-size:.72rem">
      <i class="bi bi-check-lg me-1"></i>Zaproszony <?= date_pl($canva_invited_at) ?>
    </span>
    <?php elseif ($canva_access): ?>
    <span class="badge bg-warning text-dark" style="font-size:.72rem">
      <i class="bi bi-hourglass-split me-1"></i>Oczekuje na zaproszenie
    </span>
    <?php else: ?>
    <span class="badge bg-light text-secondary border" style="font-size:.72rem">Brak dostępu</span>
    <?php endif; ?>
  </div>
  <div class="card-body py-3">

    <?php if (!$has_m365_for_canva): ?>
    <div class="alert alert-warning py-2 small mb-0 d-flex gap-2">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
      <span>Wolontariusz nie ma konta M365. Konto M365 jest <strong>wymagane</strong> do logowania w Canva przez Microsoft 365.</span>
    </div>

    <?php elseif ($canva_access && $canva_invited_at): ?>
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <div class="small text-success">
        <i class="bi bi-check-circle-fill me-1"></i>
        Dostęp aktywny. Wolontariusz loguje się do Canva kontem M365: <strong><?= h($row['m365_login'] ?? '') ?></strong>
      </div>
      <form method="post" class="ms-auto">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_canva_toggle" value="1">
        <input type="hidden" name="canva_access_val" value="0">
        <button type="submit" class="btn btn-sm btn-outline-danger"
                onclick="return confirm('Cofnąć dostęp do Canva?')">
          <i class="bi bi-x-circle me-1"></i>Cofnij dostęp
        </button>
      </form>
    </div>

    <?php elseif ($canva_access): ?>
    <div class="small text-muted mb-3">
      <i class="bi bi-info-circle me-1"></i>
      Dostęp zaznaczony. Zaproś ręcznie w panelu Canva, następnie oznacz poniżej.
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="https://www.canva.com/brand/invite" target="_blank"
         class="btn btn-sm btn-primary" style="background:#7c3aed;border-color:#7c3aed">
        <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz panel zaproszeń Canva
      </a>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_canva_invited" value="1">
        <button type="submit" class="btn btn-sm btn-success">
          <i class="bi bi-check-lg me-1"></i>Zaproszenie wysłane — oznacz i powiadom wolontariusza
        </button>
      </form>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_canva_toggle" value="1">
        <input type="hidden" name="canva_access_val" value="0">
        <button type="submit" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-x me-1"></i>Anuluj
        </button>
      </form>
    </div>

    <?php else: ?>
    <div class="small text-muted mb-2">
      Brak dostępu do Canva. Nadaj dostęp — system powiadomi admina i wyśle instrukcję wolontariuszowi.
    </div>
    <form method="post" class="d-inline">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_canva_toggle" value="1">
      <input type="hidden" name="canva_access_val" value="1">
      <button type="submit" class="btn btn-sm" style="background:#7c3aed;border-color:#7c3aed;color:#fff">
        <i class="bi bi-plus-circle me-1"></i>Nadaj dostęp do Canva
      </button>
    </form>
    <?php endif; ?>

  </div>
  </div>
  <?php endif; ?>

</div><!-- /tab-m365 -->

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 6 — HISTORIA ZDARZEŃ
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-historia" role="tabpanel">

  <?php if ($audit_log): ?>
  <ul class="list-group list-group-flush rounded">
  <?php foreach ($audit_log as $log): ?>
  <li class="list-group-item d-flex justify-content-between align-items-start py-2">
    <div>
      <?= action_badge($log['action']) ?>
      <span class="ms-2 small"><?= h($log['user_snapshot'] ?? 'System') ?></span>
      <?php if ($log['note']): ?>
      <br><small class="text-muted ms-1"><?= h($log['note']) ?></small>
      <?php endif; ?>
    </div>
    <small class="text-muted text-nowrap"><?= date_pl($log['created_at']) ?></small>
  </li>
  <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <p class="text-muted small mb-0">Brak wpisów w historii.</p>
  <?php endif; ?>

</div><!-- /tab-historia -->

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB — ZADANIA
     ════════════════════════════════════════════════════════════════════════════ -->
<?php if ($_tasks_enabled && can_edit()): ?>
<div class="tab-pane fade" id="tab-tasks" role="tabpanel">
  <a id="tab-tasks-anchor"></a>

  <?php if ($_wa_send_result): ?>
  <div class="alert alert-<?= $_wa_send_result['ok'] ? 'success' : 'danger' ?> py-2 small d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-<?= $_wa_send_result['ok'] ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
    <?= h($_wa_send_result['msg']) ?>
  </div>
  <?php endif; ?>

  <div class="row g-3">
  <!-- Formularz nowego zadania -->
  <div class="col-lg-5">
    <div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-plus-circle text-primary me-1"></i> Dodaj na tablicę</div>
    <div class="card-body">
      <?php if (!$_contract_workspaces): ?>
      <div class="alert alert-warning small py-2 mb-0">
        <i class="bi bi-exclamation-triangle"></i>
        Brak aktywnych obszarów (workspace). Utwórz obszar w
        <a href="<?= APP_URL ?>/admin/tasks_workspaces.php">Zarządzaniu zadaniami</a>.
      </div>
      <?php else: ?>
      <form method="post" id="addTaskForm">
        <input type="hidden" name="_csrf"     value="<?= csrf_token() ?>">
        <input type="hidden" name="_add_task" value="1">
        <div class="mb-2">
          <label class="form-label fw-semibold small">Tytuł zadania <span class="text-danger">*</span></label>
          <input type="text" name="task_title" class="form-control form-control-sm" required
                 value="<?= h($row['imie_nazwisko']) ?>" placeholder="np. Onboarding wolontariusza">
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold small">Obszar (workspace) <span class="text-danger">*</span></label>
          <select name="task_ws_id" id="wsSelect" class="form-select form-select-sm" required
                  onchange="loadLists(this.value)">
            <option value="">— wybierz —</option>
            <?php foreach ($_contract_workspaces as $ws): ?>
            <option value="<?= (int)$ws['id'] ?>"><?= h($ws['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold small">Kolumna (lista) <span class="text-danger">*</span></label>
          <select name="task_list_id" id="listSelect" class="form-select form-select-sm" required>
            <option value="">— najpierw wybierz obszar —</option>
          </select>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-7">
            <label class="form-label fw-semibold small">Priorytet</label>
            <select name="task_priority" class="form-select form-select-sm">
              <?php foreach ([1=>'Niski',2=>'Normalny',3=>'Wysoki',4=>'Krytyczny'] as $pv => $pl): ?>
              <option value="<?= $pv ?>" <?= $pv === 2 ? 'selected' : '' ?>><?= $pl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-5">
            <label class="form-label fw-semibold small">Termin</label>
            <input type="date" name="task_due" class="form-control form-control-sm">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small">Opis <span class="text-muted fw-normal">(opcjonalny)</span></label>
          <textarea name="task_desc" class="form-control form-control-sm" rows="2"
                    placeholder="Wolontariusz: <?= h($row['imie_nazwisko']) ?> · Umowa: <?= h($row['numer_umowy']) ?>"></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-sm w-100">
          <i class="bi bi-plus-circle"></i> Dodaj na tablicę
        </button>
      </form>
      <?php endif; ?>
    </div>
    </div>

    <?php if (wa_enabled()): ?>
    <!-- WhatsApp -->
    <div class="card shadow-sm mt-3">
    <div class="card-header fw-semibold"><i class="bi bi-whatsapp text-success me-1"></i> Wyślij na WhatsApp</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
        <input type="hidden" name="_wa_send" value="1">
        <div class="mb-2">
          <label class="form-label fw-semibold small">Numer telefonu</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text">+48</span>
            <input type="tel" name="wa_phone" class="form-control font-monospace"
                   value="<?= h(preg_replace('/\D/', '', $row['telefon'] ?? '')) ?>"
                   placeholder="123456789" required>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold small">Wiadomość <span class="text-danger">*</span></label>
          <textarea name="wa_message" class="form-control form-control-sm" rows="3" required
                    placeholder="Wpisz wiadomość WhatsApp..."></textarea>
        </div>
        <button type="submit" class="btn btn-success btn-sm w-100">
          <i class="bi bi-whatsapp"></i> Wyślij WhatsApp
        </button>
      </form>
    </div>
    </div>
    <?php endif; ?>
  </div><!-- /col-lg-5 -->

  <!-- Lista zadań powiązanych z umową -->
  <div class="col-lg-7">
    <div class="card shadow-sm">
    <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
      <span><i class="bi bi-list-task me-1"></i> Powiązane zadania</span>
      <a href="<?= APP_URL ?>/tasks/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-kanban"></i> Otwórz tablicę
      </a>
    </div>
    <div class="card-body p-0">
      <?php if (!$_contract_tasks): ?>
      <div class="text-center text-muted py-4 small">
        <i class="bi bi-kanban display-6 opacity-25"></i><br>
        Brak zadań powiązanych z tą umową.
      </div>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($_contract_tasks as $ct):
          $p = TASK_PRIORITIES[$ct['priority']] ?? TASK_PRIORITIES[2];
        ?>
        <a href="<?= APP_URL ?>/tasks/detail.php?id=<?= (int)$ct['id'] ?>"
           class="list-group-item list-group-item-action py-2 px-3 <?= $ct['completed_at'] ? 'text-muted' : '' ?>">
          <div class="d-flex align-items-center gap-2">
            <?php if ($ct['completed_at']): ?>
            <i class="bi bi-check-circle-fill text-success flex-shrink-0"></i>
            <?php else: ?>
            <i class="bi bi-circle text-muted flex-shrink-0"></i>
            <?php endif; ?>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold small text-truncate <?= $ct['completed_at'] ? 'text-decoration-line-through' : '' ?>">
                <?= h($ct['title']) ?>
              </div>
              <div class="d-flex align-items-center gap-2 mt-1">
                <span class="badge bg-<?= $p['class'] ?> small" style="font-size:.65rem">
                  <i class="bi <?= $p['icon'] ?> me-1"></i><?= $p['label'] ?>
                </span>
                <span class="text-muted" style="font-size:.75rem">
                  <?= h($ct['workspace_name']) ?> › <?= h($ct['list_name']) ?>
                </span>
                <?php if ($ct['due_date']): ?>
                <span class="text-muted" style="font-size:.75rem">
                  <i class="bi bi-calendar2"></i> <?= date_pl($ct['due_date']) ?>
                </span>
                <?php endif; ?>
              </div>
            </div>
            <i class="bi bi-arrow-right text-muted flex-shrink-0"></i>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    </div>
  </div><!-- /col-lg-7 -->
  </div><!-- /row -->

</div><!-- /tab-tasks -->
<?php endif; ?>

<!-- ══ TAB: Zwroty kosztów ═══════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-zwroty" role="tabpanel">

  <!-- Nagłówek + przycisk -->
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h6 class="fw-bold mb-0"><i class="bi bi-receipt-cutoff text-success me-1"></i> Zwroty kosztów wolontariatu</h6>
      <?php if ($_zwroty_el['eligible'] && $_zwroty_el['limit'] !== null): ?>
      <div class="small text-muted mt-1">
        Limit: <strong><?= number_format($_zwroty_el['limit'],2,',',' ') ?> PLN</strong> ·
        Zatwierdzone: <strong class="text-danger"><?= number_format($_zwroty_el['zuzyty'],2,',',' ') ?> PLN</strong> ·
        Dostępne: <strong class="text-success"><?= number_format($_zwroty_el['dostepny'],2,',',' ') ?> PLN</strong>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($_zwroty_el['eligible']): ?>
    <a href="<?= APP_URL ?>/contracts/zwroty/add.php?umowa_id=<?= $id ?>&umowa_type=wolontariat"
       class="btn btn-sm btn-success">
      <i class="bi bi-plus-lg me-1"></i>Złóż wniosek
    </a>
    <?php else: ?>
    <span class="badge bg-secondary">Zwroty niedostępne</span>
    <?php endif; ?>
  </div>

  <!-- Ostrzeżenie gdy brak uprawnień -->
  <?php if (!$_zwroty_el['eligible']): ?>
  <div class="alert alert-warning py-2" style="font-size:.84rem">
    <i class="bi bi-slash-circle me-1"></i><?= h($_zwroty_el['reason']) ?>
  </div>
  <?php endif; ?>

  <!-- Lista wniosków -->
  <?php if ($_zwroty): ?>
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
    <thead class="table-light">
      <tr>
        <th>Numer wniosku</th>
        <th>Tytuł</th>
        <th class="text-end">Kwota</th>
        <th class="text-center">Data wydatku</th>
        <th class="text-center">Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($_zwroty as $zr): ?>
    <tr>
      <td><?= zwroty_nr_html($zr['nr_wniosku'] ?? '—') ?></td>
      <td>
        <div><?= h($zr['tytul']) ?></div>
        <?php if ($zr['kategoria']): ?>
        <div class="small text-muted"><?= h(zwroty_kategoria_label($zr['kategoria'])) ?></div>
        <?php endif; ?>
      </td>
      <td class="text-end fw-semibold text-nowrap">
        <?= number_format((float)$zr['kwota'],2,',',' ') ?> PLN
      </td>
      <td class="text-center small text-muted text-nowrap">
        <?= $zr['data_wydatku'] ? date('d.m.Y', strtotime($zr['data_wydatku'])) : '—' ?>
      </td>
      <td class="text-center"><?= zwroty_status_badge($zr['status']) ?></td>
      <td class="text-end">
        <a href="<?= APP_URL ?>/contracts/zwroty/view.php?id=<?= $zr['id'] ?>"
           class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-eye"></i>
        </a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot class="table-light">
      <tr>
        <td colspan="2" class="fw-semibold small">Razem (wszystkie wnioski)</td>
        <td class="text-end fw-bold"><?= number_format(array_sum(array_column($_zwroty,'kwota')),2,',',' ') ?> PLN</td>
        <td colspan="3"></td>
      </tr>
    </tfoot>
  </table>
  </div>
  <?php else: ?>
  <div class="text-center py-4 text-muted">
    <i class="bi bi-receipt fs-2 mb-2 d-block"></i>
    Brak wniosków o zwrot kosztów dla tej umowy.
    <?php if ($_zwroty_el['eligible']): ?>
    <div class="mt-2">
      <a href="<?= APP_URL ?>/contracts/zwroty/add.php?umowa_id=<?= $id ?>&umowa_type=wolontariat"
         class="btn btn-sm btn-outline-success">
        <i class="bi bi-plus-lg me-1"></i>Złóż pierwszy wniosek
      </a>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /tab-zwroty -->

<div class="tab-pane fade" id="tab-messages" role="tabpanel">
  <a id="tab-messages-anchor"></a>
  <?php
    $u = current_user();
    $msg_ctx_type      = 'contract';
    $msg_ctx_id        = $id;
    $msg_contract_type = $TYPE;
    $msg_viewer        = can_edit() ? 'admin' : 'user';
    $msg_viewer_name   = $u['name'];
    $msg_viewer_id     = (int)$u['id'];
    $msg_post_url      = APP_URL . "/contracts/{$TYPE}/view.php?id={$id}";
    include dirname(dirname(__DIR__)) . '/includes/messages_widget.php';
  ?>
</div><!-- /tab-messages -->

<?php if (apaczka_setting('apaczka_enabled') !== '0' && can_edit()): ?>
<!-- ════════════════════════════════════════════════════════════════════════════
     TAB PRZESYŁKI
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-shipments" role="tabpanel">
  <div class="p-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h6 class="mb-0 fw-bold"><i class="bi bi-box-seam me-2 text-primary"></i>Przesyłki dla tej umowy</h6>
      <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/admin/shipments.php?new=1&contract_id=<?= $id ?>&direction=out"
           class="btn btn-sm btn-outline-primary">
          <i class="bi bi-arrow-right me-1"></i>Wyślij do wolontariusza
        </a>
        <a href="<?= APP_URL ?>/admin/shipments.php?new=1&contract_id=<?= $id ?>&direction=return"
           class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-arrow-return-left me-1"></i>Etykieta zwrotna
        </a>
      </div>
    </div>

    <?php if ($_ship_rows): ?>
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr><th>Kierunek</th><th>Cel</th><th>Nr WB</th><th>Status</th><th>Data</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($_ship_rows as $_sr):
        $wurl = $_sr['waybill_path'] ? APP_URL . '/uploads/' . $_sr['waybill_path'] : '';
      ?>
      <tr>
        <td>
          <span style="font-size:.72rem;font-weight:700;padding:.1rem .4rem;border-radius:.25rem;
            background:<?= $_sr['direction']==='out'?'#dbeafe':'#fce7f3' ?>;
            color:<?= $_sr['direction']==='out'?'#1d4ed8':'#9d174d' ?>">
            <?= $_sr['direction']==='out' ? '→ Wol.' : '← FEER' ?>
          </span>
        </td>
        <td class="small"><?= h(SHIPMENT_PURPOSE[$_sr['purpose']] ?? $_sr['purpose']) ?></td>
        <td class="font-monospace small"><?= $_sr['waybill_number'] ? h($_sr['waybill_number']) : '—' ?></td>
        <td><?= shipment_badge($_sr['status']) ?></td>
        <td class="small text-muted"><?= date_pl($_sr['created_at']) ?></td>
        <td>
          <a href="<?= APP_URL ?>/admin/shipments.php?id=<?= $_sr['id'] ?>" class="btn btn-xs btn-outline-secondary"
             style="font-size:.72rem;padding:.2rem .5rem">
            <i class="bi bi-eye"></i>
          </a>
          <?php if ($wurl): ?>
          <a href="<?= h($wurl) ?>" target="_blank" class="btn btn-xs btn-outline-dark ms-1"
             style="font-size:.72rem;padding:.2rem .5rem" title="Etykieta PDF">
            <i class="bi bi-printer"></i>
          </a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="text-center py-4 text-muted">
      <i class="bi bi-box-seam" style="font-size:2rem;opacity:.3"></i>
      <div class="mt-2 small">Brak przesyłek dla tej umowy.</div>
    </div>
    <?php endif; ?>
  </div>
</div><!-- /tab-shipments -->
<?php endif; /* apaczka_enabled */ ?>

<?php if (module_enabled('timesheets_enabled')): ?>
<!-- ════════════════════════════════════════════════════════════════════════════
     TAB GODZINY — Ewidencja godzin wolontariatu
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-godziny" role="tabpanel">
  <div class="p-3">

    <?php
    $_ts_approved = ts_total_approved($id);
    $_ts_total    = ts_total_all($id);
    $ts_full      = $_ts_rows; // załadowane wcześniej przy budowaniu zakładki
    ?>

    <!-- Statsy godzin -->
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="border rounded p-3 text-center">
          <div class="fs-3 fw-bold text-success"><?= number_format($_ts_approved, 1, ',', ' ') ?></div>
          <div class="text-muted small">godz. zatwierdzonych</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="border rounded p-3 text-center">
          <div class="fs-3 fw-bold"><?= number_format($_ts_total, 1, ',', ' ') ?></div>
          <div class="text-muted small">godz. łącznie (wszystkie)</div>
        </div>
      </div>
      <?php if ($row['godzin_tygodniowo']): ?>
      <div class="col-6 col-md-3">
        <div class="border rounded p-3 text-center">
          <div class="fs-3 fw-bold text-primary"><?= h($row['godzin_tygodniowo']) ?></div>
          <div class="text-muted small">godz. / tydzień (plan)</div>
        </div>
      </div>
      <?php endif; ?>
      <div class="col-6 col-md-3">
        <div class="border rounded p-3 text-center">
          <div class="fs-3 fw-bold text-secondary"><?= count($ts_full) ?></div>
          <div class="text-muted small">wpisów miesięcznych</div>
        </div>
      </div>
    </div>

    <?php if ($ts_full): ?>
    <!-- Tabela wpisów -->
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>Miesiąc</th>
          <th class="text-end">Godziny</th>
          <th>Status</th>
          <th>Opis działań</th>
          <?php if (can_edit()): ?>
          <th>Akcja</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($ts_full as $_tsrow):
        $ts_class = $_tsrow['status'] === 'złożone' ? 'table-warning' :
                   ($_tsrow['status'] === 'zatwierdzone' ? 'table-success bg-opacity-25' :
                   ($_tsrow['status'] === 'odrzucone' ? 'table-danger bg-opacity-25' : ''));
      ?>
      <tr class="<?= $ts_class ?>">
        <td class="fw-semibold small"><?= ts_month_label((int)$_tsrow['rok'], (int)$_tsrow['miesiac']) ?></td>
        <td class="text-end fw-bold"><?= number_format((float)$_tsrow['godziny'], 1, ',', ' ') ?> h</td>
        <td><?= ts_badge($_tsrow['status']) ?></td>
        <td class="text-muted small">
          <?= $_tsrow['opis'] ? h(mb_strimwidth($_tsrow['opis'], 0, 80, '…')) : '—' ?>
          <?php if ($_tsrow['status'] === 'odrzucone' && $_tsrow['uwagi_admin']): ?>
          <div class="text-danger"><i class="bi bi-x-circle"></i> <?= h($_tsrow['uwagi_admin']) ?></div>
          <?php endif; ?>
        </td>
        <?php if (can_edit()): ?>
        <td>
          <?php if ($_tsrow['status'] === 'złożone'): ?>
          <div class="d-flex gap-1">
            <form method="post" action="<?= APP_URL ?>/admin/timesheets.php" class="d-inline">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="approve">
              <input type="hidden" name="ts_id"   value="<?= $_tsrow['id'] ?>">
              <button type="submit" class="btn btn-xs btn-success" style="font-size:.72rem;padding:.2rem .55rem">
                <i class="bi bi-check-lg"></i> Zatwierdź
              </button>
            </form>
            <button type="button" class="btn btn-xs btn-outline-danger"
                    style="font-size:.72rem;padding:.2rem .5rem"
                    onclick="document.getElementById('ts-reject-<?= $_tsrow['id'] ?>').classList.toggle('d-none')">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
          <div id="ts-reject-<?= $_tsrow['id'] ?>" class="d-none mt-1">
            <form method="post" action="<?= APP_URL ?>/admin/timesheets.php">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="reject">
              <input type="hidden" name="ts_id"   value="<?= $_tsrow['id'] ?>">
              <div class="input-group input-group-sm">
                <input name="uwagi_admin" class="form-control" placeholder="Powód (opcjonalnie)">
                <button type="submit" class="btn btn-danger">Odrzuć</button>
              </div>
            </form>
          </div>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <?php
      $ts_zatw = array_filter($ts_full, fn($r) => $r['status'] === 'zatwierdzone');
      if (count($ts_zatw) > 1): ?>
      <tfoot class="table-secondary fw-bold">
        <tr>
          <td>Razem zatwierdzonych</td>
          <td class="text-end text-success"><?= number_format($_ts_approved, 1, ',', ' ') ?> h</td>
          <td colspan="<?= can_edit() ? 3 : 2 ?>"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
    <?php else: ?>
    <div class="text-center py-4 text-muted">
      <i class="bi bi-clock" style="font-size:2rem;opacity:.3"></i>
      <div class="mt-2">Brak wpisów ewidencji godzin dla tej umowy.</div>
      <div class="small mt-1">Wolontariusz może dodawać wpisy ze swojego panelu.</div>
    </div>
    <?php endif; ?>

  </div>
</div><!-- /tab-godziny -->
<?php endif; /* timesheets_enabled */ ?>

</div><!-- /tab-content -->

<!-- ── Modal usunięcia ──────────────────────────────────────────────────────── -->
<?php if (is_admin()): ?>
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-trash3"></i> Usuń umowę</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="<?= APP_URL ?>/contracts/approvals/delete.php">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="type" value="<?= $TYPE ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="modal-body">
          <p class="text-danger fw-bold">Tej operacji nie można cofnąć.</p>
          <label class="form-label">Powód usunięcia <span class="text-danger">*</span></label>
          <textarea name="reason" class="form-control" rows="3" required placeholder="Wpisz powód usunięcia..."></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-trash3"></i> Usuń</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// ── Zapamiętaj aktywną zakładkę ─────────────────────────────────────────────
(function () {
  var STORAGE_KEY = 'wolontariat_tab_<?= $id ?>';
  var tabs = document.getElementById('wolontariatTabs');
  if (!tabs) return;

  // Przywróć z localStorage lub otwórz pierwszą
  var saved = localStorage.getItem(STORAGE_KEY) || 'tab-umowa';
  var target = document.querySelector('[data-bs-target="#' + saved + '"]');
  if (target) {
    new bootstrap.Tab(target).show();
  } else {
    new bootstrap.Tab(tabs.querySelector('[data-bs-toggle="tab"]')).show();
  }

  // Obsługa URL hash (#tab-obieg itp.)
  if (location.hash && location.hash.startsWith('#tab-')) {
    var hashTarget = document.querySelector('[data-bs-target="' + location.hash + '"]');
    if (hashTarget) {
      setTimeout(function () { new bootstrap.Tab(hashTarget).show(); }, 50);
    }
  }

  // Otwórz zakładkę wiadomości jeśli URL zawiera anchor
  if (window.location.hash === '#tab-messages-anchor' || window.location.search.includes('msg=1')) {
    var msgBtn = document.getElementById('tab-messages-btn');
    if (msgBtn) { new bootstrap.Tab(msgBtn).show(); localStorage.setItem(STORAGE_KEY,'tab-messages'); }
  }

  // Otwórz zakładkę zadań jeśli URL zawiera anchor
  if (window.location.hash === '#tab-tasks-anchor') {
    var tasksBtn = document.getElementById('tab-tasks-btn');
    if (tasksBtn) { setTimeout(function(){ new bootstrap.Tab(tasksBtn).show(); localStorage.setItem(STORAGE_KEY,'tab-tasks'); }, 50); }
  }

  // Zapisz przy zmianie
  tabs.addEventListener('shown.bs.tab', function (e) {
    var id = e.target.dataset.bsTarget.replace('#', '');
    localStorage.setItem(STORAGE_KEY, id);
  });
})();

// ── Dynamiczne listy zadań po wyborze workspace ────────────────────────────
function loadLists(wsId) {
    var sel = document.getElementById('listSelect');
    if (!wsId) { sel.innerHTML = '<option value="">— najpierw wybierz obszar —</option>'; return; }
    sel.innerHTML = '<option value="">Ładowanie…</option>';
    fetch('<?= APP_URL ?>/tasks/api/list.php?action=lists&ws=' + wsId + '&_csrf=<?= urlencode(csrf_token()) ?>')
        .then(function(r){ return r.json(); })
        .then(function(data) {
            sel.innerHTML = '<option value="">— wybierz kolumnę —</option>';
            if (data.ok && data.data) {
                data.data.forEach(function(l) {
                    var o = document.createElement('option');
                    o.value = l.id; o.textContent = l.name;
                    sel.appendChild(o);
                });
            }
        })
        .catch(function(){ sel.innerHTML = '<option value="">Błąd ładowania</option>'; });
}
</script>

<?php
/* ── Modal onboardingowy — uruchamia się po dodaniu umowy (?onboard=1) ──── */
$_onboard = !empty($_GET['onboard']);

// Sprawdź czy moduł zadań jest aktywny
$_tasks_onboard = false;
try {
    $_tm2 = db_one("SELECT value FROM settings WHERE key_='tasks_enabled'");
    $_tasks_onboard = ($_tm2['value'] ?? '1') !== '0';
} catch (\Throwable $e) {}

// Pobierz jednostki organizacyjne do kroku 1
$_org_units = [];
try {
    $_org_units = db_all("SELECT id, name FROM org_units WHERE status='active' ORDER BY sort_order, name");
} catch (\Throwable $e) {}

// Pobierz dostępne wolne zadania do kroku 2 (jeśli moduł aktywny)
$_open_tasks = [];
if ($_tasks_onboard) {
    try {
        $_open_tasks = db_all(
            "SELECT t.id, t.title, t.priority, t.due_date,
                    tl.name AS list_name, tw.name AS ws_name, tw.color AS ws_color
             FROM tasks t
             JOIN task_lists tl ON tl.id = t.list_id
             JOIN task_workspaces tw ON tw.id = t.workspace_id
             WHERE t.deleted_at IS NULL
               AND t.completed_at IS NULL
               AND (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id=t.id) = 0
             ORDER BY t.priority DESC, t.due_date ASC NULLS LAST
             LIMIT 30"
        );
    } catch (\Throwable $e) {}
}

// Znajdź user_id wolontariusza (jeśli ma konto)
$_vol_user_id = 0;
if (!empty($row['email'])) {
    $vu = db_one("SELECT id FROM users WHERE email=?", [$row['email']]);
    $_vol_user_id = (int)($vu['id'] ?? 0);
}

if ($_onboard): ?>

<!-- ══ MODAL: Onboarding wolontariusza ══════════════════════════════════ -->
<div class="modal fade" id="onboardModal"
     data-bs-backdrop="static" data-bs-keyboard="false"
     tabindex="-1" aria-labelledby="onboardModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow-lg">

      <!-- Nagłówek -->
      <div class="modal-header bg-primary text-white py-3">
        <div>
          <h2 class="h5 modal-title fw-bold mb-0" id="onboardModalLabel">
            <i class="bi bi-person-plus me-2" aria-hidden="true"></i>Konfiguracja wolontariusza
          </h2>
          <p class="mb-0 opacity-75 small">
            <?= h($row['imie_nazwisko'] ?? 'Nowy wolontariusz') ?>
          </p>
        </div>
        <button type="button" class="btn-close btn-close-white"
                data-bs-dismiss="modal"
                aria-label="Pomiń konfigurację i zamknij"></button>
      </div>

      <!-- Wskaźnik kroków -->
      <div class="px-4 pt-3 pb-0">
        <div class="d-flex align-items-center gap-2" role="list" aria-label="Kroki konfiguracji">
          <div class="d-flex align-items-center gap-2" role="listitem">
            <span class="d-flex align-items-center justify-content-center rounded-circle fw-bold"
                  id="step-ind-1"
                  style="width:28px;height:28px;background:#2563eb;color:#fff;font-size:.82rem"
                  aria-current="step">1</span>
            <span class="small fw-semibold" id="step-lbl-1">Miejsce w strukturze</span>
          </div>
          <?php if ($_tasks_onboard && $_open_tasks): ?>
          <div class="flex-grow-1" style="height:2px;background:#e2e8f0" aria-hidden="true"></div>
          <div class="d-flex align-items-center gap-2" role="listitem">
            <span class="d-flex align-items-center justify-content-center rounded-circle fw-bold"
                  id="step-ind-2"
                  style="width:28px;height:28px;background:#e2e8f0;color:#64748b;font-size:.82rem">2</span>
            <span class="small text-muted" id="step-lbl-2">Przypisanie do zadań</span>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="modal-body px-4 py-3">

        <!-- ── Krok 1: Miejsce w strukturze ─────────────────────────── -->
        <div id="onboard-step-1">
          <h3 class="h6 fw-bold mb-1">
            <i class="bi bi-diagram-3 me-1 text-primary" aria-hidden="true"></i>Miejsce w strukturze organizacyjnej
          </h3>
          <p class="text-muted small mb-3">
            Przypisz wolontariusza do właściwej jednostki organizacyjnej.
            Możesz to pominąć, jeśli nie jest teraz potrzebne.
          </p>

          <?php if ($_org_units): ?>
          <div class="mb-3">
            <label class="form-label fw-semibold small" for="ob-org-unit">Jednostka organizacyjna</label>
            <select id="ob-org-unit" class="form-select">
              <option value="">— Nie przypisuj teraz —</option>
              <?php foreach ($_org_units as $ou):
                $sel = ((int)($row['org_unit_id']??0) === (int)$ou['id']) ? 'selected' : '';
              ?>
              <option value="<?= $ou['id'] ?>" <?= $sel ?>><?= h($ou['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php else: ?>
          <div class="alert alert-info small py-2">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            Brak zdefiniowanych jednostek organizacyjnych.
            <?php if ($is_admin ?? false): ?>
            <a href="<?= APP_URL ?>/org/" target="_blank">Zarządzaj strukturą</a>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- ── Krok 2: Przypisanie do zadań ─────────────────────────── -->
        <?php if ($_tasks_onboard && $_open_tasks): ?>
        <div id="onboard-step-2" style="display:none">
          <h3 class="h6 fw-bold mb-1">
            <i class="bi bi-grid-3x2-gap me-1 text-primary" aria-hidden="true"></i>Przypisanie do wolnych zadań
          </h3>
          <p class="text-muted small mb-3">
            Wybierz zadania, do których chcesz od razu przypisać
            <strong><?= h($row['imie_nazwisko'] ?? 'tego wolontariusza') ?></strong>.
          </p>

          <?php if (!$_vol_user_id): ?>
          <div class="alert alert-warning small py-2 mb-3">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            Wolontariusz nie ma jeszcze konta w systemie — przypisanie do zadań będzie możliwe po jego utworzeniu.
          </div>
          <?php else: ?>
          <div class="d-flex flex-column gap-2" id="ob-task-list" role="group" aria-label="Lista wolnych zadań">
            <?php
            $pri_colors = [4=>'#dc2626',3=>'#f59e0b',2=>'#3b82f6',1=>'#94a3b8'];
            foreach ($_open_tasks as $ot):
              $pc = $pri_colors[$ot['priority']] ?? '#94a3b8';
            ?>
            <label class="d-flex align-items-start gap-3 p-3 rounded border"
                   style="cursor:pointer;background:#fafafa;transition:background .1s"
                   onmouseover="this.style.background='#eff6ff'"
                   onmouseout="this.style.background='#fafafa'">
              <input type="checkbox"
                     class="form-check-input flex-shrink-0 mt-1 ob-task-check"
                     value="<?= $ot['id'] ?>"
                     aria-label="Przypisz do zadania: <?= h($ot['title']) ?>">
              <div class="flex-grow-1 min-width-0">
                <div class="d-flex align-items-center gap-2 mb-1">
                  <span class="rounded-pill px-2 py-0" style="background:<?= $pc ?>;color:#fff;font-size:.65rem;font-weight:700">
                    <?= ['','Niski','Normalny','Wysoki','Krytyczny'][$ot['priority']] ?? '' ?>
                  </span>
                  <span class="text-muted small"><?= h($ot['ws_name']) ?> › <?= h($ot['list_name']) ?></span>
                </div>
                <div class="fw-semibold small"><?= h($ot['title']) ?></div>
                <?php if ($ot['due_date']): ?>
                <div class="text-muted" style="font-size:.72rem">
                  <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Termin: <?= h($ot['due_date']) ?>
                </div>
                <?php endif; ?>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Komunikaty -->
        <div id="ob-error"   class="alert alert-danger  small py-2 mt-2 d-none" role="alert"></div>
        <div id="ob-success" class="alert alert-success small py-2 mt-2 d-none" role="status"></div>

      </div><!-- /modal-body -->

      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-outline-secondary btn-sm"
                data-bs-dismiss="modal">
          <i class="bi bi-x me-1" aria-hidden="true"></i>Pomiń
        </button>
        <div class="d-flex gap-2" id="ob-btn-group">
          <?php if ($_tasks_onboard && $_open_tasks && $_vol_user_id): ?>
          <button type="button" class="btn btn-outline-secondary btn-sm"
                  id="ob-btn-skip" onclick="obNextStep()" style="display:none">
            Pomiń krok 2
          </button>
          <?php endif; ?>
          <button type="button"
                  class="btn btn-primary btn-sm"
                  id="ob-btn-next"
                  onclick="obNext()">
            <?= ($_tasks_onboard && $_open_tasks && $_vol_user_id) ? 'Dalej <i class="bi bi-arrow-right ms-1"></i>' : '<i class="bi bi-check2 me-1"></i>Zapisz i zakończ' ?>
          </button>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function(){
'use strict';
const CSRF       = <?= json_encode(csrf_token()) ?>;
const BASE       = <?= json_encode(rtrim(APP_URL,'/')) ?>;
const CONTRACT_ID= <?= (int)$id ?>;
const VOL_USER   = <?= (int)$_vol_user_id ?>;
const HAS_STEP2  = <?= ($_tasks_onboard && $_open_tasks && $_vol_user_id) ? 'true' : 'false' ?>;
let   currentStep = 1;

// Otwórz modal automatycznie
document.addEventListener('DOMContentLoaded', function() {
    const m = document.getElementById('onboardModal');
    if (m) bootstrap.Modal.getOrCreateInstance(m).show();
});

window.obNext = async function() {
    const btn = document.getElementById('ob-btn-next');
    btn.disabled = true;

    if (currentStep === 1) {
        // Zapisz jednostkę organizacyjną (jeśli wybrana)
        const orgUnit = document.getElementById('ob-org-unit')?.value;
        if (orgUnit) {
            try {
                const r = await fetch(BASE + '/contracts/wolontariat/api_onboard.php', {
                    method:  'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({_csrf:CSRF, contract_id:CONTRACT_ID, org_unit_id:parseInt(orgUnit)})
                }).then(r=>r.json());
                if (!r.ok) { obError(r.error || 'Błąd zapisu.'); btn.disabled=false; return; }
            } catch(e) { obError('Błąd połączenia.'); btn.disabled=false; return; }
        }

        if (HAS_STEP2) {
            obGoStep2();
        } else {
            obFinish();
        }

    } else if (currentStep === 2) {
        // Przypisz do zaznaczonych zadań
        const checked = Array.from(document.querySelectorAll('.ob-task-check:checked')).map(el=>parseInt(el.value));
        if (checked.length) {
            try {
                for (const tid of checked) {
                    await fetch(BASE + '/tasks/api/assign.php', {
                        method:  'POST',
                        headers: {'Content-Type':'application/json'},
                        body: JSON.stringify({_csrf:CSRF, task_id:tid, user_id:VOL_USER, action:'add'})
                    }).then(r=>r.json());
                }
            } catch(e) { obError('Błąd przypisania.'); btn.disabled=false; return; }
        }
        obFinish();
    }

    btn.disabled = false;
};

window.obNextStep = function() { obGoStep2(); };

function obGoStep2() {
    currentStep = 2;
    document.getElementById('onboard-step-1').style.display = 'none';
    document.getElementById('onboard-step-2').style.display = '';

    // Aktualizuj wskaźnik kroków
    const i1 = document.getElementById('step-ind-1');
    const i2 = document.getElementById('step-ind-2');
    const l2 = document.getElementById('step-lbl-2');
    if (i1) { i1.style.background='#16a34a'; i1.innerHTML='<i class="bi bi-check2"></i>'; i1.removeAttribute('aria-current'); }
    if (i2) { i2.style.background='#2563eb'; i2.style.color='#fff'; i2.setAttribute('aria-current','step'); }
    if (l2) { l2.className='small fw-semibold'; }

    const btnNext = document.getElementById('ob-btn-next');
    if (btnNext) btnNext.innerHTML = '<i class="bi bi-check2 me-1" aria-hidden="true"></i>Przypisz i zakończ';
    const btnSkip = document.getElementById('ob-btn-skip');
    if (btnSkip) btnSkip.style.display = '';
}

function obFinish() {
    bootstrap.Modal.getInstance(document.getElementById('onboardModal'))?.hide();
    const s = document.getElementById('ob-success');
    if (s) { s.textContent = 'Konfiguracja zapisana.'; s.classList.remove('d-none'); }
    // Odśwież stronę (czysto, bez ?onboard=1)
    const url = new URL(window.location.href);
    url.searchParams.delete('onboard');
    window.location.href = url.toString();
}

function obError(msg) {
    const e = document.getElementById('ob-error');
    if (e) { e.textContent = msg; e.classList.remove('d-none'); }
}
})();
</script>
<?php endif; /* $_onboard */ ?>

<?php if ($_show_recovery && $_recovery_code): ?>
<!-- ── Modal kodu odzyskiwania ────────────────────────────────────────────── -->
<div class="modal fade" id="recoveryModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"
     aria-labelledby="recoveryModalLabel" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg overflow-hidden">

      <!-- Nagłówek -->
      <div class="modal-header border-0 pb-0 pt-4 px-4"
           style="background:linear-gradient(135deg,#1e40af 0%,#2563eb 100%)">
        <div class="text-white">
          <div class="d-flex align-items-center gap-2 mb-1">
            <span style="background:rgba(255,255,255,.2);border-radius:8px;width:36px;height:36px;
                         display:flex;align-items:center;justify-content:center">
              <i class="bi bi-shield-lock-fill fs-5"></i>
            </span>
            <h5 class="modal-title fw-bold mb-0" id="recoveryModalLabel">Kod odzyskiwania dostępu</h5>
          </div>
          <p class="small mb-3 opacity-75">
            Wygenerowany dla: <strong><?= h($row['imie_nazwisko'] ?? '') ?></strong>
          </p>
        </div>
      </div>

      <!-- Ciało -->
      <div class="modal-body px-4 pt-4">

        <div class="alert alert-warning d-flex gap-2 py-2 mb-4">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
          <div class="small">
            <strong>Wyświetlany tylko raz.</strong> Zanotuj lub wyślij kod teraz —
            nie będzie można go odczytać ponownie.
          </div>
        </div>

        <!-- Kod -->
        <div class="text-center mb-4">
          <div class="text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.08em">
            Kod odzyskiwania
          </div>
          <div id="recovery-code-display"
               style="font-size:2.2rem;font-weight:900;letter-spacing:.4em;font-family:monospace;
                      background:#f1f5f9;border:2px dashed #94a3b8;border-radius:12px;
                      padding:16px 24px;display:inline-block;cursor:pointer;user-select:all"
               title="Kliknij aby skopiować"
               onclick="rcCopy()"><?= h($_recovery_code) ?></div>
          <div id="rc-copy-info" class="text-success small mt-2" style="min-height:1.2em"></div>
        </div>

        <!-- Wysyłka -->
        <div class="d-flex gap-2 flex-wrap justify-content-center mb-2">
          <?php if (!empty($row['telefon'])): ?>
          <button type="button" class="btn btn-outline-primary d-flex align-items-center gap-2"
                  id="btn-rc-sms" onclick="rcSend('sms')">
            <i class="bi bi-chat-dots"></i>
            <span>Wyślij SMS</span>
            <span class="text-muted small">···<?= substr(preg_replace('/\D/','',$row['telefon']),-4) ?></span>
          </button>
          <?php endif; ?>
          <?php if (!empty($row['email'])): ?>
          <button type="button" class="btn btn-outline-primary d-flex align-items-center gap-2"
                  id="btn-rc-email" onclick="rcSend('email')">
            <i class="bi bi-envelope"></i>
            <span>Wyślij e-mail</span>
            <span class="text-muted small"><?= h($row['email']) ?></span>
          </button>
          <?php endif; ?>
        </div>
        <div id="rc-send-info" class="text-center small mt-2" style="min-height:1.4em"></div>
      </div>

      <!-- Stopka -->
      <div class="modal-footer border-0 px-4 pb-4">
        <button type="button" class="btn btn-primary px-4" data-bs-dismiss="modal">
          <i class="bi bi-check-lg me-1"></i>Rozumiem, zapisałem kod
        </button>
      </div>

    </div>
  </div>
</div>

<script>
// Otwórz modal automatycznie
document.addEventListener('DOMContentLoaded', function() {
  var m = document.getElementById('recoveryModal');
  if (m) new bootstrap.Modal(m).show();
});

var _rcCode = <?= json_encode($_recovery_code) ?>;
var _rcId   = <?= $id ?>;

function rcCopy() {
  if (!navigator.clipboard) {
    var el = document.getElementById('recovery-code-display');
    var range = document.createRange();
    range.selectNode(el);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    document.execCommand('copy');
    window.getSelection().removeAllRanges();
  } else {
    navigator.clipboard.writeText(_rcCode);
  }
  var info = document.getElementById('rc-copy-info');
  info.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Skopiowano do schowka';
  setTimeout(function() { info.textContent = ''; }, 3000);
}

function rcSend(channel) {
  var btn  = document.getElementById('btn-rc-' + channel);
  var info = document.getElementById('rc-send-info');
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Wysyłanie…'; }
  info.textContent = '';

  var fd = new FormData();
  fd.append('id',      _rcId);
  fd.append('channel', channel);
  fd.append('code',    _rcCode);

  fetch('<?= APP_URL ?>/contracts/wolontariat/api_send_recovery.php', {
    method: 'POST', body: fd
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    if (btn) btn.disabled = false;
    if (d.ok) {
      info.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>' + d.info + '</span>';
      if (btn) btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Wysłano';
    } else {
      info.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>' + (d.error||'Błąd wysyłki') + '</span>';
      if (btn) btn.innerHTML = channel === 'sms' ? '<i class="bi bi-chat-dots me-1"></i>Ponów SMS' : '<i class="bi bi-envelope me-1"></i>Ponów e-mail';
    }
  })
  .catch(function() {
    if (btn) { btn.disabled = false; btn.textContent = 'Błąd — ponów'; }
    info.innerHTML = '<span class="text-danger">Błąd połączenia</span>';
  });
}
</script>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
