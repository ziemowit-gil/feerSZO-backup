<?php
/**
 * Seed script — przykładowe umowy wolontariatu + wolontariusze
 * Uruchom: php seed_wolontariat.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/persons.php';

$pdo = db();

// Znajdź usera ziemowit.gil@feer.org.pl
$user = db_one("SELECT id, name FROM users WHERE email = 'ziemowit.gil@feer.org.pl'");
if (!$user) {
    echo "BŁĄD: Nie znaleziono użytkownika ziemowit.gil@feer.org.pl\n";
    echo "Dostępni użytkownicy:\n";
    $all = db_all("SELECT id, email, name FROM users LIMIT 20");
    foreach ($all as $u) echo "  [{$u['id']}] {$u['email']} — {$u['name']}\n";
    exit(1);
}

$creator_id = $user['id'];
echo "Tworzę umowy dla użytkownika [{$creator_id}] {$user['name']}\n";

// Funkcja generująca numer umowy
$counter = 1;
function next_numer(int &$counter): string {
    return 'WOL/' . date('Y') . '/' . str_pad($counter++, 3, '0', STR_PAD_LEFT);
}

// Pobierz istniejące osoby lub utwórz nowe
$persons_data = [
    ['Anna Kowalska',    '85010112345', '1985-01-01', 'anna.kowalska@example.com',    '601100200', 'ul. Różana 12, 01-001 Warszawa'],
    ['Piotr Nowak',      '90050578901', '1990-05-05', 'piotr.nowak@example.com',      '602200300', 'ul. Lipowa 5, 30-001 Kraków'],
    ['Marta Wiśniewska', '95112234567', '1995-11-22', 'marta.wisn@example.com',       '603300400', 'ul. Akacjowa 7, 50-001 Wrocław'],
    ['Tomasz Zielński',  '88030345678', '1988-03-03', 'tomasz.ziel@example.com',      '604400500', 'ul. Sosnowa 3, 60-001 Poznań'],
    ['Karolina Dąbrowska','93070756789', '1993-07-07', 'karolina.d@example.com',      '605500600', 'ul. Dębowa 9, 80-001 Gdańsk'],
    ['Marek Lewandowski','78112267890', '1978-11-22', 'marek.lew@example.com',        '606600700', 'ul. Brzozowa 11, 20-001 Lublin'],
    ['Ewa Kamińska',     '02260378901', '2002-06-03', 'ewa.kaminska@example.com',     '607700800', 'ul. Wiśniowa 2, 40-001 Katowice'],
];

$person_ids = [];
foreach ($persons_data as $pd) {
    [$name, $pesel, $dob, $email, $phone, $addr] = $pd;
    $existing = db_one("SELECT id FROM persons WHERE pesel=? OR (imie_nazwisko=? AND email=?)", [$pesel, $name, $email]);
    if ($existing) {
        $person_ids[] = $existing['id'];
        echo "  Osoba już istnieje: {$name} (id={$existing['id']})\n";
    } else {
        $pid = db_insert('persons', [
            'imie_nazwisko'  => $name,
            'pesel'          => $pesel,
            'data_urodzenia' => $dob,
            'email'          => $email,
            'telefon'        => $phone,
            'adres'          => $addr,
        ]);
        $person_ids[] = $pid;
        echo "  Dodano osobę: {$name} (id={$pid})\n";
    }
}

// Sprawdź/stwórz akcję i grant
$action_id = db_one("SELECT id FROM actions LIMIT 1")['id'] ?? null;
$grant_id  = db_one("SELECT id FROM grants  LIMIT 1")['id'] ?? null;

// Przykładowe umowy
$contracts = [
    [
        'numer_umowy'           => next_numer($counter),
        'status'                => 'aktywna',
        'person_id'             => $person_ids[0],
        'imie_nazwisko'         => $persons_data[0][0],
        'pesel'                 => $persons_data[0][1],
        'email'                 => $persons_data[0][3],
        'telefon'               => $persons_data[0][4],
        'adres'                 => $persons_data[0][5],
        'data_urodzenia'        => $persons_data[0][2],
        'przedmiot_porozumienia'=> 'Pomoc przy organizacji wydarzeń kulturalnych i edukacyjnych dla dzieci i młodzieży.',
        'miejsce_wolontariatu'  => 'Centrum Kultury "Pod Lipą", Warszawa',
        'data_zawarcia'         => date('Y-m-d', strtotime('-3 months')),
        'data_rozpoczecia'      => date('Y-m-d', strtotime('-3 months')),
        'data_zakonczenia'      => date('Y-m-d', strtotime('+9 months')),
        'bezterminowa'          => 0,
        'godzin_tygodniowo'     => 8,
        'godzin_przepracowanych'=> 96,
        'ubezpieczenie_nnw'     => 1,
        'numer_polisy_nnw'      => 'PZU/NNW/2024/001234',
        'szkolenie_bhp'         => 1,
        'data_szkolenia_bhp'    => date('Y-m-d', strtotime('-3 months +3 days')),
        'zwrot_kosztow'         => 1,
        'zwrot_kosztow_opis'    => 'Dojazd na zajęcia, materiały szkoleniowe',
        'limit_zwrotu_kosztow'  => 500.00,
        'opiekun'               => 'Jan Kowalczyk',
        'projekt_program'       => 'Aktywna Młodzież 2024',
        'forma_podpisania'      => 'papier',
        'created_by'            => $creator_id,
        'action_id'             => $action_id,
        'grant_id'              => $grant_id,
    ],
    [
        'numer_umowy'           => next_numer($counter),
        'status'                => 'w realizacji',
        'person_id'             => $person_ids[1],
        'imie_nazwisko'         => $persons_data[1][0],
        'pesel'                 => $persons_data[1][1],
        'email'                 => $persons_data[1][3],
        'telefon'               => $persons_data[1][4],
        'adres'                 => $persons_data[1][5],
        'data_urodzenia'        => $persons_data[1][2],
        'przedmiot_porozumienia'=> 'Wsparcie techniczne przy realizacji programu cyfryzacji NGO. Szkolenia z obsługi narzędzi online.',
        'miejsce_wolontariatu'  => 'Siedziba fundacji, ul. Długa 1, Kraków',
        'data_zawarcia'         => date('Y-m-d', strtotime('-1 month')),
        'data_rozpoczecia'      => date('Y-m-d', strtotime('-1 month')),
        'data_zakonczenia'      => date('Y-m-d', strtotime('+5 months')),
        'bezterminowa'          => 0,
        'godzin_tygodniowo'     => 12,
        'godzin_przepracowanych'=> 48,
        'ubezpieczenie_nnw'     => 1,
        'numer_polisy_nnw'      => 'ERGO/NNW/2024/005678',
        'szkolenie_bhp'         => 1,
        'data_szkolenia_bhp'    => date('Y-m-d', strtotime('-1 month +2 days')),
        'zwrot_kosztow'         => 1,
        'zwrot_kosztow_opis'    => 'Transport, zakwaterowanie na wyjazdy szkoleniowe',
        'limit_zwrotu_kosztow'  => 1200.00,
        'opiekun'               => 'Maria Nowak',
        'projekt_program'       => 'Cyfrowe NGO',
        'forma_podpisania'      => 'elektroniczna',
        'platforma_el'          => 'DocuSign',
        'created_by'            => $creator_id,
        'action_id'             => $action_id,
    ],
    [
        'numer_umowy'           => next_numer($counter),
        'status'                => 'projekt',
        'person_id'             => $person_ids[2],
        'imie_nazwisko'         => $persons_data[2][0],
        'pesel'                 => $persons_data[2][1],
        'email'                 => $persons_data[2][3],
        'telefon'               => $persons_data[2][4],
        'adres'                 => $persons_data[2][5],
        'data_urodzenia'        => $persons_data[2][2],
        'przedmiot_porozumienia'=> 'Koordynacja wolontariuszy podczas festiwalu muzycznego "Lato z kulturą".',
        'miejsce_wolontariatu'  => 'Park Miejski, Wrocław',
        'data_zawarcia'         => null,
        'data_rozpoczecia'      => date('Y-m-d', strtotime('+1 month')),
        'data_zakonczenia'      => date('Y-m-d', strtotime('+2 months')),
        'bezterminowa'          => 0,
        'godzin_tygodniowo'     => 20,
        'godzin_przepracowanych'=> 0,
        'ubezpieczenie_nnw'     => 0,
        'szkolenie_bhp'         => 0,
        'zwrot_kosztow'         => 0,
        'opiekun'               => 'Piotr Wiśniewski',
        'projekt_program'       => 'Lato z Kulturą 2025',
        'forma_podpisania'      => 'papier',
        'created_by'            => $creator_id,
    ],
    [
        'numer_umowy'           => next_numer($counter),
        'status'                => 'zakończona',
        'person_id'             => $person_ids[3],
        'imie_nazwisko'         => $persons_data[3][0],
        'pesel'                 => $persons_data[3][1],
        'email'                 => $persons_data[3][3],
        'telefon'               => $persons_data[3][4],
        'adres'                 => $persons_data[3][5],
        'data_urodzenia'        => $persons_data[3][2],
        'przedmiot_porozumienia'=> 'Wsparcie biurowe i administracyjne: archiwizacja dokumentów, obsługa korespondencji.',
        'miejsce_wolontariatu'  => 'Biuro fundacji, Poznań',
        'data_zawarcia'         => date('Y-m-d', strtotime('-8 months')),
        'data_rozpoczecia'      => date('Y-m-d', strtotime('-8 months')),
        'data_zakonczenia'      => date('Y-m-d', strtotime('-1 month')),
        'bezterminowa'          => 0,
        'godzin_tygodniowo'     => 6,
        'godzin_przepracowanych'=> 168,
        'ubezpieczenie_nnw'     => 1,
        'numer_polisy_nnw'      => 'AXA/NNW/2023/009012',
        'szkolenie_bhp'         => 1,
        'data_szkolenia_bhp'    => date('Y-m-d', strtotime('-8 months +1 week')),
        'zwrot_kosztow'         => 1,
        'zwrot_kosztow_opis'    => 'Dojazd — bilet miesięczny',
        'limit_zwrotu_kosztow'  => 300.00,
        'opiekun'               => 'Jan Kowalczyk',
        'projekt_program'       => 'Biuro NGO',
        'forma_podpisania'      => 'papier',
        'created_by'            => $creator_id,
    ],
    [
        'numer_umowy'           => next_numer($counter),
        'status'                => 'aktywna',
        'person_id'             => $person_ids[4],
        'imie_nazwisko'         => $persons_data[4][0],
        'pesel'                 => $persons_data[4][1],
        'email'                 => $persons_data[4][3],
        'telefon'               => $persons_data[4][4],
        'adres'                 => $persons_data[4][5],
        'data_urodzenia'        => $persons_data[4][2],
        'niepelnoletni'         => 0,
        'przedmiot_porozumienia'=> 'Prowadzenie mediów społecznościowych fundacji, tworzenie grafik i treści promocyjnych.',
        'miejsce_wolontariatu'  => 'Praca zdalna',
        'data_zawarcia'         => date('Y-m-d', strtotime('-2 months')),
        'data_rozpoczecia'      => date('Y-m-d', strtotime('-2 months')),
        'bezterminowa'          => 1,
        'godzin_tygodniowo'     => 5,
        'godzin_przepracowanych'=> 40,
        'ubezpieczenie_nnw'     => 1,
        'numer_polisy_nnw'      => 'WARTA/NNW/2024/011111',
        'szkolenie_bhp'         => 1,
        'data_szkolenia_bhp'    => date('Y-m-d', strtotime('-2 months +5 days')),
        'zwrot_kosztow'         => 1,
        'zwrot_kosztow_opis'    => 'Narzędzia graficzne (subskrypcja Canva Pro)',
        'limit_zwrotu_kosztow'  => 200.00,
        'opiekun'               => 'Maria Nowak',
        'projekt_program'       => 'Komunikacja Fundacji',
        'forma_podpisania'      => 'elektroniczna',
        'platforma_el'          => 'ePUAP',
        'created_by'            => $creator_id,
        'grant_id'              => $grant_id,
    ],
    [
        'numer_umowy'           => next_numer($counter),
        'status'                => 'oczekuje',
        'person_id'             => $person_ids[5],
        'imie_nazwisko'         => $persons_data[5][0],
        'pesel'                 => $persons_data[5][1],
        'email'                 => $persons_data[5][3],
        'telefon'               => $persons_data[5][4],
        'adres'                 => $persons_data[5][5],
        'data_urodzenia'        => $persons_data[5][2],
        'przedmiot_porozumienia'=> 'Mentoring i coaching zawodowy beneficjentów programu aktywizacji zawodowej.',
        'miejsce_wolontariatu'  => 'Centrum Doradztwa, Lublin',
        'data_zawarcia'         => date('Y-m-d', strtotime('-1 week')),
        'data_rozpoczecia'      => date('Y-m-d', strtotime('-1 week')),
        'data_zakonczenia'      => date('Y-m-d', strtotime('+11 months')),
        'bezterminowa'          => 0,
        'godzin_tygodniowo'     => 4,
        'godzin_przepracowanych'=> 8,
        'ubezpieczenie_nnw'     => 0,
        'szkolenie_bhp'         => 0,
        'zwrot_kosztow'         => 1,
        'zwrot_kosztow_opis'    => 'Dojazd samochodem prywatnym',
        'limit_zwrotu_kosztow'  => 800.00,
        'opiekun'               => 'Karol Wiśniewski',
        'projekt_program'       => 'Aktywizacja Zawodowa 50+',
        'forma_podpisania'      => 'papier',
        'created_by'            => $creator_id,
        'action_id'             => $action_id,
        'grant_id'              => $grant_id,
    ],
    [
        'numer_umowy'           => next_numer($counter),
        'status'                => 'aktywna',
        'person_id'             => $person_ids[6],
        'imie_nazwisko'         => $persons_data[6][0],
        'pesel'                 => $persons_data[6][1],
        'email'                 => $persons_data[6][3],
        'telefon'               => $persons_data[6][4],
        'adres'                 => $persons_data[6][5],
        'data_urodzenia'        => $persons_data[6][2],
        'niepelnoletni'         => 0,
        'przedmiot_porozumienia'=> 'Asystowanie przy prowadzeniu zajęć tanecznych dla seniorów oraz pomoc w transporcie uczestników.',
        'miejsce_wolontariatu'  => 'Dom Seniora "Złota Jesień", Katowice',
        'data_zawarcia'         => date('Y-m-d', strtotime('-5 weeks')),
        'data_rozpoczecia'      => date('Y-m-d', strtotime('-5 weeks')),
        'data_zakonczenia'      => date('Y-m-d', strtotime('+7 months')),
        'bezterminowa'          => 0,
        'godzin_tygodniowo'     => 10,
        'godzin_przepracowanych'=> 50,
        'ubezpieczenie_nnw'     => 1,
        'numer_polisy_nnw'      => 'ALLIANZ/NNW/2025/000333',
        'szkolenie_bhp'         => 1,
        'data_szkolenia_bhp'    => date('Y-m-d', strtotime('-5 weeks +3 days')),
        'zwrot_kosztow'         => 1,
        'zwrot_kosztow_opis'    => 'Dojazd, ubezpieczenie dodatkowe',
        'limit_zwrotu_kosztow'  => 600.00,
        'opiekun'               => 'Jan Kowalczyk',
        'projekt_program'       => 'Wolontariat Seniora',
        'forma_podpisania'      => 'papier',
        'created_by'            => $creator_id,
        'grant_id'              => $grant_id,
    ],
];

// Wyczyść stare przykładowe umowy tego usera (opcjonalnie)
$existing_count = db_one("SELECT COUNT(*) as cnt FROM umowy_wolontariat WHERE created_by=?", [$creator_id])['cnt'];
if ($existing_count > 0) {
    echo "\nUwaga: istnieje już {$existing_count} umów dla tego usera. Pomijam istniejące.\n";
}

// Wstaw umowy
$inserted = 0;
foreach ($contracts as $c) {
    // Sprawdź czy numer już istnieje
    if (db_one("SELECT id FROM umowy_wolontariat WHERE numer_umowy=?", [$c['numer_umowy']])) {
        echo "  Pomijam (istnieje): {$c['numer_umowy']}\n";
        continue;
    }
    $id = db_insert('umowy_wolontariat', $c);
    echo "  ✓ Dodano: [{$id}] {$c['numer_umowy']} — {$c['imie_nazwisko']} [{$c['status']}]";
    if (!empty($c['limit_zwrotu_kosztow'])) {
        echo " | limit zwrotu: {$c['limit_zwrotu_kosztow']} zł";
    }
    echo "\n";
    $inserted++;
}

echo "\nGotowe! Dodano {$inserted} umów.\n";
