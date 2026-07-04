<?php
/**
 * karty30/ti/kursant/accounts.php — Zarządzanie kontami kursantów (admin K30).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_online.php'; // konta MS / Moodle
require_once __DIR__ . '/auth.php'; // parent_make_token()

k30_require_access();
karty30_migrate();
if (!(can_write('karty30') || is_admin())) {
    flash_set('danger','Brak uprawnień.'); header('Location: ../index.php'); exit;
}

$PAGE_TITLE = 'Konta kursantów TI';

/** Generuje prosty login: pierwsza litera imienia + kropka + nazwisko, bez polskich znaków */
function _gen_student_login(string $name, string $suffix = ''): string {
    $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
            'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z'];
    $n = strtr($name, $map);
    $parts = preg_split('/\s+/', trim($n));
    if (count($parts) >= 2) {
        $login = strtolower($parts[0][0] . '.' . end($parts));
    } else {
        $login = strtolower($parts[0]);
    }
    $login = preg_replace('/[^a-z0-9._-]/', '', $login);
    return $login . ($suffix ? $suffix : '');
}

/** Generuje hasło: słowo + cyfry */
function _gen_student_pass(): string {
    $words = ['Kot','Pies','Dom','Las','Rok','Nos','Byk','Lis','Mak','Rak'];
    return $words[random_int(0, count($words)-1)] . random_int(10, 99);
}

/**
 * Wysyła dane logowania do panelu kursanta SMS-em (jeśli SMS włączony i jest numer).
 * Zwraca dopisek do komunikatu flash informujący o statusie wysyłki.
 */
function _student_send_login_sms(string $phone, string $login, string $pass): string {
    $phone = trim($phone);
    if ($phone === '') return ' (brak numeru telefonu — przekaż hasło ręcznie)';
    if (!sms_is_enabled()) return ' (SMS wyłączony — przekaż hasło ręcznie)';
    $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';
    $msg = "{$org} - panel kursanta. Login: {$login}, haslo: {$pass}";
    try {
        sms_send($phone, $msg);
        return ' Hasło wysłano SMS-em.';
    } catch (\Throwable $e) {
        return ' (błąd wysyłki SMS: ' . $e->getMessage() . ')';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'clear_alias') {
        $aid = (int)($_POST['account_id'] ?? 0);
        if ($aid) {
            db()->prepare("UPDATE k30_ti_student_accounts SET login_alias='', updated_at=datetime('now') WHERE id=?")
               ->execute([$aid]);
            flash_set('success', 'Alias logowania usunięty.');
        }
        header('Location: accounts.php'); exit;
    }

    // Ręczne zatwierdzenie dodatkowego numeru SMS (alternatywa dla samoobsługowej weryfikacji
    // kodem SMS — np. gdy kursant nie może odebrać kodu na ten numer).
    if ($op === 'approve_notify_phone') {
        $aid   = (int)($_POST['account_id'] ?? 0);
        $which = ((string)($_POST['which'] ?? '')) === '3' ? 3 : 2;
        if ($aid) {
            db()->prepare("UPDATE k30_ti_student_accounts
                            SET notify_phone{$which}_verified=1, notify_phone{$which}_otp='', notify_phone{$which}_otp_expires='',
                                updated_at=datetime('now') WHERE id=?")
               ->execute([$aid]);
            flash_set('success', 'Numer zatwierdzony — od teraz będzie otrzymywał powiadomienia SMS.');
        }
        header('Location: accounts.php'); exit;
    }

    if ($op === 'create') {
        $cid  = (int)($_POST['client_id'] ?? 0);
        $c    = $cid ? db_one("SELECT * FROM k30_clients WHERE id=?", [$cid]) : null;
        if (!$c) { flash_set('danger','Wybierz beneficjenta.'); header('Location: accounts.php'); exit; }

        // Wygeneruj unikalny login
        $base  = _gen_student_login($c['name']);
        $login = $base;
        $i     = 2;
        while (db_one("SELECT id FROM k30_ti_student_accounts WHERE login=?", [$login])) {
            $login = $base . $i++;
        }
        $pass  = _gen_student_pass();
        $hash  = password_hash($pass, PASSWORD_BCRYPT);

        db_insert('k30_ti_student_accounts', [
            'client_id'     => $cid,
            'login'         => $login,
            'password_hash' => $hash,
            'is_active'     => 1,
            'must_change_password' => 1, // kursant ustawi własne hasło przy pierwszym logowaniu
            'created_by'    => (int)(current_user()['id'] ?? 0),
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        // Pokaż hasło raz w sesji
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['new_student_creds'] = ['login' => $login, 'password' => $pass, 'name' => $c['name']];
        $sms = _student_send_login_sms($c['phone'] ?? '', $login, $pass);
        flash_set('success', "Konto kursanta dla {$c['name']} utworzone. Login: {$login}." . $sms);
        header('Location: accounts.php'); exit;
    }

    // Zbiorcze tworzenie kont panelu dla wielu beneficjentów naraz
    if ($op === 'bulk_create') {
        $ids = array_values(array_unique(array_map('intval', (array)($_POST['client_ids'] ?? []))));
        if (!$ids) { flash_set('danger','Zaznacz co najmniej jednego beneficjenta.'); header('Location: accounts.php'); exit; }

        $existing = array_map('intval', array_column(db_all("SELECT client_id FROM k30_ti_student_accounts"), 'client_id'));
        $uid      = (int)(current_user()['id'] ?? 0);
        $send_sms = isset($_POST['send_sms']);
        $rows = []; $skipped = 0;

        foreach ($ids as $cid) {
            if ($cid <= 0 || in_array($cid, $existing, true)) { $skipped++; continue; }
            $c = db_one("SELECT * FROM k30_clients WHERE id=?", [$cid]);
            if (!$c) { $skipped++; continue; }

            $base = _gen_student_login($c['name']); $login = $base; $i = 2;
            while (db_one("SELECT id FROM k30_ti_student_accounts WHERE login=?", [$login])) { $login = $base . $i++; }
            $pass = _gen_student_pass();

            db_insert('k30_ti_student_accounts', [
                'client_id'     => $cid,
                'login'         => $login,
                'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
                'is_active'     => 1,
                'must_change_password' => 1,
                'created_by'    => $uid,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            $existing[] = $cid;
            $sms = $send_sms ? _student_send_login_sms($c['phone'] ?? '', $login, $pass) : '';
            $rows[] = ['name' => $c['name'], 'login' => $login, 'password' => $pass, 'sms' => trim($sms)];
        }

        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['bulk_student_creds'] = ['rows' => $rows, 'ts' => time()];
        flash_set('success', 'Utworzono kont: ' . count($rows) . ($skipped ? " (pominięto już istniejące: {$skipped})" : '') . '.');
        header('Location: accounts.php'); exit;
    }

    if ($op === 'reset_pass') {
        $aid  = (int)($_POST['account_id'] ?? 0);
        $acc  = $aid ? db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if (!$acc) { flash_set('danger','Konto nie istnieje.'); header('Location: accounts.php'); exit; }
        $pass = _gen_student_pass();
        db()->prepare("UPDATE k30_ti_student_accounts SET password_hash=?, must_change_password=1, updated_at=datetime('now') WHERE id=?")
           ->execute([password_hash($pass, PASSWORD_BCRYPT), $aid]);
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $c = db_one("SELECT name, phone FROM k30_clients WHERE id=?", [$acc['client_id']]);
        $_SESSION['new_student_creds'] = ['login' => $acc['login'], 'password' => $pass, 'name' => $c['name'] ?? ''];
        $sms = _student_send_login_sms($c['phone'] ?? '', $acc['login'], $pass);
        flash_set('success', "Hasło zresetowane dla {$acc['login']}." . $sms);
        header('Location: accounts.php'); exit;
    }

    // Nadanie / zmiana numeru kursanta (przez administratora)
    if ($op === 'set_no') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $no  = trim($_POST['student_no'] ?? '');
        if ($aid) {
            db()->prepare("UPDATE k30_ti_student_accounts SET student_no=?, updated_at=datetime('now') WHERE id=?")
               ->execute([mb_substr($no, 0, 40), $aid]);
            flash_set('success', $no !== '' ? 'Numer kursanta zapisany.' : 'Numer kursanta usunięty.');
        }
        header('Location: accounts.php'); exit;
    }

    if ($op === 'toggle') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]);
        if ($acc) {
            db()->prepare("UPDATE k30_ti_student_accounts SET is_active=?, updated_at=datetime('now') WHERE id=?")
               ->execute([$acc['is_active'] ? 0 : 1, $aid]);
        }
        header('Location: accounts.php'); exit;
    }

    if ($op === 'delete') {
        $aid = (int)($_POST['account_id'] ?? 0);
        db()->prepare("DELETE FROM k30_ti_student_accounts WHERE id=?")->execute([$aid]);
        flash_set('success','Konto usunięte.');
        header('Location: accounts.php'); exit;
    }

    // Odblokowanie dostępu wstrzymanego przez opiekuna (interwencja administracji)
    if ($op === 'child_unblock') {
        $aid = (int)($_POST['account_id'] ?? 0);
        db()->prepare("UPDATE k30_ti_student_accounts SET child_access_blocked=0, updated_at=datetime('now') WHERE id=?")
           ->execute([$aid]);
        flash_set('success','Dostęp kursanta do panelu został przywrócony.');
        header('Location: accounts.php'); exit;
    }

    // ── Podszywanie się pod kursanta („zaloguj jako") ────────────────────────
    if ($op === 'impersonate') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if (!$acc || empty($acc['is_active'])) {
            flash_set('danger', 'Konto nie istnieje lub jest nieaktywne.');
            header('Location: accounts.php'); exit;
        }
        $admin     = current_user() ?: [];
        $adminId   = (int)($admin['id'] ?? 0);
        $adminName = (string)($admin['name'] ?? $admin['username'] ?? $admin['email'] ?? 'administrator');
        // Zamknij sesję głównej aplikacji, otwórz osobną sesję kursanta (k30_student).
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        student_impersonate($acc, $adminId, $adminName);
        header('Location: ' . rtrim(APP_URL, '/') . '/karty30/ti/kursant/index.php');
        exit;
    }

    // ── Konto Microsoft 365 (tenant szkoleniowy) ─────────────────────────────
    if ($op === 'ms_create') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $res = ti_ms_provision($aid);
        if ($res['ok']) {
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['new_ms_creds'] = ['upn' => $res['upn'] ?? '', 'password' => $res['password'] ?? ''];
            flash_set('success', 'Konto Microsoft utworzone: ' . ($res['upn'] ?? ''));
        } else {
            flash_set('danger', $res['msg']);
        }
        header('Location: accounts.php'); exit;
    }

    if ($op === 'ms_delete') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $res = ti_ms_delete($aid);
        flash_set($res['ok'] ? 'success' : 'danger', $res['msg']);
        header('Location: accounts.php'); exit;
    }

    // ── Konto Moodle (login = UPN konta MS) ──────────────────────────────────
    if ($op === 'moodle_create') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $res = ti_moodle_provision($aid);
        flash_set($res['ok'] ? 'success' : 'danger', $res['ok'] ? ('Konto Moodle gotowe: ' . ($res['login'] ?? '')) : $res['msg']);
        header('Location: accounts.php'); exit;
    }

    // Zapis danych opiekuna + status małoletniego
    if ($op === 'guardian_save') {
        $aid = (int)($_POST['account_id'] ?? 0);
        if ($aid) {
            db()->prepare(
                "UPDATE k30_ti_student_accounts
                 SET is_minor=?, guardian_name=?, guardian_phone=?, guardian_email=?, updated_at=datetime('now')
                 WHERE id=?"
            )->execute([
                isset($_POST['is_minor']) ? 1 : 0,
                trim($_POST['guardian_name'] ?? ''),
                trim($_POST['guardian_phone'] ?? ''),
                trim($_POST['guardian_email'] ?? ''),
                $aid,
            ]);
            flash_set('success', 'Dane opiekuna zapisane.');
        }
        header('Location: accounts.php?guardian=' . $aid); exit;
    }

    // Wygeneruj link magiczny rodzica i wyślij go e-mailem (jeśli jest adres)
    if ($op === 'parent_link') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if ($acc) {
            $token = parent_make_token($aid);
            $url   = rtrim(APP_URL, '/') . '/karty30/ti/kursant/parent.php?t=' . $token;
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['parent_link'] = $url;
            $email = trim($acc['guardian_email'] ?? '');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                require_once dirname(dirname(dirname(__DIR__))) . '/includes/mail_queue.php';
                $cl   = db_one("SELECT name FROM k30_clients WHERE id=?", [$acc['client_id']]);
                $org  = defined('ORG_NAME') ? ORG_NAME : 'Panel';
                $html = "<p>Dzień dobry,</p>"
                      . "<p>Poniższy link daje dostęp do rozliczeń i frekwencji kursanta <strong>"
                      . h($cl['name'] ?? '') . "</strong> w {$org}:</p>"
                      . "<p><a href=\"{$url}\">{$url}</a></p>"
                      . "<p style='color:#888;font-size:12px'>Link jest ważny 30 dni. Nie udostępniaj go osobom trzecim.</p>";
                try {
                    mail_queue_add($email, $acc['guardian_name'] ?? '', "Dostęp do rozliczeń — {$org}", $html, '', 'ti_parent', $aid, '', true);
                    flash_set('success', 'Link wysłano na e-mail opiekuna: ' . $email);
                } catch (\Throwable $e) {
                    flash_set('warning', 'Link wygenerowany, ale wysyłka e-mail nie powiodła się: ' . $e->getMessage());
                }
            } else {
                flash_set('warning', 'Brak poprawnego e-maila opiekuna — skopiuj link ręcznie poniżej.');
            }
        }
        header('Location: accounts.php?guardian=' . $aid); exit;
    }

    // Utwórz konto rodzica (login: pierwsza litera imienia.nazwisko-r + hasło)
    if ($op === 'parent_account_create') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one(
            "SELECT a.*, cl.name AS client_name FROM k30_ti_student_accounts a
             JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=?", [$aid]
        ) : null;
        if (!$acc) { flash_set('danger','Konto kursanta nie istnieje.'); header('Location: accounts.php'); exit; }
        if (!empty($acc['parent_login'])) {
            flash_set('warning', 'Konto rodzica już istnieje (login: ' . $acc['parent_login'] . '). Użyj „Resetuj hasło".');
            header('Location: accounts.php?guardian=' . $aid); exit;
        }
        // login z imienia i nazwiska dziecka z dopiskiem „-r" (rodzic), unikalny
        $base  = _gen_student_login($acc['client_name'], '-r');
        $login = $base; $i = 2;
        while (db_one("SELECT id FROM k30_ti_student_accounts WHERE parent_login=?", [$login])) {
            $login = $base . $i++;
        }
        $pass = _gen_student_pass();
        db()->prepare(
            "UPDATE k30_ti_student_accounts
             SET parent_login=?, parent_password_hash=?, parent_must_change=1, updated_at=datetime('now')
             WHERE id=?"
        )->execute([$login, password_hash($pass, PASSWORD_BCRYPT), $aid]);

        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['new_parent_creds'] = ['login' => $login, 'password' => $pass, 'name' => $acc['client_name']];
        // Wyślij dane SMS-em na numer opiekuna (jeśli jest i SMS włączony)
        $gphone = trim($acc['guardian_phone'] ?? '');
        $sms = '';
        if ($gphone !== '' && sms_is_enabled()) {
            try {
                $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';
                sms_send($gphone, "{$org} - panel rodzica. Login: {$login}, haslo: {$pass}");
                $sms = ' Dane wysłano SMS-em na numer opiekuna.';
            } catch (\Throwable $e) { $sms = ' (błąd wysyłki SMS: ' . $e->getMessage() . ')'; }
        }
        flash_set('success', "Konto rodzica utworzone. Login: {$login}." . $sms);
        header('Location: accounts.php?guardian=' . $aid); exit;
    }

    // Reset hasła konta rodzica
    if ($op === 'parent_account_reset') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if (!$acc || empty($acc['parent_login'])) { flash_set('danger','Konto rodzica nie istnieje.'); header('Location: accounts.php?guardian=' . $aid); exit; }
        $pass = _gen_student_pass();
        db()->prepare(
            "UPDATE k30_ti_student_accounts SET parent_password_hash=?, parent_must_change=1, updated_at=datetime('now') WHERE id=?"
        )->execute([password_hash($pass, PASSWORD_BCRYPT), $aid]);
        $cl = db_one("SELECT name FROM k30_clients WHERE id=?", [$acc['client_id']]);
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['new_parent_creds'] = ['login' => $acc['parent_login'], 'password' => $pass, 'name' => $cl['name'] ?? ''];
        $gphone = trim($acc['guardian_phone'] ?? '');
        $sms = '';
        if ($gphone !== '' && sms_is_enabled()) {
            try {
                $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';
                sms_send($gphone, "{$org} - panel rodzica. Login: {$acc['parent_login']}, haslo: {$pass}");
                $sms = ' Dane wysłano SMS-em na numer opiekuna.';
            } catch (\Throwable $e) { $sms = ' (błąd wysyłki SMS: ' . $e->getMessage() . ')'; }
        }
        flash_set('success', "Hasło konta rodzica zresetowane (login: {$acc['parent_login']})." . $sms);
        header('Location: accounts.php?guardian=' . $aid); exit;
    }

    // Usuń konto rodzica
    if ($op === 'parent_account_delete') {
        $aid = (int)($_POST['account_id'] ?? 0);
        if ($aid) {
            db()->prepare(
                "UPDATE k30_ti_student_accounts
                 SET parent_login='', parent_password_hash='', parent_must_change=0, updated_at=datetime('now')
                 WHERE id=?"
            )->execute([$aid]);
            flash_set('success', 'Konto rodzica usunięte.');
        }
        header('Location: accounts.php?guardian=' . $aid); exit;
    }

    // ── Upoważnienia (tylko pełnoletni) ──────────────────────────────────────
    if ($op === 'authp_scan_upload') {
        // Wgraj skan podpisanego upoważnienia do istniejącego wpisu
        $ap_id_scan = (int)($_POST['ap_id'] ?? 0);
        $ap_scan = $ap_id_scan ? db_one("SELECT * FROM k30_ti_authorized_persons WHERE id=?", [$ap_id_scan]) : null;
        $aid_scan = $ap_scan ? (int)$ap_scan['student_account_id'] : 0;
        if (!$ap_scan) { flash_set('danger','Nie znaleziono upoważnienia.'); header('Location: accounts.php'); exit; }
        require_once dirname(dirname(dirname(__DIR__))) . '/includes/mail_queue.php';
        $scan_att = isset($_FILES['scan_file']) ? mail_queue_save_attachment($_FILES['scan_file']) : null;
        if ($scan_att) {
            db()->prepare("UPDATE k30_ti_authorized_persons SET scan_path=? WHERE id=?")
               ->execute([$scan_att['path'], $ap_id_scan]);
            flash_set('success', 'Skan podpisanego upoważnienia zapisany.');
        } else {
            flash_set('danger', 'Nie udało się zapisać skanu. Sprawdź format (PDF, PNG, JPG) i rozmiar (max 15 MB).');
        }
        header('Location: accounts.php?authp=' . $aid_scan); exit;
    }

    if (in_array($op, ['authp_add', 'authp_toggle', 'authp_delete', 'authp_reset_pass', 'authp_revoke_scan'], true)) {
        $aid  = (int)($_POST['account_id'] ?? 0);
        $acc  = $aid ? db_one(
            "SELECT a.*, cl.name AS client_name FROM k30_ti_student_accounts a
             JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=?", [$aid]
        ) : null;
        if (!$acc || !empty($acc['is_minor'])) {
            flash_set('danger', 'Upoważnienia obsługiwane tylko dla pełnoletnich kursantów.');
            header('Location: accounts.php'); exit;
        }
        if ($op === 'authp_add') {
            $ap_name      = mb_substr(trim((string)($_POST['ap_name']   ?? '')), 0, 100);
            $ap_email     = mb_substr(trim((string)($_POST['ap_email']  ?? '')), 0, 150);
            $ap_notes     = mb_substr(trim((string)($_POST['ap_notes']  ?? '')), 0, 300);
            $ap_reason    = mb_substr(trim((string)($_POST['ap_reason'] ?? '')), 0, 400);
            $ap_added_by  = current_user()['name'] ?? current_user()['email'] ?? 'Administrator';
            if ($ap_name === '') {
                flash_set('danger', 'Podaj imię i nazwisko osoby upoważnionej.');
                header('Location: accounts.php?authp=' . $aid); exit;
            }
            if ($ap_reason === '') {
                flash_set('danger', 'Podaj powód upoważnienia.');
                header('Location: accounts.php?authp=' . $aid); exit;
            }
            // Wygeneruj unikalny login z imienia/nazwiska + sufiks -up
            $ap_login = _gen_student_login($ap_name, '-up');
            $suffix = 0;
            $base   = $ap_login;
            while (db_one("SELECT id FROM k30_ti_authorized_persons WHERE login=?", [$ap_login])
                || db_one("SELECT id FROM k30_ti_student_accounts WHERE login=?", [$ap_login])) {
                $suffix++;
                $ap_login = $base . $suffix;
            }
            $ap_pass = _authp_gen_pass_admin();
            $ap_id   = db_insert('k30_ti_authorized_persons', [
                'student_account_id' => $aid,
                'name'         => $ap_name,
                'login'        => $ap_login,
                'email'        => $ap_email,
                'password_hash'=> password_hash($ap_pass, PASSWORD_BCRYPT),
                'notes'        => $ap_notes,
                'added_by_name'=> $ap_added_by,
                'reason'       => $ap_reason,
            ]);
            // Pobierz prowadzących kursanta — do kopii maila
            $instructors = db()->prepare(
                "SELECT DISTINCT u.name, u.email FROM k30_ti_enrollments e
                 JOIN k30_ti_courses c ON c.id=e.course_id
                 JOIN users u ON u.id=c.instructor_id
                 WHERE e.client_id=? AND e.status='active' AND u.email!='' AND u.email IS NOT NULL"
            );
            $instructors->execute([(int)$acc['client_id']]);
            $instructor_rows = $instructors->fetchAll(\PDO::FETCH_ASSOC);

            // Wyślij mail do osoby upoważnionej + CC do prowadzącego
            if ($ap_email !== '' || !empty($instructor_rows)) {
                try {
                    require_once dirname(dirname(dirname(__DIR__))) . '/includes/mail_queue.php';
                    $org_name    = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
                    $portal_link = rtrim(APP_URL, '/') . '/karty30/ti/kursant/parent.php?role=up';
                    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
                    $mail_html   = '<html><body style="font-family:sans-serif;font-size:15px;line-height:1.6;color:#222;max-width:600px;margin:0 auto;padding:24px">'
                        . '<p>Dzień dobry,</p>'
                        . '<p>Niniejszym informujemy, że administrator systemu <strong>' . $esc($org_name) . '</strong>'
                        . ' — <strong>' . $esc($ap_added_by) . '</strong>'
                        . ' — wystawił upoważnienie do wglądu w dane panelu kursanta'
                        . ' <strong>' . $esc($acc['client_name']) . '</strong>'
                        . ' dla osoby: <strong>' . $esc($ap_name) . '</strong>.</p>'
                        . '<p><strong>Powód:</strong> ' . $esc($ap_reason) . '</p>'
                        . '<p>Upoważnienie umożliwia podgląd lekcji, frekwencji i rozliczeń kursanta — wyłącznie do odczytu.</p>'
                        . '<table style="border-collapse:collapse;margin:16px 0">'
                        . '<tr><td style="padding:3px 20px 3px 0;color:#555;white-space:nowrap">Panel:</td><td><a href="' . $esc($portal_link) . '">' . $esc($portal_link) . '</a></td></tr>'
                        . '<tr><td style="padding:3px 20px 3px 0;color:#555;white-space:nowrap">Login:</td><td><strong>' . $esc($ap_login) . '</strong></td></tr>'
                        . '<tr><td style="padding:3px 20px 3px 0;color:#555;white-space:nowrap">Hasło:</td><td>przekazane ustnie / na kartce upoważnienia</td></tr>'
                        . '</table>'
                        . '<p>Upoważnienie zostało wystawione przez administratora. Prosimy o nieudostępnianie danych logowania osobom trzecim.</p>'
                        . '<p>W razie pytań skontaktuj się z biurem.</p>'
                        . '<hr style="border:none;border-top:1px solid #ddd;margin:20px 0">'
                        . '<p style="font-size:.8em;color:#888">Wiadomość automatyczna — ' . $esc($org_name) . '.</p>'
                        . '</body></html>';
                    $subject = "Upoważnienie do wglądu w panel kursanta — {$org_name}";
                    // Mail do osoby upoważnionej
                    if ($ap_email !== '') {
                        mail_queue_add($ap_email, $ap_name, $subject, $mail_html, '', 'authp', (int)$ap_id, '', true);
                    }
                    // Kopia do każdego prowadzącego
                    $instr_subject = "[Kopia] Upoważnienie wgląd w panel: {$acc['client_name']} → {$ap_name}";
                    $instr_html    = '<html><body style="font-family:sans-serif;font-size:15px;line-height:1.6;color:#222;max-width:600px;margin:0 auto;padding:24px">'
                        . '<p><strong>Kopia informacyjna dla prowadzącego.</strong></p>'
                        . '<p>Administrator <strong>' . $esc($ap_added_by) . '</strong> wystawił upoważnienie do wglądu w panel kursanta'
                        . ' <strong>' . $esc($acc['client_name']) . '</strong>'
                        . ' dla osoby: <strong>' . $esc($ap_name) . '</strong>.</p>'
                        . '<p><strong>Powód:</strong> ' . $esc($ap_reason) . '</p>'
                        . ($ap_notes !== '' ? '<p><strong>Uwagi:</strong> ' . $esc($ap_notes) . '</p>' : '')
                        . '<p>Upoważniony/a ma dostęp do podglądu lekcji, frekwencji i rozliczeń kursanta.</p>'
                        . '<hr style="border:none;border-top:1px solid #ddd;margin:20px 0">'
                        . '<p style="font-size:.8em;color:#888">Wiadomość automatyczna — ' . $esc($org_name) . '.</p>'
                        . '</body></html>';
                    foreach ($instructor_rows as $instr) {
                        mail_queue_add($instr['email'], $instr['name'], $instr_subject, $instr_html, '', 'authp_cc', (int)$ap_id, '', true);
                    }
                } catch (\Throwable $e) {}
            }
            $_SESSION['new_authp_creds'] = [
                'id'          => (int)$ap_id,
                'name'        => $ap_name,
                'login'       => $ap_login,
                'pass'        => $ap_pass,
                'student_name'=> $acc['client_name'],
                'account_id'  => $aid,
                'emailed'     => $ap_email !== '',
            ];
            flash_set('success', "Upoważnienie dodane: {$ap_name} (login: {$ap_login}). Wygenerowano hasło — wydrukuj kartkę.");
            header('Location: accounts.php?authp=' . $aid . '&new_authp=1'); exit;
        }
        $ap_id_edit = (int)($_POST['ap_id'] ?? 0);
        $ap = $ap_id_edit ? db_one(
            "SELECT * FROM k30_ti_authorized_persons WHERE id=? AND student_account_id=?",
            [$ap_id_edit, $aid]
        ) : null;
        if (!$ap) { flash_set('danger','Nie znaleziono upoważnienia.'); header('Location: accounts.php?authp=' . $aid); exit; }
        if ($op === 'authp_revoke_scan') {
            require_once dirname(dirname(dirname(__DIR__))) . '/includes/mail_queue.php';
            $rs_att = mail_queue_save_attachment($_FILES['revoke_scan_file'] ?? []);
            if ($rs_att) {
                db()->prepare("UPDATE k30_ti_authorized_persons SET revoke_scan_path=? WHERE id=?")
                   ->execute([$rs_att['path'], $ap_id_edit]);
                flash_set('success', 'Skan odwołania zapisany.');
            } else {
                flash_set('danger', 'Nie udało się zapisać pliku.');
            }
            header('Location: accounts.php?authp=' . $aid); exit;
        } elseif ($op === 'authp_toggle') {
            $revoking = (bool)$ap['is_active']; // true = właśnie cofamy dostęp
            $revoker  = current_user()['name'] ?? current_user()['email'] ?? 'Administrator';
            if ($revoking) {
                db()->prepare("UPDATE k30_ti_authorized_persons SET is_active=0, revoked_at=datetime('now'), revoked_by_name=? WHERE id=?")
                   ->execute([$revoker, $ap_id_edit]);
                $_SESSION['authp_revoked_id'] = $ap_id_edit;
                flash_set('success', 'Dostęp wstrzymany. Wydrukuj dokument odwołania.');
            } else {
                db()->prepare("UPDATE k30_ti_authorized_persons SET is_active=1, revoked_at=NULL, revoked_by_name='' WHERE id=?")
                   ->execute([$ap_id_edit]);
                flash_set('success', 'Dostęp przywrócony.');
            }
        } elseif ($op === 'authp_delete') {
            db()->prepare("DELETE FROM k30_ti_authorized_persons WHERE id=?")->execute([$ap_id_edit]);
            flash_set('success', "Upoważnienie usunięte: {$ap['name']}.");
        } elseif ($op === 'authp_reset_pass') {
            $np = _authp_gen_pass_admin();
            db()->prepare("UPDATE k30_ti_authorized_persons SET password_hash=? WHERE id=?")
               ->execute([password_hash($np, PASSWORD_BCRYPT), $ap_id_edit]);
            $cl_row = db_one("SELECT cl.name FROM k30_ti_student_accounts a JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=?", [$aid]);
            $_SESSION['new_authp_creds'] = [
                'id'          => $ap_id_edit,
                'name'        => $ap['name'],
                'login'       => $ap['login'],
                'pass'        => $np,
                'student_name'=> $cl_row['name'] ?? '',
                'account_id'  => $aid,
            ];
            flash_set('success', "Hasło upoważnienia zresetowane dla {$ap['name']}. Wydrukuj kartkę.");
            header('Location: accounts.php?authp=' . $aid . '&new_authp=1'); exit;
        }
        header('Location: accounts.php?authp=' . $aid); exit;
    }
}

function _authp_gen_pass_admin(): string {
    $w = ['Kotek','Rower','Zamek','Kwiat','Statek','Zegar','Obraz','Lampa'];
    return $w[array_rand($w)] . random_int(100, 999);
}

// Wczytaj
$accounts = db_all(
    "SELECT a.*, cl.name AS client_name
     FROM k30_ti_student_accounts a
     JOIN k30_clients cl ON cl.id=a.client_id
     ORDER BY cl.name"
);
$taken_ids   = array_column($accounts, 'client_id');
$all_clients = db_all("SELECT id, name FROM k30_clients ORDER BY name");
$no_account  = array_filter($all_clients, fn($c) => !in_array((int)$c['id'], $taken_ids));

// Dane nowego konta (z sesji)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$new_creds = $_SESSION['new_student_creds'] ?? null;
unset($_SESSION['new_student_creds']);
$bulk_creds = $_SESSION['bulk_student_creds'] ?? null;
unset($_SESSION['bulk_student_creds']);
$new_ms_creds = $_SESSION['new_ms_creds'] ?? null;
unset($_SESSION['new_ms_creds']);
$ms_online_enabled     = ti_ms_enabled();
$moodle_online_enabled = ti_moodle_enabled();
$parent_link = $_SESSION['parent_link'] ?? null;
unset($_SESSION['parent_link']);
$new_parent_creds = $_SESSION['new_parent_creds'] ?? null;
unset($_SESSION['new_parent_creds']);
$new_authp_creds  = $_SESSION['new_authp_creds']  ?? null;
unset($_SESSION['new_authp_creds']);
$authp_revoked_id = $_SESSION['authp_revoked_id'] ?? null;
unset($_SESSION['authp_revoked_id']);

// Edytor opiekuna
$guardian_id  = (int)($_GET['guardian'] ?? 0);
$guardian_acc = $guardian_id ? db_one(
    "SELECT a.*, cl.name AS client_name FROM k30_ti_student_accounts a
     JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=?", [$guardian_id]
) : null;

$portal_url         = rtrim(APP_URL, '/') . '/karty30/ti/kursant/login.php';
$parent_portal_url  = rtrim(APP_URL, '/') . '/karty30/ti/kursant/parent.php';
$authp_portal_url   = rtrim(APP_URL, '/') . '/karty30/ti/kursant/authorized_person.php';

// Edytor upoważnień
$authp_account_id  = (int)($_GET['authp'] ?? 0);
$authp_account     = $authp_account_id ? db_one(
    "SELECT a.*, cl.name AS client_name FROM k30_ti_student_accounts a
     JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=? AND COALESCE(a.is_minor,0)=0",
    [$authp_account_id]
) : null;
$authp_list_admin  = $authp_account ? db_all(
    "SELECT * FROM k30_ti_authorized_persons WHERE student_account_id=? ORDER BY created_at DESC",
    [$authp_account_id]
) : [];

include dirname(dirname(dirname(__DIR__))) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/ti/index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Konta kursantów</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-person-badge text-primary me-2"></i>Konta kursantów</h4>
  <div class="ms-auto d-flex gap-2">
    <a href="<?= h($parent_portal_url) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-people me-1"></i>Panel rodzica
    </a>
    <a href="<?= h($portal_url) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-box-arrow-up-right me-1"></i>Panel kursanta
    </a>
  </div>
</div>

<?= flash_html() ?>

<!-- Nowo wygenerowane dane -->
<?php if ($new_creds): ?>
<div class="alert alert-warning d-flex gap-3 align-items-start mb-4 shadow-sm">
  <i class="bi bi-key-fill fs-4 flex-shrink-0" style="color:#b45309"></i>
  <div class="flex-grow-1">
    <div class="fw-bold mb-2">⚠ Dane dostępowe — przekaż kursantowi i zamknij!</div>
    <table class="table table-sm table-bordered mb-2" style="max-width:360px;background:#fff;font-size:.88rem">
      <tr><th>Beneficjent</th><td><?= h($new_creds['name']) ?></td></tr>
      <tr><th>Login</th><td class="font-monospace fw-bold"><?= h($new_creds['login']) ?></td></tr>
      <tr><th>Hasło</th><td class="font-monospace fw-bold text-danger"><?= h($new_creds['password']) ?></td></tr>
    </table>
    <div class="small text-muted">Link do logowania: <a href="<?= h($portal_url) ?>" target="_blank"><?= h($portal_url) ?></a></div>
  </div>
  <button type="button" class="btn-close" onclick="this.closest('.alert').remove()"></button>
</div>
<?php endif; ?>

<!-- Zbiorczo utworzone konta -->
<?php if ($bulk_creds && !empty($bulk_creds['rows'])): ?>
<div class="alert alert-warning mb-4 shadow-sm" id="bulk-result">
  <div class="d-flex align-items-start gap-2 mb-2">
    <i class="bi bi-people-fill fs-4 flex-shrink-0" style="color:#b45309"></i>
    <div class="fw-bold flex-grow-1">⚠ Zbiorczo utworzone konta (<?= count($bulk_creds['rows']) ?>) — zapisz lub wydrukuj teraz, hasła nie będą pokazane ponownie!</div>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printBulk()"><i class="bi bi-printer me-1"></i>Drukuj</button>
    <button type="button" class="btn-close" onclick="this.closest('.alert').remove()"></button>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-bordered mb-0" style="background:#fff;font-size:.86rem" id="bulk-table">
      <thead class="table-light"><tr><th>Beneficjent</th><th>Login</th><th>Hasło</th><th>SMS</th></tr></thead>
      <tbody>
        <?php foreach ($bulk_creds['rows'] as $r): ?>
        <tr>
          <td><?= h($r['name']) ?></td>
          <td class="font-monospace fw-bold"><?= h($r['login']) ?></td>
          <td class="font-monospace fw-bold text-danger"><?= h($r['password']) ?></td>
          <td class="small text-muted"><?= h($r['sms'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="small text-muted mt-2">Link do logowania: <a href="<?= h($portal_url) ?>" target="_blank"><?= h($portal_url) ?></a></div>
</div>
<script>
function printBulk(){
  var w = window.open('', '_blank');
  w.document.write('<html><head><title>Konta kursantów</title>'
    + '<style>body{font-family:Arial,sans-serif;font-size:13px}table{border-collapse:collapse;width:100%}'
    + 'th,td{border:1px solid #999;padding:4px 8px;text-align:left}th{background:#eee}</style></head><body>'
    + '<h3><?= h(addslashes(ORG_NAME ?? 'Panel kursanta')) ?> — dane dostępowe do panelu kursanta</h3>'
    + document.getElementById('bulk-table').outerHTML
    + '<p>Logowanie: <?= h($portal_url) ?></p></body></html>');
  w.document.close(); w.focus(); w.print();
}
</script>
<?php endif; ?>

<!-- Nowo utworzone konto Microsoft -->
<?php if ($new_ms_creds): ?>
<div class="alert alert-warning d-flex gap-3 align-items-start mb-4 shadow-sm">
  <i class="bi bi-microsoft fs-4 flex-shrink-0" style="color:#0078d4"></i>
  <div class="flex-grow-1">
    <div class="fw-bold mb-2">⚠ Dane konta Microsoft 365 — przekaż kursantowi i zamknij!</div>
    <table class="table table-sm table-bordered mb-2" style="max-width:360px;background:#fff;font-size:.88rem">
      <tr><th>Login (UPN)</th><td class="font-monospace fw-bold"><?= h($new_ms_creds['upn']) ?></td></tr>
      <tr><th>Hasło tymczasowe</th><td class="font-monospace fw-bold text-danger"><?= h($new_ms_creds['password']) ?></td></tr>
    </table>
    <div class="small text-muted">Ten sam login służy do logowania w Moodle. Dane wysłano też e-mailem/SMS-em (jeśli skonfigurowane).</div>
  </div>
  <button type="button" class="btn-close" onclick="this.closest('.alert').remove()"></button>
</div>
<?php endif; ?>

<!-- Nowo utworzone konto rodzica -->
<?php if ($new_parent_creds): ?>
<div class="alert alert-warning d-flex gap-3 align-items-start mb-4 shadow-sm">
  <i class="bi bi-people-fill fs-4 flex-shrink-0" style="color:#b45309"></i>
  <div class="flex-grow-1">
    <div class="fw-bold mb-2">⚠ Dane konta rodzica — przekaż opiekunowi i zamknij!</div>
    <table class="table table-sm table-bordered mb-2" style="max-width:380px;background:#fff;font-size:.88rem">
      <tr><th>Kursant</th><td><?= h($new_parent_creds['name']) ?></td></tr>
      <tr><th>Login rodzica</th><td class="font-monospace fw-bold"><?= h($new_parent_creds['login']) ?></td></tr>
      <tr><th>Hasło</th><td class="font-monospace fw-bold text-danger"><?= h($new_parent_creds['password']) ?></td></tr>
    </table>
    <div class="small text-muted">Logowanie rodzica (login + hasło): <a href="<?= h($parent_portal_url) ?>" target="_blank"><?= h($parent_portal_url) ?></a></div>
  </div>
  <button type="button" class="btn-close" onclick="this.closest('.alert').remove()"></button>
</div>
<?php endif; ?>

<!-- Edytor opiekuna / dostęp rodzica -->
<?php if ($guardian_acc): ?>
<div class="card border-0 shadow-sm mb-4" style="max-width:640px">
  <div class="card-header fw-semibold d-flex align-items-center">
    <span><i class="bi bi-people me-2 text-primary"></i>Opiekun / dostęp rodzica — <?= h($guardian_acc['client_name']) ?></span>
    <a href="accounts.php" class="btn-close ms-auto" aria-label="Zamknij"></a>
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Gdy kursant jest <strong>małoletni</strong>, nie widzi własnych rozliczeń — dostęp ma rodzic/opiekun
      (logowanie kodem SMS na numer opiekuna lub przez link wysłany e-mailem).
    </p>
    <?php if ($parent_link): ?>
    <div class="alert alert-info py-2 small">
      <div class="fw-semibold mb-1"><i class="bi bi-link-45deg me-1"></i>Link dostępu rodzica (ważny 30 dni):</div>
      <code style="word-break:break-all"><?= h($parent_link) ?></code>
    </div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op" value="guardian_save">
      <input type="hidden" name="account_id" value="<?= (int)$guardian_acc['id'] ?>">
      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" name="is_minor" id="minor" <?= $guardian_acc['is_minor'] ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="minor">Kursant małoletni (ukryj rozliczenia, dostęp dla rodzica)</label>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-md-12"><label class="form-label small">Imię i nazwisko opiekuna</label>
          <input class="form-control form-control-sm" name="guardian_name" value="<?= h($guardian_acc['guardian_name'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label small">Telefon opiekuna (do logowania SMS)</label>
          <input class="form-control form-control-sm" name="guardian_phone" value="<?= h($guardian_acc['guardian_phone'] ?? '') ?>" placeholder="np. 600 100 200"></div>
        <div class="col-md-6"><label class="form-label small">E-mail opiekuna (do linku dostępu)</label>
          <input class="form-control form-control-sm" name="guardian_email" value="<?= h($guardian_acc['guardian_email'] ?? '') ?>" placeholder="rodzic@example.com"></div>
      </div>
      <div class="d-flex gap-2 mt-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Zapisz</button>
      </div>
    </form>
    <form method="post" class="mt-2">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op" value="parent_link">
      <input type="hidden" name="account_id" value="<?= (int)$guardian_acc['id'] ?>">
      <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-envelope-paper me-1"></i>Wygeneruj i wyślij link rodzicowi</button>
    </form>

    <hr class="my-3">

    <!-- Konto rodzica: login + hasło -->
    <div class="fw-semibold mb-1"><i class="bi bi-person-lock me-1 text-primary"></i>Konto rodzica (login i hasło)</div>
    <p class="text-muted small mb-2">
      Stałe konto dla opiekuna do logowania <strong>loginem i hasłem</strong> — przydatne, gdy rodzic nie odbiera SMS-ów ani e-maili.
      Login w formacie <code>pierwsza-litera-imienia.nazwisko-r</code> (na podstawie danych kursanta).
    </p>
    <?php if (!empty($guardian_acc['parent_login'])): ?>
    <dl class="row small mb-2">
      <dt class="col-sm-4 text-muted fw-normal">Login rodzica</dt>
      <dd class="col-sm-8 font-monospace fw-bold"><?= h($guardian_acc['parent_login']) ?></dd>
      <dt class="col-sm-4 text-muted fw-normal">Ostatnie logowanie</dt>
      <dd class="col-sm-8"><?= !empty($guardian_acc['parent_last_login']) ? date('d.m.Y H:i', strtotime($guardian_acc['parent_last_login'])) : '—' ?></dd>
    </dl>
    <div class="d-flex gap-2 flex-wrap">
      <form method="post" onsubmit="return confirm('Zresetować hasło konta rodzica?')">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="parent_account_reset">
        <input type="hidden" name="account_id" value="<?= (int)$guardian_acc['id'] ?>">
        <button class="btn btn-outline-warning btn-sm"><i class="bi bi-key me-1"></i>Resetuj hasło</button>
      </form>
      <form method="post" onsubmit="return confirm('Usunąć konto rodzica? Opiekun straci możliwość logowania loginem i hasłem.')">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="parent_account_delete">
        <input type="hidden" name="account_id" value="<?= (int)$guardian_acc['id'] ?>">
        <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Usuń konto rodzica</button>
      </form>
    </div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op" value="parent_account_create">
      <input type="hidden" name="account_id" value="<?= (int)$guardian_acc['id'] ?>">
      <button class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Utwórz konto rodzica</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($authp_account): ?>
<!-- ── Edytor upoważnionych osób ──────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-person-check text-primary"></i>
    <span>Upoważnieni — <?= h($authp_account['client_name']) ?></span>
    <a href="accounts.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-arrow-left me-1"></i>Zamknij</a>
  </div>
  <div class="card-body">

    <?php if ($new_authp_creds): ?>
    <div class="alert alert-success d-flex gap-3 align-items-start">
      <i class="bi bi-check-circle-fill flex-shrink-0 fs-5 mt-1"></i>
      <div class="flex-grow-1">
        <div class="fw-semibold mb-1">Hasło wygenerowane — przekaż kartkę do podpisu</div>
        <div class="font-monospace bg-white border rounded p-2 small mb-2">
          Kursant: <strong><?= h($new_authp_creds['student_name']) ?></strong><br>
          Osoba upoważniona: <strong><?= h($new_authp_creds['name']) ?></strong><br>
          Login: <strong><?= h($new_authp_creds['login']) ?></strong><br>
          Hasło: <strong><?= h($new_authp_creds['pass']) ?></strong>
        </div>
        <?php if (!empty($new_authp_creds['emailed'])): ?>
        <div class="small text-success mb-2"><i class="bi bi-envelope-check me-1"></i>E-mail z informacją o upoważnieniu wysłany do osoby upoważnionej.</div>
        <?php endif; ?>
        <div class="d-flex gap-2 flex-wrap align-items-center">
          <a href="authp_print.php?id=<?= (int)$new_authp_creds['id'] ?>"
             target="_blank" class="btn btn-sm btn-primary">
            <i class="bi bi-printer me-1"></i>Drukuj kartkę dla osoby upoważnionej
          </a>
          <a href="authp_declaration.php?id=<?= (int)$new_authp_creds['id'] ?>"
             target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-file-earmark-text me-1"></i>Drukuj oświadczenie administratora
          </a>
          <span class="badge text-bg-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>Pamiętaj o wgraniu skanu po podpisaniu
          </span>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($authp_revoked_id):
      $rev_ap = db_one("SELECT p.*, cl.name AS student_name FROM k30_ti_authorized_persons p
                        JOIN k30_ti_student_accounts a ON a.id=p.student_account_id
                        JOIN k30_clients cl ON cl.id=a.client_id WHERE p.id=?", [$authp_revoked_id]); ?>
    <?php if ($rev_ap): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-3">
      <div class="fw-semibold mb-1"><i class="bi bi-slash-circle me-1"></i>Dostęp odwołany: <?= h($rev_ap['name']) ?></div>
      <div class="d-flex gap-2 flex-wrap align-items-center mt-2">
        <a href="authp_revoke_print.php?id=<?= (int)$authp_revoked_id ?>" target="_blank"
           class="btn btn-sm btn-warning">
          <i class="bi bi-printer me-1"></i>Drukuj dokument odwołania
        </a>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
          <?= csrf_field() ?>
          <input type="hidden" name="_op" value="authp_revoke_scan">
          <input type="hidden" name="account_id" value="<?= (int)$authp_account_id ?>">
          <input type="hidden" name="ap_id" value="<?= (int)$authp_revoked_id ?>">
          <input type="file" name="revoke_scan_file" accept="image/*,application/pdf"
                 class="form-control form-control-sm" style="max-width:220px">
          <button type="submit" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-cloud-upload me-1"></i>Wgraj skan odwołania
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($authp_list_admin): ?>
    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Imię i nazwisko</th><th>Login</th><th>E-mail</th><th>Uwagi</th>
            <th>Ostatnie log.</th><th>Status</th><th>Skan</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($authp_list_admin as $ap): ?>
          <tr class="<?= $ap['is_active'] ? '' : 'text-body-secondary' ?>">
            <td class="fw-semibold"><?= h($ap['name']) ?></td>
            <td class="font-monospace small"><?= h($ap['login']) ?></td>
            <td class="small"><?= h($ap['email']) ?: '—' ?></td>
            <td class="small"><?= h($ap['notes']) ?: '—' ?></td>
            <td class="small text-nowrap"><?= $ap['last_login'] ? h(date('d.m.Y H:i', strtotime($ap['last_login']))) : '—' ?></td>
            <td><span class="badge text-bg-<?= $ap['is_active'] ? 'success' : 'secondary' ?>"><?= $ap['is_active'] ? 'aktywna' : 'wstrzymana' ?></span></td>
            <td class="small">
              <?php if (!empty($ap['scan_path'])): ?>
                <?php $scan_url = rtrim(APP_URL,'/'). '/uploads/' . ltrim($ap['scan_path'],'/'); ?>
                <a href="<?= h($scan_url) ?>" target="_blank" class="btn btn-sm btn-outline-success py-0" title="Otwórz skan">
                  <i class="bi bi-file-earmark-check"></i>
                </a>
              <?php else: ?>
                <button type="button" class="btn btn-sm btn-outline-warning py-0"
                        onclick="document.getElementById('scan-form-<?= (int)$ap['id'] ?>').classList.toggle('d-none')"
                        title="Wgraj skan podpisanego dokumentu">
                  <i class="bi bi-upload"></i>
                </button>
                <form id="scan-form-<?= (int)$ap['id'] ?>" method="post" enctype="multipart/form-data"
                      class="d-none mt-1" style="min-width:200px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_op" value="authp_scan_upload">
                  <input type="hidden" name="ap_id" value="<?= (int)$ap['id'] ?>">
                  <input type="file" class="form-control form-control-sm mb-1" name="scan_file"
                         accept=".pdf,.png,.jpg,.jpeg" required>
                  <button type="submit" class="btn btn-sm btn-success py-0">
                    <i class="bi bi-cloud-upload me-1"></i>Zapisz
                  </button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <a href="authp_print.php?id=<?= (int)$ap['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-secondary py-0" title="Kartka dla osoby upoważnionej"><i class="bi bi-printer"></i></a>
                <a href="authp_declaration.php?id=<?= (int)$ap['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-secondary py-0" title="Oświadczenie administratora"><i class="bi bi-file-earmark-text"></i></a>
                <?php if (!$ap['is_active']): ?>
                <a href="authp_revoke_print.php?id=<?= (int)$ap['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-warning py-0" title="Dokument odwołania"><i class="bi bi-file-earmark-x"></i></a>
                <?php endif; ?>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_op" value="authp_toggle">
                  <input type="hidden" name="account_id" value="<?= $authp_account_id ?>">
                  <input type="hidden" name="ap_id" value="<?= (int)$ap['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-secondary py-0" title="<?= $ap['is_active'] ? 'Wstrzymaj' : 'Aktywuj' ?>">
                    <i class="bi bi-<?= $ap['is_active'] ? 'pause' : 'play' ?>-fill"></i>
                  </button>
                </form>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_op" value="authp_reset_pass">
                  <input type="hidden" name="account_id" value="<?= $authp_account_id ?>">
                  <input type="hidden" name="ap_id" value="<?= (int)$ap['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-warning py-0" title="Resetuj hasło"><i class="bi bi-key"></i></button>
                </form>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć upoważnienie dla <?= h(addslashes($ap['name'])) ?>?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_op" value="authp_delete">
                  <input type="hidden" name="account_id" value="<?= $authp_account_id ?>">
                  <input type="hidden" name="ap_id" value="<?= (int)$ap['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger py-0" title="Usuń"><i class="bi bi-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <p class="text-body-secondary small mb-3">Brak upoważnionych osób dla tego kursanta.</p>
    <?php endif; ?>

    <form method="post" class="border-top pt-3 mt-1">
      <?= csrf_field() ?>
      <input type="hidden" name="_op" value="authp_add">
      <input type="hidden" name="account_id" value="<?= $authp_account_id ?>">
      <div class="row g-2">
        <div class="col-md-5">
          <label class="form-label small fw-semibold" for="apn-name">Imię i nazwisko *</label>
          <input type="text" class="form-control form-control-sm" id="apn-name" name="ap_name" maxlength="100" required
                 placeholder="np. Anna Kowalska" autofocus>
          <div class="form-text">Login zostanie wygenerowany automatycznie.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold" for="apn-email">E-mail (do powiadomienia)</label>
          <input type="email" class="form-control form-control-sm" id="apn-email" name="ap_email" maxlength="150" placeholder="anna@example.com">
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold" for="apn-notes">Stosunek do kursanta</label>
          <input type="text" class="form-control form-control-sm" id="apn-notes" name="ap_notes" maxlength="300" placeholder="np. opiekun, rodzic">
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold" for="apn-reason">Powód upoważnienia *</label>
          <input type="text" class="form-control form-control-sm" id="apn-reason" name="ap_reason" maxlength="400" required
                 placeholder="np. Kursant przebywa za granicą i upoważnił opiekuna do kontrolowania postępów">
        </div>
      </div>
      <button type="submit" class="btn btn-sm btn-primary mt-2">
        <i class="bi bi-person-plus me-1"></i>Dodaj i wygeneruj login + hasło
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">

  <!-- Utwórz konto -->
  <?php if ($no_account): ?>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-person-plus me-2 text-success"></i>Utwórz konto</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Login i hasło generowane automatycznie.
          Kursant ma dostęp <strong>tylko</strong> do panelu kursanta.
        </p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"   value="create">
          <div class="mb-3">
            <label class="form-label fw-semibold">Beneficjent <span class="text-danger">*</span></label>
            <select class="form-select" name="client_id" required>
              <option value="">— wybierz —</option>
              <?php foreach ($no_account as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-success w-100">
            <i class="bi bi-person-plus me-1"></i>Utwórz konto
          </button>
        </form>

        <hr class="my-3">

        <button type="button" class="btn btn-outline-success w-100" data-bs-toggle="modal" data-bs-target="#bulkCreateModal">
          <i class="bi bi-people me-1"></i>Utwórz zbiorczo (<?= count($no_account) ?>)
        </button>
      </div>
    </div>
  </div>

  <!-- Modal: zbiorcze tworzenie kont -->
  <div class="modal fade" id="bulkCreateModal" tabindex="-1" aria-labelledby="bulkCreateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <form method="post" class="modal-content">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"   value="bulk_create">
        <div class="modal-header">
          <h2 class="modal-title h5" id="bulkCreateModalLabel"><i class="bi bi-people text-success me-2" aria-hidden="true"></i>Utwórz konta zbiorczo</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mt-0 mb-2">Zaznacz beneficjentów — dla każdego powstanie konto z loginem i hasłem (pokazane raz).</p>
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="bulk_all"
                   onclick="var v=this.checked;document.querySelectorAll('.bulk-cb').forEach(function(c){c.checked=v});">
            <label class="form-check-label small fw-semibold" for="bulk_all">Zaznacz wszystkich (<?= count($no_account) ?>)</label>
          </div>
          <div class="border rounded p-2 mb-2" style="max-height:320px;overflow:auto">
            <?php foreach ($no_account as $c): ?>
            <div class="form-check">
              <input class="form-check-input bulk-cb" type="checkbox" name="client_ids[]" value="<?= (int)$c['id'] ?>" id="bc<?= (int)$c['id'] ?>">
              <label class="form-check-label small" for="bc<?= (int)$c['id'] ?>"><?= h($c['name']) ?></label>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="send_sms" id="bulk_sms" checked>
            <label class="form-check-label small" for="bulk_sms">Wyślij dane SMS-em (gdy jest numer telefonu)</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-people me-1" aria-hidden="true"></i>Utwórz zaznaczonym</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Lista kont -->
  <div class="col-lg-<?= $no_account ? '8' : '12' ?>">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center">
        <i class="bi bi-people me-2 text-primary"></i>Konta kursantów
        <span class="badge bg-secondary ms-2"><?= count($accounts) ?></span>
      </div>
      <?php if (!$accounts): ?>
      <div class="card-body text-muted">Brak kont. Utwórz pierwsze konto dla beneficjenta.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
          <thead class="table-light">
            <tr><th>Beneficjent</th><th>Nr kursanta</th><th>Login</th><th>Status</th><th>Nauka online</th><th>Ostatnie logowanie</th><th class="text-end">Akcje</th></tr>
          </thead>
          <tbody>
            <?php foreach ($accounts as $a): ?>
            <tr class="<?= $a['is_active'] ? '' : 'opacity-50' ?>">
              <td class="fw-semibold"><?= h($a['client_name']) ?></td>
              <td>
                <form method="post" class="d-flex gap-1">
                  <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op"         value="set_no">
                  <input type="hidden" name="account_id"  value="<?= (int)$a['id'] ?>">
                  <label class="visually-hidden" for="no<?= (int)$a['id'] ?>">Numer kursanta — <?= h($a['client_name']) ?></label>
                  <input type="text" id="no<?= (int)$a['id'] ?>" name="student_no" value="<?= h($a['student_no'] ?? '') ?>"
                         class="form-control form-control-sm font-monospace py-0" style="width:84px" placeholder="—">
                  <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Zapisz numer kursanta" aria-label="Zapisz numer kursanta"><i class="bi bi-save" aria-hidden="true"></i></button>
                </form>
              </td>
              <td>
                <div class="font-monospace"><?= h($a['login']) ?></div>
                <?php if (!empty($a['login_alias'])): ?>
                <div class="small text-info" title="Alias ustawiony przez kursanta"><i class="bi bi-arrow-return-right" aria-hidden="true"></i> <?= h($a['login_alias']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $a['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                  <?= $a['is_active'] ? 'Aktywne' : 'Zablokowane' ?>
                </span>
                <?php if (!empty($a['is_minor'])): ?>
                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" title="Małoletni — rozliczenia dla rodzica">
                  <i class="bi bi-people"></i> małoletni
                </span>
                <?php endif; ?>
                <?php if (!empty($a['child_access_blocked'])): ?>
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" title="Logowanie kursanta wstrzymane przez opiekuna">
                  <i class="bi bi-lock-fill"></i> wstrzymany przez opiekuna
                </span>
                <?php endif; ?>
              </td>
              <td style="min-width:160px">
                <?php $has_ms = !empty($a['ms_user_id']); $has_moodle = !empty($a['moodle_user_id']); ?>
                <?php if (!$ms_online_enabled && !$moodle_online_enabled): ?>
                <span class="text-muted small">moduł wyłączony</span>
                <?php else: ?>
                <div class="d-flex flex-column gap-1">
                  <?php if ($ms_online_enabled): ?>
                  <?php if ($has_ms): ?>
                  <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle text-truncate" style="max-width:100%" title="<?= h($a['ms_upn']) ?>">
                    <i class="bi bi-microsoft" aria-hidden="true"></i> <span class="font-monospace"><?= h($a['ms_upn']) ?></span>
                  </span>
                  <?php else: ?>
                  <span class="badge bg-secondary-subtle text-secondary-emphasis border">Brak konta MS</span>
                  <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($moodle_online_enabled): ?>
                  <?php if ($has_moodle): ?>
                  <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle text-truncate" style="max-width:100%" title="<?= h($a['moodle_username']) ?>">
                    <i class="bi bi-mortarboard" aria-hidden="true"></i> <span class="font-monospace"><?= h($a['moodle_username']) ?></span>
                  </span>
                  <?php elseif ($has_ms): ?>
                  <span class="badge bg-secondary-subtle text-secondary-emphasis border">Brak konta Moodle</span>
                  <?php else: ?>
                  <span class="text-muted small">Moodle wymaga konta MS</span>
                  <?php endif; ?>
                  <?php endif; ?>
                </div>
                <?php endif; ?>
              </td>
              <td class="text-muted"><?= $a['last_login'] ? date('d.m.Y H:i', strtotime($a['last_login'])) : '—' ?></td>
              <td class="text-end">
                <?php $aname = h($a['client_name']); ?>
                <div class="dropdown">
                  <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
                          data-acct-menu data-bs-toggle="dropdown" aria-expanded="false"
                          aria-label="Działania dla kursanta <?= $aname ?>">
                    <i class="bi bi-three-dots-vertical" aria-hidden="true"></i><span class="d-none d-xl-inline ms-1">Działania</span>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li><h6 class="dropdown-header"><?= $aname ?></h6></li>

                    <?php if ($a['is_active']): ?>
                    <li>
                      <form method="post" target="_blank" onsubmit="return confirm('Otworzyć panel kursanta jako ten użytkownik? Twoja sesja administratora pozostanie aktywna w tej karcie.')">
                        <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="_op"        value="impersonate">
                        <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-in-right me-2 text-primary" aria-hidden="true"></i>Zaloguj jako kursant <span class="text-body-secondary small">(podgląd)</span></button>
                      </form>
                    </li>
                    <?php endif; ?>
                    <li><a class="dropdown-item" href="../messages.php?student=<?= (int)$a['id'] ?>"><i class="bi bi-envelope me-2" aria-hidden="true"></i>Wyślij wiadomość</a></li>
                    <li><a class="dropdown-item" href="?guardian=<?= (int)$a['id'] ?>"><i class="bi bi-people me-2 text-info" aria-hidden="true"></i>Opiekun / dostęp rodzica</a></li>
                    <?php if (empty($a['is_minor'])): ?>
                    <li><a class="dropdown-item" href="?authp=<?= (int)$a['id'] ?>"><i class="bi bi-person-check me-2 text-primary" aria-hidden="true"></i>Osoby upoważnione</a></li>
                    <?php endif; ?>

                    <?php foreach ([2, 3] as $pn):
                      $pval = trim((string)($a["notify_phone{$pn}"] ?? ''));
                      if ($pval === '' || !empty($a["notify_phone{$pn}_verified"])) continue;
                    ?>
                    <li>
                      <form method="post" onsubmit="return confirm('Zatwierdzić numer <?= h($pval) ?> do powiadomień SMS tego kursanta? Rób to tylko, gdy masz pewność, że numer należy do kursanta lub jego opiekuna.')">
                        <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="_op"        value="approve_notify_phone">
                        <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                        <input type="hidden" name="which"      value="<?= $pn ?>">
                        <button type="submit" class="dropdown-item">
                          <i class="bi bi-shield-check me-2 text-warning" aria-hidden="true"></i>Zatwierdź numer SMS <?= $pn === 2 ? 'drugi' : 'trzeci' ?>
                          <span class="text-body-secondary small">(<?= h($pval) ?>)</span>
                        </button>
                      </form>
                    </li>
                    <?php endforeach; ?>

                    <?php if (!empty($a['login_alias'])): ?>
                    <li>
                      <form method="post" onsubmit="return confirm('Usunąć alias logowania tego kursanta?')">
                        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="_op" value="clear_alias">
                        <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="dropdown-item"><i class="bi bi-x-circle me-2 text-secondary" aria-hidden="true"></i>Usuń alias logowania <span class="text-body-secondary small">(<?= h($a['login_alias']) ?>)</span></button>
                      </form>
                    </li>
                    <?php endif; ?>

                    <?php if ($ms_online_enabled || $moodle_online_enabled): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header">Nauka online</h6></li>
                    <?php if ($ms_online_enabled): ?>
                    <li>
                      <form method="post" <?= $has_ms ? "onsubmit=\"return confirm('Usunąć konto Microsoft tego kursanta?')\"" : '' ?>>
                        <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="_op"        value="<?= $has_ms ? 'ms_delete' : 'ms_create' ?>">
                        <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="dropdown-item <?= $has_ms ? 'text-danger' : '' ?>">
                          <i class="bi bi-microsoft me-2 <?= $has_ms ? 'text-danger' : 'text-primary' ?>" aria-hidden="true"></i><?= $has_ms ? 'Usuń konto Microsoft' : 'Utwórz konto Microsoft' ?>
                        </button>
                      </form>
                    </li>
                    <?php endif; ?>
                    <?php if ($moodle_online_enabled && !$has_moodle && $has_ms): ?>
                    <li>
                      <form method="post">
                        <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="_op"        value="moodle_create">
                        <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="dropdown-item"><i class="bi bi-mortarboard me-2 text-success" aria-hidden="true"></i>Utwórz konto Moodle</button>
                      </form>
                    </li>
                    <?php endif; ?>
                    <?php endif; ?>

                    <li><hr class="dropdown-divider"></li>

                    <li>
                      <form method="post" onsubmit="return confirm('Zresetować hasło?')">
                        <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="_op"        value="reset_pass">
                        <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="dropdown-item"><i class="bi bi-key me-2 text-warning" aria-hidden="true"></i>Resetuj hasło</button>
                      </form>
                    </li>
                    <li>
                      <form method="post">
                        <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="_op"        value="toggle">
                        <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="dropdown-item">
                          <i class="bi <?= $a['is_active'] ? 'bi-lock' : 'bi-unlock text-success' ?> me-2" aria-hidden="true"></i><?= $a['is_active'] ? 'Zablokuj konto' : 'Odblokuj konto' ?>
                        </button>
                      </form>
                    </li>
                    <?php if (!empty($a['child_access_blocked'])): ?>
                    <li>
                      <form method="post" onsubmit="return confirm('Przywrócić kursantowi dostęp do panelu? Dostęp został wstrzymany przez opiekuna.')">
                        <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="_op"        value="child_unblock">
                        <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="dropdown-item"><i class="bi bi-unlock text-success me-2" aria-hidden="true"></i>Odblokuj dostęp <span class="text-body-secondary small">(wstrzymany przez opiekuna)</span></button>
                      </form>
                    </li>
                    <?php endif; ?>

                    <li><hr class="dropdown-divider"></li>

                    <li>
                      <form method="post" onsubmit="return confirm('Usunąć konto „<?= h(addslashes($a['client_name'])) ?>”? Tej operacji nie można cofnąć.')">
                        <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="_op"        value="delete">
                        <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2" aria-hidden="true"></i>Usuń konto</button>
                      </form>
                    </li>
                  </ul>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
// Menu działań w wierszu: Popper ze strategią 'fixed', aby rozwijane menu nie
// było obcinane przez overflow kontenera .table-responsive.
(function(){
  if (typeof bootstrap === 'undefined') return;
  document.querySelectorAll('[data-acct-menu]').forEach(function(el){
    bootstrap.Dropdown.getOrCreateInstance(el, {
      popperConfig: function(defaultConfig){
        return Object.assign({}, defaultConfig, { strategy: 'fixed' });
      }
    });
  });
})();
</script>

<?php include dirname(dirname(dirname(__DIR__))) . '/karty30/includes/footer_k30.php'; ?>
