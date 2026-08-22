<?php
/**
 * Seed script — demonstracyjna umowa zlecenie + konto do panelu.
 *
 * Do przetestowania modułu „Rachunki" umowy zlecenie:
 *   - baner „Wyślij rachunek" (panel zleceniobiorcy, widok umowy, lista umów),
 *   - dodanie rachunku + powiadomienie e-mail z linkiem,
 *   - publiczna strona rachunku: pobranie, wgranie podpisanego skanu, komentarze.
 *
 * Profil zależy od środowiska (zob. seed_zlecenie_common.php):
 *   - testowe  → testy@feer.org.pl,     umowa UZ/TEST/001
 *   - PRODUKCJA→ produkcja@feer.org.pl, umowa UZ/DEMO/001, dane demo
 *
 * Na produkcji seed jest dozwolony, ale wyłącznie w profilu demo:
 *   - tworzy/aktualizuje TYLKO konto produkcja@feer.org.pl i umowę UZ/DEMO/001,
 *   - hasło jest losowe i wypisywane raz (żadnych stałych haseł na produkcji),
 *   - rachunki są zawsze testowe (test_mode=1) — nic nie wchodzi do EOD,
 *     a --rachunek-real jest odrzucane.
 * Do sprzątnięcia służy drugi skrypt: php seed_zlecenie_usun.php
 *
 * Uruchom:
 *   php seed_test_zlecenie.php                  — konto + umowa (baner „wyślij rachunek")
 *   php seed_test_zlecenie.php --rachunek       — dodatkowo rachunek w trybie TESTOWYM
 *                                                 (bez numeru, poza obiegiem księgowym)
 *   php seed_test_zlecenie.php --rachunek-real  — rachunek zwykły: numer RACH/{nr}/{MM}/{RRRR}
 *                                                 (niedostępne na produkcji)
 *   php seed_test_zlecenie.php --reset          — usuwa rachunki umowy seeda i zaczyna od zera
 *
 * Bezpieczne do wielokrotnego uruchamiania — aktualizuje istniejące wpisy
 * (dopasowanie po e-mailu konta i numerze umowy) zamiast tworzyć duplikaty.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/seed_zlecenie_common.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/zlecenie_schema.php';
require_once __DIR__ . '/includes/zlecenie_rachunki.php';

$opts        = array_slice($argv ?? [], 1);
$rach_real   = in_array('--rachunek-real', $opts, true);
$with_rach   = $rach_real || in_array('--rachunek', $opts, true);
$do_reset    = in_array('--reset',    $opts, true);

$profile     = seed_zl_profile();
$SEED_EMAIL  = $profile['email'];
$SEED_NUMER  = $profile['numer'];
$SEED_NAME   = $profile['name'];
$IS_PROD     = seed_zl_is_production();

// Na produkcji dane pozostają demo: żadnych rachunków wchodzących do księgowości.
if ($IS_PROD && $rach_real) {
    fwrite(STDERR, "BŁĄD: --rachunek-real jest niedostępne na produkcji — profil demo tworzy wyłącznie rachunki testowe.\n");
    exit(1);
}

// Stałe hasło tylko poza produkcją; na produkcji losowe, pokazane raz.
$SEED_PASSWORD = $IS_PROD
    ? bin2hex(random_bytes(6)) . '-Demo!'
    : 'Test1234!';

seed_zl_banner('Seed umowy zlecenie');

$creator = db_one("SELECT id, name FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1");
$creator_id = (int)($creator['id'] ?? 0);
if (!$creator_id) {
    echo "UWAGA: brak aktywnego admina — created_by zostanie puste.\n";
}

// ── Konto do panelu ───────────────────────────────────────────────────────────
// Rola „viewer": konta @feer.org.pl są ograniczone do logowania przez MS365
// tylko dla ról admin/editor (account_is_office_only), więc viewer zaloguje się
// hasłem lokalnym. Dodatkowo ustawiamy allow_local_fallback=1 na wszelki wypadek.
$hash = password_hash($SEED_PASSWORD, PASSWORD_BCRYPT);
$user = db_one("SELECT id FROM users WHERE email=?", [$SEED_EMAIL]);
$user_fields = [
    'name'                 => $SEED_NAME,
    'password'             => $hash,
    'role'                 => 'viewer',
    'is_active'            => 1,
    'must_change_password' => 0,
    'allow_local_fallback' => 1,
    'm365_login'           => $SEED_EMAIL,
];
if ($user) {
    $user_id = (int)$user['id'];
    db_update('users', $user_fields, $user_id);
    echo "· Konto istniało — zaktualizowano hasło i ustawienia: {$SEED_EMAIL} (id={$user_id})\n";
} else {
    $user_id = db_insert('users', $user_fields + [
        'email'      => $SEED_EMAIL,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    echo "✓ Utworzono konto: {$SEED_EMAIL} (id={$user_id})\n";
}

// ── Umowa zlecenie ────────────────────────────────────────────────────────────
// m365_login musi być ustawiony — po nim panel wiąże użytkownika z umową
// (panel_contracts() dopasowuje zlecenie po m365_user_id / m365_login).
$today      = date('Y-m-d');
$start      = date('Y-m-01', strtotime('-2 months'));
$end        = date('Y-m-t',  strtotime('+4 months'));
$contract_fields = [
    'numer_umowy'             => $SEED_NUMER,
    'status'                  => 'w realizacji',
    'imie_nazwisko'           => $SEED_NAME,
    'email'                   => $SEED_EMAIL,
    'm365_login'              => $SEED_EMAIL,
    'pesel'                   => '90010112345',
    'addr_street'             => 'Testowa',
    'addr_house'              => '1',
    'addr_flat'               => '2',
    'addr_postal'             => '00-001',
    'addr_city'               => 'Warszawa',
    'addr_country'            => 'PL',
    'rachunek_bankowy'        => 'PL61109010140000071219812874',
    'przedmiot_zlecenia'      => $IS_PROD
        ? 'DEMO — rekord pokazowy modułu Rachunki. Nie jest to rzeczywista umowa.'
        : 'Testowe zlecenie — wsparcie merytoryczne przy projekcie demonstracyjnym.',
    'data_zawarcia'           => $start,
    'data_rozpoczecia'        => $start,
    'data_zakonczenia'        => $end,
    'wynagrodzenie_brutto'    => 3200.00,
    'typ_stawki'              => 'miesięczna',
    'liczba_godzin_planowana' => 40,
    'sposob_rozliczenia'      => 'na podstawie rachunku',
    'termin_platnosci'        => '14 dni od dostarczenia rachunku',
    'wymagany_rachunek'       => 1,
    'zus_skladki'             => 1,
    'opiekun'                 => $creator['name'] ?? '',
    'uwagi'                   => ($IS_PROD ? 'REKORD DEMO' : 'Rekord testowy')
        . ' utworzony przez seed_test_zlecenie.php. Usuwanie: php seed_zlecenie_usun.php',
    'updated_at'              => date('Y-m-d H:i:s'),
];

$contract = db_one("SELECT id FROM umowy_zlecenie WHERE numer_umowy=?", [$SEED_NUMER]);
if ($contract) {
    $contract_id = (int)$contract['id'];
    db_update('umowy_zlecenie', $contract_fields, $contract_id);
    echo "· Umowa istniała — zaktualizowano: {$SEED_NUMER} (id={$contract_id})\n";
} else {
    $contract_id = db_insert('umowy_zlecenie', $contract_fields + [
        'created_by' => $creator_id ?: null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    echo "✓ Utworzono umowę zlecenie: {$SEED_NUMER} (id={$contract_id})\n";
}

// ── Opcjonalny reset rachunków ────────────────────────────────────────────────
if ($do_reset) {
    $n = 0;
    foreach (get_rachunki('zlecenie', $contract_id) as $r) { delete_rachunek((int)$r['id']); $n++; }
    echo "· Usunięto rachunki testowej umowy: {$n}\n";
}

// ── Opcjonalny rachunek w rejestrze ───────────────────────────────────────────
$rach_link = null;
if ($with_rach) {
    $rid = create_rachunek([
        'contract_id'      => $contract_id,
        'data_wystawienia' => $today,
        'okres'            => rachunek_month_label(),
        'kwota_brutto'     => 3200.00,
        'test_mode'        => $rach_real ? 0 : 1,
        'uwagi'            => 'Rachunek z seeda. Plik nie jest dołączony — wgraj własny, aby przetestować pobieranie.',
    ], $creator_id ?: null);
    $rach_link = rachunek_public_url($rid);
    $rach_row  = get_rachunek($rid);
    echo $rach_real
        ? "✓ Dodano rachunek #{$rid} nr {$rach_row['numer']} za " . rachunek_month_label() . "\n"
        : "✓ Dodano rachunek TESTOWY #{$rid} za " . rachunek_month_label()
          . " (bez numeru, poza obiegiem księgowym)\n";
}

// ── Podsumowanie ──────────────────────────────────────────────────────────────
$base = rtrim(APP_URL, '/');
echo "\n";
echo "════════════════════════════════════════════════════════════════\n";
echo ($IS_PROD ? " DANE DEMO (produkcja)\n" : " DANE TESTOWE\n");
echo "════════════════════════════════════════════════════════════════\n";
echo " Login (panel):    {$SEED_EMAIL}\n";
echo " Hasło:            {$SEED_PASSWORD}\n";
echo " Rola:             viewer (logowanie hasłem lokalnym)\n";
if ($IS_PROD) {
    echo " Hasło losowe — zapisz je teraz, nie zostanie pokazane ponownie.\n";
    echo " Rachunki w profilu demo są ZAWSZE testowe (poza obiegiem księgowym).\n";
}
echo "\n";
echo " Panel:            {$base}/panel/index.php\n";
echo " Umowa (pracownik):{$base}/contracts/zlecenie/view.php?id={$contract_id}&tab=rachunki\n";
if ($rach_link) {
    echo " Rachunek (link publiczny, bez logowania):\n";
    echo "   {$rach_link}\n";
    if (!$rach_real) {
        echo "\n Rachunek jest TESTOWY: nie trafi do EOD, nie ma numeru i NIE wycisza\n";
        echo " banera „Wyślij rachunek”. Zwykły rachunek: php seed_test_zlecenie.php --rachunek-real\n";
    }
} else {
    echo " Rachunku brak — w panelu i na umowie zobaczysz baner „Wyślij rachunek”.\n";
    echo " Aby dodać rachunek testowy: php seed_test_zlecenie.php --rachunek\n";
}
echo "\n Sprzątanie (umowa + rachunki + komentarze + pliki):\n";
echo "   php seed_zlecenie_usun.php          — usuwa umowę {$SEED_NUMER}\n";
echo "   php seed_zlecenie_usun.php --konto  — dodatkowo konto {$SEED_EMAIL}\n";
echo "════════════════════════════════════════════════════════════════\n";
