<?php
/**
 * Seed script — testowy wolontariusz niepełnoletni z kontem opiekuna,
 * do ręcznego przetestowania: popup zgody RPTS, popup zgody przedstawiciela
 * ustawowego (panel/zgody.php), cron/minor_volunteer_periodic_verification.php,
 * cron/guardian_consent_renewal.php.
 *
 * Uruchom: php seed_test_minor_volunteer.php
 * Bezpieczne do wielokrotnego uruchamiania — aktualizuje istniejący wpis
 * zamiast tworzyć duplikaty (dopasowanie po numer_umowy).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wolontariat_schema.php';
require_once __DIR__ . '/includes/rpts.php';
require_once __DIR__ . '/includes/guardian_consent.php';

$creator = db_one("SELECT id, name FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1");
if (!$creator) {
    echo "BŁĄD: nie znaleziono żadnego aktywnego admina do przypisania jako created_by.\n";
    exit(1);
}
$creator_id = (int)$creator['id'];

$TEST_PASSWORD = 'Test1234!';
$hash = password_hash($TEST_PASSWORD, PASSWORD_BCRYPT);

// ── Konto dziecka (wolontariusza) ────────────────────────────────────────────
$child_email = 'test.wolontariusz.mlodociany@example.com';
$child = db_one("SELECT id FROM users WHERE email=?", [$child_email]);
if (!$child) {
    $child_id = db_insert('users', [
        'name'       => 'Kacper Testowy',
        'email'      => $child_email,
        'password'   => $hash,
        'role'       => 'viewer',
        'is_active'  => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    echo "Utworzono konto wolontariusza: {$child_email} (id={$child_id})\n";
} else {
    $child_id = (int)$child['id'];
    echo "Konto wolontariusza już istnieje: {$child_email} (id={$child_id})\n";
}

// ── Konto opiekuna (przedstawiciela ustawowego) ──────────────────────────────
$guardian_email = 'test.opiekun@example.com';
$guardian = db_one("SELECT id FROM users WHERE email=?", [$guardian_email]);
if (!$guardian) {
    $guardian_id = db_insert('users', [
        'name'       => 'Beata Testowa',
        'email'      => $guardian_email,
        'password'   => $hash,
        'role'       => 'viewer',
        'is_active'  => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    echo "Utworzono konto opiekuna: {$guardian_email} (id={$guardian_id})\n";
} else {
    $guardian_id = (int)$guardian['id'];
    echo "Konto opiekuna już istnieje: {$guardian_email} (id={$guardian_id})\n";
}

try { db()->exec("ALTER TABLE users ADD COLUMN guardian_user_id INTEGER NULL"); } catch (\Throwable $e) {}
db()->prepare("UPDATE users SET guardian_user_id=? WHERE id=?")->execute([$guardian_id, $child_id]);

// ── Umowa wolontariacka (niepełnoletni, kontakt z małoletnimi = RPTS) ────────
$numer = 'WOL/TEST/' . date('Y') . '/001';
$data = [
    'numer_umowy'             => $numer,
    'status'                  => 'aktywna',
    'imie_nazwisko'           => 'Kacper Testowy',
    'pesel'                   => null,
    'data_urodzenia'          => date('Y-m-d', strtotime('-16 years')),
    'niepelnoletni'           => 1,
    'adres'                   => 'ul. Testowa 1, 33-300 Nowy Sącz',
    'email'                   => $child_email,
    'telefon'                 => '500100200',
    'rodzic_imie_nazwisko'    => 'Beata Testowa',
    'rodzic_email'            => $guardian_email,
    'rodzic_telefon'          => '500300400',
    'przedmiot_porozumienia'  => "Pomoc przy organizacji zajęć świetlicowych dla dzieci\nWsparcie logistyczne podczas wydarzeń fundacji",
    'miejsce_wolontariatu'    => 'Świetlica środowiskowa FEER, Nowy Sącz',
    'data_zawarcia'           => date('Y-m-d', strtotime('-1 month')),
    'data_rozpoczecia'        => date('Y-m-d', strtotime('-1 month')),
    'bezterminowa'            => 1,
    'godzin_tygodniowo'       => 4,
    'ubezpieczenie_nnw'       => 1,
    'numer_polisy_nnw'        => 'TEST/NNW/0001',
    'szkolenie_bhp'           => 1,
    'data_szkolenia_bhp'      => date('Y-m-d', strtotime('-1 month +2 days')),
    'opiekun'                 => $creator['name'],
    'projekt_program'         => 'Świetlica FEER',
    'forma_podpisania'        => 'papier',
    // RPTS — kontakt z małoletnimi, zgoda jeszcze nieudzielona (popup w panelu wolontariusza)
    'rpts_wymagana'           => 1,
    'rpts_zweryfikowano'      => 0,
    // Zgoda przedstawiciela ustawowego — jeszcze nieudzielona (popup w panelu opiekuna)
    'zgoda_przedstawiciela'   => 0,
    'created_by'              => $creator_id,
];

$existing_contract = db_one("SELECT id FROM umowy_wolontariat WHERE numer_umowy=?", [$numer]);
if ($existing_contract) {
    $contract_id = (int)$existing_contract['id'];
    unset($data['numer_umowy']); // nie nadpisuj klucza dopasowania
    db_update('umowy_wolontariat', $data, $contract_id);
    echo "Zaktualizowano istniejącą umowę testową: {$numer} (id={$contract_id})\n";
} else {
    $contract_id = db_insert('umowy_wolontariat', $data);
    echo "Utworzono umowę testową: {$numer} (id={$contract_id})\n";
}

echo "\n─────────────────────────────────────────────\n";
echo "Gotowe. Dane do testów:\n\n";
echo "Umowa:            contracts/wolontariat/view.php?id={$contract_id}\n\n";
echo "Konto wolontariusza (do testu popupu RPTS):\n";
echo "  e-mail:  {$child_email}\n";
echo "  hasło:   {$TEST_PASSWORD}\n\n";
echo "Konto opiekuna (do testu popupu zgody przedstawiciela / panel/zgody.php):\n";
echo "  e-mail:  {$guardian_email}\n";
echo "  hasło:   {$TEST_PASSWORD}\n\n";
echo "Cron do przetestowania ręcznie:\n";
echo "  php cron/minor_volunteer_periodic_verification.php\n";
echo "  php cron/guardian_consent_renewal.php\n";
echo "─────────────────────────────────────────────\n";
