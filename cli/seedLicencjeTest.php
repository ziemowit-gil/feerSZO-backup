<?php
/**
 * cli/seedLicencjeTest.php — testowy uczestnik do panelu „Moje licencje” (kursant.feer.org.pl/licencje/).
 *
 * Zakłada uczestnika „Uczestnik Licencje (TEST)” z kontem w panelu (login domyślnie kursant.licencje, oznaczony jako wirtualny — bez e-maili i SMS-ów)
 * i danymi do faktury, a do tego:
 *   • licencje w module Licencje: Claude Team (Anthropic), NordVPN (Nord Security, z loginem), Adobe Creative Cloud (TEST) z kluczem,
 *   • licencję na Office — sprawę AM7 „przyznana” z kontem w tenancie (login l + 8 cyfr),
 *   • login do panelu licencji „l” + 8 cyfr (modules/license_login) z hasłem serwisowym — logowanie na kursant.feer.org.pl/licencje/,
 *   • przykładowe konto w tenancie edukacyjnym: l…@edu.feer.org.pl (tylko wpis w SZO — ms_upn; bez konta w Microsoft), wymagane do Claude,
 *   • usługi refakturowane przez FEER (modules/ti_products): domenę i hosting z ceną miesięczną.
 * Abonamentu Claude/NordVPN NIE włącza — to robi się w panelu (przycisk „Włącz …”), żeby przejść cały przebieg; NordVPN wymaga ceny w ustawieniach.
 *
 * Użycie:
 *   php cli/seedLicencjeTest.php create [--haslo=…] [--login=kursant.licencje]   # idempotentne; hasło = hasło serwisowe (niżej)
 *   php cli/seedLicencjeTest.php haslo [--login=…]    # nowe LOSOWE hasło serwisowe (ustawione kontu i wypisane)
 *   php cli/seedLicencjeTest.php status [--login=…]
 *   php cli/seedLicencjeTest.php delete [--login=…]   # usuwa uczestnika, konto, licencje, sprawę AM7, usługi, abonamenty i naliczenia (pozycje portalu → anulowane)
 * Hasło serwisowe: przy pierwszym „create” bez --haslo losowane i zapisywane w ustawieniach (settings: seed_licencje_test_haslo), potem każde
 * „create” ustawia kontu to samo hasło, a „status” je pokazuje (Admin › Seedery — tylko admin). Podane --haslo staje się nowym hasłem serwisowym.
 * Hasła nie ma w kodzie ani w gicie.
 * Także z Admin › Seedery (klucz licencje_test).
 * Tryby konta: „create” zakłada konto WIRTUALNE (bez e-maili i SMS, dokumenty w symulacji). „rzeczywiste” przełącza je na prawdziwe:
 * SMS-y i e-maile wychodzą naprawdę, przy pierwszym logowaniu do panelu licencji trzeba podać własny e-mail i telefon, a dokumenty idą do
 * Fakturowni (proforma; fakturę VAT wystawia Helpdesk — to prawdziwy dokument księgowy). Dotychczasowe abonamenty i opłaty (symulacje) są
 * czyszczone, żeby CRON nie zamienił ich w prawdziwe proformy. „wirtualne” wraca do trybu testowego.
 */
require_once dirname(__DIR__) . '/includes/cli_inproc.php';   // cli_exit() — także uruchamianie z Admin › Seedery
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) { http_response_code(403); exit("Ten skrypt można uruchomić tylko z CLI.\n"); }
$base = dirname(__DIR__);
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
defined('APP_INSTALLED') || define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/karty30.php';
require_once $base . '/modules/license_login/logic/licenseLogin.php';
require_once $base . '/modules/claude_billing/logic/claudeBilling.php';

const LT_NAME   = 'Uczestnik Licencje (TEST)';
const LT_MARKER = 'SEED:licencje-test';
const LT_EMAIL  = 'licencje.test@example.invalid';
const LT_PASS_KEY = 'seed_licencje_test_haslo';   // hasło serwisowe konta testowego (settings)

$args = array_map('strval', array_slice($GLOBALS['__szoCliArgs'] ?? ($argv ?? []), defined('SZO_CLI_INPROC') ? 0 : 1));
$cmd = ''; $opts = [];
foreach ($args as $a) {
    if (str_starts_with($a, '--')) { [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, ''); $opts[$k] = $v; }
    elseif ($cmd === '') $cmd = $a;
}
$login = strtolower(trim((string)($opts['login'] ?? '')) ?: 'kursant.licencje');
$pass  = (string)($opts['haslo'] ?? '');
if (!preg_match('/^[a-z0-9._-]{3,60}$/', $login)) { fwrite(STDERR, "Nieprawidłowy --login (a–z, cyfry, kropka, myślnik).\n"); cli_exit(2); }

karty30_migrate();
$acc = db_one("SELECT a.id, a.client_id, c.name, c.notes FROM k30_ti_student_accounts a JOIN k30_clients c ON c.id=a.client_id WHERE a.login=?", [$login]);
$ours = $acc && str_contains((string)$acc['notes'], LT_MARKER);
$licNames = ['Claude Team' => 'Anthropic', 'NordVPN' => 'Nord Security', 'Adobe Creative Cloud (TEST)' => 'Adobe'];

/** Wiersz katalogu licencji (k30_ti_licenses) — istniejący po nazwie albo nowy. */
$licenseId = function (string $name, string $vendor): int {
    $r = db_one("SELECT id FROM k30_ti_licenses WHERE name=? AND vendor=?", [$name, $vendor]);
    return $r ? (int)$r['id'] : db_insert('k30_ti_licenses', ['name' => $name, 'vendor' => $vendor, 'notes' => LT_MARKER]);
};

switch ($cmd) {
    case 'status':
        if (!$acc) { echo "Brak konta {$login}.\n"; cli_exit(0); }
        $cid = (int)$acc['client_id'];
        echo "Konto {$login} → uczestnik #{$cid} „{$acc['name']}”" . ($ours ? '' : ' (NIE założone przez ten seeder)') . "\n";
        foreach (k30_ti_client_licenses($cid) as $l) echo "  licencja: {$l['license_name']} ({$l['vendor']})" . ($l['login'] ? ", login {$l['login']}" : '') . "\n";
        echo "  panel licencji: https://kursant.feer.org.pl/licencje/  login " . (LicenseLogin::get($cid) ?: '—') . " (hasło serwisowe)\n";
        echo "  konto edu (seed): " . (ClaudeBilling::eduAccount($cid) ?: '—') . "\n";
        echo "  hasło serwisowe: " . (org_setting(LT_PASS_KEY) ?: '— (ustawi się przy „Załóż”)') . "\n";
        $virt = (int)(db_one("SELECT COALESCE(is_virtual,0) v FROM k30_ti_student_accounts WHERE id=?", [(int)$acc['id']])['v'] ?? 0);
        echo "  tryb: " . ($virt ? 'WIRTUALNE (symulacja: bez e-maili, SMS i Fakturowni)' : 'RZECZYWISTE (SMS, e-maile i Fakturownia naprawdę)') . "\n";
        echo "  logowanie: https://kursant.feer.org.pl/  login {$login}\n";
        try {   // diagnoza logowania (modules/ti_login): pierwsze logowanie kodem SMS i blokada po nieudanych próbach
            require_once $base . '/includes/ti_student_reauth.php';
            if (function_exists('ti_reauth_migrate')) ti_reauth_migrate();
            $row = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [(int)$acc['id']]);
            echo "  logowanie hasłem: " . (ti_student_needs_reauth($row) ? 'NIE — konto czeka na pierwsze logowanie kodem SMS (uruchom „Nowe losowe hasło”)' : 'tak') . ", aktywne: " . ((int)$row['is_active'] ? 'tak' : 'NIE') . "\n";
            $n = (int)(db_one("SELECT COUNT(*) n FROM login_attempts WHERE identifier=? AND created_at > ?", ['ti:' . $login, date('Y-m-d H:i:s', time() - 3600)])['n'] ?? 0);
            echo "  nieudane próby w ostatniej godzinie: {$n}" . ($n ? ' („Nowe losowe hasło” je wyczyści)' : '') . "\n";
        } catch (\Throwable $e) {}
        foreach (array_keys(ClaudeBilling::PRODUCTS) as $p) { $s = ClaudeBilling::forClient($cid, false, $p); if ($s) echo "  abonament {$p}: {$s['status']} od {$s['activated_on']}\n"; }
        try { foreach (db_all("SELECT case_number, request_status, tenant_upn FROM licenses WHERE interested_kind='client' AND interested_id=?", [$cid]) as $o) echo "  sprawa {$o['case_number']}: {$o['request_status']} {$o['tenant_upn']}\n"; } catch (\Throwable $e) {}
        try { foreach (db_all("SELECT name, product_type, status FROM k30_ti_client_products WHERE client_id=?", [$cid]) as $p) echo "  usługa: {$p['name']} ({$p['product_type']}, {$p['status']})\n"; } catch (\Throwable $e) {}
        cli_exit(0);

    case 'create':
        if ($acc && !$ours) { fwrite(STDERR, "Konto {$login} już istnieje i nie jest kontem tego seedera — wybierz inny --login.\n"); cli_exit(1); }
        if ($pass !== '' && strlen($pass) < 8) { fwrite(STDERR, "Hasło musi mieć co najmniej 8 znaków.\n"); cli_exit(2); }
        // hasło serwisowe: podane → zapamiętane; inaczej zapamiętane wcześniej; inaczej wylosowane i zapamiętane
        $newPass = $pass !== '' ? $pass : (org_setting(LT_PASS_KEY) ?: 'Lic-' . substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12));
        if (org_setting(LT_PASS_KEY) !== $newPass) org_setting_set(LT_PASS_KEY, $newPass);
        if (!$acc) {
            $cid = db_insert('k30_clients', ['name' => LT_NAME, 'email' => LT_EMAIL, 'status' => 'learning', 'address' => 'ul. Testowa 1/2, 30-001 Kraków',
                'notes' => LT_MARKER . ' — uczestnik do testów panelu „Moje licencje”. Usuń: php cli/seedLicencjeTest.php delete']);
            $aid = db_insert('k30_ti_student_accounts', ['client_id' => $cid, 'login' => $login, 'password_hash' => password_hash($newPass, PASSWORD_DEFAULT), 'is_active' => 1, 'is_minor' => 0]);
            try { db_exec("UPDATE k30_ti_student_accounts SET is_virtual=1 WHERE id=?", [$aid]); } catch (\Throwable $e) {}   // wirtualny = bez e-maili i SMS-ów
            do { $no = (string)random_int(100000000000, 999999999999); } while (db_one("SELECT 1 FROM k30_ti_student_accounts WHERE student_no=?", [$no]));
            try { db_exec("UPDATE k30_ti_student_accounts SET student_no=? WHERE id=?", [$no, $aid]); } catch (\Throwable $e) {}   // nr beneficjenta jak w Ewidencji Uczestników
            $acc = ['id' => $aid];
            echo "OK uczestnik #{$cid} „" . LT_NAME . "”, konto {$login}\n";
        } else {
            $cid = (int)$acc['client_id'];
            db_exec("UPDATE k30_ti_student_accounts SET password_hash=?, is_active=1 WHERE id=?", [password_hash($newPass, PASSWORD_DEFAULT), (int)$acc['id']]);
            echo "Konto {$login} już jest (uczestnik #{$cid}) — uzupełniam brakujące dane.\n";
        }
        // logowanie hasłem od razu: bez „pierwszego logowania kodem SMS” (ti_student_needs_reauth — brak last_login / sec_chosen), obrazek bezpieczeństwa wybrany
        try { db_exec("UPDATE k30_ti_student_accounts SET last_login=COALESCE(last_login, datetime('now')), sec_chosen=1, sec_image=COALESCE(sec_image, 3), reauth_at=COALESCE(reauth_at, datetime('now')), must_change_password=0 WHERE id=?", [(int)$acc['id']]); }
        catch (\Throwable $e) { echo "UWAGA: nie ustawiono pierwszego logowania ({$e->getMessage()}) — konto może prosić o kod SMS.\n"; }
        try { db_exec("DELETE FROM login_attempts WHERE identifier=?", ['ti:' . $login]); } catch (\Throwable $e) {}   // blokada po nieudanych próbach
        // licencje w module Licencje
        foreach ($licNames as $n => $v) {
            $lid = $licenseId($n, $v);
            if (db_one("SELECT 1 FROM k30_ti_client_licenses WHERE client_id=? AND license_id=? AND status='active'", [$cid, $lid])) continue;
            db_insert('k30_ti_client_licenses', ['license_id' => $lid, 'client_id' => $cid, 'status' => 'active', 'expires_at' => date('Y-m-d', strtotime('+1 year')),
                'login' => $n === 'NordVPN' ? LT_EMAIL : '', 'access_key' => $n === 'Adobe Creative Cloud (TEST)' ? 'TEST-1234-ABCD-5678' : '', 'notes' => LT_MARKER]);
            echo "OK licencja {$n}\n";
        }
        // login do panelu licencji l + 8 cyfr z hasłem serwisowym + przykładowe konto w tenancie edukacyjnym (wpis w SZO)
        $own = LicenseLogin::generate($cid, 'seeder');
        LicenseLogin::setPassword($cid, $newPass);
        echo "OK panel licencji: login {$own} (hasło serwisowe)\n";
        try { db_exec("UPDATE k30_ti_student_accounts SET ms_upn=? WHERE id=? AND COALESCE(ms_upn,'')=''", [$own . '@edu.feer.org.pl', (int)$acc['id']]); echo "OK konto edu (seed): {$own}@edu.feer.org.pl\n"; }
        catch (\Throwable $e) { echo "POMINIĘTO konto edu: {$e->getMessage()}\n"; }
        // licencja na Office — sprawa AM7 „przyznana” z kontem w tenancie
        try {
            require_once $base . '/modules/ti_licencje_dydaktyczne/lib/ldSchema.php';
            if (function_exists('ldMigrate')) ldMigrate();
            if (!db_one("SELECT 1 FROM licenses WHERE interested_kind='client' AND interested_id=? AND COALESCE(form_kind,'AM5')='AM7'", [$cid])) {
                $seq = (int)(db_one("SELECT MIN(case_seq) m FROM licenses")['m'] ?? 0);
                $seq = min(-1, $seq - 1);   // ujemny numer — poza normalną numeracją spraw
                db_insert('licenses', ['case_number' => 'AM7/TEST/' . $cid, 'case_seq' => $seq, 'form_kind' => 'AM7', 'title' => 'Microsoft 365 A1 (Office) — TEST',
                    'interested_kind' => 'client', 'interested_id' => $cid, 'interested_name' => LT_NAME, 'interested_email' => LT_EMAIL,
                    'request_status' => 'granted', 'period_from' => date('Y-m-d'), 'period_to' => date('Y-m-d', strtotime('+1 year')),
                    'tenant_kind' => 'edu', 'tenant_upn' => $own . '@edu.feer.org.pl', 'tenant_created_at' => date('Y-m-d H:i:s'),
                    'created_name' => 'seeder', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                echo "OK licencja na Office (sprawa AM7/TEST/{$cid}, login {$own}@edu.feer.org.pl)\n";
            }
        } catch (\Throwable $e) { echo "POMINIĘTO licencja na Office: {$e->getMessage()}\n"; }
        // usługi refakturowane przez FEER
        try {
            require_once $base . '/modules/ti_products/logic/tiProducts.php';
            TiProducts::migrate();
            foreach ([
                ['product_type' => 'domain', 'provider' => 'ovh', 'name' => 'licencje-test-' . $cid . '.pl', 'expires_at' => date('Y-m-d', strtotime('+20 days')), 'status' => 'expiring',
                 'monthly_amount' => '9.90', 'billing_day' => '8', 'billing_form' => 'FAK'],
                ['product_type' => 'hosting', 'provider' => 'mydevil', 'name' => 'Hosting projektu (TEST)', 'server_details' => 'MyDevil — pakiet podstawowy', 'expires_at' => date('Y-m-d', strtotime('+11 months')),
                 'status' => 'active', 'monthly_amount' => '25', 'billing_day' => '8', 'billing_form' => 'FAK', 'discount_type' => 'pct', 'discount_value' => '20', 'discount_note' => 'rabat dla uczestnika'],
            ] as $in) {
                if (db_one("SELECT 1 FROM k30_ti_client_products WHERE client_id=? AND name=?", [$cid, $in['name']])) continue;
                $r = TiProducts::save($cid, $in);
                echo $r['ok'] ? "OK usługa {$in['name']}\n" : "POMINIĘTO usługa {$in['name']}: " . implode(' ', $r['errors']) . "\n";
            }
        } catch (\Throwable $e) { echo "POMINIĘTO usługi: {$e->getMessage()}\n"; }
        echo "\nPanel licencji: https://kursant.feer.org.pl/licencje/  →  {$own} / {$newPass}\n";
        echo "Panel uczestnika TI: https://kursant.feer.org.pl/  →  {$login} / {$newPass}  (hasło serwisowe — „Stan” je pokaże)\n";
        echo "Panel: " . rtrim((string)APP_URL, '/') . "/licencje/  (na produkcji: https://kursant.feer.org.pl/licencje/)\n";
        $ns = ClaudeBilling::settings('nordvpn');
        if (!$ns['priced'] || !$ns['enabled']) echo "Uwaga: NordVPN ma wyłączone samodzielne włączanie albo brak ceny — ustaw w panelu dydaktyka › Płatne licencje › NordVPN.\n";
        cli_exit(0);

    case 'haslo':
        if (!$acc) { fwrite(STDERR, "Brak konta {$login} — najpierw „create”.\n"); cli_exit(1); }
        if (!$ours) { fwrite(STDERR, "Konto {$login} nie zostało założone przez ten seeder.\n"); cli_exit(1); }
        $newPass = 'Lic-' . substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12);
        org_setting_set(LT_PASS_KEY, $newPass);
        db_exec("UPDATE k30_ti_student_accounts SET password_hash=?, is_active=1, last_login=COALESCE(last_login, datetime('now')), sec_chosen=1, sec_image=COALESCE(sec_image, 3), reauth_at=COALESCE(reauth_at, datetime('now')), must_change_password=0 WHERE id=?",
            [password_hash($newPass, PASSWORD_DEFAULT), (int)$acc['id']]);
        try { db_exec("DELETE FROM login_attempts WHERE identifier=?", ['ti:' . $login]); } catch (\Throwable $e) {}   // blokada po nieudanych próbach
        if ($ll = LicenseLogin::get((int)$acc['client_id'])) LicenseLogin::setPassword((int)$acc['client_id'], $newPass);
        echo "OK nowe losowe hasło serwisowe (blokada nieudanych prób wyczyszczona).\n";
        if ($ll) echo "Panel licencji: https://kursant.feer.org.pl/licencje/  →  {$ll} / {$newPass}\n";
        echo "Panel uczestnika TI: https://kursant.feer.org.pl/  →  {$login} / {$newPass}\n";
        cli_exit(0);

    case 'rzeczywiste':
    case 'wirtualne':
        if (!$acc) { fwrite(STDERR, "Brak konta {$login} — najpierw „create”.\n"); cli_exit(1); }
        if (!$ours) { fwrite(STDERR, "Konto {$login} nie zostało założone przez ten seeder.\n"); cli_exit(1); }
        $cid = (int)$acc['client_id'];
        ClaudeBilling::migrate();
        // czysty start: abonamenty i opłaty z poprzedniego trybu (symulacje SYM-… nie mogą stać się prawdziwymi proformami)
        foreach (db_all("SELECT pp_item_id FROM claude_charges WHERE client_id=? AND pp_item_id IS NOT NULL", [$cid]) as $r) db_exec("UPDATE payable_items SET status='cancelled' WHERE id=? AND status IN ('pending','processing')", [(int)$r['pp_item_id']]);
        $n = (int)(db_one("SELECT COUNT(*) n FROM claude_subscriptions WHERE client_id=?", [$cid])['n'] ?? 0);
        db_exec("DELETE FROM claude_events WHERE subscription_id IN (SELECT id FROM claude_subscriptions WHERE client_id=?)", [$cid]);
        db_exec("DELETE FROM claude_charges WHERE client_id=?", [$cid]);
        db_exec("DELETE FROM claude_subscriptions WHERE client_id=?", [$cid]);
        try { db_exec("DELETE FROM license_panel_contacts WHERE client_id=?", [$cid]); } catch (\Throwable $e) {}   // pierwsze wejście znowu pyta o e-mail i telefon
        if ($cmd === 'rzeczywiste') {
            db_exec("UPDATE k30_ti_student_accounts SET is_virtual=0 WHERE client_id=?", [$cid]);
            db_exec("UPDATE k30_clients SET email='', phone='' WHERE id=?", [$cid]);   // fikcyjne dane kontaktowe out — podasz własne
            echo "OK konto {$login} jest RZECZYWISTE" . ($n ? " (usunięto {$n} abonament(y) z symulacji)" : '') . ".\n";
            echo "Przy pierwszym wejściu do panelu licencji podaj własny e-mail i telefon (kod przyjdzie SMS-em).\n";
            echo "Dokumenty idą do Fakturowni: proforma od razu po wniosku, faktura VAT po kliknięciu w Helpdesku (prawdziwy dokument księgowy).\n";
            echo "Powrót do testów: akcja „Konto testowe (symulacja)”.\n";
        } else {
            db_exec("UPDATE k30_ti_student_accounts SET is_virtual=1 WHERE client_id=?", [$cid]);
            db_exec("UPDATE k30_clients SET email=?, phone='' WHERE id=?", [LT_EMAIL, $cid]);
            echo "OK konto {$login} jest znowu WIRTUALNE (symulacja)" . ($n ? ", usunięto {$n} abonament(y)" : '') . ". Dokumenty z Fakturowni z trybu rzeczywistego usuń tam ręcznie.\n";
        }
        if (function_exists('ti_virtual_contacts')) { require_once $base . '/includes/ti_virtual.php'; ti_virtual_contacts(true); }
        if ($ll = LicenseLogin::get($cid)) echo "Panel licencji: https://kursant.feer.org.pl/licencje/  →  {$ll} / hasło serwisowe (akcja „Stan”)\n";
        cli_exit(0);

    case 'delete':
        if (!$acc) { echo "Brak konta {$login} — nic do usunięcia.\n"; cli_exit(0); }
        if (!$ours) { fwrite(STDERR, "Konto {$login} nie zostało założone przez ten seeder — nie usuwam.\n"); cli_exit(1); }
        $cid = (int)$acc['client_id'];
        ClaudeBilling::migrate();
        foreach (db_all("SELECT pp_item_id FROM claude_charges WHERE client_id=? AND pp_item_id IS NOT NULL", [$cid]) as $r) db_exec("UPDATE payable_items SET status='cancelled' WHERE id=? AND status IN ('pending','processing')", [(int)$r['pp_item_id']]);
        db_exec("DELETE FROM claude_events WHERE subscription_id IN (SELECT id FROM claude_subscriptions WHERE client_id=?)", [$cid]);
        db_exec("DELETE FROM claude_charges WHERE client_id=?", [$cid]);
        db_exec("DELETE FROM claude_subscriptions WHERE client_id=?", [$cid]);
        db_exec("DELETE FROM k30_ti_client_licenses WHERE client_id=?", [$cid]);
        try { db_exec("DELETE FROM license_logins WHERE client_id=?", [$cid]); } catch (\Throwable $e) {}
        try { db_exec("DELETE FROM licenses WHERE interested_kind='client' AND interested_id=? AND case_number LIKE 'AM7/TEST/%'", [$cid]); } catch (\Throwable $e) {}
        try { db_exec("DELETE FROM k30_ti_client_products WHERE client_id=?", [$cid]); } catch (\Throwable $e) {}
        db_exec("DELETE FROM k30_ti_student_accounts WHERE id=?", [(int)$acc['id']]);
        db_exec("DELETE FROM k30_clients WHERE id=?", [$cid]);
        echo "OK usunięto uczestnika #{$cid}, konto {$login} i jego licencje, usługi oraz abonamenty (pozycje w portalu płatności anulowane; faktury w Fakturowni — jeśli powstały — usuń tam ręcznie).\n";
        cli_exit(0);

    default:
        fwrite(STDERR, "Użycie: php cli/seedLicencjeTest.php create|haslo|status|rzeczywiste|wirtualne|delete [--haslo=…] [--login=kursant.licencje]\n");
        cli_exit(2);
}
