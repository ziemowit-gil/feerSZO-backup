<?php
/**
 * karty30/ti/dydaktyk/konta.php — Zarządzanie kontami kursantów (panel kierownika).
 *
 * Przeniesione z modułu administracyjnego (karty30/ti/kursant/accounts.php, teraz
 * przekierowanie) — wzorzec jak urlopy.php/klienci.php. dyd_is_staff() zastępuje
 * dawne is_admin()/can_write('karty30') — kierownik nie ma konta SZO.
 * Obejmuje też upoważnienia („osoby upoważnione" — k30_ti_authorized_persons),
 * konta rodzica/opiekuna i konta Microsoft 365 (nauka online).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_online.php'; // konta MS
require_once dirname(__DIR__) . '/kursant/auth.php'; // parent_make_token(), student_impersonate()
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_referrals.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_payment_account_schema.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/payment_portal/logic/paymentPortal.php'; // rachunek wirtualny z nr kursanta
require_once dirname(dirname(dirname(__DIR__))) . '/modules/sprawdz_konto/logic/sprawdz_konto.php';

$me       = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '') ?: 'Kierownik';
karty30_migrate();

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
 * Generuje 8-znakowe hasło serwisowe (litery + cyfry, bez znaków mylących się
 * wizualnie: 0/O, 1/l/I) — do przekazania telefonicznie/SMS-em i ustawienia
 * jednocześnie jako hasło lokalne panelu i hasło konta Microsoft (jeśli istnieje).
 */
function _gen_service_password(): string {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $pass  = '';
    for ($i = 0; $i < 8; $i++) $pass .= $chars[random_int(0, strlen($chars) - 1)];
    return $pass;
}

/**
 * Czy 12-cyfrowy numer jest już częścią NNNN rachunku wirtualnego (portal płatności)
 * innego uczestnika — wtedy nie wolno go nadać kursantowi (pomyłka przy księgowaniu wpłat).
 */
function _student_no_nrb_conflict(string $no, int $client_id = 0): bool {
    if (!preg_match('/^\d{12}$/', $no)) return false;
    try {
        return (bool)db_one("SELECT 1 FROM payment_portal_users WHERE substr(individual_nrb,15,12)=? AND participant_id!=?", [$no, $client_id]);
    } catch (\Throwable $e) { return false; }   // tabela portalu jeszcze nie istnieje
}

/** Zapisuje rachunek wirtualny kursanta w portalu płatności (bez powiadomień; błąd nie blokuje). */
function _student_sync_portal_nrb(int $client_id): void {
    try { pp_ti_sync_nrb($client_id, 'konta TI', null); } catch (\Throwable $e) {}
}

/** Generuje unikalny 12-cyfrowy numer identyfikacyjny kursanta */
function _gen_student_no(int $client_id = 0): string {
    do {
        $no = '';
        for ($i = 0; $i < 12; $i++) $no .= random_int(0, 9);
    } while (db_one("SELECT id FROM k30_ti_student_accounts WHERE student_no=?", [$no]) || _student_no_nrb_conflict($no, $client_id));
    return $no;
}

/**
 * Samonaprawa: konta z numerem innym niż 12 cyfr (puste, ręcznie wpisane w innym
 * formacie, albo sprzed wprowadzenia automatycznego generowania) dostają nowy,
 * unikalny numer. Tanie przy pustym wyniku — bezpieczne do wołania co żądanie.
 */
function _fix_invalid_student_no(): void {
    $bad = db_all(
        "SELECT id FROM k30_ti_student_accounts WHERE length(student_no) != 12 OR student_no GLOB '*[^0-9]*'"
    );
    foreach ($bad as $row) {
        db()->prepare("UPDATE k30_ti_student_accounts SET student_no=?, updated_at=datetime('now') WHERE id=?")
           ->execute([_gen_student_no(), (int)$row['id']]);
    }
}
_fix_invalid_student_no();

/**
 * Wysyła dane logowania do panelu kursanta SMS-em (jeśli SMS włączony i jest numer).
 * Zwraca dopisek do komunikatu flash informujący o statusie wysyłki.
 */
function _student_send_login_sms(string $phone, string $login, string $pass): string {
    $phone = trim($phone);
    if ($phone === '') return ' (brak numeru telefonu — przekaż hasło ręcznie)';
    if (!sms_channel_ready()) return ' (SMS wyłączony — przekaż hasło ręcznie)';
    $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';
    $msg = "{$org} - panel kursanta. Login: {$login}, haslo: {$pass}";
    try {
        sms_send($phone, $msg);
        return ' Hasło wysłano SMS-em.';
    } catch (\Throwable $e) {
        return ' (błąd wysyłki SMS: ' . $e->getMessage() . ')';
    }
}

function _authp_gen_pass_admin(): string {
    $w = ['Kotek','Rower','Zamek','Kwiat','Statek','Zegar','Obraz','Lampa'];
    return $w[array_rand($w)] . random_int(100, 999);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'clear_alias') {
        $aid = (int)($_POST['account_id'] ?? 0);
        if ($aid) {
            db()->prepare("UPDATE k30_ti_student_accounts SET login_alias='', updated_at=datetime('now') WHERE id=?")
               ->execute([$aid]);
            flash_set('success', 'Alias logowania usunięty.');
        }
        header('Location: konta.php?selected=' . $aid); exit;
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
        header('Location: konta.php?selected=' . $aid); exit;
    }

    if ($op === 'create') {
        $cid  = (int)($_POST['client_id'] ?? 0);
        $c    = $cid ? db_one("SELECT * FROM k30_clients WHERE id=?", [$cid]) : null;
        if (!$c) { flash_set('danger','Wybierz beneficjenta.'); header('Location: konta.php'); exit; }

        // Wygeneruj unikalny login
        $base  = _gen_student_login($c['name']);
        $login = $base;
        $i     = 2;
        while (db_one("SELECT id FROM k30_ti_student_accounts WHERE login=?", [$login])) {
            $login = $base . $i++;
        }
        $pass  = _gen_student_pass();
        $hash  = password_hash($pass, PASSWORD_BCRYPT);

        $new_id = db_insert('k30_ti_student_accounts', [
            'client_id'     => $cid,
            'login'         => $login,
            'password_hash' => $hash,
            'student_no'    => _gen_student_no(),
            'is_active'     => 1,
            'must_change_password' => 1, // kursant ustawi własne hasło przy pierwszym logowaniu
            'created_by'    => $uid,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        _student_sync_portal_nrb((int)$cid);

        // Kod polecający (opcjonalnie) — rabat dla obu stron, patrz ti_referrals.php
        $ref_code = trim((string)($_POST['referral_code'] ?? ''));
        $ref_msg  = '';
        if ($ref_code !== '') {
            $ref = ti_referral_redeem($ref_code, $cid, $uid);
            $ref_msg = $ref['ok']
                ? " Kod polecający przyjęty — polecił {$ref['referrer_name']}."
                : ' Kod polecający: ' . $ref['error'];
        }

        // Pokaż hasło raz w sesji
        $_SESSION['new_student_creds'] = ['login' => $login, 'password' => $pass, 'name' => $c['name']];
        $sms = _student_send_login_sms($c['phone'] ?? '', $login, $pass);
        flash_set('success', "Konto kursanta dla {$c['name']} utworzone. Login: {$login}." . $sms . $ref_msg);
        header('Location: konta.php?selected=' . $new_id); exit;
    }

    // Zbiorcze tworzenie kont panelu dla wielu beneficjentów naraz
    if ($op === 'bulk_create') {
        $ids = array_values(array_unique(array_map('intval', (array)($_POST['client_ids'] ?? []))));
        if (!$ids) { flash_set('danger','Zaznacz co najmniej jednego beneficjenta.'); header('Location: konta.php'); exit; }

        $existing = array_map('intval', array_column(db_all("SELECT client_id FROM k30_ti_student_accounts"), 'client_id'));
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
                'student_no'    => _gen_student_no(),
                'is_active'     => 1,
                'must_change_password' => 1,
                'created_by'    => $uid,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            _student_sync_portal_nrb((int)$cid);
            $existing[] = $cid;
            $sms = $send_sms ? _student_send_login_sms($c['phone'] ?? '', $login, $pass) : '';
            $rows[] = ['name' => $c['name'], 'login' => $login, 'password' => $pass, 'sms' => trim($sms)];
        }

        $_SESSION['bulk_student_creds'] = ['rows' => $rows, 'ts' => time()];
        flash_set('success', 'Utworzono kont: ' . count($rows) . ($skipped ? " (pominięto już istniejące: {$skipped})" : '') . '.');
        header('Location: konta.php'); exit;
    }

    if ($op === 'reset_pass') {
        $aid  = (int)($_POST['account_id'] ?? 0);
        $acc  = $aid ? db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if (!$acc) { flash_set('danger','Konto nie istnieje.'); header('Location: konta.php'); exit; }
        $pass = _gen_student_pass();
        db()->prepare("UPDATE k30_ti_student_accounts SET password_hash=?, must_change_password=1, updated_at=datetime('now') WHERE id=?")
           ->execute([password_hash($pass, PASSWORD_BCRYPT), $aid]);
        $c = db_one("SELECT name, phone FROM k30_clients WHERE id=?", [$acc['client_id']]);
        $_SESSION['new_student_creds'] = ['login' => $acc['login'], 'password' => $pass, 'name' => $c['name'] ?? ''];
        $sms = _student_send_login_sms($c['phone'] ?? '', $acc['login'], $pass);
        flash_set('success', "Hasło zresetowane dla {$acc['login']}." . $sms);
        header('Location: konta.php?selected=' . $aid); exit;
    }

    // Hasło serwisowe: stałe 8-znakowe hasło ustawiane RAZEM jako hasło lokalne
    // panelu kursanta i (jeśli kursant ma konto MS) hasło jego konta Microsoft —
    // by nie miał dwóch różnych haseł do zapamiętania.
    if ($op === 'service_pass') {
        $aid  = (int)($_POST['account_id'] ?? 0);
        $acc  = $aid ? db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if (!$acc) { flash_set('danger','Konto nie istnieje.'); header('Location: konta.php'); exit; }

        $pass = _gen_service_password();
        db()->prepare("UPDATE k30_ti_student_accounts SET password_hash=?, must_change_password=1, updated_at=datetime('now') WHERE id=?")
           ->execute([password_hash($pass, PASSWORD_BCRYPT), $aid]);

        $ms_msg = '';
        if (!empty($acc['ms_user_id'])) {
            try {
                m365_training()->set_password($acc['ms_user_id'], $pass, true);
                $ms_msg = ' Nadpisano też hasło konta Microsoft (' . $acc['ms_upn'] . ').';
            } catch (\Throwable $e) {
                $ms_msg = ' UWAGA: nie udało się nadpisać hasła konta Microsoft: ' . $e->getMessage();
            }
        }

        $c = db_one("SELECT name, phone FROM k30_clients WHERE id=?", [$acc['client_id']]);
        $_SESSION['new_student_creds'] = ['login' => $acc['login'], 'password' => $pass, 'name' => $c['name'] ?? ''];
        $sms = _student_send_login_sms($c['phone'] ?? '', $acc['login'], $pass);
        flash_set('success', "Hasło serwisowe ustawione dla {$acc['login']}." . $sms . $ms_msg);
        header('Location: konta.php?selected=' . $aid); exit;
    }

    // Nadanie / zmiana numeru kursanta (przez kierownika)
    if ($op === 'set_no') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $no  = trim($_POST['student_no'] ?? '');
        $cid = (int)(db_one("SELECT client_id FROM k30_ti_student_accounts WHERE id=?", [$aid])['client_id'] ?? 0);
        if ($aid && _student_no_nrb_conflict($no, $cid)) {
            flash_set('danger', 'Ten numer jest już użyty w rachunku wirtualnym innego uczestnika (portal płatności) — wybierz inny.');
        } elseif ($aid) {
            db()->prepare("UPDATE k30_ti_student_accounts SET student_no=?, updated_at=datetime('now') WHERE id=?")
               ->execute([mb_substr($no, 0, 40), $aid]);
            if ($no !== '' && $cid && pp_user($cid)) _student_sync_portal_nrb($cid);   // portal ma już dostęp → nowy rachunek
            flash_set('success', $no !== '' ? 'Numer kursanta zapisany.' : 'Numer kursanta usunięty.');
        }
        header('Location: konta.php?selected=' . $aid); exit;
    }

    if ($op === 'toggle') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]);
        if ($acc) {
            db()->prepare("UPDATE k30_ti_student_accounts SET is_active=?, updated_at=datetime('now') WHERE id=?")
               ->execute([$acc['is_active'] ? 0 : 1, $aid]);
        }
        header('Location: konta.php?selected=' . $aid); exit;
    }

    if ($op === 'toggle_virtual') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]);
        if ($acc) {
            $v = empty($acc['is_virtual']) ? 1 : 0;
            db()->prepare("UPDATE k30_ti_student_accounts SET is_virtual=?, updated_at=datetime('now') WHERE id=?")->execute([$v, $aid]);
            flash_set('success', $v ? 'Kursant oznaczony jako wirtualny — nie dostanie rachunku wirtualnego (nadany wcześniej numer zostaje bez zmian).'
                                    : 'Kursant nie jest już wirtualny — może dostać rachunek z puli.');
        }
        header('Location: konta.php?selected=' . $aid); exit;
    }

    if ($op === 'delete') {
        $aid = (int)($_POST['account_id'] ?? 0);
        db()->prepare("DELETE FROM k30_ti_student_accounts WHERE id=?")->execute([$aid]);
        flash_set('success','Konto usunięte.');
        header('Location: konta.php'); exit;
    }

    // Odblokowanie dostępu wstrzymanego przez opiekuna (interwencja kierownika)
    if ($op === 'child_unblock') {
        $aid = (int)($_POST['account_id'] ?? 0);
        db()->prepare("UPDATE k30_ti_student_accounts SET child_access_blocked=0, updated_at=datetime('now') WHERE id=?")
           ->execute([$aid]);
        flash_set('success','Dostęp kursanta do panelu został przywrócony.');
        header('Location: konta.php?selected=' . $aid); exit;
    }

    // ── Podszywanie się pod kursanta („zaloguj jako") ────────────────────────
    if ($op === 'impersonate') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if (!$acc || empty($acc['is_active'])) {
            flash_set('danger', 'Konto nie istnieje lub jest nieaktywne.');
            header('Location: konta.php?selected=' . $aid); exit;
        }
        // Zamknij sesję panelu kierownika, otwórz osobną sesję kursanta (k30_student).
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        student_impersonate($acc, $uid, $dyd_name);
        header('Location: ' . rtrim(APP_URL, '/') . '/karty30/ti/kursant/index.php');
        exit;
    }

    // ── Konto Microsoft 365 (tenant szkoleniowy) ─────────────────────────────
    if ($op === 'ms_create') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $res = ti_ms_provision($aid);
        if ($res['ok']) {
            $_SESSION['new_ms_creds'] = ['upn' => $res['upn'] ?? '', 'password' => $res['password'] ?? ''];
            flash_set('success', 'Konto Microsoft utworzone: ' . ($res['upn'] ?? ''));
        } else {
            flash_set('danger', $res['msg']);
        }
        header('Location: konta.php?selected=' . $aid); exit;
    }

    if ($op === 'ms_delete') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $res = ti_ms_delete($aid);
        flash_set($res['ok'] ? 'success' : 'danger', $res['msg']);
        header('Location: konta.php?selected=' . $aid); exit;
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
        header('Location: konta.php?guardian=' . $aid); exit;
    }

    // Zapis danych sekcji Płatności: nadpłata do końca roku (kierownik) + dozwolone
    // metody płatności (admin) — ten sam ekran obsługuje oba, bo panel kierownika
    // nie rozróżnia ról w praktyce (dyd_is_staff() = admin SZO LUB rola panelu
    // kierownik/zastępca). Numer konta do wpłat ma OSOBNĄ akcję z krokiem
    // zatwierdzenia — patrz payment_account_confirm poniżej.
    if ($op === 'overpay_save') {
        $aid = (int)($_POST['account_id'] ?? 0);
        if ($aid) {
            $methods = array_values(array_intersect(
                ['stripe', 'payu', 'p24', 'transfer'],
                (array)($_POST['allowed_methods'] ?? [])
            ));
            db()->prepare(
                "UPDATE k30_ti_student_accounts
                 SET allow_year_end_overpay=?, allowed_payment_methods=?, updated_at=datetime('now')
                 WHERE id=?"
            )->execute([
                isset($_POST['allow_year_end_overpay']) ? 1 : 0,
                implode(',', $methods),
                $aid,
            ]);
            flash_set('success', 'Ustawienia płatności zapisane.');
        }
        header('Location: konta.php?selected=' . $aid); exit;
    }

    // Zatwierdzenie numeru konta do wpłat za zajęcia — krok weryfikacyjny
    // (modal podglądu) przed zapisem. Loguje historię zmian i wysyła e-mail
    // do kursanta (i opiekuna, jeśli małoletni) z zatwierdzonym numerem.
    if ($op === 'payment_account_confirm') {
        $aid    = (int)($_POST['account_id'] ?? 0);
        $source = ($_POST['pay_source'] ?? '') === 'org' ? 'org' : 'custom';
        $acc = $aid ? db_one(
            "SELECT a.*, cl.name AS client_name, cl.email AS client_email
             FROM k30_ti_student_accounts a
             JOIN k30_clients cl ON cl.id = a.client_id WHERE a.id = ?", [$aid]
        ) : null;

        if (!$acc) { flash_set('danger', 'Konto kursanta nie istnieje.'); header('Location: konta.php'); exit; }

        $new_account = '';
        $type_label  = '';
        if ($source === 'org') {
            $org_idx = (int)($_POST['org_account_index'] ?? -1);
            $org_acc = null;
            foreach (k30_ti_org_accounts_list() as $oa) {
                if ($oa['index'] === $org_idx) { $org_acc = $oa; break; }
            }
            if (!$org_acc) { flash_set('danger', 'Wybrany rachunek organizacji nie istnieje.'); header('Location: konta.php?selected=' . $aid); exit; }
            $new_account = $org_acc['nrb'];
            $type_label  = 'Rachunek organizacji: ' . $org_acc['label'] . ($org_acc['dla_ti'] ? ' (dla TI)' : '');
        } else {
            $new_account = mb_substr(trim($_POST['custom_account'] ?? ''), 0, 40);
            $type_label  = 'Numer indywidualny (niestandardowy)';
            if ($new_account === '') { flash_set('danger', 'Podaj numer konta.'); header('Location: konta.php?selected=' . $aid); exit; }
        }

        $old_account = (string)($acc['payment_bank_account'] ?? '');

        db()->prepare(
            "UPDATE k30_ti_student_accounts
             SET payment_bank_account=?, payment_bank_account_source=?, updated_at=datetime('now')
             WHERE id=?"
        )->execute([$new_account, $source, $aid]);

        $emailed = false;
        $to_addrs = [];
        if (!empty($acc['client_email']) && filter_var($acc['client_email'], FILTER_VALIDATE_EMAIL)) {
            $to_addrs[$acc['client_email']] = $acc['client_name'];
        }
        if (!empty($acc['is_minor']) && !empty($acc['guardian_email']) && filter_var($acc['guardian_email'], FILTER_VALIDATE_EMAIL)) {
            $to_addrs[$acc['guardian_email']] = $acc['guardian_name'] ?? '';
        }
        if ($to_addrs) {
            require_once dirname(dirname(dirname(__DIR__))) . '/includes/mail_queue.php';
            require_once dirname(dirname(dirname(__DIR__))) . '/includes/email_templates.php';
            $org   = defined('ORG_NAME') ? ORG_NAME : 'Dydaktyka TI';
            $token = sprawdz_konto_create_token('kursant', 'ti', $aid, $uid);
            $rendered = email_tpl_render('ti_payment_account', [
                'accent'      => '#0d6efd',
                'osoba'       => $acc['client_name'],
                'numer_konta' => $new_account,
                'typ_konta'   => $type_label,
                'link'        => sprawdz_konto_token_url($token),
                'org'         => $org,
            ]);
            if ($rendered['enabled']) {
                try {
                    foreach ($to_addrs as $addr => $name) {
                        mail_queue_add($addr, (string)$name, $rendered['subject'], $rendered['html'], '', 'ti_payment_account', $aid);
                    }
                    $emailed = true;
                } catch (\Throwable $e) {}
            }
        }

        db_insert('k30_ti_payment_account_log', [
            'student_account_id' => $aid,
            'old_account'        => $old_account,
            'new_account'        => $new_account,
            'source'             => $source,
            'changed_by'         => $uid,
            'changed_by_name'    => $dyd_name,
            'emailed'            => $emailed ? 1 : 0,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        flash_set('success', 'Numer konta zatwierdzony.' . ($emailed ? ' Kursant otrzymał powiadomienie e-mail.' : ' Nie wysłano e-maila (brak poprawnego adresu kursanta).'));
        header('Location: konta.php?selected=' . $aid); exit;
    }

    // Ponowna wysyłka linku do sprawdzarki numeru konta, BEZ zmiany samego
    // numeru — np. gdy kursant/opiekun zgubił poprzedni e-mail.
    if ($op === 'payment_account_resend_link') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $ok  = $aid ? sprawdz_konto_resend_kursant_link($aid, $uid) : false;
        flash_set($ok ? 'success' : 'danger', $ok
            ? 'Link do sprawdzarki numeru konta wysłany ponownie.'
            : 'Nie udało się wysłać linku — sprawdź, czy kursant ma ustawiony numer konta i poprawny adres e-mail.');
        header('Location: konta.php?selected=' . $aid); exit;
    }

    // Licencje na oprogramowanie (inne niż MS365) — przypisanie/cofnięcie wprost
    // z panelu akcji konta kursanta (ta sama baza co katalog/pełny widok w
    // licencje.php — ekran kierownika, bez konta SZO — i w admin/licencje_admin.php).
    if ($op === 'license_assign') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one("SELECT client_id FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        $license_id = (int)($_POST['license_id'] ?? 0);
        if (!$acc || !$license_id) {
            flash_set('danger', 'Wybierz oprogramowanie.');
            header('Location: konta.php?selected=' . $aid); exit;
        }
        $exp = trim($_POST['expires_at'] ?? '');
        if ($exp !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $exp);
            if (!$d || $d->format('Y-m-d') !== $exp) $exp = '';
        }
        db_insert('k30_ti_client_licenses', [
            'license_id'  => $license_id,
            'client_id'   => $acc['client_id'],
            'login'       => trim($_POST['login'] ?? ''),
            'access_key'  => trim($_POST['access_key'] ?? ''),
            'notes'       => trim($_POST['notes'] ?? ''),
            'expires_at'  => $exp ?: null,
            'status'      => 'active',
            'assigned_by' => $uid,
        ]);
        flash_set('success', 'Licencja przypisana kursantowi.');
        header('Location: konta.php?selected=' . $aid); exit;
    }

    if ($op === 'license_revoke') {
        $aid           = (int)($_POST['account_id'] ?? 0);
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);
        if ($assignment_id) {
            db()->prepare("UPDATE k30_ti_client_licenses SET status='revoked' WHERE id=?")->execute([$assignment_id]);
            flash_set('success', 'Przypisanie licencji cofnięte.');
        }
        header('Location: konta.php?selected=' . $aid); exit;
    }

    // Wygeneruj link magiczny rodzica i wyślij go e-mailem (jeśli jest adres)
    if ($op === 'parent_link') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if ($acc) {
            $token = parent_make_token($aid);
            $url   = rtrim(APP_URL, '/') . '/karty30/ti/kursant/parent.php?t=' . $token;
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
        header('Location: konta.php?guardian=' . $aid); exit;
    }

    // Utwórz konto rodzica (login: pierwsza litera imienia.nazwisko-r + hasło)
    if ($op === 'parent_account_create') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one(
            "SELECT a.*, cl.name AS client_name FROM k30_ti_student_accounts a
             JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=?", [$aid]
        ) : null;
        if (!$acc) { flash_set('danger','Konto kursanta nie istnieje.'); header('Location: konta.php'); exit; }
        if (!empty($acc['parent_login'])) {
            flash_set('warning', 'Konto rodzica już istnieje (login: ' . $acc['parent_login'] . '). Użyj „Resetuj hasło".');
            header('Location: konta.php?guardian=' . $aid); exit;
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

        $_SESSION['new_parent_creds'] = ['login' => $login, 'password' => $pass, 'name' => $acc['client_name']];
        // Wyślij dane SMS-em na numer opiekuna (jeśli jest i SMS włączony)
        $gphone = trim($acc['guardian_phone'] ?? '');
        $sms = '';
        if ($gphone !== '' && sms_channel_ready()) {
            try {
                $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';
                sms_send($gphone, "{$org} - panel rodzica. Login: {$login}, haslo: {$pass}");
                $sms = ' Dane wysłano SMS-em na numer opiekuna.';
            } catch (\Throwable $e) { $sms = ' (błąd wysyłki SMS: ' . $e->getMessage() . ')'; }
        }
        flash_set('success', "Konto rodzica utworzone. Login: {$login}." . $sms);
        header('Location: konta.php?guardian=' . $aid); exit;
    }

    // Reset hasła konta rodzica
    if ($op === 'parent_account_reset') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if (!$acc || empty($acc['parent_login'])) { flash_set('danger','Konto rodzica nie istnieje.'); header('Location: konta.php?guardian=' . $aid); exit; }
        $pass = _gen_student_pass();
        db()->prepare(
            "UPDATE k30_ti_student_accounts SET parent_password_hash=?, parent_must_change=1, updated_at=datetime('now') WHERE id=?"
        )->execute([password_hash($pass, PASSWORD_BCRYPT), $aid]);
        $cl = db_one("SELECT name FROM k30_clients WHERE id=?", [$acc['client_id']]);
        $_SESSION['new_parent_creds'] = ['login' => $acc['parent_login'], 'password' => $pass, 'name' => $cl['name'] ?? ''];
        $gphone = trim($acc['guardian_phone'] ?? '');
        $sms = '';
        if ($gphone !== '' && sms_channel_ready()) {
            try {
                $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';
                sms_send($gphone, "{$org} - panel rodzica. Login: {$acc['parent_login']}, haslo: {$pass}");
                $sms = ' Dane wysłano SMS-em na numer opiekuna.';
            } catch (\Throwable $e) { $sms = ' (błąd wysyłki SMS: ' . $e->getMessage() . ')'; }
        }
        flash_set('success', "Hasło konta rodzica zresetowane (login: {$acc['parent_login']})." . $sms);
        header('Location: konta.php?guardian=' . $aid); exit;
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
        header('Location: konta.php?guardian=' . $aid); exit;
    }

    // ── Upoważnienia (tylko pełnoletni) ──────────────────────────────────────
    if ($op === 'authp_scan_upload') {
        // Wgraj skan podpisanego upoważnienia do istniejącego wpisu
        $ap_id_scan = (int)($_POST['ap_id'] ?? 0);
        $ap_scan = $ap_id_scan ? db_one("SELECT * FROM k30_ti_authorized_persons WHERE id=?", [$ap_id_scan]) : null;
        $aid_scan = $ap_scan ? (int)$ap_scan['student_account_id'] : 0;
        if (!$ap_scan) { flash_set('danger','Nie znaleziono upoważnienia.'); header('Location: konta.php'); exit; }
        require_once dirname(dirname(dirname(__DIR__))) . '/includes/mail_queue.php';
        $scan_att = isset($_FILES['scan_file']) ? mail_queue_save_attachment($_FILES['scan_file']) : null;
        if ($scan_att) {
            db()->prepare("UPDATE k30_ti_authorized_persons SET scan_path=? WHERE id=?")
               ->execute([$scan_att['path'], $ap_id_scan]);
            flash_set('success', 'Skan podpisanego upoważnienia zapisany.');
        } else {
            flash_set('danger', 'Nie udało się zapisać skanu. Sprawdź format (PDF, PNG, JPG) i rozmiar (max 15 MB).');
        }
        header('Location: konta.php?authp=' . $aid_scan); exit;
    }

    if (in_array($op, ['authp_add', 'authp_toggle', 'authp_delete', 'authp_reset_pass', 'authp_revoke_scan'], true)) {
        $aid  = (int)($_POST['account_id'] ?? 0);
        $acc  = $aid ? db_one(
            "SELECT a.*, cl.name AS client_name FROM k30_ti_student_accounts a
             JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=?", [$aid]
        ) : null;
        if (!$acc || !empty($acc['is_minor'])) {
            flash_set('danger', 'Upoważnienia obsługiwane tylko dla pełnoletnich kursantów.');
            header('Location: konta.php'); exit;
        }
        if ($op === 'authp_add') {
            $ap_name      = mb_substr(trim((string)($_POST['ap_name']   ?? '')), 0, 100);
            $ap_email     = mb_substr(trim((string)($_POST['ap_email']  ?? '')), 0, 150);
            $ap_notes     = mb_substr(trim((string)($_POST['ap_notes']  ?? '')), 0, 300);
            $ap_reason    = mb_substr(trim((string)($_POST['ap_reason'] ?? '')), 0, 400);
            $ap_added_by  = $dyd_name;
            if ($ap_name === '') {
                flash_set('danger', 'Podaj imię i nazwisko osoby upoważnionej.');
                header('Location: konta.php?authp=' . $aid); exit;
            }
            if ($ap_reason === '') {
                flash_set('danger', 'Podaj powód upoważnienia.');
                header('Location: konta.php?authp=' . $aid); exit;
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
            header('Location: konta.php?authp=' . $aid . '&new_authp=1'); exit;
        }
        $ap_id_edit = (int)($_POST['ap_id'] ?? 0);
        $ap = $ap_id_edit ? db_one(
            "SELECT * FROM k30_ti_authorized_persons WHERE id=? AND student_account_id=?",
            [$ap_id_edit, $aid]
        ) : null;
        if (!$ap) { flash_set('danger','Nie znaleziono upoważnienia.'); header('Location: konta.php?authp=' . $aid); exit; }
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
            header('Location: konta.php?authp=' . $aid); exit;
        } elseif ($op === 'authp_toggle') {
            $revoking = (bool)$ap['is_active']; // true = właśnie cofamy dostęp
            $revoker  = $dyd_name;
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
            header('Location: konta.php?authp=' . $aid . '&new_authp=1'); exit;
        }
        header('Location: konta.php?authp=' . $aid); exit;
    }
}

// Wczytaj
$accounts = db_all(
    "SELECT a.*, cl.name AS client_name, cl.email AS client_email
     FROM k30_ti_student_accounts a
     JOIN k30_clients cl ON cl.id=a.client_id
     ORDER BY cl.name"
);
$ti_org_accounts = k30_ti_org_accounts_list();
$taken_ids   = array_column($accounts, 'client_id');
$all_clients = db_all("SELECT id, name FROM k30_clients ORDER BY name");
$no_account  = array_filter($all_clients, fn($c) => !in_array((int)$c['id'], $taken_ids));

// Dane nowego konta (z sesji)
$new_creds = $_SESSION['new_student_creds'] ?? null;
unset($_SESSION['new_student_creds']);
$bulk_creds = $_SESSION['bulk_student_creds'] ?? null;
unset($_SESSION['bulk_student_creds']);
$new_ms_creds = $_SESSION['new_ms_creds'] ?? null;
unset($_SESSION['new_ms_creds']);
$ms_online_enabled = ti_ms_enabled();
$parent_link = $_SESSION['parent_link'] ?? null;
unset($_SESSION['parent_link']);
$new_parent_creds = $_SESSION['new_parent_creds'] ?? null;
unset($_SESSION['new_parent_creds']);
$new_authp_creds  = $_SESSION['new_authp_creds']  ?? null;
unset($_SESSION['new_authp_creds']);
$authp_revoked_id = $_SESSION['authp_revoked_id'] ?? null;
unset($_SESSION['authp_revoked_id']);

// Konto wybrane do panelu szczegółów (master-detail: lista po lewej, jeden
// kursant naraz po prawej, w zakładkach). ?selected=/?guardian=/?authp=
// wskazują to samo konto — dawniej trzy osobne podstrony, dziś trzy różne
// wejścia do tego samego panelu z inną domyślną zakładką (zachowane dla
// wstecznej zgodności z istniejącymi przekierowaniami po zapisie formularzy
// powyżej, żeby nie trzeba było zmieniać ~40 handlerów POST).
$selected_id  = (int)($_GET['selected'] ?? $_GET['guardian'] ?? $_GET['authp'] ?? 0);
$selected_acc = null;
foreach ($accounts as $a) {
    if ((int)$a['id'] === $selected_id) { $selected_acc = $a; break; }
}

$active_tab = $_GET['ptab'] ?? (isset($_GET['guardian']) ? 'opiekun' : (isset($_GET['authp']) ? 'upowaznieni' : 'ogolne'));
if (!in_array($active_tab, ['ogolne', 'platnosci', 'licencje', 'opiekun', 'upowaznieni'], true)) $active_tab = 'ogolne';

// Opiekun/rodzic — te same kolumny co $selected_acc (k30_ti_student_accounts),
// więc bez osobnego zapytania.
$guardian_acc = $selected_acc;

$portal_url         = rtrim(APP_URL, '/') . '/karty30/ti/kursant/login.php';
$parent_portal_url  = rtrim(APP_URL, '/') . '/karty30/ti/kursant/parent.php';

// Upoważnieni — tylko dla pełnoletnich (jak dawniej COALESCE(is_minor,0)=0).
$authp_account     = ($selected_acc && empty($selected_acc['is_minor'])) ? $selected_acc : null;
$authp_account_id  = $authp_account ? (int)$authp_account['id'] : 0;
$authp_list_admin  = $authp_account ? db_all(
    "SELECT * FROM k30_ti_authorized_persons WHERE student_account_id=? ORDER BY created_at DESC",
    [$authp_account_id]
) : [];
// Zabezpieczenie przed martwą zakładką: stary link „?authp=" do konta, które
// w międzyczasie oznaczono jako małoletnie (zakładka Upoważnieni już się nie
// renderuje) — wróć do Ogólnych zamiast pustego panelu bez aktywnej zakładki.
if ($active_tab === 'upowaznieni' && !$authp_account) $active_tab = 'ogolne';

$KP_TITLE  = 'Konta kursantów — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'konta.php'; $KIER_LABEL = 'Konta kursantów';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">

<div class="d-flex align-items-center mb-3 gap-2">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-person-badge text-primary me-2" aria-hidden="true"></i>Konta kursantów</h1>
  <div class="ms-auto d-flex gap-2">
    <a href="<?= h($parent_portal_url) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-people me-1" aria-hidden="true"></i>Panel rodzica
    </a>
    <a href="<?= h($portal_url) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Panel kursanta
    </a>
  </div>
</div>

<?= flash_html() ?>

<script>
function printCredCard(title, rows, portalUrl){
  var w = window.open('', '_blank');
  var body = '<table>' + rows.map(function(r){
    return '<tr><th>' + r[0] + '</th><td>' + r[1] + '</td></tr>';
  }).join('') + '</table>';
  w.document.write('<html><head><title>' + title + '</title>'
    + '<style>body{font-family:Arial,sans-serif;font-size:13px;padding:16px}'
    + 'table{border-collapse:collapse;width:100%;max-width:420px}'
    + 'th,td{border:1px solid #999;padding:6px 10px;text-align:left}th{background:#eee;width:40%}'
    + 'h3{margin:0 0 12px}</style></head><body>'
    + '<h3><?= h(addslashes(ORG_NAME ?? 'Panel kursanta')) ?></h3>'
    + '<div style="font-weight:bold;margin-bottom:8px">' + title + '</div>'
    + body
    + '<p style="margin-top:16px;font-size:14px">Adres panelu: <strong>kursant.feer.org.pl</strong></p>'
    + '<p style="font-size:12px;color:#555">Link bezpośredni: ' + portalUrl + '</p>'
    + '</body></html>');
  w.document.close(); w.focus(); w.print();
}
</script>

<!-- Nowo wygenerowane dane -->
<?php if ($new_creds): ?>
<div class="alert alert-warning d-flex gap-3 align-items-start mb-4 shadow-sm">
  <i class="bi bi-key-fill fs-4 flex-shrink-0" style="color:#b45309" aria-hidden="true"></i>
  <div class="flex-grow-1">
    <div class="fw-bold mb-2">⚠ Dane dostępowe — przekaż kursantowi i zamknij!</div>
    <table class="table table-sm table-bordered mb-2" style="max-width:360px;background:#fff;font-size:.88rem">
      <tr><th>Beneficjent</th><td><?= h($new_creds['name']) ?></td></tr>
      <tr><th>Login</th><td class="font-monospace fw-bold"><?= h($new_creds['login']) ?></td></tr>
      <tr><th>Hasło</th><td class="font-monospace fw-bold text-danger"><?= h($new_creds['password']) ?></td></tr>
    </table>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <div class="small text-muted">Link do logowania: <a href="<?= h($portal_url) ?>" target="_blank"><?= h($portal_url) ?></a></div>
      <button type="button" class="btn btn-sm btn-outline-secondary"
              onclick='printCredCard("Dane dostępowe — panel kursanta", [["Beneficjent","<?= h(addslashes($new_creds['name'])) ?>"],["Login","<?= h(addslashes($new_creds['login'])) ?>"],["Hasło","<?= h(addslashes($new_creds['password'])) ?>"]], "<?= h(addslashes($portal_url)) ?>")'>
        <i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj kartkę
      </button>
    </div>
  </div>
  <button type="button" class="btn-close" onclick="this.closest('.alert').remove()" aria-label="Zamknij"></button>
</div>
<?php endif; ?>

<!-- Zbiorczo utworzone konta -->
<?php if ($bulk_creds && !empty($bulk_creds['rows'])): ?>
<div class="alert alert-warning mb-4 shadow-sm" id="bulk-result">
  <div class="d-flex align-items-start gap-2 mb-2">
    <i class="bi bi-people-fill fs-4 flex-shrink-0" style="color:#b45309" aria-hidden="true"></i>
    <div class="fw-bold flex-grow-1">⚠ Zbiorczo utworzone konta (<?= count($bulk_creds['rows']) ?>) — zapisz lub wydrukuj teraz, hasła nie będą pokazane ponownie!</div>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printBulk()"><i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj</button>
    <button type="button" class="btn-close" onclick="this.closest('.alert').remove()" aria-label="Zamknij"></button>
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
  <i class="bi bi-microsoft fs-4 flex-shrink-0" style="color:#0078d4" aria-hidden="true"></i>
  <div class="flex-grow-1">
    <div class="fw-bold mb-2">⚠ Dane konta Microsoft 365 — przekaż kursantowi i zamknij!</div>
    <table class="table table-sm table-bordered mb-2" style="max-width:360px;background:#fff;font-size:.88rem">
      <tr><th>Login (UPN)</th><td class="font-monospace fw-bold"><?= h($new_ms_creds['upn']) ?></td></tr>
      <tr><th>Hasło tymczasowe</th><td class="font-monospace fw-bold text-danger"><?= h($new_ms_creds['password']) ?></td></tr>
    </table>
    <div class="small text-muted">Dane wysłano też e-mailem/SMS-em (jeśli skonfigurowane).</div>
  </div>
  <button type="button" class="btn-close" onclick="this.closest('.alert').remove()" aria-label="Zamknij"></button>
</div>
<?php endif; ?>

<!-- Nowo utworzone konto rodzica -->
<?php if ($new_parent_creds): ?>
<div class="alert alert-warning d-flex gap-3 align-items-start mb-4 shadow-sm">
  <i class="bi bi-people-fill fs-4 flex-shrink-0" style="color:#b45309" aria-hidden="true"></i>
  <div class="flex-grow-1">
    <div class="fw-bold mb-2">⚠ Dane konta rodzica — przekaż opiekunowi i zamknij!</div>
    <table class="table table-sm table-bordered mb-2" style="max-width:380px;background:#fff;font-size:.88rem">
      <tr><th>Kursant</th><td><?= h($new_parent_creds['name']) ?></td></tr>
      <tr><th>Login rodzica</th><td class="font-monospace fw-bold"><?= h($new_parent_creds['login']) ?></td></tr>
      <tr><th>Hasło</th><td class="font-monospace fw-bold text-danger"><?= h($new_parent_creds['password']) ?></td></tr>
    </table>
    <div class="small text-muted">Logowanie rodzica (login + hasło): <a href="<?= h($parent_portal_url) ?>" target="_blank"><?= h($parent_portal_url) ?></a></div>
  </div>
  <button type="button" class="btn-close" onclick="this.closest('.alert').remove()" aria-label="Zamknij"></button>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     Master-detail: lista (lewa kolumna) + szczegóły wybranego kursanta w
     zakładkach (prawa kolumna) — zamiast modali/osobnych podstron, jak w
     karty30/ti/curriculum.php (a11y: nav-tabs to NIE modal — bez focus trap,
     bez aria-hidden na resztę strony). Na wąskich ekranach kolumny się
     składają (Bootstrap col-lg-*) — najpierw lista, potem szczegóły.
     Wszystkie akcje poniżej to te same formularze/handlery co dotąd —
     zmienił się tylko układ, nie logika. ═══════════════════════════════════ -->
<div class="row g-4">

<!-- ── Prawa kolumna (detail) — order-2: na mobile DRUGA (pod listą), na
     desktopie po PRAWEJ (bo col-lg-* układa się w rząd dopiero od lg) ── -->
<div class="col-12 col-lg-7 col-xxl-8 order-2">
<?php if ($selected_acc): $sa = $selected_acc; $sa_has_ms = !empty($sa['ms_user_id']); ?>
<div class="card border-0 shadow-sm mb-4" id="detail-panel">
  <div class="card-header fw-semibold d-flex align-items-center flex-wrap gap-2">
    <i class="bi bi-person-badge me-2 text-primary" aria-hidden="true"></i>
    <span><?= h($sa['client_name']) ?></span>
    <span class="badge <?= $sa['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $sa['is_active'] ? 'Aktywne' : 'Zablokowane' ?></span>
    <?php if (!empty($sa['is_minor'])): ?>
    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><i class="bi bi-people" aria-hidden="true"></i> małoletni</span>
    <?php endif; ?>
    <?php if (!empty($sa['child_access_blocked'])): ?>
    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-lock-fill" aria-hidden="true"></i> wstrzymany przez opiekuna</span>
    <?php endif; ?>
    <span class="font-monospace text-body-secondary small">(<?= h($sa['login']) ?>)</span>
    <a href="konta.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Zamknij</a>
  </div>

  <!-- Szybkie akcje — bez przechodzenia do zakładek -->
  <div class="card-body border-bottom pb-3">
    <div class="d-flex flex-wrap gap-2">
      <?php if ($sa['is_active']): ?>
      <form method="post" target="_blank" onsubmit="return confirm('Otworzyć panel kursanta jako ten użytkownik? Twoja sesja panelu pozostanie aktywna w tej karcie.')">
        <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op"        value="impersonate">
        <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj jako kursant <span class="text-body-secondary small">(podgląd)</span></button>
      </form>
      <?php endif; ?>
      <a class="btn btn-sm btn-outline-secondary" href="../messages.php?student=<?= (int)$sa['id'] ?>"><i class="bi bi-envelope me-1" aria-hidden="true"></i>Wyślij wiadomość</a>
      <form method="post" class="d-inline" onsubmit="return confirm('Wysłać ponownie link do sprawdzarki numeru konta? Poprzedni link przestanie działać.')">
        <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op"        value="payment_account_resend_link">
        <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-envelope-arrow-up me-1" aria-hidden="true"></i>Wyślij ponownie link do konta</button>
      </form>

      <?php foreach ([2, 3] as $pn):
        $pval = trim((string)($sa["notify_phone{$pn}"] ?? ''));
        if ($pval === '' || !empty($sa["notify_phone{$pn}_verified"])) continue;
      ?>
      <form method="post" onsubmit="return confirm('Zatwierdzić numer <?= h($pval) ?> do powiadomień SMS tego kursanta? Rób to tylko, gdy masz pewność, że numer należy do kursanta lub jego opiekuna.')">
        <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op"        value="approve_notify_phone">
        <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
        <input type="hidden" name="which"      value="<?= $pn ?>">
        <button type="submit" class="btn btn-sm btn-outline-warning">
          <i class="bi bi-shield-check me-1" aria-hidden="true"></i>Zatwierdź numer SMS <?= $pn === 2 ? 'drugi' : 'trzeci' ?>
          <span class="text-body-secondary small">(<?= h($pval) ?>)</span>
        </button>
      </form>
      <?php endforeach; ?>

      <?php if (!empty($sa['login_alias'])): ?>
      <form method="post" onsubmit="return confirm('Usunąć alias logowania tego kursanta?')">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="clear_alias">
        <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Usuń alias logowania <span class="text-body-secondary small">(<?= h($sa['login_alias']) ?>)</span></button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Zakładki -->
  <ul class="nav nav-tabs px-3 pt-2" id="detailTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $active_tab === 'ogolne' ? 'active' : '' ?>" id="tab-btn-ogolne" data-bs-toggle="tab" data-bs-target="#tab-ogolne" type="button" role="tab" aria-controls="tab-ogolne" aria-selected="<?= $active_tab === 'ogolne' ? 'true' : 'false' ?>">
        <i class="bi bi-sliders me-1" aria-hidden="true"></i>Ogólne
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $active_tab === 'platnosci' ? 'active' : '' ?>" id="tab-btn-platnosci" data-bs-toggle="tab" data-bs-target="#tab-platnosci" type="button" role="tab" aria-controls="tab-platnosci" aria-selected="<?= $active_tab === 'platnosci' ? 'true' : 'false' ?>">
        <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Płatności
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $active_tab === 'licencje' ? 'active' : '' ?>" id="tab-btn-licencje" data-bs-toggle="tab" data-bs-target="#tab-licencje" type="button" role="tab" aria-controls="tab-licencje" aria-selected="<?= $active_tab === 'licencje' ? 'true' : 'false' ?>">
        <i class="bi bi-key me-1" aria-hidden="true"></i>Licencje
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $active_tab === 'opiekun' ? 'active' : '' ?>" id="tab-btn-opiekun" data-bs-toggle="tab" data-bs-target="#tab-opiekun" type="button" role="tab" aria-controls="tab-opiekun" aria-selected="<?= $active_tab === 'opiekun' ? 'true' : 'false' ?>">
        <i class="bi bi-people me-1" aria-hidden="true"></i>Opiekun / Rodzic
      </button>
    </li>
    <?php if ($authp_account): ?>
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $active_tab === 'upowaznieni' ? 'active' : '' ?>" id="tab-btn-upowaznieni" data-bs-toggle="tab" data-bs-target="#tab-upowaznieni" type="button" role="tab" aria-controls="tab-upowaznieni" aria-selected="<?= $active_tab === 'upowaznieni' ? 'true' : 'false' ?>">
        <i class="bi bi-person-check me-1" aria-hidden="true"></i>Upoważnieni
        <?php if ($authp_list_admin): ?><span class="badge bg-secondary ms-1"><?= count($authp_list_admin) ?></span><?php endif; ?>
      </button>
    </li>
    <?php endif; ?>
  </ul>

  <div class="tab-content card-body">

    <!-- ── Zakładka: Ogólne ─────────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $active_tab === 'ogolne' ? 'show active' : '' ?>" id="tab-ogolne" role="tabpanel" aria-labelledby="tab-btn-ogolne" tabindex="0">
      <div class="d-flex flex-column gap-3">

        <div>
          <div class="small fw-semibold text-body-secondary mb-1">Dane</div>
          <div class="row g-3 align-items-end">
            <div class="col-auto">
              <form method="post" class="d-flex gap-1">
                <input type="hidden" name="_token"      value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op"         value="set_no">
                <input type="hidden" name="account_id"  value="<?= (int)$sa['id'] ?>">
                <div>
                  <label class="form-label small mb-0" for="no<?= (int)$sa['id'] ?>">Nr kursanta</label>
                  <div class="d-flex gap-1">
                    <input type="text" id="no<?= (int)$sa['id'] ?>" name="student_no" value="<?= h($sa['student_no'] ?? '') ?>"
                           class="form-control form-control-sm font-monospace" style="width:110px" placeholder="—">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Zapisz numer kursanta" aria-label="Zapisz numer kursanta"><i class="bi bi-save" aria-hidden="true"></i></button>
                  </div>
                  <?php if ($vn = pp_vnrb_for_ti((int)$sa['client_id'])): ?>
                  <div class="small text-muted mt-1">Rachunek do wpłat: <span class="font-monospace user-select-all"><?= h($vn) ?></span></div>
                  <?php endif; ?>
                </div>
              </form>
            </div>
            <div class="col-auto">
              <div class="small text-muted mb-0">Ostatnie logowanie</div>
              <div>
                <?php if ($sa['last_login']): ?>
                <?= h(date('d.m.Y H:i', strtotime($sa['last_login']))) ?>
                <?php if (!empty($sa['last_login_ip'])): ?>
                <span class="font-monospace text-muted small"><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= h($sa['last_login_ip']) ?></span>
                <?php endif; ?>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </div>
            </div>
            <?php if (!empty($sa['login_alias'])): ?>
            <div class="col-auto">
              <div class="small text-muted mb-0">Alias logowania</div>
              <div class="text-info"><i class="bi bi-arrow-return-right" aria-hidden="true"></i> <?= h($sa['login_alias']) ?></div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($ms_online_enabled): ?>
        <div>
          <div class="small fw-semibold text-body-secondary mb-1">Nauka online</div>
          <?php if ($sa_has_ms): ?>
          <div class="small mb-2">
            <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
              <i class="bi bi-microsoft" aria-hidden="true"></i> <span class="font-monospace"><?= h($sa['ms_upn']) ?></span>
            </span>
          </div>
          <?php endif; ?>
          <div class="d-flex flex-wrap gap-2">
            <form method="post" <?= $sa_has_ms ? "onsubmit=\"return confirm('Usunąć konto Microsoft tego kursanta?')\"" : '' ?>>
              <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"        value="<?= $sa_has_ms ? 'ms_delete' : 'ms_create' ?>">
              <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
              <button type="submit" class="btn btn-sm <?= $sa_has_ms ? 'btn-outline-danger' : 'btn-outline-primary' ?>">
                <i class="bi bi-microsoft me-1" aria-hidden="true"></i><?= $sa_has_ms ? 'Usuń konto Microsoft' : 'Utwórz konto Microsoft' ?>
              </button>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <div>
          <div class="small fw-semibold text-body-secondary mb-1">Hasło i dostęp</div>
          <div class="d-flex flex-wrap gap-2">
            <form method="post" onsubmit="return confirm('Zresetować hasło?')">
              <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"        value="reset_pass">
              <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-key me-1" aria-hidden="true"></i>Resetuj hasło</button>
            </form>
            <form method="post" onsubmit="return confirm('Ustawić hasło serwisowe (8 znaków)?<?= $sa_has_ms ? ' Nadpisze też hasło konta Microsoft tego kursanta.' : '' ?>')">
              <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"        value="service_pass">
              <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Hasło serwisowe <span class="text-body-secondary small">(8 znaków<?= $sa_has_ms ? ', nadpisuje też MS' : '' ?>)</span></button>
            </form>
            <form method="post">
              <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"        value="toggle">
              <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="bi <?= $sa['is_active'] ? 'bi-lock' : 'bi-unlock text-success' ?> me-1" aria-hidden="true"></i><?= $sa['is_active'] ? 'Zablokuj konto' : 'Odblokuj konto' ?>
              </button>
            </form>
            <form method="post">
              <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"        value="toggle_virtual">
              <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-secondary" title="Kursant wirtualny nie dostaje rachunku wirtualnego z puli; już nadany numer zostaje">
                <i class="bi bi-person-badge me-1" aria-hidden="true"></i><?= !empty($sa['is_virtual']) ? 'Kursant wirtualny: TAK (wyłącz)' : 'Oznacz jako kursanta wirtualnego' ?>
              </button>
            </form>
            <?php if (!empty($sa['child_access_blocked'])): ?>
            <form method="post" onsubmit="return confirm('Przywrócić kursantowi dostęp do panelu? Dostęp został wstrzymany przez opiekuna.')">
              <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"        value="child_unblock">
              <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-unlock me-1" aria-hidden="true"></i>Odblokuj dostęp <span class="text-body-secondary small">(wstrzymany przez opiekuna)</span></button>
            </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="border-top pt-3">
          <form method="post" onsubmit="return confirm('Usunąć konto „<?= h(addslashes($sa['client_name'])) ?>”? Tej operacji nie można cofnąć.')">
            <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op"        value="delete">
            <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń konto</button>
          </form>
        </div>

      </div>
    </div>

    <!-- ── Zakładka: Płatności (nadpłata/metody + numer konta do wpłat) ──── -->
    <div class="tab-pane fade <?= $active_tab === 'platnosci' ? 'show active' : '' ?>" id="tab-platnosci" role="tabpanel" aria-labelledby="tab-btn-platnosci" tabindex="0">
      <?php
        $_oy_methods = array_filter(array_map('trim', explode(',', (string)($sa['allowed_payment_methods'] ?? ''))));
        $_oy_all_methods = ['stripe' => 'Stripe', 'payu' => 'PayU', 'p24' => 'Przelewy24', 'transfer' => 'Przelew tradycyjny'];
      ?>
      <div class="row g-4">
        <div class="col-lg-6">
          <h3 class="h6 fw-bold mb-3">Numer konta do wpłat za zajęcia</h3>
          <form method="post" id="payAccForm">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="payment_account_confirm">
            <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
            <input type="hidden" name="pay_source" id="payAccSourceField" value="">
            <input type="hidden" name="org_account_index" id="payAccOrgIdxField" value="">
            <input type="hidden" name="custom_account" id="payAccCustomField" value="">

            <!-- Krok 1: wybór rachunku -->
            <div id="payAccStep1">
              <div class="mb-3">
                <label class="form-label small fw-semibold" for="payAccSourceSel">Rodzaj rachunku</label>
                <select class="form-select form-select-sm" id="payAccSourceSel" onchange="tiPayAccToggle()">
                  <?php foreach ($ti_org_accounts as $oa): ?>
                  <option value="org:<?= $oa['index'] ?>"
                          data-nrb="<?= h($oa['nrb']) ?>"
                          data-label="<?= h('Rachunek organizacji: ' . $oa['label'] . ($oa['dla_ti'] ? ' (dla TI)' : '')) ?>"
                          <?= ($sa['payment_bank_account_source'] ?? '') === 'org' && $sa['payment_bank_account'] === $oa['nrb'] ? 'selected' : '' ?>>
                    <?= h($oa['label']) ?> — <?= h($oa['nrb']) ?><?= $oa['dla_ti'] ? ' (dla TI)' : '' ?>
                  </option>
                  <?php endforeach; ?>
                  <option value="custom" <?= (($sa['payment_bank_account_source'] ?? '') === 'custom' || !$ti_org_accounts) ? 'selected' : '' ?>>
                    Inny / niestandardowy numer
                  </option>
                </select>
                <?php if (!$ti_org_accounts): ?>
                <div class="form-text">Brak zdefiniowanych rachunków organizacji — dodaj je w Ustawieniach → Rachunki.</div>
                <?php endif; ?>
              </div>
              <div class="mb-2" id="payAccCustomWrap" style="<?= (($sa['payment_bank_account_source'] ?? '') === 'custom' || !$ti_org_accounts) ? '' : 'display:none' ?>">
                <label class="form-label small fw-semibold" for="payAccCustomInput">Numer konta</label>
                <input type="text" id="payAccCustomInput"
                       value="<?= ($sa['payment_bank_account_source'] ?? '') === 'custom' ? h($sa['payment_bank_account'] ?? '') : '' ?>"
                       class="form-control form-control-sm font-monospace" placeholder="np. numer nadany przez bank" maxlength="40">
                <div class="form-text">Numer nadany przez bank organizacji — system go nie generuje ani nie weryfikuje.</div>
              </div>
              <div class="form-text">
                Aktualnie zatwierdzony numer:
                <strong class="font-monospace"><?= $sa['payment_bank_account'] ? h($sa['payment_bank_account']) : '— brak —' ?></strong>
              </div>
            </div>

            <!-- Krok 2: podgląd i zatwierdzenie -->
            <div id="payAccStep2" style="display:none">
              <p class="fw-semibold">Czy zatwierdzasz ten rachunek do obsługi płatności za zajęcia i szkolenia dla tego kursanta?</p>
              <table class="table table-sm table-borderless mb-0">
                <tr><td class="text-muted" style="width:110px">Kursant</td><td><strong><?= h($sa['client_name']) ?></strong></td></tr>
                <tr><td class="text-muted">Numer konta</td><td><strong class="font-monospace" id="payAccPreviewNumber"></strong></td></tr>
                <tr><td class="text-muted">Typ</td><td id="payAccPreviewType"></td></tr>
              </table>
              <div class="form-text">Po zatwierdzeniu kursant (i opiekun, jeśli małoletni) otrzyma e-mail z tym numerem konta.</div>
            </div>

            <div class="d-flex gap-2 mt-3">
              <button type="button" class="btn btn-sm btn-primary" id="payAccNextBtn" onclick="tiPayAccPreview()">
                Dalej — podgląd <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="payAccBackBtn" onclick="tiPayAccBack()">Wróć</button>
              <button type="submit" class="btn btn-sm btn-success d-none" id="payAccSubmitBtn">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zatwierdź rachunek
              </button>
            </div>
          </form>
        </div>

        <div class="col-lg-6">
          <h3 class="h6 fw-bold mb-3">Nadpłata i metody płatności</h3>
          <form method="post">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="overpay_save">
            <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
            <p class="text-muted small mb-3">
              Nadpłata do końca roku (kreator w portfelu kursanta) z powodów podatkowych/księgowych musi
              być opłacona w tym samym roku kalendarzowym — dlatego jest dostępna tylko dla wybranych
              kursantów, świadomie włączona przez kierownika.
            </p>
            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" name="allow_year_end_overpay" id="oy_allow"
                     <?= $sa['allow_year_end_overpay'] ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold" for="oy_allow">Zezwól na nadpłatę do końca roku</label>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-semibold d-block">Dozwolone metody płatności (puste = bez ograniczenia)</label>
              <?php foreach ($_oy_all_methods as $mk => $ml): ?>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="allowed_methods[]" id="oy_m_<?= h($mk) ?>"
                       value="<?= h($mk) ?>" <?= in_array($mk, $_oy_methods, true) ? 'checked' : '' ?>>
                <label class="form-check-label" for="oy_m_<?= h($mk) ?>"><?= h($ml) ?></label>
              </div>
              <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
          </form>
        </div>
      </div>
    </div>

    <!-- ── Zakładka: Licencje ───────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $active_tab === 'licencje' ? 'show active' : '' ?>" id="tab-licencje" role="tabpanel" aria-labelledby="tab-btn-licencje" tabindex="0">
      <?php
        $sa_licenses = k30_ti_client_licenses((int)$sa['client_id']);
        $sa_license_catalog = array_filter(k30_ti_licenses_all(true), fn($l) => !empty($l['is_active']));
      ?>
      <div class="small fw-semibold text-body-secondary mb-1">
        Licencje na oprogramowanie <span class="fw-normal">(inne niż Microsoft 365 — Adobe, Canva, antywirus…)</span>
      </div>
      <?php if ($sa_licenses): ?>
      <ul class="list-group list-group-flush mb-2" style="max-width:560px">
        <?php foreach ($sa_licenses as $sl): $sl_expired = !empty($sl['expires_at']) && $sl['expires_at'] < date('Y-m-d'); ?>
        <li class="list-group-item d-flex align-items-center gap-2 px-0 py-1 small">
          <span class="fw-semibold"><?= h($sl['license_name']) ?></span>
          <?php if ($sl['login']): ?><span class="font-monospace text-body-secondary"><?= h($sl['login']) ?></span><?php endif; ?>
          <?php if (!empty($sl['expires_at'])): ?>
          <span class="<?= $sl_expired ? 'text-danger fw-semibold' : 'text-body-secondary' ?>">do <?= h($sl['expires_at']) ?><?= $sl_expired ? ' (wygasło)' : '' ?></span>
          <?php endif; ?>
          <form method="post" class="ms-auto" onsubmit="return confirm('Cofnąć przypisanie licencji „<?= h(addslashes($sl['license_name'])) ?>”?')">
            <input type="hidden" name="_token"       value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op"          value="license_revoke">
            <input type="hidden" name="account_id"   value="<?= (int)$sa['id'] ?>">
            <input type="hidden" name="assignment_id" value="<?= (int)$sl['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Cofnij przypisanie" aria-label="Cofnij przypisanie licencji <?= h($sl['license_name']) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
          </form>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="text-body-secondary small mb-2">Brak przypisanych licencji.</p>
      <?php endif; ?>

      <?php if ($sa_license_catalog): ?>
      <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
        <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op"        value="license_assign">
        <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
        <div>
          <label class="form-label small mb-0" for="lic_sel">Oprogramowanie</label>
          <select class="form-select form-select-sm" id="lic_sel" name="license_id" required style="min-width:170px">
            <option value="">— wybierz —</option>
            <?php foreach ($sa_license_catalog as $lc): ?>
            <option value="<?= (int)$lc['id'] ?>"><?= h($lc['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label small mb-0" for="lic_login">Login</label>
          <input type="text" class="form-control form-control-sm font-monospace" id="lic_login" name="login" style="width:130px">
        </div>
        <div>
          <label class="form-label small mb-0" for="lic_key">Klucz</label>
          <input type="text" class="form-control form-control-sm font-monospace" id="lic_key" name="access_key" style="width:130px">
        </div>
        <div>
          <label class="form-label small mb-0" for="lic_exp">Ważne do</label>
          <input type="date" class="form-control form-control-sm" id="lic_exp" name="expires_at" min="<?= date('Y-m-d') ?>">
        </div>
        <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Przypisz</button>
      </form>
      <?php else: ?>
      <p class="text-body-secondary small mb-0">Brak aktywnego oprogramowania w katalogu.</p>
      <?php endif; ?>
      <a href="licencje.php" class="small d-inline-block mt-2"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Pełny katalog oprogramowania i wszystkie przypisania</a>
    </div>

    <!-- ── Zakładka: Opiekun / Rodzic ───────────────────────────────────── -->
    <div class="tab-pane fade <?= $active_tab === 'opiekun' ? 'show active' : '' ?>" id="tab-opiekun" role="tabpanel" aria-labelledby="tab-btn-opiekun" tabindex="0">
      <div style="max-width:640px">
        <p class="text-muted small mb-3">
          Gdy kursant jest <strong>małoletni</strong>, nie widzi własnych rozliczeń — dostęp ma rodzic/opiekun
          (logowanie kodem SMS na numer opiekuna lub przez link wysłany e-mailem).
        </p>
        <?php if ($parent_link): ?>
        <div class="alert alert-info py-2 small">
          <div class="fw-semibold mb-1"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Link dostępu rodzica (ważny 30 dni):</div>
          <code style="word-break:break-all"><?= h($parent_link) ?></code>
        </div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
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
            <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
          </div>
        </form>
        <form method="post" class="mt-2">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="parent_link">
          <input type="hidden" name="account_id" value="<?= (int)$guardian_acc['id'] ?>">
          <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-envelope-paper me-1" aria-hidden="true"></i>Wygeneruj i wyślij link rodzicowi</button>
        </form>

        <hr class="my-3">

        <!-- Konto rodzica: login + hasło -->
        <div class="fw-semibold mb-1"><i class="bi bi-person-lock me-1 text-primary" aria-hidden="true"></i>Konto rodzica (login i hasło)</div>
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
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="parent_account_reset">
            <input type="hidden" name="account_id" value="<?= (int)$guardian_acc['id'] ?>">
            <button class="btn btn-outline-warning btn-sm"><i class="bi bi-key me-1" aria-hidden="true"></i>Resetuj hasło</button>
          </form>
          <form method="post" onsubmit="return confirm('Usunąć konto rodzica? Opiekun straci możliwość logowania loginem i hasłem.')">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="parent_account_delete">
            <input type="hidden" name="account_id" value="<?= (int)$guardian_acc['id'] ?>">
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń konto rodzica</button>
          </form>
        </div>
        <?php else: ?>
        <form method="post">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="parent_account_create">
          <input type="hidden" name="account_id" value="<?= (int)$guardian_acc['id'] ?>">
          <button class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Utwórz konto rodzica</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($authp_account): ?>
    <!-- ── Zakładka: Upoważnieni ────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $active_tab === 'upowaznieni' ? 'show active' : '' ?>" id="tab-upowaznieni" role="tabpanel" aria-labelledby="tab-btn-upowaznieni" tabindex="0">

    <?php if ($new_authp_creds): ?>
    <div class="alert alert-success d-flex gap-3 align-items-start">
      <i class="bi bi-check-circle-fill flex-shrink-0 fs-5 mt-1" aria-hidden="true"></i>
      <div class="flex-grow-1">
        <div class="fw-semibold mb-1">Hasło wygenerowane — przekaż kartkę do podpisu</div>
        <div class="font-monospace bg-white border rounded p-2 small mb-2">
          Kursant: <strong><?= h($new_authp_creds['student_name']) ?></strong><br>
          Osoba upoważniona: <strong><?= h($new_authp_creds['name']) ?></strong><br>
          Login: <strong><?= h($new_authp_creds['login']) ?></strong><br>
          Hasło: <strong><?= h($new_authp_creds['pass']) ?></strong>
        </div>
        <?php if (!empty($new_authp_creds['emailed'])): ?>
        <div class="small text-success mb-2"><i class="bi bi-envelope-check me-1" aria-hidden="true"></i>E-mail z informacją o upoważnieniu wysłany do osoby upoważnionej.</div>
        <?php endif; ?>
        <div class="d-flex gap-2 flex-wrap align-items-center">
          <button type="button" class="btn btn-sm btn-primary"
                  onclick='printCredCard("Dane dostępowe — osoba upoważniona", [["Kursant","<?= h(addslashes($new_authp_creds['student_name'])) ?>"],["Osoba upoważniona","<?= h(addslashes($new_authp_creds['name'])) ?>"],["Login","<?= h(addslashes($new_authp_creds['login'])) ?>"],["Hasło","<?= h(addslashes($new_authp_creds['pass'])) ?>"]], "<?= h(addslashes($parent_portal_url)) ?>")'>
            <i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj kartkę z hasłem
          </button>
          <a href="../kursant/authp_print.php?id=<?= (int)$new_authp_creds['id'] ?>"
             target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj kartkę dla osoby upoważnionej
          </a>
          <a href="../kursant/authp_declaration.php?id=<?= (int)$new_authp_creds['id'] ?>"
             target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Drukuj oświadczenie administratora
          </a>
          <span class="badge text-bg-warning">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Pamiętaj o wgraniu skanu po podpisaniu
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
      <div class="fw-semibold mb-1"><i class="bi bi-slash-circle me-1" aria-hidden="true"></i>Dostęp odwołany: <?= h($rev_ap['name']) ?></div>
      <div class="d-flex gap-2 flex-wrap align-items-center mt-2">
        <a href="../kursant/authp_revoke_print.php?id=<?= (int)$authp_revoked_id ?>" target="_blank"
           class="btn btn-sm btn-warning">
          <i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj dokument odwołania
        </a>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="authp_revoke_scan">
          <input type="hidden" name="account_id" value="<?= (int)$authp_account_id ?>">
          <input type="hidden" name="ap_id" value="<?= (int)$authp_revoked_id ?>">
          <input type="file" name="revoke_scan_file" accept="image/*,application/pdf"
                 class="form-control form-control-sm" style="max-width:220px">
          <button type="submit" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-cloud-upload me-1" aria-hidden="true"></i>Wgraj skan odwołania
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
                  <i class="bi bi-file-earmark-check" aria-hidden="true"></i>
                </a>
              <?php else: ?>
                <button type="button" class="btn btn-sm btn-outline-warning py-0"
                        onclick="document.getElementById('scan-form-<?= (int)$ap['id'] ?>').classList.toggle('d-none')"
                        title="Wgraj skan podpisanego dokumentu">
                  <i class="bi bi-upload" aria-hidden="true"></i>
                </button>
                <form id="scan-form-<?= (int)$ap['id'] ?>" method="post" enctype="multipart/form-data"
                      class="d-none mt-1" style="min-width:200px">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="authp_scan_upload">
                  <input type="hidden" name="ap_id" value="<?= (int)$ap['id'] ?>">
                  <input type="file" class="form-control form-control-sm mb-1" name="scan_file"
                         accept=".pdf,.png,.jpg,.jpeg" required>
                  <button type="submit" class="btn btn-sm btn-success py-0">
                    <i class="bi bi-cloud-upload me-1" aria-hidden="true"></i>Zapisz
                  </button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <a href="../kursant/authp_print.php?id=<?= (int)$ap['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-secondary py-0" title="Kartka dla osoby upoważnionej"><i class="bi bi-printer" aria-hidden="true"></i></a>
                <a href="../kursant/authp_declaration.php?id=<?= (int)$ap['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-secondary py-0" title="Oświadczenie administratora"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></a>
                <?php if (!$ap['is_active']): ?>
                <a href="../kursant/authp_revoke_print.php?id=<?= (int)$ap['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-warning py-0" title="Dokument odwołania"><i class="bi bi-file-earmark-x" aria-hidden="true"></i></a>
                <?php endif; ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="authp_toggle">
                  <input type="hidden" name="account_id" value="<?= $authp_account_id ?>">
                  <input type="hidden" name="ap_id" value="<?= (int)$ap['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-secondary py-0" title="<?= $ap['is_active'] ? 'Wstrzymaj' : 'Aktywuj' ?>">
                    <i class="bi bi-<?= $ap['is_active'] ? 'pause' : 'play' ?>-fill" aria-hidden="true"></i>
                  </button>
                </form>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="authp_reset_pass">
                  <input type="hidden" name="account_id" value="<?= $authp_account_id ?>">
                  <input type="hidden" name="ap_id" value="<?= (int)$ap['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-warning py-0" title="Resetuj hasło"><i class="bi bi-key" aria-hidden="true"></i></button>
                </form>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć upoważnienie dla <?= h(addslashes($ap['name'])) ?>?')">
                  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                  <input type="hidden" name="_op" value="authp_delete">
                  <input type="hidden" name="account_id" value="<?= $authp_account_id ?>">
                  <input type="hidden" name="ap_id" value="<?= (int)$ap['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger py-0" title="Usuń"><i class="bi bi-trash" aria-hidden="true"></i></button>
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
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
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
        <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Dodaj i wygeneruj login + hasło
      </button>
    </form>
    </div>
    <?php endif; // authp_account ?>

  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm mb-4 text-center text-muted py-5">
  <i class="bi bi-person-badge fs-1 d-block mb-2 opacity-25" aria-hidden="true"></i>
  Wybierz kursanta z listy, aby zobaczyć jego kartotekę.
</div>
<?php endif; // selected_acc ?>
</div>
<!-- ── /Prawa kolumna ──────────────────────────────────────────────────── -->

<!-- ── Lewa kolumna (master: wyszukiwarka, filtry, lista) — order-1: zawsze
     pierwsza (na mobile na górze, na desktopie po lewej) ─────────────── -->
<div class="col-12 col-lg-5 col-xxl-4 order-1">

  <!-- Utwórz konto -->
  <?php if ($no_account): ?>
  <div class="mb-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-person-plus me-2 text-success" aria-hidden="true"></i>Utwórz konto</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Login i hasło generowane automatycznie.
          Kursant ma dostęp <strong>tylko</strong> do panelu kursanta.
        </p>
        <form method="post">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
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
          <?php if (ti_referral_settings()['enabled']): ?>
          <div class="mb-3">
            <label class="form-label small">Kod polecający (opcjonalnie)</label>
            <input type="text" class="form-control text-uppercase" name="referral_code" maxlength="6"
                   placeholder="np. AB12CD" style="letter-spacing:.15em">
            <div class="form-text">Jeśli ktoś polecił zajęcia temu kursantowi — rabat dla obu stron naliczy się automatycznie.</div>
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-success w-100">
            <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Utwórz konto
          </button>
        </form>

        <hr class="my-3">

        <button type="button" class="btn btn-outline-success w-100" data-bs-toggle="modal" data-bs-target="#bulkCreateModal">
          <i class="bi bi-people me-1" aria-hidden="true"></i>Utwórz zbiorczo (<?= count($no_account) ?>)
        </button>
      </div>
    </div>
  </div>

  <!-- Modal: zbiorcze tworzenie kont -->
  <div class="modal fade" id="bulkCreateModal" tabindex="-1" aria-labelledby="bulkCreateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <form method="post" class="modal-content">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
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
  <div>
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center">
        <i class="bi bi-people me-2 text-primary" aria-hidden="true"></i>Konta kursantów
        <span class="badge bg-secondary ms-2"><?= count($accounts) ?></span>
      </div>
      <?php if (!$accounts): ?>
      <div class="card-body text-muted">Brak kont. Utwórz pierwsze konto dla beneficjenta.</div>
      <?php else: ?>
      <div class="card-body pb-2">
        <label class="visually-hidden" for="studentSearch">Szukaj kursanta po nazwisku lub loginie</label>
        <input type="search" id="studentSearch" class="form-control form-control-sm mb-2"
               placeholder="Szukaj po nazwisku lub loginie…">
        <div class="d-flex flex-wrap gap-1" role="group" aria-label="Filtruj po statusie">
          <button type="button" class="btn btn-sm btn-outline-secondary student-filter-chip active" data-status="all" aria-pressed="true">Wszyscy</button>
          <button type="button" class="btn btn-sm btn-outline-secondary student-filter-chip" data-status="aktywne" aria-pressed="false">Aktywni</button>
          <button type="button" class="btn btn-sm btn-outline-secondary student-filter-chip" data-status="zablokowane" aria-pressed="false">Zablokowani</button>
          <button type="button" class="btn btn-sm btn-outline-secondary student-filter-chip" data-status="maloletni" aria-pressed="false">Małoletni</button>
        </div>
      </div>
      <!-- Zwarta lista — bez tabeli/przewijania w poziomie (kolumna jest teraz
           węższa niż dawniej, pełna tabela by się nie mieściła). Nr kursanta,
           MS365 i ostatnie logowanie przeniesione do zakładki „Ogólne". -->
      <div class="list-group list-group-flush" id="accounts-table">
        <?php foreach ($accounts as $a): $is_sel = $selected_id === (int)$a['id'];
          $_status_flags = trim(($a['is_active'] ? 'aktywne' : 'zablokowane') . (!empty($a['is_minor']) ? ' maloletni' : ''));
        ?>
        <a href="?selected=<?= (int)$a['id'] ?>#detail-panel"
           class="list-group-item list-group-item-action d-flex justify-content-between align-items-start gap-2 <?= $is_sel ? 'active' : '' ?> <?= $a['is_active'] ? '' : 'opacity-75' ?>"
           data-name="<?= h(mb_strtolower($a['client_name'])) ?>"
           data-login="<?= h(mb_strtolower($a['login'])) ?>"
           data-status="<?= h($_status_flags) ?>"
           <?= $is_sel ? 'aria-current="true"' : '' ?>>
          <span class="text-truncate">
            <span class="fw-semibold d-block text-truncate"><?= h($a['client_name']) ?></span>
            <span class="small font-monospace <?= $is_sel ? '' : 'text-muted' ?>"><?= h($a['login']) ?></span>
          </span>
          <span class="text-end small flex-shrink-0 <?= $is_sel ? '' : 'text-muted' ?>">
            <?php
              $_status_words = [$a['is_active'] ? 'Aktywne' : 'Zablokowane'];
              if (!empty($a['is_minor'])) $_status_words[] = 'małoletni';
              if (!empty($a['child_access_blocked'])) $_status_words[] = 'wstrzymany przez opiekuna';
            ?>
            <?= h(implode(' · ', $_status_words)) ?>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>
<!-- ── /Lewa kolumna (zamyka się poniżej razem z row — patrz koniec pliku) ── -->

<script>
// Numer konta do wpłat — teraz jedna instancja w zakładce Płatności (nie
// jedna na kursanta w liście), więc bez sufiksów ID.
function tiPayAccToggle() {
  var sel  = document.getElementById('payAccSourceSel');
  var wrap = document.getElementById('payAccCustomWrap');
  if (sel && wrap) wrap.style.display = (sel.value === 'custom') ? '' : 'none';
}
function tiPayAccPreview() {
  var sel = document.getElementById('payAccSourceSel');
  if (!sel) return;
  var val = sel.value;
  var numberEl = document.getElementById('payAccPreviewNumber');
  var typeEl   = document.getElementById('payAccPreviewType');

  if (val === 'custom') {
    var input = document.getElementById('payAccCustomInput');
    var num = (input.value || '').trim();
    if (!num) { alert('Podaj numer konta.'); return; }
    numberEl.textContent = num;
    typeEl.textContent   = 'Numer indywidualny (niestandardowy)';
    document.getElementById('payAccSourceField').value = 'custom';
    document.getElementById('payAccOrgIdxField').value = '';
    document.getElementById('payAccCustomField').value = num;
  } else {
    var opt = sel.options[sel.selectedIndex];
    numberEl.textContent = opt.getAttribute('data-nrb') || '';
    typeEl.textContent   = opt.getAttribute('data-label') || '';
    document.getElementById('payAccSourceField').value = 'org';
    document.getElementById('payAccOrgIdxField').value = val.split(':')[1] || '';
    document.getElementById('payAccCustomField').value = '';
  }

  document.getElementById('payAccStep1').style.display = 'none';
  document.getElementById('payAccStep2').style.display = '';
  document.getElementById('payAccNextBtn').classList.add('d-none');
  document.getElementById('payAccBackBtn').classList.remove('d-none');
  document.getElementById('payAccSubmitBtn').classList.remove('d-none');
}
function tiPayAccBack() {
  document.getElementById('payAccStep1').style.display = '';
  document.getElementById('payAccStep2').style.display = 'none';
  document.getElementById('payAccNextBtn').classList.remove('d-none');
  document.getElementById('payAccBackBtn').classList.add('d-none');
  document.getElementById('payAccSubmitBtn').classList.add('d-none');
}

// Filtr master-listy: szukaj po nazwisku/loginie + chipy statusu (klient,
// bez przeładowania — dane już są w DOM, to tylko widoczność wierszy).
(function () {
  var search = document.getElementById('studentSearch');
  var chips  = document.querySelectorAll('.student-filter-chip');
  var rows   = document.querySelectorAll('#accounts-table .list-group-item[data-name]');
  var activeStatus = 'all';

  function apply() {
    var q = (search && search.value || '').trim().toLowerCase();
    rows.forEach(function (row) {
      var matchesText = !q || row.dataset.name.indexOf(q) !== -1 || row.dataset.login.indexOf(q) !== -1;
      var matchesStatus = activeStatus === 'all' || row.dataset.status.indexOf(activeStatus) !== -1;
      row.style.display = (matchesText && matchesStatus) ? '' : 'none';
    });
  }

  if (search) search.addEventListener('input', apply);
  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      chips.forEach(function (c) { c.classList.remove('active'); c.setAttribute('aria-pressed', 'false'); });
      chip.classList.add('active');
      chip.setAttribute('aria-pressed', 'true');
      activeStatus = chip.dataset.status;
      apply();
    });
  });
})();
</script>

</div>
</div>
</main>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
