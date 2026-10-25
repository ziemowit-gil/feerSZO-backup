<?php
/**
 * karty30/ti/dydaktyk/konta.php — Ewidencja Uczestników: konta kursantów w panelu (panel kierownika).
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
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_authorized_persons.php'; // wspólne reguły upoważnień (też panel kursanta)
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_payment_account_schema.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/payment_portal/logic/paymentPortal.php'; // rachunek wirtualny z nr kursanta
require_once dirname(dirname(dirname(__DIR__))) . '/modules/sprawdz_konto/logic/sprawdz_konto.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_licencje/logic/tiLicencjeManager.php'; // licencje kursantów: przypisanie, forma rozliczenia
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_products/logic/tiProductReminders.php'; // usługi i produkty: domeny, hosting, inne + przypomnienia

$me       = dyd_require();
/** Tryby panelu kursanta (k30_ti_student_accounts.simple_mode) — te same wartości co STUDENT_SIMPLE_MODES w kursant/auth.php. */
const KK_SIMPLE_MODES = [0 => 'pełny panel (albo wg grupy uproszczonej)', 1 => 'uproszczony — tylko terminy', 2 => 'uproszczony — terminy + płatności', 3 => 'uproszczony — terminy + płatności uproszczone', 4 => 'beneficjent konsultacji — konsultacje i rozliczenia'];
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '') ?: 'Kierownik';
karty30_migrate();
ti_licencje_migrate();   // kolumny formy rozliczenia licencji (zakładka „Licencje”)

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
        sms_send($phone, $msg, 'access_data');
        return ' Hasło wysłano SMS-em.';
    } catch (\Throwable $e) {
        return ' (błąd wysyłki SMS: ' . $e->getMessage() . ')';
    }
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

        // Login w formacie imie.nazwisko (TiLoginFormat), unikalny we wszystkich loginach TI
        require_once dirname(__DIR__, 3) . '/modules/ti_login/logic/TiLoginFormat.php';
        $login = TiLoginFormat::nameLogin((string)$c['name']);
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

            require_once dirname(__DIR__, 3) . '/modules/ti_login/logic/TiLoginFormat.php';
            $login = TiLoginFormat::nameLogin((string)$c['name']);   // imie.nazwisko
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

    // Reset do 1 logowania: brak daty ostatniego logowania = następne wejście idzie kodem SMS (jak przy nowym koncie).
    if ($op === 'reset_first_login') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one("SELECT login FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if (!$acc) { flash_set('danger','Konto nie istnieje.'); header('Location: konta.php'); exit; }
        db()->prepare("UPDATE k30_ti_student_accounts SET last_login=NULL, must_change_password=1, updated_at=datetime('now') WHERE id=?")
           ->execute([$aid]);
        flash_set('success', "Konto {$acc['login']} wraca do 1 logowania: kolejne wejście kodem SMS z karty kursanta.");
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
                m365_edu()->set_password($acc['ms_user_id'], $pass, true);
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

    // Numer rachunku wirtualnego kursanta — fizycznie zapisany (payment_portal_users.individual_nrb)
    if ($op === 'set_vnrb' || $op === 'assign_vnrb_pool') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]);
        if ($acc) {
            $cid = (int)$acc['client_id'];
            $by_name = (string)($me['name'] ?? '');
            $uid_n = (int)($me['user_id'] ?? 0) ?: null;
            if ($op === 'assign_vnrb_pool') {
                $cur = pp_user($cid);
                if ($cur && trim((string)$cur['individual_nrb']) !== '') flash_set('warning', 'Kursant ma już numer rachunku — użyj pola poniżej, aby go zmienić.');
                else {
                    $n = pp_ti_sync_nrb($cid, $by_name, $uid_n);
                    flash_set($n ? 'success' : 'warning', $n ? 'Nadano numer z puli: ' . pp_nrb_format($n) : 'Brak wolnych numerów w puli TI (albo kursant jest wirtualny).');
                }
            } elseif (($cur0 = pp_user($cid)) && trim((string)$cur0['individual_nrb']) !== '') {
                flash_set('danger', 'Numer rachunku został nadany w Płatnościach — edycja zablokowana. Zmień go w panelu płatności (/platnosci/admin.php).');
            } else {
                $raw = trim((string)($_POST['vnrb'] ?? ''));
                if ($raw !== '' && is_string($u = pp_user_ensure($cid, $by_name, $uid_n))) { flash_set('danger', $u); }
                else {
                    $err = pp_set_nrb($cid, $raw, $by_name, $uid_n);
                    if ($err) flash_set('danger', $err);
                    else {
                        if ($raw !== '') db()->prepare("UPDATE pp_vnrb_pool SET participant_id=?, assigned_at=datetime('now') WHERE nrb=? AND participant_id IS NULL AND crm_contact_id IS NULL")
                                           ->execute([$cid, pp_nrb_normalize($raw)]);
                        flash_set('success', $raw === '' ? 'Numer rachunku usunięty.' : 'Numer rachunku zapisany: ' . pp_nrb_format($raw));
                    }
                }
            }
        }
        header('Location: konta.php?selected=' . $aid . '#detail-panel'); exit;
    }

    if ($op === 'toggle_no_billing') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]);
        if ($acc) {
            $v = empty($acc['no_billing']) ? 1 : 0;
            db()->prepare("UPDATE k30_ti_student_accounts SET no_billing=?, updated_at=datetime('now') WHERE id=?")->execute([$v, $aid]);
            flash_set('success', $v ? 'Kursant „bez rozliczeń”: nie naliczamy należności i nie wysyłamy SMS ani e-maili (istniejące rozliczenia i numer rachunku zostają).'
                                    : 'Kursant znów rozliczany i powiadamiany.');
        }
        header('Location: konta.php?selected=' . $aid); exit;
    }

    // „Tylko rozliczenie licencji” — uczestnik bez zajęć; nie naliczamy lekcji (jak „bez rozliczeń”), a każda licencja musi mieć kwotę
    // miesięczną i dzień rozliczenia. Po włączeniu prosimy o dodanie licencji (zakładka Licencje).
    if ($op === 'toggle_licenses_only') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]);
        if ($acc) {
            $v = empty($acc['licenses_only']) ? 1 : 0;
            db()->prepare("UPDATE k30_ti_student_accounts SET licenses_only=?, updated_at=datetime('now') WHERE id=?")->execute([$v, $aid]);
            if ($v) {
                flash_set('warning', 'Włączono „tylko rozliczenie licencji”: lekcje nie są naliczane. Dodaj teraz licencję z kwotą miesięczną i dniem rozliczenia — uczestnik dostanie e-mail i SMS z kwotą i terminem, a potem przypomnienie dzień przed każdym terminem.');
                header('Location: konta.php?selected=' . $aid . '&ptab=licencje'); exit;
            }
            flash_set('success', 'Wyłączono „tylko rozliczenie licencji”. Zajęcia znów rozliczane według ustawień; istniejące licencje z rozliczeniem miesięcznym działają dalej.');
        }
        header('Location: konta.php?selected=' . $aid); exit;
    }

    // „Wymaga dokumentu potwierdzającego” — po zalogowaniu kursant wgrywa skan albo składa oświadczenie (modules/ti_student_docs)
    if ($op === 'doc_required') {
        require_once dirname(__DIR__, 3) . '/modules/ti_student_docs/logic/studentDocs.php';
        $aid = (int)($_POST['account_id'] ?? 0);
        if ($aid && db_one("SELECT 1 FROM k30_ti_student_accounts WHERE id=?", [$aid])) {
            $on = !empty($_POST['requires_doc']); $reset = !empty($_POST['doc_reset']);
            TiStudentDocs::setRequired($aid, $on, (string)($_POST['requires_doc_label'] ?? ''), $reset, $uid, $dyd_name);
            flash_set('success', $on ? 'Wymóg dokumentu potwierdzającego zapisany' . ($reset ? ' — kursant złoży dokument lub oświadczenie przy najbliższym logowaniu.' : '.') : 'Wymóg dokumentu potwierdzającego wyłączony.');
        }
        header('Location: konta.php?selected=' . $aid); exit;
    }

    if ($op === 'set_simple') {
        // Kursant uproszczony: 0 = pełny panel, 1 = tylko terminy, 2 = terminy + płatności (egzekwowanie: kursant/auth.php)
        $aid  = (int)($_POST['account_id'] ?? 0);
        $mode = (int)($_POST['simple_mode'] ?? 0);
        $acc  = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]);
        if ($acc && isset(KK_SIMPLE_MODES[$mode]) && (int)($acc['simple_mode'] ?? 0) !== $mode) {
            db()->prepare("UPDATE k30_ti_student_accounts SET simple_mode=?, updated_at=datetime('now') WHERE id=?")->execute([$mode, $aid]);
            ti_account_log($aid, 'simple_mode', 'Tryb panelu: ' . KK_SIMPLE_MODES[$mode] . '.', (int)($me['user_id'] ?? 0) ?: null, (string)($me['name'] ?? ''));
            flash_set('success', match ($mode) {
                1 => 'Kursant uproszczony: w panelu widzi tylko terminy zajęć (Moje lekcje) — może je odwołać, przesunąć lub usprawiedliwić nieobecność.',
                2 => 'Kursant uproszczony z płatnościami: terminy zajęć oraz Płatności, Rozliczenia i Portfel. Reszta panelu jest ukryta.',
                3 => 'Kursant uproszczony z płatnościami uproszczonymi: terminy zajęć i jeden ekran „Opłaty” (ile do zapłaty, numer konta, płatność online).',
                4 => 'Beneficjent konsultacji: w panelu tylko Moje konsultacje (terminy, karty z etapem, PDF) i Rozliczenia.',
                default => 'Kursant ma znów dostęp do całego panelu.',
            });
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
        header('Location: ' . tiPanelUrl('kursant', 'index.php'));
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

    // Licencje na oprogramowanie (inne niż MS365) — przypisanie/unieważnienie wprost z panelu akcji
    // konta kursanta. Te same reguły co na ekranie „Licencje kursantów” (licencjeKursantow.php,
    // moduł modules/ti_licencje): forma rozliczenia obowiązkowa, powiadomienie SMS / e-mail o przypisaniu.
    if ($op === 'license_assign') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one("SELECT client_id FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if (!$acc) {
            flash_set('danger', 'Nie znaleziono konta kursanta.');
            header('Location: konta.php?selected=' . $aid . '&ptab=licencje'); exit;
        }
        // client_id z konta (lewa strona „+” wygrywa) — formularz nie może go podmienić
        $res = TiLicencjeManager::assign(['client_id' => (int)$acc['client_id']] + $_POST, $uid, !empty($_POST['notify']) ? ['email', 'sms'] : []);
        if ($res['ok']) {
            $n = $res['notify'];
            flash_set($n && !$n['ok'] ? 'warning' : 'success',
                      'Licencja przypisana kursantowi.' . ($n ? ' Powiadomienia — ' . $n['msg'] : ' Bez powiadomienia.'));
        } else {
            flash_set('danger', 'Nie przypisano licencji: ' . implode(' ', $res['errors']));
        }
        header('Location: konta.php?selected=' . $aid . '&ptab=licencje'); exit;
    }

    // Ponowna wysyłka informacji o przypisaniu licencji (przy rozliczeniu miesięcznym: e-mail + SMS z kwotą, formą i dniem)
    if ($op === 'license_notify') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $res = TiLicencjeManager::notifyAssigned((int)($_POST['assignment_id'] ?? 0), $uid, true);
        flash_set($res['ok'] ? 'success' : 'warning', 'Powiadomienie o licencji — ' . $res['msg']);
        header('Location: konta.php?selected=' . $aid . '&ptab=licencje'); exit;
    }

    if ($op === 'license_revoke') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $res = TiLicencjeManager::revoke((int)($_POST['assignment_id'] ?? 0), '', $uid);
        flash_set($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'Licencja unieważniona.' : $res['error']);
        header('Location: konta.php?selected=' . $aid . '&ptab=licencje'); exit;
    }

    // Usługi i produkty kursanta (domeny, hosting, inne) — modules/ti_products. client_id zawsze z konta, nie z formularza.
    if ($op === 'product_admin_email') {   // adres(y) kopii powiadomień o nowych usługach (puste = konta z rolą admin)
        $aid = (int)($_POST['account_id'] ?? 0);
        $em  = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($_POST['admin_email'] ?? ''))));
        $bad = array_filter($em, fn($e) => !filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($bad) flash_set('danger', 'Nieprawidłowy adres e-mail: ' . implode(', ', $bad));
        else {
            org_setting_set('ti_products_admin_email', implode(', ', $em));
            // przypomnienia o wygasających domenach i hostingu (cron/ti_product_reminders.php)
            org_setting_set('ti_prodrem_enabled', !empty($_POST['rem_enabled']) ? '1' : '0');
            $rd = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string)($_POST['rem_days'] ?? ''))), fn($x) => $x >= 0 && $x <= 365)));
            rsort($rd);
            org_setting_set('ti_prodrem_days', $rd ? implode(',', $rd) : TiProductReminders::DEFAULT_DAYS);
            flash_set('success', 'Ustawienia powiadomień o usługach zapisane.');
        }
        header('Location: konta.php?selected=' . $aid . '&ptab=produkty#tab-produkty'); exit;
    }
    if ($op === 'product_save' || $op === 'product_delete') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one("SELECT client_id FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        $pid = (int)($_POST['product_id'] ?? 0);
        if (!$acc) {
            flash_set('danger', 'Nie znaleziono konta kursanta.');
        } elseif ($op === 'product_delete') {
            $ok = TiProducts::delete((int)$acc['client_id'], $pid);
            flash_set($ok ? 'success' : 'danger', $ok ? 'Produkt usunięty.' : 'Nie znaleziono produktu tego kursanta.');
        } else {
            $res = TiProducts::save((int)$acc['client_id'], $_POST, $pid, $uid);
            if ($res['ok']) {
                // Nowa usługa: e-mail „uruchomiono w Twoim imieniu” do kursanta (+ opiekun małoletniego) i kopia do administratora
                $note = (!$pid && !empty($_POST['notify'])) ? ' Powiadomienia — ' . TiProducts::notifyStarted($res['id'], $dyd_name) . '.' : '';
                // Rozliczenie cykliczne (kwota miesięczna): e-mail + SMS z kwotą, formą i dniem rozliczenia — jak przy licencjach
                if (!$pid && !empty($_POST['notify']) && trim((string)($_POST['monthly_amount'] ?? '')) !== '') {
                    require_once dirname(__DIR__, 3) . '/modules/ti_licencje/logic/tiLicencjeNotify.php';
                    require_once dirname(__DIR__, 3) . '/modules/ti_licencje/logic/tiLicencjeBilling.php';
                    $bs = TiLicencjeBilling::sendStart((int)$res['id'], ['email', 'sms'], $uid, 'product');
                    $note .= ' Rozliczenie — ' . $bs['msg'];
                }
                flash_set('success', ($pid ? 'Zmiany produktu zapisane.' : 'Produkt dodany.') . $note);
            }
            else {
                flash_set('danger', 'Nie zapisano produktu: ' . implode(' ', $res['errors']));
                $_SESSION['ti_product_old'] = $_POST;   // formularz wraca z wpisanymi danymi
                header('Location: konta.php?selected=' . $aid . '&ptab=produkty' . ($pid ? '&pedit=' . $pid : '&pnew=1') . '#tab-produkty'); exit;
            }
        }
        header('Location: konta.php?selected=' . $aid . '&ptab=produkty#tab-produkty'); exit;
    }

    // Wygeneruj link magiczny rodzica i wyślij go e-mailem (jeśli jest adres)
    if ($op === 'parent_link') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if ($acc) {
            $token = parent_make_token($aid);
            $url   = tiPanelUrl('kursant', 'parent.php?t=' . $token);
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
        // login rodzica: 4 cyfry + „r” (TiLoginFormat; przy koncie SzK 1234k → 1234r)
        require_once dirname(__DIR__, 3) . '/modules/ti_login/logic/TiLoginFormat.php';
        $_al = db_one("SELECT login FROM k30_ti_student_accounts WHERE id=?", [$aid]);
        $login = TiLoginFormat::numberLogin(TiLoginFormat::PARENT, TiLoginFormat::numberOf((string)($_al['login'] ?? '')));
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
                sms_send($gphone, "{$org} - panel rodzica. Login: {$login}, haslo: {$pass}", 'access_data');
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
                sms_send($gphone, "{$org} - panel rodzica. Login: {$acc['parent_login']}, haslo: {$pass}", 'access_data');
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
            $ap_added_by = $dyd_name;
            // Walidacja, login, hasło i zapis — wspólne z panelem kursanta (includes/ti_authorized_persons.php).
            // Kierownik nie podlega limitowi TI_AUTHP_MAX_ACTIVE i może dodać do nieaktywnego konta.
            $ap_res = ti_authp_create([
                'name'   => $_POST['ap_name']   ?? '',
                'email'  => $_POST['ap_email']  ?? '',
                'notes'  => $_POST['ap_notes']  ?? '',
                'reason' => $_POST['ap_reason'] ?? '',
            ], $aid, $ap_added_by, ['enforce_limit' => false, 'require_active' => false]);
            if (empty($ap_res['ok'])) {
                flash_set('danger', $ap_res['error'] ?? 'Nie udało się dodać upoważnienia.');
                header('Location: konta.php?authp=' . $aid); exit;
            }
            $ap_id     = (int)$ap_res['id'];
            $ap_name   = $ap_res['name'];
            $ap_login  = $ap_res['login'];
            $ap_pass   = $ap_res['pass'];
            $ap_email  = $ap_res['email'];
            $ap_notes  = $ap_res['notes'];
            $ap_reason = $ap_res['reason'];
            ti_account_log($aid, 'authp_added', "Dodano osobę upoważnioną: {$ap_name} (login: {$ap_login}).", null, $ap_added_by);
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
                    $portal_link = tiPanelUrl('kursant', 'parent.php?role=up');
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
                ti_authp_revoke($ap_id_edit, $aid, $revoker);
                ti_account_log($aid, 'authp_revoked', "Odwołano upoważnienie: {$ap['name']}.", null, $revoker);
                $_SESSION['authp_revoked_id'] = $ap_id_edit;
                flash_set('success', 'Dostęp wstrzymany. Wydrukuj dokument odwołania.');
            } else {
                ti_authp_restore($ap_id_edit, $aid);
                ti_account_log($aid, 'authp_toggled', "Przywrócono dostęp osobie upoważnionej: {$ap['name']}.", null, $revoker);
                flash_set('success', 'Dostęp przywrócony.');
            }
        } elseif ($op === 'authp_delete') {
            db()->prepare("DELETE FROM k30_ti_authorized_persons WHERE id=?")->execute([$ap_id_edit]);
            flash_set('success', "Upoważnienie usunięte: {$ap['name']}.");
        } elseif ($op === 'authp_reset_pass') {
            $np = ti_authp_gen_pass();
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
// Grupy kursantów do tabeli ewidencji i filtra „Grupa” — tylko aktywne zapisy
// w niezamkniętych grupach. client_id => [course_id => etykieta].
$acc_groups = []; $group_opts = [];
$_acc_cids = array_flip(array_map('intval', array_column($accounts, 'client_id')));
foreach (db_all(
    "SELECT e.client_id, c.id AS course_id, c.name, c.display_name, c.group_code
     FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id=e.course_id
     WHERE e.status='active' AND c.closed_at IS NULL"
) as $_g) {
    if (!isset($_acc_cids[(int)$_g['client_id']])) continue;
    $_lbl = trim((string)$_g['display_name']) !== '' ? $_g['display_name'] : $_g['name'];
    if (trim((string)$_g['group_code']) !== '') $_lbl = $_g['group_code'] . ' · ' . $_lbl;
    $acc_groups[(int)$_g['client_id']][(int)$_g['course_id']] = $_lbl;
    $group_opts[(int)$_g['course_id']] = $_lbl;
}
uasort($group_opts, fn($a, $b) => strcasecmp($a, $b));
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
// Podgląd skanu dokumentu potwierdzającego (tylko kadra panelu — ta strona)
if (isset($_GET['doc_view'])) {
    require_once dirname(__DIR__, 3) . '/modules/ti_student_docs/logic/studentDocs.php';
    $_dr = TiStudentDocs::get((int)$_GET['doc_view']);
    $_df = $_dr ? TiStudentDocs::filePath($_dr) : null;
    if (!$_df) { http_response_code(404); exit('Brak pliku.'); }
    try { k30_log_access('student_document', (int)$_GET['doc_view'], 'view'); } catch (\Throwable $e) {}
    $_ext = pathinfo($_df, PATHINFO_EXTENSION);
    header('Content-Type: ' . ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$_ext]);
    header('Content-Disposition: inline; filename="dokument-' . (int)$_GET['doc_view'] . '.' . $_ext . '"');
    header('Cache-Control: no-store, private');
    readfile($_df); exit;
}
$selected_acc = null;
foreach ($accounts as $a) {
    if ((int)$a['id'] === $selected_id) { $selected_acc = $a; break; }
}

$active_tab = $_GET['ptab'] ?? (isset($_GET['guardian']) ? 'opiekun' : (isset($_GET['authp']) ? 'upowaznieni' : 'ogolne'));
if (!in_array($active_tab, ['ogolne', 'platnosci', 'licencje', 'produkty', 'dokumenty', 'opiekun', 'upowaznieni'], true)) $active_tab = 'ogolne';

// Opiekun/rodzic — te same kolumny co $selected_acc (k30_ti_student_accounts),
// więc bez osobnego zapytania.
$guardian_acc = $selected_acc;

$portal_url         = tiPanelUrl('kursant', 'login.php');
$parent_portal_url  = tiPanelUrl('kursant', 'parent.php');

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

$KP_TITLE  = 'Ewidencja Uczestników — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'konta.php'; $KIER_LABEL = 'Ewidencja Uczestników';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<style>
  /* Ewidencja Uczestników — szczegóły konta w tej samej szacie co karty po lewej (ti-skin): jasny nagłówek, pasek szybkich akcji,
     zakładki poziomo, a w „Ogólne” sekcje wierszami „opis | akcja” zamiast rozsypanych przycisków. */
  #detail-panel > .card-header { gap: .5rem; padding: .55rem .8rem !important; font-size: .95rem; }
  #detail-panel .kk-name { font-size: 1.05rem; font-weight: 700; color: var(--ti-navy); }
  #detail-panel .kk-akcje { background: #f7f9fc; border-bottom: 1px solid var(--ti-grid); padding: .5rem .8rem !important; }
  #detail-panel .kk-akcje .btn { background: #fff; }
  #detail-panel #detailTabs { padding: .5rem .8rem 0 !important; border-bottom: 1px solid var(--ti-bar-border); gap: .15rem; }
  #detail-panel #detailTabs .nav-link { color: var(--ti-link); font-weight: 600; font-size: .85rem; padding: .45rem .8rem; border-radius: 6px 6px 0 0; }
  #detail-panel #detailTabs .nav-link.active { color: var(--ti-navy); background: #fff; border-color: var(--ti-bar-border) var(--ti-bar-border) #fff; }
  #detail-panel #detailTabs .nav-link:focus-visible { outline: 3px solid #facc15; outline-offset: 1px; box-shadow: none; }
  #detail-panel > .tab-content { padding: 1rem .9rem !important; }
  .kk-sec { border: 1px solid var(--ti-grid); border-radius: 6px; margin-bottom: 1rem; background: #fff; }
  .kk-sec > h3 { font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--ti-navy);
    background: var(--ti-zebra); border-bottom: 1px solid var(--ti-grid); margin: 0; padding: .45rem .8rem; border-radius: 6px 6px 0 0; }
  .kk-sec > h3 .bi { margin-right: .35rem; }
  .kk-row { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem 1rem; padding: .6rem .8rem; border-top: 1px solid #e6ebf1; }
  .kk-sec > h3 + .kk-row { border-top: 0; }
  .kk-row-info { flex: 1 1 260px; min-width: 0; }
  .kk-row-title { font-weight: 600; font-size: .88rem; }
  .kk-row-desc { font-size: .78rem; color: #5b6576; }
  .kk-row-ctl { display: flex; align-items: center; flex-wrap: wrap; gap: .4rem; }
  .kk-row-ctl form { margin: 0; }
  .kk-val { font-size: .88rem; }
  .kk-sec-danger { border-color: #f1b8b8; }
  .kk-sec-danger > h3 { background: #fdf1f1; color: #9b1c1c; border-bottom-color: #f1b8b8; }
  .kk-state { font-size: .72rem; font-weight: 600; padding: .1rem .45rem; border-radius: 999px; border: 1px solid; }
  .kk-state-on  { color: #8a4b00; background: #fff6e5; border-color: #f3cf8b; }
  .kk-state-off { color: #4b5563; background: #f3f4f6; border-color: #d1d5db; }
  /* Ewidencja — tabela uczestników na całą szerokość */
  .ewid-table thead th { background: var(--ti-zebra); color: var(--ti-navy); font-size: .8rem; white-space: nowrap; vertical-align: bottom; }
  .ewid-sort { all: unset; cursor: pointer; font-weight: 700; display: inline-flex; align-items: center; border-radius: 3px; }
  .ewid-sort:hover { text-decoration: underline; }
  .ewid-sort:focus-visible, .ewid-link:focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
  .ewid-table tbody tr { cursor: pointer; }
  .ewid-table tbody tr.ewid-off td { color: #5b6576; }
  .ewid-link { color: var(--ti-link); }

  /* Szata Metro UI dla szczegółów konta: płasko, zero zaokrągleń, ciemna belka z nazwiskiem, pivot zamiast kart, akcent cyan */
  #detail-panel { font-family: "Segoe UI", "Open Sans", system-ui, sans-serif; box-shadow: none !important; border: 1px solid #d6d6d6 !important; }
  #detail-panel, #detail-panel *, #detail-panel .btn, #detail-panel .badge, #detail-panel .form-control, #detail-panel .form-select, #detail-panel .nav-link { border-radius: 0 !important; }
  #detail-panel > .card-header { background: #1d1d1d; color: #fff; border: 0; padding: .8rem 1rem !important; }
  #detail-panel > .card-header .bi { color: #1ba1e2 !important; font-size: 1.3rem; }
  #detail-panel .kk-name { color: #fff; font-size: 1.5rem; font-weight: 300; letter-spacing: .01em; }
  #detail-panel > .card-header .font-monospace { color: #c8c8c8 !important; }
  #detail-panel > .card-header .badge { font-weight: 600; letter-spacing: .03em; border: 0 !important; }
  #detail-panel > .card-header .badge.bg-success { background: #008a00 !important; }
  #detail-panel > .card-header .badge.bg-secondary { background: #647687 !important; }
  #detail-panel > .card-header .btn-outline-secondary { color: #fff; border-color: #fff; background: transparent; }
  #detail-panel > .card-header .btn-outline-secondary:hover, #detail-panel > .card-header .btn-outline-secondary:focus { background: #fff; color: #1d1d1d; }
  #detail-panel .kk-akcje { background: #f4f4f4; border-bottom: 1px solid #d6d6d6; padding: .6rem 1rem !important; }
  #detail-panel .kk-akcje .btn { background: #1ba1e2; color: #fff; border: 0; font-weight: 600; }
  #detail-panel .kk-akcje .btn:hover, #detail-panel .kk-akcje .btn:focus { background: #0050ef; color: #fff; }
  #detail-panel .kk-akcje .btn-outline-secondary { background: #1d1d1d; }
  #detail-panel .kk-akcje .btn-outline-warning { background: #f0a30a; color: #1d1d1d; }
  #detail-panel .kk-akcje .btn .text-body-secondary { color: rgba(255,255,255,.8) !important; }
  #detail-panel #detailTabs { border-bottom: 1px solid #d6d6d6; gap: 1.2rem; padding: .6rem 1rem 0 !important; }
  #detail-panel #detailTabs .nav-link { border: 0; background: none; color: #647687; font-size: 1.05rem; font-weight: 300; padding: .35rem 0; border-bottom: 3px solid transparent; }
  #detail-panel #detailTabs .nav-link:hover { color: #1d1d1d; }
  #detail-panel #detailTabs .nav-link.active { color: #1d1d1d; background: none; border-bottom-color: #1ba1e2; }
  #detail-panel #detailTabs .nav-link .bi { color: inherit; }
  #detail-panel .kk-sec { border: 1px solid #d6d6d6; border-left: 6px solid #1ba1e2; }
  #detail-panel .kk-sec > h3 { background: #fff; color: #647687; font-size: .78rem; letter-spacing: .08em; border-bottom: 1px solid #e6e6e6; padding: .55rem .9rem; }
  #detail-panel .kk-sec > h3 .bi { color: #1ba1e2; }
  #detail-panel .kk-row { border-top-color: #eeeeee; padding: .65rem .9rem; }
  #detail-panel .kk-row-title { font-weight: 600; color: #1d1d1d; }
  #detail-panel .kk-row-desc { color: #647687; }
  #detail-panel .kk-row-ctl .btn-outline-secondary, #detail-panel .kk-row-ctl .btn-outline-dark { background: #e6e6e6; color: #1d1d1d; border: 0; font-weight: 600; }
  #detail-panel .kk-row-ctl .btn-outline-secondary:hover, #detail-panel .kk-row-ctl .btn-outline-dark:hover { background: #1d1d1d; color: #fff; }
  #detail-panel .kk-row-ctl .btn-outline-primary { background: #1ba1e2; color: #fff; border: 0; font-weight: 600; }
  #detail-panel .kk-row-ctl .btn-outline-primary:hover { background: #0050ef; }
  #detail-panel .kk-row-ctl .btn-outline-danger { background: #a20025; color: #fff; border: 0; font-weight: 600; }
  #detail-panel .badge.text-bg-success { background: #008a00 !important; }
  #detail-panel .badge.text-bg-secondary { background: #647687 !important; }
  #detail-panel .kk-state { border: 0; font-weight: 600; letter-spacing: .02em; }
  #detail-panel .kk-state-on  { background: #f0a30a; color: #1d1d1d; }
  #detail-panel .kk-state-off { background: #e6e6e6; color: #1d1d1d; }
  #detail-panel .kk-sec-danger { border-color: #e6b3bf; border-left-color: #a20025; }
  #detail-panel .kk-sec-danger > h3 { background: #fff; color: #a20025; }
  #detail-panel .kk-sec-danger > h3 .bi { color: #a20025; }
  #detail-panel .btn:focus-visible, #detail-panel .form-control:focus, #detail-panel .form-select:focus { outline: 3px solid #1ba1e2; outline-offset: 1px; box-shadow: none; }
</style>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1600px">

<div class="d-flex align-items-center flex-wrap mb-3 gap-2">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-person-badge text-primary me-2" aria-hidden="true"></i>Ewidencja Uczestników</h1>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <?php if ($no_account): ?>
    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#createAccModal">
      <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Utwórz konto
    </button>
    <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#bulkCreateModal">
      <i class="bi bi-people me-1" aria-hidden="true"></i>Utwórz zbiorczo (<?= count($no_account) ?>)
    </button>
    <?php endif; ?>
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
  w.document.write('<html><head><title>Ewidencja Uczestników</title>'
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
     Dwa widoki na całą szerokość: bez ?selected= — tabela uczestników z
     wyszukiwarką, filtrami i sortowaniem; z ?selected=ID — kartoteka kursanta
     w zakładkach (a11y: nav-tabs to NIE modal — bez focus trap) z linkiem
     „Wróć do listy”. Wszystkie akcje kartoteki to te same formularze/handlery
     co dotąd (przekierowują na ?selected=ID#detail-panel). ═════════════════ -->
<?php foreach (pp_pool_alarms() as $_al): if ($_al['grp'] !== 'ti') continue; ?>
<div class="alert <?= $_al['level'] === 'crit' ? 'alert-danger' : 'alert-warning' ?> py-2 small" role="alert">
  <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i><strong>Pula rachunków TI:</strong> <?= h($_al['msg']) ?> Nowi kursanci mogą zostać bez numeru — zgłoś administratorowi zamówienie numerów w banku.
</div>
<?php endforeach; ?>
<?php if ($selected_acc): $sa = $selected_acc; $sa_has_ms = !empty($sa['ms_user_id']); ?>
<a href="konta.php?last=<?= (int)$sa['id'] ?>" class="btn btn-sm btn-outline-secondary mb-3">
  <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do listy uczestników
</a>
<div class="card border-0 shadow-sm mb-4" id="detail-panel">
  <div class="card-header fw-semibold d-flex align-items-center flex-wrap gap-2">
    <i class="bi bi-person-badge text-primary" aria-hidden="true"></i>
    <span class="kk-name"><?= h($sa['client_name']) ?></span>
    <span class="badge <?= $sa['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $sa['is_active'] ? 'Aktywne' : 'Zablokowane' ?></span>
    <?php if (!empty($sa['is_minor'])): ?>
    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><i class="bi bi-people" aria-hidden="true"></i> małoletni</span>
    <?php endif; ?>
    <?php if (!empty($sa['simple_mode'])): ?>
    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle"><i class="bi bi-calendar-check" aria-hidden="true"></i> <?= h(KK_SIMPLE_MODES[(int)$sa['simple_mode']] ?? 'uproszczony') ?></span>
    <?php endif; ?>
    <?php if (!empty($sa['child_access_blocked'])): ?>
    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-lock-fill" aria-hidden="true"></i> wstrzymany przez opiekuna</span>
    <?php endif; ?>
    <span class="font-monospace text-body-secondary small">(<?= h($sa['login']) ?>)</span>
    <a href="konta.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Zamknij</a>
  </div>

  <!-- Szybkie akcje — bez przechodzenia do zakładek -->
  <div class="kk-akcje" role="group" aria-label="Szybkie akcje">
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
  <ul class="nav nav-tabs" id="detailTabs" role="tablist">
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
      <button class="nav-link <?= $active_tab === 'produkty' ? 'active' : '' ?>" id="tab-btn-produkty" data-bs-toggle="tab" data-bs-target="#tab-produkty" type="button" role="tab" aria-controls="tab-produkty" aria-selected="<?= $active_tab === 'produkty' ? 'true' : 'false' ?>">
        <i class="bi bi-globe2 me-1" aria-hidden="true"></i>Usługi i produkty
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <?php require_once dirname(__DIR__, 3) . '/modules/ti_student_folder/logic/studentFolder.php';
            TiStudentFolder::migrate();
            $_sf_badge = (int)(db_one("SELECT COUNT(*) AS c FROM k30_ti_sfile WHERE account_id=? AND status='pending' AND deleted_at IS NULL", [(int)$sa['id']])['c'] ?? 0); ?>
      <button class="nav-link <?= $active_tab === 'dokumenty' ? 'active' : '' ?>" id="tab-btn-dokumenty" data-bs-toggle="tab" data-bs-target="#tab-dokumenty" type="button" role="tab" aria-controls="tab-dokumenty" aria-selected="<?= $active_tab === 'dokumenty' ? 'true' : 'false' ?>">
        <i class="bi bi-folder2-open me-1" aria-hidden="true"></i>Dokumenty
        <?php if ($_sf_badge): ?><span class="badge bg-warning text-dark ms-1" title="Do zatwierdzenia"><?= $_sf_badge ?></span><?php endif; ?>
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

    <!-- ── Zakładka: Ogólne — sekcje wierszami „opis | akcja” (formularze i operacje bez zmian) ── -->
    <div class="tab-pane fade <?= $active_tab === 'ogolne' ? 'show active' : '' ?>" id="tab-ogolne" role="tabpanel" aria-labelledby="tab-btn-ogolne" tabindex="0">
      <?php
        require_once dirname(__DIR__, 3) . '/modules/ti_student_docs/logic/studentDocs.php';
        $_doc   = TiStudentDocs::get((int)$sa['id']) ?: [];
        $pu     = pp_user((int)$sa['client_id']);
        $stored = preg_replace('/\D/', '', (string)($pu['individual_nrb'] ?? ''));
        $_sid   = (int)$sa['id'];
      ?>

      <section class="kk-sec" aria-labelledby="kk-h-dane">
        <h3 id="kk-h-dane"><i class="bi bi-person-vcard" aria-hidden="true"></i>Dane konta</h3>
        <div class="kk-row">
          <div class="kk-row-info">
            <label class="kk-row-title" for="no<?= $_sid ?>">Numer kursanta</label>
            <div class="kk-row-desc">Numer w ewidencji, widoczny na wydrukach.</div>
          </div>
          <form method="post" class="kk-row-ctl">
            <input type="hidden" name="_token"      value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op"         value="set_no">
            <input type="hidden" name="account_id"  value="<?= $_sid ?>">
            <input type="text" id="no<?= $_sid ?>" name="student_no" value="<?= h($sa['student_no'] ?? '') ?>"
                   class="form-control form-control-sm font-monospace" style="width:120px" placeholder="—">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Zapisz</button>
          </form>
        </div>
        <div class="kk-row">
          <div class="kk-row-info">
            <label class="kk-row-title" for="vn<?= $_sid ?>">Numer rachunku do wpłat</label>
            <?= $stored !== '' ? '<span class="badge text-bg-success ms-1">nadany</span>' : '<span class="badge text-bg-secondary ms-1">brak</span>' ?>
            <div class="kk-row-desc">
              <?php if ($stored === ''): ?>Bez numeru kursant płaci na rachunek grupy albo organizacji.
              <?php else: ?><i class="bi bi-lock" aria-hidden="true"></i> Nadany w Płatnościach — zmiana tylko w <a href="../../../platnosci/admin.php?q=<?= urlencode((string)$sa['client_name']) ?>">panelu płatności</a> ·
                <a href="rachunki_wirtualne.php?scope=notice&amp;kind=client&amp;client=<?= (int)$sa['client_id'] ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF z informacją</a><?php endif; ?>
            </div>
          </div>
          <form method="post" class="kk-row-ctl">
            <input type="hidden" name="_token"      value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="account_id"  value="<?= $_sid ?>">
            <input type="text" id="vn<?= $_sid ?>" name="vnrb" value="<?= h($stored !== '' ? pp_nrb_format($stored) : '') ?>"
                   class="form-control form-control-sm font-monospace user-select-all" style="width:300px;max-width:100%" placeholder="26 cyfr" <?= $stored !== '' ? 'readonly aria-readonly="true"' : '' ?>>
            <?php if ($stored === ''): ?>
            <button type="submit" name="_op" value="set_vnrb" class="btn btn-sm btn-outline-secondary">Zapisz</button>
            <button type="submit" name="_op" value="assign_vnrb_pool" class="btn btn-sm btn-outline-primary" title="Nadaj kolejny wolny numer z puli TI">Nadaj z puli</button>
            <?php endif; ?>
          </form>
        </div>
        <div class="kk-row">
          <div class="kk-row-info">
            <div class="kk-row-title">Ostatnie logowanie</div>
            <?php if (!empty($sa['login_alias'])): ?><div class="kk-row-desc">Alias logowania: <strong><?= h($sa['login_alias']) ?></strong></div><?php endif; ?>
          </div>
          <div class="kk-val">
            <?php if ($sa['last_login']): ?>
            <?= h(date('d.m.Y H:i', strtotime($sa['last_login']))) ?>
            <?php if (!empty($sa['last_login_ip'])): ?><span class="font-monospace text-body-secondary small ms-1"><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= h($sa['last_login_ip']) ?></span><?php endif; ?>
            <?php else: ?><span class="text-body-secondary">jeszcze się nie logował(a)</span><?php endif; ?>
          </div>
        </div>
      </section>

      <section class="kk-sec" aria-labelledby="kk-h-haslo">
        <h3 id="kk-h-haslo"><i class="bi bi-key" aria-hidden="true"></i>Logowanie i hasło</h3>
        <div class="kk-row">
          <div class="kk-row-info">
            <div class="kk-row-title">Hasło</div>
            <div class="kk-row-desc">Nowe hasło losowe albo serwisowe (8 znaków)<?= $sa_has_ms ? ' — serwisowe nadpisuje też hasło konta Microsoft' : '' ?>. Reset do 1 logowania: następne wejście kodem SMS.</div>
          </div>
          <div class="kk-row-ctl">
            <form method="post" onsubmit="return confirm('Zresetować hasło?')">
              <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"        value="reset_pass">
              <input type="hidden" name="account_id" value="<?= $_sid ?>">
              <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-key me-1" aria-hidden="true"></i>Resetuj hasło</button>
            </form>
            <form method="post" onsubmit="return confirm('Ustawić hasło serwisowe (8 znaków)?<?= $sa_has_ms ? ' Nadpisze też hasło konta Microsoft tego kursanta.' : '' ?>')">
              <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"        value="service_pass">
              <input type="hidden" name="account_id" value="<?= $_sid ?>">
              <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Hasło serwisowe</button>
            </form>
            <form method="post" onsubmit="return confirm('Przywrócić konto do 1 logowania? Następne wejście będzie kodem SMS.')">
              <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"        value="reset_first_login">
              <input type="hidden" name="account_id" value="<?= $_sid ?>">
              <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset do 1 logowania</button>
            </form>
          </div>
        </div>
        <div class="kk-row">
          <div class="kk-row-info">
            <div class="kk-row-title">Dostęp do panelu
              <span class="kk-state <?= $sa['is_active'] ? 'kk-state-off' : 'kk-state-on' ?> ms-1"><?= $sa['is_active'] ? 'aktywny' : 'zablokowany' ?></span>
              <?php if (!empty($sa['child_access_blocked'])): ?><span class="kk-state kk-state-on ms-1">wstrzymany przez opiekuna</span><?php endif; ?>
            </div>
            <div class="kk-row-desc">Zablokowany kursant nie zaloguje się do panelu; dane i rozliczenia zostają.</div>
          </div>
          <div class="kk-row-ctl">
            <?php if (!empty($sa['child_access_blocked'])): ?>
            <form method="post" onsubmit="return confirm('Przywrócić kursantowi dostęp do panelu? Dostęp został wstrzymany przez opiekuna.')">
              <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"        value="child_unblock">
              <input type="hidden" name="account_id" value="<?= $_sid ?>">
              <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-unlock me-1" aria-hidden="true"></i>Przywróć dostęp</button>
            </form>
            <?php endif; ?>
            <form method="post">
              <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"        value="toggle">
              <input type="hidden" name="account_id" value="<?= $_sid ?>">
              <button type="submit" class="btn btn-sm <?= $sa['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                <i class="bi <?= $sa['is_active'] ? 'bi-lock' : 'bi-unlock' ?> me-1" aria-hidden="true"></i><?= $sa['is_active'] ? 'Zablokuj konto' : 'Odblokuj konto' ?>
              </button>
            </form>
          </div>
        </div>
      </section>

      <section class="kk-sec" aria-labelledby="kk-h-panel">
        <h3 id="kk-h-panel"><i class="bi bi-layout-text-sidebar" aria-hidden="true"></i>Panel kursanta</h3>
        <div class="kk-row">
          <div class="kk-row-info">
            <label class="kk-row-title" for="kk-simple-<?= $_sid ?>">Wariant panelu</label>
            <div class="kk-row-desc">Uproszczony: tylko terminy zajęć (odwołanie, przesunięcie, usprawiedliwienie, plan); warianty z płatnościami dodają Płatności, Rozliczenia i Portfel albo jeden ekran „ile i jak zapłacić”.</div>
          </div>
          <form method="post" class="kk-row-ctl">
            <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op"        value="set_simple">
            <input type="hidden" name="account_id" value="<?= $_sid ?>">
            <select class="form-select form-select-sm w-auto" id="kk-simple-<?= $_sid ?>" name="simple_mode">
              <?php foreach (KK_SIMPLE_MODES as $_m => $_ml): ?><option value="<?= $_m ?>"<?= (int)($sa['simple_mode'] ?? 0) === $_m ? ' selected' : '' ?>><?= h(mb_strtoupper(mb_substr($_ml, 0, 1)) . mb_substr($_ml, 1)) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Zapisz</button>
          </form>
        </div>
        <div class="kk-row">
          <div class="kk-row-info">
            <div class="kk-row-title">Dokument potwierdzający
              <?php if (empty($_doc['requires_doc'])): ?><span class="kk-state kk-state-off ms-1">nie wymaga</span>
              <?php elseif (($_doc['doc_status'] ?? '') === 'scan'): ?><span class="badge text-bg-success ms-1">skan</span> <span class="small"><?= h(substr((string)$_doc['doc_at'], 0, 10)) ?> · <a href="konta.php?doc_view=<?= $_sid ?>" target="_blank" rel="noopener">pokaż</a></span>
              <?php elseif (($_doc['doc_status'] ?? '') === 'declared'): ?><span class="badge text-bg-info ms-1">oświadczenie</span> <span class="small"><?= h(substr((string)$_doc['doc_at'], 0, 10)) ?></span>
              <?php else: ?><span class="kk-state kk-state-on ms-1">czeka na kursanta</span><?php endif; ?>
            </div>
            <div class="kk-row-desc">Po zalogowaniu kursant wgrywa skan albo składa oświadczenie.</div>
          </div>
          <form method="post" class="kk-row-ctl">
            <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op"        value="doc_required">
            <input type="hidden" name="account_id" value="<?= $_sid ?>">
            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="requires_doc" value="1" id="kk-rd-<?= $_sid ?>"<?= !empty($_doc['requires_doc']) ? ' checked' : '' ?>>
              <label class="form-check-label small" for="kk-rd-<?= $_sid ?>">wymagaj</label></div>
            <label class="visually-hidden" for="kk-rdl-<?= $_sid ?>">Jaki dokument</label>
            <input class="form-control form-control-sm w-auto" id="kk-rdl-<?= $_sid ?>" name="requires_doc_label" size="26" placeholder="np. orzeczenie o niepełnosprawności" value="<?= h((string)($_doc['requires_doc_label'] ?? '')) ?>">
            <?php if (!empty($_doc['requires_doc']) && ($_doc['doc_status'] ?? '') !== ''): ?><div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="doc_reset" value="1" id="kk-rr-<?= $_sid ?>"><label class="form-check-label small" for="kk-rr-<?= $_sid ?>">poproś ponownie</label></div><?php endif; ?>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Zapisz</button>
          </form>
        </div>
        <?php if ($ms_online_enabled): ?>
        <div class="kk-row">
          <div class="kk-row-info">
            <div class="kk-row-title">Konto Microsoft (nauka online)</div>
            <div class="kk-row-desc"><?php if ($sa_has_ms): ?><i class="bi bi-microsoft" aria-hidden="true"></i> <span class="font-monospace"><?= h($sa['ms_upn']) ?></span><?php else: ?>Kursant nie ma konta Microsoft.<?php endif; ?></div>
          </div>
          <form method="post" class="kk-row-ctl" <?= $sa_has_ms ? "onsubmit=\"return confirm('Usunąć konto Microsoft tego kursanta?')\"" : '' ?>>
            <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op"        value="<?= $sa_has_ms ? 'ms_delete' : 'ms_create' ?>">
            <input type="hidden" name="account_id" value="<?= $_sid ?>">
            <button type="submit" class="btn btn-sm <?= $sa_has_ms ? 'btn-outline-danger' : 'btn-outline-primary' ?>">
              <i class="bi bi-microsoft me-1" aria-hidden="true"></i><?= $sa_has_ms ? 'Usuń konto Microsoft' : 'Utwórz konto Microsoft' ?>
            </button>
          </form>
        </div>
        <?php endif; ?>
      </section>

      <section class="kk-sec" aria-labelledby="kk-h-rozl">
        <h3 id="kk-h-rozl"><i class="bi bi-cash-stack" aria-hidden="true"></i>Rozliczenia</h3>
        <div class="kk-row">
          <div class="kk-row-info">
            <div class="kk-row-title">Kursant wirtualny
              <span class="kk-state <?= !empty($sa['is_virtual']) ? 'kk-state-on' : 'kk-state-off' ?> ms-1"><?= !empty($sa['is_virtual']) ? 'tak' : 'nie' ?></span></div>
            <div class="kk-row-desc">Nie dostaje rachunku wirtualnego z puli; już nadany numer zostaje.</div>
          </div>
          <form method="post" class="kk-row-ctl">
            <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op"        value="toggle_virtual">
            <input type="hidden" name="account_id" value="<?= $_sid ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= !empty($sa['is_virtual']) ? 'Wyłącz' : 'Włącz' ?></button>
          </form>
        </div>
        <div class="kk-row">
          <div class="kk-row-info">
            <div class="kk-row-title">Tylko rozliczenie licencji
              <span class="kk-state <?= !empty($sa['licenses_only']) ? 'kk-state-on' : 'kk-state-off' ?> ms-1"><?= !empty($sa['licenses_only']) ? 'tak' : 'nie' ?></span></div>
            <div class="kk-row-desc">Uczestnik bez zajęć: nie naliczamy lekcji, a rozliczamy wyłącznie licencje (kwota miesięczna, dzień rozliczenia, ewentualny rabat). E-mail i SMS w dniu przypisania oraz przypomnienie dzień przed każdym terminem. Niedostępny do wysyłek CRM.</div>
          </div>
          <form method="post" class="kk-row-ctl">
            <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op"        value="toggle_licenses_only">
            <input type="hidden" name="account_id" value="<?= $_sid ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= !empty($sa['licenses_only']) ? 'Wyłącz' : 'Włącz' ?></button>
          </form>
        </div>
        <div class="kk-row">
          <div class="kk-row-info">
            <div class="kk-row-title">Bez rozliczeń i powiadomień
              <span class="kk-state <?= !empty($sa['no_billing']) ? 'kk-state-on' : 'kk-state-off' ?> ms-1"><?= !empty($sa['no_billing']) ? 'tak' : 'nie' ?></span></div>
            <div class="kk-row-desc">Nie naliczamy należności, nie wysyłamy SMS ani e-maili. Istniejące rozliczenia zostają.</div>
          </div>
          <form method="post" class="kk-row-ctl" onsubmit="return confirm('<?= !empty($sa['no_billing']) ? 'Włączyć z powrotem rozliczenia i powiadomienia dla tego kursanta?' : 'Wyłączyć rozliczenia oraz SMS i e-mail dla tego kursanta? Istniejące rozliczenia zostają.' ?>')">
            <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op"        value="toggle_no_billing">
            <input type="hidden" name="account_id" value="<?= $_sid ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= !empty($sa['no_billing']) ? 'Wyłącz' : 'Włącz' ?></button>
          </form>
        </div>
      </section>

      <?php
        // Rezygnacja i podania uczestnika (modules/ti_dop: REZ1, REZ2, ZPRO) — wystawienie i dotychczasowe dokumenty
        $_rez = [];
        try { $_rez = db_all("SELECT id, form, number, status, created_at FROM dop_requests WHERE client_id = ? AND form IN ('REZ1','REZ2','ZPRO') ORDER BY id DESC LIMIT 20", [(int)$sa['client_id']]); } catch (\Throwable $e) {}
        $_rezSt = ['signing' => ['czeka na podpis', '#fa6800'], 'verify' => ['do decyzji kierownika', '#f0a30a'], 'execute' => ['do wykonania', '#1ba1e2'],
                   'done' => ['zrealizowany', '#008a00'], 'rejected' => ['odmowa', '#a20025'], 'cancelled' => ['wycofany', '#647687']];
        $_rezNew = fn(string $f) => 'wnioskiDop.php?' . http_build_query(['_route' => '/nowy', 'form' => $f, 'klient' => (int)$sa['client_id']]);
      ?>
      <section class="kk-sec" aria-labelledby="kk-h-rez">
        <h3 id="kk-h-rez"><i class="bi bi-file-earmark-person" aria-hidden="true"></i>Rezygnacja i podania</h3>
        <?php foreach ([['REZ1', 'Oświadczenie o rezygnacji', 'Podpis elektroniczny (ePodpis): link e-mailem i SMS-em do uczestnika albo rodzica. Po przyjęciu przez kierownika zapis kończy się z podanym dniem.', '#a20025'],
                        ['REZ2', 'Prośba o rezygnację z dniem', 'Dokument odręczny: wydruk do podpisu i skan. Po przyjęciu przez kierownika zapis kończy się z podanym dniem.', '#d80073'],
                        ['ZPRO', 'Podanie o zmianę prowadzącego zajęcia', 'Pismo z uzasadnieniem; decyzja kierownika, opcjonalnie nowy prowadzący grupy w systemie.', '#fa6800']] as [$_f, $_t, $_d, $_c]): ?>
        <div class="kk-row">
          <div class="kk-row-info">
            <span class="kk-row-title"><span class="badge" style="background:<?= $_c ?>"><?= $_f ?></span> <?= h($_t) ?></span>
            <div class="kk-row-desc"><?= h($_d) ?></div>
          </div>
          <div class="kk-row-ctl"><a class="btn btn-sm btn-outline-primary" href="<?= h($_rezNew($_f)) ?>"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Wystaw <?= $_f ?></a></div>
        </div>
        <?php endforeach; ?>
        <?php if ($_rez): ?>
        <div class="kk-row">
          <div class="kk-row-info">
            <span class="kk-row-title">Dokumenty uczestnika</span>
            <ul class="list-unstyled small mb-0 mt-1">
              <?php foreach ($_rez as $_x): [$_sl, $_sc] = $_rezSt[$_x['status']] ?? [$_x['status'], '#647687']; ?>
              <li class="py-1"><a href="wnioskiDop.php?<?= h(http_build_query(['_route' => '/wnioski/' . (int)$_x['id']])) ?>"><?= h($_x['number']) ?></a>
                <span class="badge" style="background:<?= $_sc ?>"><?= h($_sl) ?></span> <span class="text-body-secondary"><?= h(date('d.m.Y', strtotime((string)$_x['created_at']))) ?></span></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
        <?php endif; ?>
      </section>

      <?php // Zadania (moduł Zadania) powiązane z uczestnikiem — tylko z obszarów, do których kadra ma dostęp w SZO
        require_once dirname(__DIR__, 3) . '/tasks/includes/linked_tasks_box.php';
        echo tasksLinksBox('ti_client', (string)(int)$sa['client_id'], $uid, 'kk', APP_URL . '/karty30/ti/dydaktyk/konta.php?selected=' . (int)$sa['id']); ?>

      <section class="kk-sec kk-sec-danger" aria-labelledby="kk-h-usun">
        <h3 id="kk-h-usun"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i>Usuwanie konta</h3>
        <div class="kk-row">
          <div class="kk-row-info">
            <div class="kk-row-title">Usuń konto kursanta</div>
            <div class="kk-row-desc">Tej operacji nie można cofnąć. Jeśli kursant ma tylko przestać się logować, użyj „Zablokuj konto”.</div>
          </div>
          <form method="post" class="kk-row-ctl" onsubmit="return confirm('Usunąć konto „<?= h(addslashes($sa['client_name'])) ?>”? Tej operacji nie można cofnąć.')">
            <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op"        value="delete">
            <input type="hidden" name="account_id" value="<?= $_sid ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń konto</button>
          </form>
        </div>
      </section>
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
      <?php /* Wnioski DOP1 (zwrot nadpłaty) i DOP2 (płatność z subkonta) — modules/ti_dop */
        $_dopOpen = []; try { $_dopOpen = db_all("SELECT id, number, form, status FROM dop_requests WHERE client_id = ? AND status NOT IN ('done','rejected','cancelled') ORDER BY id DESC", [(int)$sa['client_id']]); } catch (\Throwable $_e) {} ?>
      <div class="border-top pt-2 mt-3">
        <div class="small fw-semibold text-body-secondary mb-1">Wnioski rozliczeniowe</div>
        <div class="d-flex flex-wrap gap-1">
          <a class="btn btn-sm btn-outline-success py-0" href="wnioskiDop.php?<?= h(http_build_query(['_route' => '/nowy', 'form' => 'DOP1', 'klient' => (int)$sa['client_id']])) ?>">DOP1 — zwrot nadpłaty</a>
          <a class="btn btn-sm btn-outline-primary py-0" href="wnioskiDop.php?<?= h(http_build_query(['_route' => '/nowy', 'form' => 'DOP2', 'klient' => (int)$sa['client_id']])) ?>">DOP2 — płatność z subkonta</a>
          <a class="btn btn-sm btn-outline-success py-0" href="wnioskiDop.php?<?= h(http_build_query(['_route' => '/rop', 'klient' => (int)$sa['client_id']])) ?>">ROP — stan subkonta</a>
          <a class="btn btn-sm btn-outline-secondary py-0" href="wnioskiDop.php?<?= h(http_build_query(['_route' => '/rozliczenia/nowy', 'kind' => 'PRZ-P', 'klient' => (int)$sa['client_id']])) ?>">PRZ-P</a>
          <a class="btn btn-sm btn-outline-warning py-0" href="wnioskiDop.php?<?= h(http_build_query(['_route' => '/rozliczenia/nowy', 'kind' => 'PRZ', 'klient' => (int)$sa['client_id']])) ?>">PRZ</a>
          <a class="btn btn-sm btn-outline-danger py-0" href="wnioskiDop.php?<?= h(http_build_query(['_route' => '/rozliczenia/nowy', 'kind' => 'RKO', 'klient' => (int)$sa['client_id']])) ?>">RKO</a>
          <a class="btn btn-sm btn-outline-success py-0" href="wnioskiDop.php?<?= h(http_build_query(['_route' => '/rozliczenia/nowy', 'kind' => 'RKS', 'klient' => (int)$sa['client_id']])) ?>" title="Ręczne księgowanie środków (kierownik)">RKS</a>
          <a class="btn btn-sm btn-outline-dark py-0" href="wnioskiDop.php?<?= h(http_build_query(['_route' => '/rozliczenia/nowy', 'kind' => 'SUB1', 'klient' => (int)$sa['client_id']])) ?>" title="Nowa procedura subkonta: wyczyść historię i księguj ręcznie">SUB1</a>
          <a class="btn btn-sm btn-outline-secondary py-0" href="wnioskiDop.php?<?= h(http_build_query(['klient' => (int)$sa['client_id']])) ?>">wszystkie wnioski uczestnika</a>
        </div>
        <?php if ($_dopOpen): ?><div class="small mt-1">Otwarte: <?php foreach ($_dopOpen as $_d): ?><a class="me-2" href="wnioskiDop.php?<?= h(http_build_query(['_route' => '/wnioski/' . (int)$_d['id']])) ?>"><?= h($_d['number']) ?></a><?php endforeach; ?></div><?php endif; ?>
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
      <?php if (!empty($sa['licenses_only'])): $_has_billed = (bool)array_filter($sa_licenses, fn($x) => $x['status'] !== 'revoked' && TiLicencjeManager::amounts($x)); if (!$_has_billed): ?>
      <div class="alert alert-warning py-2 small mb-2" role="alert" style="max-width:640px">
        To konto ma opcję <strong>„tylko rozliczenie licencji”</strong>, ale nie ma jeszcze licencji z rozliczeniem miesięcznym. Dodaj licencję poniżej —
        podaj <strong>kwotę miesięczną</strong> i (opcjonalnie) dzień rozliczenia oraz rabat. Uczestnik dostanie e-mail i SMS z kwotą i terminem.
      </div>
      <?php endif; endif; ?>
      <?php if ($sa_licenses): ?>
      <ul class="list-group list-group-flush mb-2" style="max-width:560px">
        <?php foreach ($sa_licenses as $sl): $sl_st = TiLicencjeManager::statusOf($sl); ?>
        <li class="list-group-item d-flex align-items-center gap-2 px-0 py-1 small flex-wrap">
          <span class="fw-semibold"><?= h($sl['license_name']) ?></span>
          <?php if ($sl['login']): ?><span class="font-monospace text-body-secondary"><?= h($sl['login']) ?></span><?php endif; ?>
          <?php if (!empty($sl['expires_at'])): ?>
          <span class="<?= $sl_st === 'expired' ? 'text-danger fw-semibold' : ($sl_st === 'expiring' ? 'text-warning-emphasis fw-semibold' : 'text-body-secondary') ?>">do <?= h($sl['expires_at']) ?><?= $sl_st === 'expired' ? ' (wygasła)' : ($sl_st === 'expiring' ? ' (wygasa wkrótce)' : '') ?></span>
          <?php endif; ?>
          <span class="badge border text-body" title="Forma rozliczenia"><?= $sl['billing_form'] !== '' ? h($sl['billing_form']) : 'brak formy rozliczenia' ?></span>
          <?php $sl_am = TiLicencjeManager::amounts($sl); if ($sl_am): ?>
          <span class="badge text-bg-light border" title="Rozliczenie miesięczne: kwota do zapłaty, dzień miesiąca<?= $sl_am[0] !== $sl_am[1] ? ', po rabacie (cena ' . number_format($sl_am[0], 2, ',', ' ') . ' zł)' : '' ?>">
            <?= number_format($sl_am[1], 2, ',', ' ') ?> zł · <?= (int)$sl['billing_day'] ?>. dnia<?= $sl_am[0] !== $sl_am[1] ? ' · rabat' : '' ?></span>
          <?php endif; ?>
          <form method="post" class="ms-auto d-inline-flex gap-1" onsubmit="return confirm('Wysłać ponownie informację o tej licencji (e-mail i SMS)?')">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="license_notify">
            <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
            <input type="hidden" name="assignment_id" value="<?= (int)$sl['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Wyślij informację o przypisaniu ponownie" aria-label="Wyślij ponownie informację o licencji <?= h($sl['license_name']) ?>"><i class="bi bi-envelope-arrow-up" aria-hidden="true"></i></button>
          </form>
          <form method="post" class="" onsubmit="return confirm('Cofnąć przypisanie licencji „<?= h(addslashes($sl['license_name'])) ?>”?')">
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
        <div>
          <label class="form-label small mb-0" for="lic_form">Forma rozliczenia <span class="text-danger" aria-hidden="true">*</span></label>
          <select class="form-select form-select-sm" id="lic_form" name="billing_form" required>
            <option value="">— wybierz —</option>
            <?php foreach (TiLicencjeManager::BILLING_FORMS as $bf_code => $bf_meta): ?>
            <option value="<?= $bf_code ?>"><?= $bf_code ?> — <?= h($bf_meta['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label small mb-0" for="lic_bref">Nr faktury / kod</label>
          <input type="text" class="form-control form-control-sm font-monospace" id="lic_bref" name="billing_ref" maxlength="190" style="width:150px">
        </div>
        <div>
          <label class="form-label small mb-0" for="lic_amt">Kwota mies. (zł)<?= !empty($sa['licenses_only']) ? ' <span class="text-danger" aria-hidden="true">*</span>' : '' ?></label>
          <input type="text" inputmode="decimal" class="form-control form-control-sm" id="lic_amt" name="monthly_amount" style="width:90px" placeholder="np. 49,00"<?= !empty($sa['licenses_only']) ? ' required' : '' ?>>
        </div>
        <div>
          <label class="form-label small mb-0" for="lic_day">Dzień rozliczenia</label>
          <input type="number" min="1" max="31" class="form-control form-control-sm" id="lic_day" name="billing_day" style="width:80px" placeholder="dziś">
        </div>
        <div>
          <label class="form-label small mb-0" for="lic_dt">Rabat</label>
          <select class="form-select form-select-sm" id="lic_dt" name="discount_type" style="width:90px">
            <option value="">brak</option><option value="pct">%</option><option value="amt">zł</option>
          </select>
        </div>
        <div>
          <label class="form-label small mb-0" for="lic_dv">Wartość rabatu</label>
          <input type="text" inputmode="decimal" class="form-control form-control-sm" id="lic_dv" name="discount_value" style="width:80px">
        </div>
        <div>
          <label class="form-label small mb-0" for="lic_dn">Opis rabatu</label>
          <input type="text" class="form-control form-control-sm" id="lic_dn" name="discount_note" maxlength="120" style="width:150px" placeholder="np. edukacyjny">
        </div>
        <div class="form-check mb-1">
          <input class="form-check-input" type="checkbox" id="lic_notify" name="notify" value="1" checked>
          <label class="form-check-label small" for="lic_notify">Powiadom (e-mail / SMS)</label>
        </div>
        <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Przypisz</button>
      </form>
      <?php else: ?>
      <p class="text-body-secondary small mb-0">Brak aktywnego oprogramowania w katalogu.</p>
      <?php endif; ?>
      <a href="licencjeKursantow.php" class="small d-inline-block mt-2"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Pełny katalog oprogramowania i wszystkie przypisania</a>
      <?php try { include dirname(__DIR__, 3) . '/modules/ti_licencje_dydaktyczne/view/_kontaPanel.php'; } catch (\Throwable $_ldE) { error_log('konta ld panel: ' . $_ldE->getMessage()); } /* zarządzanie licencjami AM20–AM24 */ ?>
    </div>

    <!-- ── Zakładka: Usługi i produkty (domeny, hosting, inne) ─────────── -->
    <?php include __DIR__ . '/_konta_produkty.php'; ?>

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

    <!-- ── Zakładka: Dokumenty (teczka + do akceptacji) — modules/ti_student_folder ── -->
    <div class="tab-pane fade <?= $active_tab === 'dokumenty' ? 'show active' : '' ?>" id="tab-dokumenty" role="tabpanel" aria-labelledby="tab-btn-dokumenty" tabindex="0">
      <?php include dirname(__DIR__, 3) . '/modules/ti_student_folder/ui/staffTab.php'; ?>
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
<?php endif; // selected_acc ?>

<?php if (!$selected_acc): ?>
<!-- ── Lista uczestników na całą szerokość: wyszukiwarka, filtry, sortowanie
     (po stronie klienta — wszystkie wiersze są już w DOM) ── -->
<section class="card border-0 shadow-sm" aria-labelledby="ewid-h">
  <div class="card-header fw-semibold d-flex align-items-center">
    <i class="bi bi-people me-2 text-primary" aria-hidden="true"></i><h2 class="h6 mb-0 fw-semibold" id="ewid-h">Uczestnicy</h2>
    <span class="badge bg-secondary ms-2"><?= count($accounts) ?></span>
  </div>
  <?php if (!$accounts): ?>
  <div class="card-body text-muted">Brak kont. Utwórz pierwsze konto dla beneficjenta przyciskiem „Utwórz konto” powyżej.</div>
  <?php else: ?>
  <div class="card-body pb-2 ewid-filters">
    <form class="row g-2 align-items-end" role="search" aria-label="Filtruj uczestników" onsubmit="return false">
      <div class="col-12 col-md-4 col-xl-4">
        <label class="form-label small fw-semibold mb-1" for="studentSearch">Szukaj po nazwisku, loginie lub nr kursanta</label>
        <input type="search" id="studentSearch" class="form-control form-control-sm" autocomplete="off" placeholder="np. Kowalska lub a.kowalska">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1" for="fltState">Stan konta</label>
        <select id="fltState" class="form-select form-select-sm">
          <option value="">wszystkie</option>
          <option value="aktywne">aktywne</option>
          <option value="zablokowane">zablokowane</option>
          <option value="wstrzymane">wstrzymane przez opiekuna</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1" for="fltMinor">Małoletni</label>
        <select id="fltMinor" class="form-select form-select-sm">
          <option value="">wszyscy</option>
          <option value="1">tylko małoletni</option>
          <option value="0">tylko pełnoletni</option>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label small fw-semibold mb-1" for="fltGroup">Grupa</label>
        <select id="fltGroup" class="form-select form-select-sm">
          <option value="">wszystkie grupy</option>
          <option value="none">bez aktywnej grupy</option>
          <?php foreach ($group_opts as $_gid => $_glbl): ?>
          <option value="<?= (int)$_gid ?>"><?= h($_glbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-1 d-grid">
        <button type="button" id="fltReset" class="btn btn-sm btn-outline-secondary">Wyczyść</button>
      </div>
    </form>
    <p class="small text-muted mt-2 mb-0" id="ewidCount" role="status" aria-live="polite"></p>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 ewid-table" id="accounts-table">
      <caption class="visually-hidden">Uczestnicy z kontem w panelu kursanta. Kolumny z przyciskiem w nagłówku można sortować; wybierz imię i nazwisko, aby otworzyć kartotekę.</caption>
      <thead>
        <tr>
          <th scope="col" aria-sort="ascending"><button type="button" class="ewid-sort" data-key="name">Imię i nazwisko<i class="bi" aria-hidden="true"></i></button></th>
          <th scope="col" aria-sort="none"><button type="button" class="ewid-sort" data-key="login">Login<i class="bi" aria-hidden="true"></i></button></th>
          <th scope="col" aria-sort="none"><button type="button" class="ewid-sort" data-key="no">Nr kursanta<i class="bi" aria-hidden="true"></i></button></th>
          <th scope="col" aria-sort="none"><button type="button" class="ewid-sort" data-key="state">Stan konta<i class="bi" aria-hidden="true"></i></button></th>
          <th scope="col" aria-sort="none"><button type="button" class="ewid-sort" data-key="minor">Małoletni<i class="bi" aria-hidden="true"></i></button></th>
          <th scope="col">Grupy</th>
          <th scope="col" aria-sort="none"><button type="button" class="ewid-sort" data-key="last">Ostatnie logowanie<i class="bi" aria-hidden="true"></i></button></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($accounts as $a):
          $_aid = (int)$a['id'];
          $_grp = $acc_groups[(int)$a['client_id']] ?? [];
          $_state = !$a['is_active'] ? 'zablokowane' : (!empty($a['child_access_blocked']) ? 'wstrzymane' : 'aktywne');
          $_ll = (string)($a['last_login'] ?? '');
          $_llts = $_ll !== '' ? strtotime($_ll) : false;
        ?>
        <tr id="acc-row-<?= $_aid ?>" class="<?= $a['is_active'] ? '' : 'ewid-off' ?>"
            data-name="<?= h(mb_strtolower($a['client_name'])) ?>"
            data-login="<?= h(mb_strtolower($a['login'] . ' ' . ($a['login_alias'] ?? ''))) ?>"
            data-no="<?= h((string)($a['student_no'] ?? '')) ?>"
            data-state="<?= $_state ?>"
            data-minor="<?= !empty($a['is_minor']) ? 1 : 0 ?>"
            data-groups=" <?= h(implode(' ', array_keys($_grp))) ?> "
            data-last="<?= $_llts ? (int)$_llts : 0 ?>">
          <td><a class="fw-semibold ewid-link" href="?selected=<?= $_aid ?>#detail-panel"><?= h($a['client_name']) ?></a></td>
          <td class="font-monospace small"><?= h($a['login']) ?></td>
          <td class="font-monospace small text-body-secondary"><?= h((string)($a['student_no'] ?? '')) ?: '—' ?></td>
          <td>
            <?php if ($_state === 'aktywne'): ?><span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Aktywne</span>
            <?php elseif ($_state === 'wstrzymane'): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Wstrzymane przez opiekuna</span>
            <?php else: ?><span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Zablokowane</span><?php endif; ?>
          </td>
          <td><?= !empty($a['is_minor']) ? '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">tak</span>' : '<span class="text-body-secondary">nie</span>' ?></td>
          <td class="small"><?= $_grp ? h(implode(', ', $_grp)) : '<span class="text-body-secondary">—</span>' ?></td>
          <td class="small text-nowrap"><?= $_llts ? h(date('d.m.Y H:i', $_llts)) : '<span class="text-body-secondary">nigdy</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="card-body text-muted mb-0 d-none" id="ewidEmpty">Żaden uczestnik nie pasuje do wybranych filtrów.</p>
  <?php endif; ?>
</section>
<?php endif; // !selected_acc ?>

<?php if ($no_account): ?>
<!-- Okno: utwórz konto (poza zakładkami kartoteki) -->
<div class="modal fade" id="createAccModal" tabindex="-1" aria-labelledby="createAccModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op"   value="create">
      <div class="modal-header">
        <h2 class="modal-title h5" id="createAccModalLabel"><i class="bi bi-person-plus text-success me-2" aria-hidden="true"></i>Utwórz konto</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">
          Login i hasło generowane automatycznie.
          Kursant ma dostęp <strong>tylko</strong> do panelu kursanta.
        </p>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="createAccClient">Beneficjent <span class="text-danger" aria-hidden="true">*</span></label>
          <select class="form-select" id="createAccClient" name="client_id" required aria-required="true">
            <option value="">— wybierz —</option>
            <?php foreach ($no_account as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (ti_referral_settings()['enabled']): ?>
        <div class="mb-1">
          <label class="form-label small" for="createAccRef">Kod polecający (opcjonalnie)</label>
          <input type="text" class="form-control text-uppercase" id="createAccRef" name="referral_code" maxlength="6"
                 placeholder="np. AB12CD" style="letter-spacing:.15em" aria-describedby="createAccRefHelp">
          <div class="form-text" id="createAccRefHelp">Jeśli ktoś polecił zajęcia temu kursantowi — rabat dla obu stron naliczy się automatycznie.</div>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-success"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Utwórz konto</button>
      </div>
    </form>
  </div>
</div>

<!-- Okno: zbiorcze tworzenie kont -->
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
        <fieldset class="border rounded p-2 mb-2" style="max-height:320px;overflow:auto">
          <legend class="visually-hidden">Beneficjenci bez konta</legend>
          <?php foreach ($no_account as $c): ?>
          <div class="form-check">
            <input class="form-check-input bulk-cb" type="checkbox" name="client_ids[]" value="<?= (int)$c['id'] ?>" id="bc<?= (int)$c['id'] ?>">
            <label class="form-check-label small" for="bc<?= (int)$c['id'] ?>"><?= h($c['name']) ?></label>
          </div>
          <?php endforeach; ?>
        </fieldset>
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

// Ewidencja: wyszukiwarka + filtry (stan, małoletni, grupa) + sortowanie
// kolumn. Po stronie klienta — wszystkie wiersze są już w DOM. Stan filtrów
// pamiętany w sessionStorage, żeby „Wróć do listy” z kartoteki go odtwarzał.
(function () {
  var table = document.getElementById('accounts-table');
  if (!table) return;
  var tbody  = table.tBodies[0];
  var rows   = Array.prototype.slice.call(tbody.rows);
  var search = document.getElementById('studentSearch');
  var fState = document.getElementById('fltState');
  var fMinor = document.getElementById('fltMinor');
  var fGroup = document.getElementById('fltGroup');
  var count  = document.getElementById('ewidCount');
  var empty  = document.getElementById('ewidEmpty');
  var KEY = 'tiEwidFilters';
  var sortKey = 'name', sortDir = 1;

  function save() {
    try { sessionStorage.setItem(KEY, JSON.stringify({q: search.value, s: fState.value, m: fMinor.value, g: fGroup.value, k: sortKey, d: sortDir})); } catch (e) {}
  }
  function restore() {
    var st = null;
    try { st = JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) {}
    if (!st) return;
    search.value = st.q || ''; fState.value = st.s || ''; fMinor.value = st.m || '';
    fGroup.value = st.g || ''; if (fGroup.selectedIndex < 0) fGroup.value = '';
    if (st.k) { sortKey = st.k; sortDir = st.d === -1 ? -1 : 1; }
  }

  function apply() {
    var q = search.value.trim().toLowerCase(), s = fState.value, m = fMinor.value, g = fGroup.value, shown = 0;
    rows.forEach(function (r) {
      var d = r.dataset;
      var ok = (!q || d.name.indexOf(q) !== -1 || d.login.indexOf(q) !== -1 || d.no.indexOf(q) !== -1)
        && (!s || d.state === s)
        && (!m || d.minor === m)
        && (!g || (g === 'none' ? d.groups.trim() === '' : d.groups.indexOf(' ' + g + ' ') !== -1));
      r.hidden = !ok;
      if (ok) shown++;
    });
    count.textContent = 'Wyświetlono ' + shown + ' z ' + rows.length + ' uczestników.';
    if (empty) empty.classList.toggle('d-none', shown > 0);
    save();
  }

  function sortBy(key, dir) {
    sortKey = key; sortDir = dir;
    var num = key === 'last' || key === 'minor';
    rows.sort(function (a, b) {
      var x = a.dataset[key] || '', y = b.dataset[key] || '';
      var c = num ? (Number(x) - Number(y)) : x.localeCompare(y, 'pl', {sensitivity: 'base', numeric: true});
      if (c === 0 && key !== 'name') c = a.dataset.name.localeCompare(b.dataset.name, 'pl');
      return c * dir;
    });
    rows.forEach(function (r) { tbody.appendChild(r); });
    table.querySelectorAll('thead th').forEach(function (th) {
      var btn = th.querySelector('.ewid-sort');
      if (!btn) return;
      var on = btn.dataset.key === key;
      th.setAttribute('aria-sort', on ? (dir === 1 ? 'ascending' : 'descending') : 'none');
      btn.querySelector('.bi').className = 'bi ms-1 ' + (on ? (dir === 1 ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up opacity-50');
    });
    save();
  }

  table.querySelectorAll('.ewid-sort').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var k = btn.dataset.key;
      sortBy(k, k === sortKey ? -sortDir : 1);
    });
  });
  // Klik w dowolne miejsce wiersza = wejście w kartotekę (mysz); z klawiatury
  // służy do tego link z imieniem i nazwiskiem w pierwszej kolumnie.
  tbody.addEventListener('click', function (e) {
    if (e.target.closest('a, button, input, select')) return;
    var tr = e.target.closest('tr');
    var a  = tr && tr.querySelector('.ewid-link');
    if (a && !(window.getSelection && String(window.getSelection()))) window.location.href = a.href;
  });
  [search, fState, fMinor, fGroup].forEach(function (el) { el.addEventListener(el === search ? 'input' : 'change', apply); });
  document.getElementById('fltReset').addEventListener('click', function () {
    search.value = ''; fState.value = ''; fMinor.value = ''; fGroup.value = '';
    apply(); search.focus();
  });

  restore();
  sortBy(sortKey, sortDir);
  apply();

  // Powrót z kartoteki (?last=ID): fokus na link kursanta, z którego wyszliśmy.
  var last = new URLSearchParams(window.location.search).get('last');
  var row  = last && document.getElementById('acc-row-' + last);
  if (row && !row.hidden) {
    var link = row.querySelector('.ewid-link');
    row.scrollIntoView({block: 'center'});
    if (link) link.focus();
  }
})();
</script>

</div>
</main>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
