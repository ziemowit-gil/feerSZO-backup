<?php
/**
 * Seed script — testowa umowa zlecenie dla testy@feer.org.pl + konto do panelu.
 *
 * Do ręcznego przetestowania modułu „Rachunki" umowy zlecenie:
 *   - baner „Wyślij rachunek" (panel zleceniobiorcy, widok umowy, lista umów),
 *   - dodanie rachunku + powiadomienie e-mail z linkiem,
 *   - publiczna strona rachunku: pobranie, wgranie podpisanego skanu, komentarze.
 *
 * Uruchom:
 *   php seed_test_zlecenie.php              — konto + umowa (baner „wyślij rachunek")
 *   php seed_test_zlecenie.php --rachunek   — dodatkowo rachunek + link z tokenem
 *   php seed_test_zlecenie.php --reset      — usuwa rachunki testowej umowy i zaczyna od zera
 *
 * Bezpieczne do wielokrotnego uruchamiania — aktualizuje istniejące wpisy
 * (dopasowanie po e-mailu konta i numerze umowy) zamiast tworzyć duplikaty.
 *
 * WYŁĄCZNIE środowisko testowe — odmawia uruchomienia, gdy APP_ENV=production.
 */
require_once __DIR__ . '/config.php';

if (defined('APP_ENV') && APP_ENV === 'production') {
    fwrite(STDERR, "BŁĄD: seed testowy nie może być uruchomiony w środowisku produkcyjnym (APP_ENV=production).\n");
    exit(1);
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/zlecenie_schema.php';
require_once __DIR__ . '/includes/zlecenie_rachunki.php';

$opts        = array_slice($argv ?? [], 1);
$with_rach   = in_array('--rachunek', $opts, true);
$do_reset    = in_array('--reset',    $opts, true);

// ── Stałe testowe ─────────────────────────────────────────────────────────────
const SEED_EMAIL    = 'testy@feer.org.pl';
const SEED_PASSWORD = 'Test1234!';
const SEED_NUMER    = 'UZ/TEST/001';
const SEED_NAME     = 'Testowy Zleceniobiorca';

$creator = db_one("SELECT id, name FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1");
$creator_id = (int)($creator['id'] ?? 0);
if (!$creator_id) {
    echo "UWAGA: brak aktywnego admina — created_by zostanie puste.\n";
}

// ── Konto do panelu ───────────────────────────────────────────────────────────
// Rola „viewer": konta @feer.org.pl są ograniczone do logowania przez MS365
// tylko dla ról admin/editor (account_is_office_only), więc viewer zaloguje się
// hasłem lokalnym. Dodatkowo ustawiamy allow_local_fallback=1 na wszelki wypadek.
$hash = password_hash(SEED_PASSWORD, PASSWORD_BCRYPT);
$user = db_one("SELECT id FROM users WHERE email=?", [SEED_EMAIL]);
$user_fields = [
    'name'                 => SEED_NAME,
    'password'             => $hash,
    'role'                 => 'viewer',
    'is_active'            => 1,
    'must_change_password' => 0,
    'allow_local_fallback' => 1,
    'm365_login'           => SEED_EMAIL,
];
if ($user) {
    $user_id = (int)$user['id'];
    db_update('users', $user_fields, $user_id);
    echo "· Konto istniało — zaktualizowano hasło i ustawienia: " . SEED_EMAIL . " (id={$user_id})\n";
} else {
    $user_id = db_insert('users', $user_fields + [
        'email'      => SEED_EMAIL,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    echo "✓ Utworzono konto: " . SEED_EMAIL . " (id={$user_id})\n";
}

// ── Umowa zlecenie ────────────────────────────────────────────────────────────
// m365_login musi być ustawiony — po nim panel wiąże użytkownika z umową
// (panel_contracts() dopasowuje zlecenie po m365_user_id / m365_login).
$today      = date('Y-m-d');
$start      = date('Y-m-01', strtotime('-2 months'));
$end        = date('Y-m-t',  strtotime('+4 months'));
$contract_fields = [
    'numer_umowy'             => SEED_NUMER,
    'status'                  => 'w realizacji',
    'imie_nazwisko'           => SEED_NAME,
    'email'                   => SEED_EMAIL,
    'm365_login'              => SEED_EMAIL,
    'pesel'                   => '90010112345',
    'addr_street'             => 'Testowa',
    'addr_house'              => '1',
    'addr_flat'               => '2',
    'addr_postal'             => '00-001',
    'addr_city'               => 'Warszawa',
    'addr_country'            => 'PL',
    'rachunek_bankowy'        => 'PL61109010140000071219812874',
    'przedmiot_zlecenia'      => 'Testowe zlecenie — wsparcie merytoryczne przy projekcie demonstracyjnym.',
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
    'uwagi'                   => 'Rekord testowy utworzony przez seed_test_zlecenie.php — można usunąć.',
    'updated_at'              => date('Y-m-d H:i:s'),
];

$contract = db_one("SELECT id FROM umowy_zlecenie WHERE numer_umowy=?", [SEED_NUMER]);
if ($contract) {
    $contract_id = (int)$contract['id'];
    db_update('umowy_zlecenie', $contract_fields, $contract_id);
    echo "· Umowa istniała — zaktualizowano: " . SEED_NUMER . " (id={$contract_id})\n";
} else {
    $contract_id = db_insert('umowy_zlecenie', $contract_fields + [
        'created_by' => $creator_id ?: null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    echo "✓ Utworzono umowę zlecenie: " . SEED_NUMER . " (id={$contract_id})\n";
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
        'numer'            => date('n') . '/' . date('Y'),
        'data_wystawienia' => $today,
        'okres'            => rachunek_month_label(),
        'kwota_brutto'     => 3200.00,
        'uwagi'            => 'Rachunek testowy (seed). Plik nie jest dołączony — wgraj własny, aby przetestować pobieranie.',
    ], $creator_id ?: null);
    $rach_link = rachunek_public_url($rid);
    echo "✓ Dodano rachunek testowy #{$rid} za " . rachunek_month_label() . "\n";
}

// ── Podsumowanie ──────────────────────────────────────────────────────────────
$base = rtrim(APP_URL, '/');
echo "\n";
echo "════════════════════════════════════════════════════════════════\n";
echo " DANE TESTOWE\n";
echo "════════════════════════════════════════════════════════════════\n";
echo " Login (panel):    " . SEED_EMAIL . "\n";
echo " Hasło:            " . SEED_PASSWORD . "\n";
echo " Rola:             viewer (logowanie hasłem lokalnym)\n";
echo "\n";
echo " Panel:            {$base}/panel/index.php\n";
echo " Umowa (pracownik):{$base}/contracts/zlecenie/view.php?id={$contract_id}&tab=rachunki\n";
if ($rach_link) {
    echo " Rachunek (link publiczny, bez logowania):\n";
    echo "   {$rach_link}\n";
} else {
    echo " Rachunku brak — w panelu i na umowie zobaczysz baner „Wyślij rachunek”.\n";
    echo " Aby dodać rachunek testowy: php seed_test_zlecenie.php --rachunek\n";
}
echo "════════════════════════════════════════════════════════════════\n";
